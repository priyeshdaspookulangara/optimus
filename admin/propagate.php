<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

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

$pageTitle = 'Rank Propagation';
include __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';
$log = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action == 'propagate_ranks') {
            $log[] = "Initializing Rank Propagation and Rank ID Updates for Parents...";

            $db->beginTransaction();
            try {
                // Fetch all users to update their rank and propagate up to 2 upline parent levels
                $stmtUsers = $db->query("SELECT id, username, rank_id FROM users ORDER BY id ASC");
                $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

                $updatedRanksCount = 0;

                foreach ($users as $u) {
                    $userId = $u['id'];

                    // Get sponsor uplines up to level 2 (and include user themselves)
                    $stmtUplines = $db->prepare("
                        SELECT g.parent_id, g.level, u.username, u.rank_id
                        FROM genealogy g
                        JOIN users u ON g.parent_id = u.id
                        WHERE g.user_id = ? AND g.level <= 2
                        ORDER BY g.level ASC
                    ");
                    $stmtUplines->execute([$userId]);
                    $ancestors = $stmtUplines->fetchAll(PDO::FETCH_ASSOC);

                    $targets = array_merge([['parent_id' => $userId, 'level' => 0]], $ancestors);

                    foreach ($targets as $t) {
                        $targetId = $t['parent_id'];
                        if (empty($targetId)) continue;

                        $stmtUserObj = $db->prepare("SELECT id, username, rank_id FROM users WHERE id = ?");
                        $stmtUserObj->execute([$targetId]);
                        $targetUser = $stmtUserObj->fetch(PDO::FETCH_ASSOC);
                        if (!$targetUser) continue;

                        $legStats = localGetLegsBusiness($db, $targetId);
                        $matchedBusiness = $legStats['matched_business'];
                        $slabBreakdown = $legStats['slab_breakdown'] ?? [];

                        $qualifiedRankId = localCheckRankQualification($config, $matchedBusiness);

                        if ($qualifiedRankId !== null && $qualifiedRankId > $targetUser['rank_id']) {
                            $rankName = $config['ranks'][$qualifiedRankId - 1]['name'];
                            $stmtUpd = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                            $stmtUpd->execute([$qualifiedRankId, $targetId]);

                            $updatedRanksCount++;
                            $log[] = "Updated Rank ID of @{$targetUser['username']} (ID: {$targetId}) to Rank: {$rankName} (Rank ID {$qualifiedRankId}) based on \${$matchedBusiness} matched volume.";
                        }

                        // Sync/Create matching schedules for newly qualified slab units
                        foreach ($slabBreakdown as $slab => $requiredUnits) {
                            if ($requiredUnits > 0) {
                                $stmtSched = $db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
                                $stmtSched->execute([$targetId, $slab]);
                                $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);
                                $existingUnits = (int)$existing['count'];

                                if ($requiredUnits > $existingUnits) {
                                    $dailyIncome = 0.00;
                                    foreach ($config['ranks'] as $rankConf) {
                                        if ($rankConf['matching'] == $slab) {
                                            $dailyIncome = $rankConf['daily_income'];
                                            break;
                                        }
                                    }

                                    $newUnits = $requiredUnits - $existingUnits;
                                    $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                                    for ($i = 0; $i < $newUnits; $i++) {
                                        $stmtInsert->execute([$targetId, $slab, $dailyIncome]);
                                    }
                                    $log[] = "Created {$newUnits} new Matching Contract Schedule(s) for @{$targetUser['username']} on Slab \${$slab}.";
                                }
                            }
                        }
                    }
                }

                $db->commit();
                $success_msg = "Rank propagation completed successfully! Ranks updated for {$updatedRanksCount} user(s)/parent(s).";
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error during rank propagation: " . $e->getMessage();
            }
        }
    }
}

// Fetch member ranks list
$stmtMembers = $db->query("
    SELECT u.id, u.username, u.rank_id, u.total_investment, u.created_at
    FROM users u
    ORDER BY u.rank_id DESC, u.id ASC
");
$members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card bg-dark text-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2><i class="fa fa-sitemap me-2 text-warning"></i>Rank Propagation Engine</h2>
                </div>
                <p class="text-muted">
                    This utility evaluates unilevel leg volumes and updates the **Rank ID** of members and their **upline parents (up to 2 levels high)** independently without relying on <code>engine.php</code>.
                </p>

                <?php if ($success_msg): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="propagate_ranks">
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4 py-2">
                        <i class="fa fa-play me-2"></i>Propagate & Update Parent Ranks
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($log)): ?>
            <div class="col-lg-12 mb-4">
                <div class="card p-3">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fa fa-terminal me-2"></i>Propagation Execution Log</h5>
                    </div>
                    <div class="card-body bg-dark text-light font-monospace p-3" style="max-height: 300px; overflow-y: auto; font-size: 13px;">
                        <?php foreach ($log as $l): ?>
                            <div>&gt; <?php echo htmlspecialchars($l); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-12">
            <div class="card p-4">
                <h4 class="mb-3">Current Member Ranks</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#ID</th>
                                <th>Username</th>
                                <th>Rank Name</th>
                                <th>Rank ID</th>
                                <th>Total Investment</th>
                                <th>Joined Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                                <?php $rName = ($m['rank_id'] > 0) ? $config['ranks'][$m['rank_id'] - 1]['name'] : 'Unranked'; ?>
                                <tr>
                                    <td>#<?php echo $m['id']; ?></td>
                                    <td><strong>@<?php echo htmlspecialchars($m['username']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo $m['rank_id'] > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo htmlspecialchars($rName); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $m['rank_id']; ?></td>
                                    <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($m['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
