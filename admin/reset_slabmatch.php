<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

// Local helper functions to ensure self-containment without depending on MLMEngine/engine.php

function localLogTransaction($db, $userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null, $customNetAmount = null) {
    $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);

    if ($customNetAmount !== null) {
        $netAmount = $customNetAmount;
    } elseif ($isDebit) {
        $netAmount = -($amount + $fee);
    } else {
        $netAmount = $amount - $fee;
    }

    $stmt = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description]);
}

function localGetLegsBusiness($db, $userId) {
    $stmt = $db->prepare("
        SELECT u.id, u.username,
               (u.total_investment + COALESCE((
                   SELECT SUM(downline.total_investment)
                   FROM genealogy g
                   JOIN users downline ON g.user_id = downline.id
                   WHERE g.parent_id = u.id
               ), 0)) as total_leg_business
        FROM users u
        WHERE u.sponsor_id = ?
    ");
    $stmt->execute([$userId]);
    $legs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($legs)) {
        return [
            'power_leg' => 0.00,
            'matching_leg' => 0.00,
            'matched_business' => 0.00,
            'power_carry_forward' => 0.00,
            'rest_carry_forward' => 0.00,
            'slab_breakdown' => []
        ];
    }

    $volumes = array_column($legs, 'total_leg_business');
    $powerLegRaw = max($volumes);
    $totalVolume = array_sum($volumes);
    $restLegRaw = $totalVolume - $powerLegRaw;

    $vPower = $powerLegRaw;
    $vRest = $restLegRaw;
    $totalMatched = 0.00;
    $slabBreakdown = [];

    // Ascending Slab Sequence ($500 -> $500,000)
    $slabs = [500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000];
    foreach ($slabs as $slab) {
        $m = min($vPower, $vRest);
        if ($m >= $slab) {
            $units = (int)floor($m / $slab);
            $matchedVolume = $units * $slab;

            $totalMatched += $matchedVolume;
            $vPower -= $matchedVolume;
            $vRest -= $matchedVolume;

            $slabBreakdown[$slab] = $units;
        } else {
            $slabBreakdown[$slab] = 0;
        }
    }

    return [
        'power_leg' => (float)$powerLegRaw,
        'matching_leg' => (float)$restLegRaw,
        'matched_business' => (float)$totalMatched,
        'power_carry_forward' => (float)$vPower,
        'rest_carry_forward' => (float)$vRest,
        'slab_breakdown' => $slabBreakdown
    ];
}

function localCheckRankQualification($config, $matchingBusiness) {
    $qualifiedRankId = null;
    foreach ($config['ranks'] as $id => $rank) {
        if ($matchingBusiness >= $rank['matching']) {
            $qualifiedRankId = $id + 1;
        } else {
            break;
        }
    }
    return $qualifiedRankId;
}

$message = '';
$messageType = '';
$summaryStats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_slabmatch') {
    $db->beginTransaction();
    try {
        // 1. Clear all matching schedules
        $db->exec("TRUNCATE TABLE matching_schedules");

        // 2. Clear all RANK_INCOME transactions
        $db->exec("DELETE FROM transactions WHERE type = 'RANK_INCOME'");

        // 3. Fetch all active users in chronological join order
        $stmtUsers = $db->query("SELECT id, username, rank_id, status FROM users ORDER BY id ASC");
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        $totalSchedulesCreated = 0;
        $usersRankUpdated = 0;

        foreach ($users as $user) {
            $userId = $user['id'];
            $legStats = localGetLegsBusiness($db, $userId);
            $matchedBusiness = $legStats['matched_business'];
            $slabBreakdown = $legStats['slab_breakdown'];

            $qualifiedRankId = localCheckRankQualification($config, $matchedBusiness);

            // Update user's rank_id ONLY if new qualified rank is strictly greater than existing rank_id
            if ($qualifiedRankId !== null && $qualifiedRankId > $user['rank_id']) {
                $stmtUpdateRank = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                $stmtUpdateRank->execute([$qualifiedRankId, $userId]);
                $user['rank_id'] = $qualifiedRankId;
                $usersRankUpdated++;
            }

            // Create matching contracts for qualified slab units (ascending)
            foreach ($slabBreakdown as $slab => $units) {
                if ($units > 0) {
                    $dailyIncome = 0.00;
                    foreach ($config['ranks'] as $rankConf) {
                        if ($rankConf['matching'] == $slab) {
                            $dailyIncome = $rankConf['daily_income'];
                            break;
                        }
                    }

                    $stmtInsertSched = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                    for ($i = 0; $i < $units; $i++) {
                        $stmtInsertSched->execute([$userId, $slab, $dailyIncome]);
                        $totalSchedulesCreated++;
                    }
                }
            }
        }

        // 5. Process daily payouts & propagate rank income up to max 2 upline sponsors
        $stmtActiveScheds = $db->query("SELECT * FROM matching_schedules WHERE status = 'active'");
        $activeScheds = $stmtActiveScheds->fetchAll(PDO::FETCH_ASSOC);

        $totalEarnerPayouts = 0;
        $totalPropagatedPayouts = 0;

        foreach ($activeScheds as $sched) {
            $dailyIncome = (float)$sched['daily_income'];
            $userId = $sched['user_id'];

            // Direct Earner Payout
            localLogTransaction(
                $db,
                $userId,
                'RANK_INCOME',
                $dailyIncome,
                0,
                "Daily Matching Income for Slab \$" . number_format($sched['slab_amount'], 2) . " (Day 1/100)"
            );
            $totalEarnerPayouts++;

            $stmtUpdateSched = $db->prepare("UPDATE matching_schedules SET days_passed = 1, status = ? WHERE id = ?");
            $newStatus = (1 >= $sched['max_days']) ? 'completed' : 'active';
            $stmtUpdateSched->execute([$newStatus, $sched['id']]);

            // Fetch earner username
            $stmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $origUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $origUsername = $origUser ? $origUser['username'] : "User #{$userId}";

            // Fetch max 2 levels of direct unilevel upline sponsors
            $stmtUplines = $db->prepare("
                SELECT g.parent_id, g.level, u.username, u.status
                FROM genealogy g
                JOIN users u ON g.parent_id = u.id
                WHERE g.user_id = ? AND g.level <= 2
                ORDER BY g.level ASC
            ");
            $stmtUplines->execute([$userId]);
            $uplines = $stmtUplines->fetchAll(PDO::FETCH_ASSOC);

            foreach ($uplines as $upline) {
                if ($upline['status'] === 'active') {
                    localLogTransaction(
                        $db,
                        $upline['parent_id'],
                        'RANK_INCOME',
                        $dailyIncome,
                        0,
                        "Daily Propagated Match Income from " . $origUsername . " (Slab \$" . number_format($sched['slab_amount'], 2) . ")",
                        $userId
                    );
                    $totalPropagatedPayouts++;
                }
            }
        }

        $db->commit();

        $messageType = 'success';
        $message = "Slab Match Commissions Reset and Recalculated Successfully!";
        $summaryStats = [
            'users_rank_updated' => $usersRankUpdated,
            'schedules_created' => $totalSchedulesCreated,
            'earner_payouts' => $totalEarnerPayouts,
            'propagated_payouts' => $totalPropagatedPayouts
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messageType = 'danger';
        $message = "Failed to reset slab match commissions: " . $e->getMessage();
    }
}

$pageTitle = 'Reset Slab Match';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fa fa-sync-alt me-2 text-primary"></i>Reset & Recalculate Slab Match Commissions</h2>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <strong><?php echo htmlspecialchars($message); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($summaryStats): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white card-stat p-3">
                    <div class="card-body">
                        <h5>Ranks Updated</h5>
                        <h3><?php echo number_format($summaryStats['users_rank_updated']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white card-stat p-3">
                    <div class="card-body">
                        <h5>Schedules Created</h5>
                        <h3><?php echo number_format($summaryStats['schedules_created']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white card-stat p-3">
                    <div class="card-body">
                        <h5>Direct Payouts</h5>
                        <h3><?php echo number_format($summaryStats['earner_payouts']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark card-stat p-3">
                    <div class="card-body">
                        <h5>Propagated Payouts</h5>
                        <h3><?php echo number_format($summaryStats['propagated_payouts']); ?></h3>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fa fa-info-circle me-2"></i>Reset Slab Match Specification</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>Leg Matching:</strong> Evaluates Power Leg vs. Weaker Leg ($\min(\text{Power}, \text{Rest})$).</li>
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>Ascending Slabs:</strong> Sequential slab breakdown starts from the smallest tier ascending ($\$500 \rightarrow \$1,000 \rightarrow \$2,500 \rightarrow \dots \rightarrow \$500,000$).</li>
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>Rank Updates:</strong> Updates `rank_id` field in `users` ONLY if newly qualified rank is strictly greater than existing rank.</li>
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>$0 Investment Sponsors:</strong> $0 investment active sponsors receive both direct slab matching and propagated rank income.</li>
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>Propagated Rank Income:</strong> Daily rank matching income propagates up to <strong>maximum 2 upline sponsors</strong> (Level 1 and Level 2).</li>
                <li class="list-group-item"><i class="fa fa-check text-success me-2"></i><strong>Self-Contained Engine:</strong> Runs completely independent of `engine.php`.</li>
            </ul>

            <form method="POST" onsubmit="return confirm('Are you sure you want to reset and recalculate all slab match contracts and rank commissions? This will clear existing RANK_INCOME transactions and matching contracts.');">
                <input type="hidden" name="action" value="reset_slabmatch">
                <button type="submit" class="btn btn-danger btn-lg"><i class="fa fa-exclamation-triangle me-2"></i>Execute Global Reset & Recalculate Slab Match</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
