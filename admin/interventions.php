<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();
$config = require __DIR__ . '/../includes/config.php';

$ranksList = $config['ranks'];

// Handle Auto-Fix / Cleanup of specific user's manual matching schedules/ranks
if (isset($_POST['action']) && $_POST['action'] === 'fix_user') {
    // Validate CSRF
    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['admin_csrf']) {
        $targetUserId = (int)$_POST['user_id'];

        $db->beginTransaction();
        try {
            // Get organic leg business and matched business
            $legStats = $engine->getLegsBusiness($targetUserId);
            $matchedBusiness = $legStats['matched_business'];
            $slabBreakdown = $legStats['slab_breakdown'] ?? [];

            // Calculate organic rank
            $organicRankId = 0;
            foreach ($config['ranks'] as $id => $rank) {
                if ($matchedBusiness >= $rank['matching']) {
                    $organicRankId = $id + 1;
                } else {
                    break;
                }
            }

            // 1. Correct the user rank
            $stmt = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
            $stmt->execute([$organicRankId, $targetUserId]);

            // 2. Adjust matching schedules (contracts) for each slab
            foreach ($slabBreakdown as $slab => $expectedUnits) {
                // Get actual active/completed matching schedules count
                $stmtSched = $db->prepare("SELECT id FROM matching_schedules WHERE user_id = ? AND slab_amount = ? ORDER BY id ASC");
                $stmtSched->execute([$targetUserId, $slab]);
                $actualScheds = $stmtSched->fetchAll(PDO::FETCH_COLUMN);
                $actualUnits = count($actualScheds);

                if ($actualUnits > $expectedUnits) {
                    // Excess contracts exist (manual intervention). Delete the excess ones starting from newest.
                    $excessCount = $actualUnits - $expectedUnits;
                    $schedsToDelete = array_slice(array_reverse($actualScheds), 0, $excessCount);
                    if (!empty($schedsToDelete)) {
                        $placeholders = implode(',', array_fill(0, count($schedsToDelete), '?'));
                        $stmtDelete = $db->prepare("DELETE FROM matching_schedules WHERE id IN ($placeholders)");
                        $stmtDelete->execute($schedsToDelete);
                    }
                }
            }

            $db->commit();
            $successMsg = "Successfully cleaned up manual interventions for user ID " . $targetUserId;
        } catch (Exception $e) {
            $db->rollBack();
            $errorMsg = "Error during cleanup: " . $e->getMessage();
        }
    }
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

// Fetch all users with any active/completed matching schedules or non-zero rank
$stmtUsers = $db->query("
    SELECT DISTINCT u.id, u.username, u.mid, u.rank_id, u.total_investment, u.status
    FROM users u
    LEFT JOIN matching_schedules m ON u.id = m.user_id
    WHERE u.rank_id > 0 OR m.id IS NOT NULL
    ORDER BY u.id ASC
");
$usersToAudit = $stmtUsers->fetchAll();

$interventions = [];
$totalInterventionsCount = 0;

foreach ($usersToAudit as $user) {
    $userId = $user['id'];

    // Get organic stats
    $legStats = $engine->getLegsBusiness($userId);
    $matchedBusiness = $legStats['matched_business'];
    $slabBreakdown = $legStats['slab_breakdown'] ?? [];

    // Calculate organic rank
    $organicRankId = 0;
    foreach ($config['ranks'] as $id => $rank) {
        if ($matchedBusiness >= $rank['matching']) {
            $organicRankId = $id + 1;
        } else {
            break;
        }
    }

    $actualRankId = (int)$user['rank_id'];
    $isRankOverriden = ($actualRankId > $organicRankId);

    // Get actual contracts in DB per slab
    $contractsStatus = [];
    $isContractOverriden = false;

    foreach ($slabBreakdown as $slab => $expectedUnits) {
        $stmtSched = $db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
        $stmtSched->execute([$userId, $slab]);
        $actualUnits = (int)$stmtSched->fetchColumn();

        if ($actualUnits > $expectedUnits) {
            $isContractOverriden = true;
        }

        if ($actualUnits > 0 || $expectedUnits > 0) {
            $contractsStatus[$slab] = [
                'actual' => $actualUnits,
                'expected' => $expectedUnits,
                'inflated' => ($actualUnits > $expectedUnits)
            ];
        }
    }

    if ($isRankOverriden || $isContractOverriden) {
        $totalInterventionsCount++;
        $interventions[] = [
            'user' => $user,
            'actual_rank' => $actualRankId > 0 ? $ranksList[$actualRankId - 1]['name'] : 'None',
            'expected_rank' => $organicRankId > 0 ? $ranksList[$organicRankId - 1]['name'] : 'None',
            'is_rank_inflated' => $isRankOverriden,
            'is_contracts_inflated' => $isContractOverriden,
            'contracts' => $contractsStatus,
            'power_leg' => $legStats['power_leg'],
            'matching_leg' => $legStats['matching_leg'],
            'matched_business' => $matchedBusiness
        ];
    }
}

$pageTitle = 'Audit & Manual Interventions';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body bg-dark text-white rounded p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1"><i class="fa fa-shield-halved text-warning me-2"></i> Audit & Manual Interventions</h2>
                            <p class="mb-0 text-muted">This scanner performs a real-time unilevel leg recalculation and compares active database contracts with organic performance to instantly pinpoint manual overrides.</p>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-danger p-3" style="font-size: 16px;">
                                <i class="fa fa-triangle-exclamation me-1"></i> <?php echo $totalInterventionsCount; ?> Intervention(s) Detected
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-circle-check me-2"></i> <?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm border-0 p-3">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-list text-primary me-2"></i> Detected Overrides & Discrepancies</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($interventions)): ?>
                        <div class="text-center py-5">
                            <i class="fa fa-shield-heart text-success mb-3" style="font-size: 64px;"></i>
                            <h4 class="text-success">Perfect Sync!</h4>
                            <p class="text-muted">No manual overrides or database inconsistencies detected. All user ranks and contracts are 100% organic and aligned with their team volumes.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>User Details</th>
                                        <th>Real-Time Leg Volume</th>
                                        <th>Rank Status</th>
                                        <th>Slab Contracts (Actual / Expected)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($interventions as $item):
                                        $u = $item['user'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                                                <small class="text-muted">MID: <?php echo htmlspecialchars($u['mid'] ?? 'None'); ?></small><br>
                                                <small class="text-muted">Pkg: $<?php echo number_format($u['total_investment'], 2); ?></small>
                                            </td>
                                            <td>
                                                <span class="text-primary font-weight-bold">Power Leg:</span> $<?php echo number_format($item['power_leg'], 2); ?><br>
                                                <span class="text-success font-weight-bold">Matching Leg:</span> $<?php echo number_format($item['matching_leg'], 2); ?><br>
                                                <span class="text-dark font-weight-bold">Matched:</span> $<?php echo number_format($item['matched_business'], 2); ?>
                                            </td>
                                            <td>
                                                <?php if ($item['is_rank_inflated']): ?>
                                                    <span class="text-danger font-weight-bold"><i class="fa fa-arrow-trend-up me-1"></i> Inflated Rank</span><br>
                                                    <span class="badge bg-danger">DB: <?php echo $item['actual_rank']; ?></span><br>
                                                    <span class="badge bg-secondary mt-1">Organic: <?php echo $item['expected_rank']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Organic: <?php echo $item['actual_rank']; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php foreach ($item['contracts'] as $slab => $stat): ?>
                                                    <div class="mb-1">
                                                        <span class="badge bg-dark">$<?php echo number_format($slab); ?>:</span>
                                                        <?php if ($stat['inflated']): ?>
                                                            <span class="badge bg-danger"><?php echo $stat['actual']; ?> / <?php echo $stat['expected']; ?> (Manual)</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success"><?php echo $stat['actual']; ?> / <?php echo $stat['expected']; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <form method="post" onsubmit="return confirm('Are you sure you want to clean up manual entries and restore this user to their organic rank & contract state?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <input type="hidden" name="action" value="fix_user">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fa fa-wrench me-1"></i> Auto-Fix
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>