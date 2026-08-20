<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

/**
 * Local helper functions to eliminate dependency on engine.php
 */

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

    $slabs = [500000, 250000, 100000, 50000, 25000, 10000, 5000, 2500, 1000, 500];
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

function localGetAllSponsorUplines($db, $userId) {
    $stmt = $db->prepare("
        SELECT g.parent_id, u.username, u.status, u.rank_id, g.level
        FROM genealogy g
        JOIN users u ON g.parent_id = u.id
        WHERE g.user_id = ? AND g.level <= 2
        ORDER BY g.level ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Reset Rank Income';
include __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';
$recalc_log = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action == 'reset_rank') {
            $recalc_log[] = "Initializing Recursive Top-to-Bottom Reset of Rank and Rank Income...";

            $db->beginTransaction();
            try {
                // Step 1: Backup existing matching schedules (days_passed and status) to restore progression
                $recalc_log[] = "Backing up existing matching schedules to preserve active days and progression...";
                $stmtBackup = $db->query("SELECT * FROM matching_schedules");
                $backupSchedules = $stmtBackup->fetchAll(PDO::FETCH_ASSOC);

                $backup = [];
                foreach ($backupSchedules as $sched) {
                    $uid = $sched['user_id'];
                    $slab = (int)$sched['slab_amount'];
                    $backup[$uid][$slab][] = [
                        'days_passed' => $sched['days_passed'],
                        'status' => $sched['status']
                    ];
                }
                $recalc_log[] = "Successfully backed up progression for " . count($backupSchedules) . " matching schedule(s).";

                // Step 2: Clear RANK_INCOME transactions (keeping Level and ROI intact)
                $recalc_log[] = "Clearing all RANK_INCOME transactions from ledger...";
                $db->exec("DELETE FROM transactions WHERE type = 'RANK_INCOME'");

                // Step 3: Clear matching schedules
                $recalc_log[] = "Clearing all matching schedules...";
                $db->exec("DELETE FROM matching_schedules");

                // Step 4: Reset user rank IDs to 0
                $recalc_log[] = "Resetting all user rank IDs to 0 (Unranked)...";
                $db->exec("UPDATE users SET rank_id = 0");

                // Step 5: Fetch all users in chronological order of joining (created_at ASC)
                $recalc_log[] = "Fetching all members in chronological order of joining...";
                $stmtUsers = $db->query("SELECT id, username, created_at, rank_id FROM users ORDER BY created_at ASC, id ASC");
                $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

                $recalc_log[] = "Processing " . count($users) . " member(s) sequentially from top-to-bottom...";

                // Process each user sequentially to organically rebuild unilevel leg business and ranks
                foreach ($users as $user) {
                    $uid = $user['id'];
                    $uname = $user['username'];

                    // Fetch unilevel leg business volumes using local function
                    $legStats = localGetLegsBusiness($db, $uid);
                    $matchedBusiness = $legStats['matched_business'];
                    $slabBreakdown = $legStats['slab_breakdown'] ?? [];

                    // Evaluate Rank Qualification based on matched business
                    $qualifiedRankId = null;
                    foreach ($config['ranks'] as $idx => $rank) {
                        if ($matchedBusiness >= $rank['matching']) {
                            $qualifiedRankId = $idx + 1;
                        } else {
                            break;
                        }
                    }

                    // Update user rank if qualified
                    if ($qualifiedRankId !== null) {
                        $rankName = $config['ranks'][$qualifiedRankId - 1]['name'];
                        $stmtUpdateRank = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                        $stmtUpdateRank->execute([$qualifiedRankId, $uid]);
                        $recalc_log[] = "Member @{$uname} qualified for Rank: {$rankName} with \${$matchedBusiness} matched volume.";
                    } else {
                        $recalc_log[] = "Member @{$uname} evaluated with \${$matchedBusiness} matched volume (No rank qualification).";
                    }

                    // Sync/Create matching schedules for the matched slabs sequentially
                    foreach ($slabBreakdown as $slab => $requiredUnits) {
                        if ($requiredUnits > 0) {
                            // Find daily income rate for this slab from config
                            $dailyIncome = 0.00;
                            foreach ($config['ranks'] as $rankConf) {
                                if ($rankConf['matching'] == $slab) {
                                    $dailyIncome = $rankConf['daily_income'];
                                    break;
                                }
                            }

                            $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                            for ($i = 0; $i < $requiredUnits; $i++) {
                                $stmtInsert->execute([$uid, $slab, $dailyIncome]);
                            }
                        }
                    }
                }

                // Step 6: Restore days_passed, status, and regenerate sequential transaction payout histories with proper upward propagation
                $recalc_log[] = "Restoring matching schedule progression and propagating daily payout histories upwards...";
                $stmtAllScheds = $db->query("
                    SELECT ms.*, u.username
                    FROM matching_schedules ms
                    JOIN users u ON ms.user_id = u.id
                    ORDER BY ms.id ASC
                ");
                $newSchedules = $stmtAllScheds->fetchAll(PDO::FETCH_ASSOC);

                $restoredCount = 0;
                $userSlabCounters = [];

                foreach ($newSchedules as $ns) {
                    $uid = $ns['user_id'];
                    $uname = $ns['username'];
                    $slab = (int)$ns['slab_amount'];

                    if (!isset($userSlabCounters[$uid][$slab])) {
                        $userSlabCounters[$uid][$slab] = 0;
                    }
                    $index = $userSlabCounters[$uid][$slab];

                    if (isset($backup[$uid][$slab][$index])) {
                        $bData = $backup[$uid][$slab][$index];
                        $stmtUpdate = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
                        $stmtUpdate->execute([$bData['days_passed'], $bData['status'], $ns['id']]);

                        // Regenerate the deleted RANK_INCOME daily ledger payouts and propagate them upwards
                        $daysPassed = (int)$bData['days_passed'];
                        $dailyIncome = (float)$ns['daily_income'];
                        if ($daysPassed > 0) {
                            // Find matching rank level corresponding to this slab for verification
                            $requiredRankId = 0;
                            foreach ($config['ranks'] as $idx => $rankConf) {
                                if ($rankConf['matching'] == $slab) {
                                    $requiredRankId = $idx + 1;
                                    break;
                                }
                            }

                            // Fetch qualified sponsor uplines using local function
                            $uplines = localGetAllSponsorUplines($db, $uid);

                            $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
                            $stmtTx = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at) VALUES (?, ?, NULL, 0, 'RANK_INCOME', ?, 0.00, ?, ?, NOW())");

                            for ($day = 1; $day <= $daysPassed; $day++) {
                                // 1. Pay the qualifying user
                                $desc = "Slab \$" . number_format($slab, 2) . " matching payout - Day " . $day . "/" . $ns['max_days'];
                                $stmtTx->execute([$uid, null, $dailyIncome, $dailyIncome, $desc]);

                                // 2. Propagate up to active sponsor uplines (Levels 1 and 2)
                                foreach ($uplines as $upline) {
                                    if ($upline['status'] === 'active') {
                                        $pDesc = "Daily Propagated Match Income from " . $uname . " (Slab \$" . number_format($slab, 2) . ")";
                                        $stmtTx->execute([$upline['parent_id'], $uid, $dailyIncome, $dailyIncome, $pDesc]);
                                    }
                                }
                            }
                        }
                        $restoredCount++;
                    }

                    $userSlabCounters[$uid][$slab]++;
                }

                $recalc_log[] = "Restored " . $restoredCount . " matching contract(s) progression and regenerated all historical daily rank payouts with recursive upward propagation successfully.";

                $db->commit();
                $success_msg = "Recursive Rank and Rank Income reset completed successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error during Reset & Recalculation: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card bg-dark text-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2><i class="fa fa-sync-alt me-2 text-warning"></i>Reset & Rebuild Rank Income</h2>
                </div>
                <p class="text-muted">
                    This utility recursively clears all active and past Rank Qualifications, clears all Matching Schedules (contracts), and clears all Daily Rank Income ledgers. It then sequentially rebuilds leg volumes, ranks, and contract schedules **chronologically in joining order** from top to bottom.
                </p>

                <?php if ($success_msg): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form method="post" class="mt-4" onsubmit="return confirm('WARNING: This will clear all Rank logs and rebuild them sequentially. Are you sure you want to proceed?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="reset_rank">
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4 py-2">
                        <i class="fa fa-exclamation-triangle me-2"></i>Reset & Recalculate Rank Income
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($recalc_log)): ?>
            <div class="col-lg-12">
                <div class="card p-3">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fa fa-terminal me-2"></i>Recalculation Output Log</h5>
                    </div>
                    <div class="card-body bg-dark text-light font-monospace p-3" style="max-height: 400px; overflow-y: auto; font-size: 13px;">
                        <?php foreach ($recalc_log as $log): ?>
                            <div>&gt; <?php echo htmlspecialchars($log); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
