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

// --------------------------------------------------------
// Report 1: Unilevel Level Commission Breakdown (Levels 1 to 12)
// --------------------------------------------------------
$totalLevelIncome = (float)$db->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE type = 'LEVEL_INCOME'
")->fetchColumn();

$levelCommissionsRaw = $db->query("
    SELECT
        level,
        COUNT(id) as count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM transactions
    WHERE type = 'LEVEL_INCOME' AND level IS NOT NULL AND level <= 12
    GROUP BY level
    ORDER BY level ASC
")->fetchAll();

$levelBreakdown = [];
for ($l = 1; $l <= 12; $l++) {
    $levelBreakdown[$l] = [
        'count' => 0,
        'total' => 0.00,
        'percentage' => 0.0
    ];
}
foreach ($levelCommissionsRaw as $row) {
    $lvl = (int)$row['level'];
    $total = (float)$row['total_amount'];
    $percentage = $totalLevelIncome > 0 ? round(($total / $totalLevelIncome) * 100, 1) : 0;
    if (isset($levelBreakdown[$lvl])) {
        $levelBreakdown[$lvl] = [
            'count' => (int)$row['count'],
            'total' => $total,
            'percentage' => $percentage
        ];
    }
}

// --------------------------------------------------------
// Report 2: Sequential Slab & Rank Achievement Report
// --------------------------------------------------------
$rankAchievements = [];
foreach ($ranksList as $idx => $r) {
    $rankId = $idx + 1;
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM users WHERE rank_id = ? AND status = 'active'");
    $stmtCount->execute([$rankId]);
    $count = (int)$stmtCount->fetchColumn();

    $rankAchievements[] = [
        'name' => $r['name'],
        'slab' => $r['matching'],
        'count' => $count
    ];
}

// Active Contract Schedules
$activeSchedules = $db->query("
    SELECT m.*, u.username, u.mid
    FROM matching_schedules m
    JOIN users u ON m.user_id = u.id
    WHERE m.status = 'active'
    ORDER BY m.slab_amount DESC, m.created_at DESC
")->fetchAll();

// --------------------------------------------------------
// Report 3: Power Leg vs. Matching Leg Volume Report
// --------------------------------------------------------
$leadersList = $db->query("
    SELECT DISTINCT u.id, u.username, u.mid, u.total_investment
    FROM users u
    WHERE u.id IN (SELECT DISTINCT user_id FROM matching_schedules) OR u.rank_id > 0
    ORDER BY u.id ASC
    LIMIT 20
")->fetchAll();

$legVolumeReports = [];
foreach ($leadersList as $ldr) {
    $stats = $engine->getLegsBusiness($ldr['id']);
    $legVolumeReports[] = [
        'username' => $ldr['username'],
        'mid' => $ldr['mid'],
        'power_leg' => $stats['power_leg'],
        'matching_leg' => $stats['matching_leg'],
        'matched_business' => $stats['matched_business'],
        'power_carry' => $stats['power_carry_forward'],
        'rest_carry' => $stats['rest_carry_forward']
    ];
}

// --------------------------------------------------------
// Report 4: Abuse & Anti-Stacking Audit Log
// --------------------------------------------------------
// We will recursively check the tree for single-referral nodes chains
$genealogyNodes = $db->query("
    SELECT u.id, u.username, u.mid, u.sponsor_id
    FROM users u
    WHERE u.status = 'active' AND u.id > 1
")->fetchAll();

$abusiveChains = [];
$processedChains = [];

foreach ($genealogyNodes as $node) {
    $userId = $node['id'];
    if (in_array($userId, $processedChains)) continue;

    // Traverse upwards from each node to trace chains of single referrals
    $currentId = $userId;
    $currentChain = [];
    $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");

    while ($currentId > 1) {
        $stmtParent = $db->prepare("SELECT sponsor_id, username, mid FROM users WHERE id = ?");
        $stmtParent->execute([$currentId]);
        $parent = $stmtParent->fetch();
        if (!$parent) break;

        $parentId = $parent['sponsor_id'];
        if (!$parentId) break;

        // Check parent direct referrals count
        $stmtReferrals->execute([$parentId]);
        $refCount = (int)$stmtReferrals->fetchColumn();

        if ($refCount === 1) {
            $currentChain[] = [
                'id' => $parentId,
                'username' => $parent['username'],
                'mid' => $parent['mid']
            ];
            $processedChains[] = $parentId;
            $currentId = $parentId;
        } else {
            break;
        }
    }

    if (count($currentChain) >= 3) {
        $abusiveChains[] = [
            'trigger_user' => $node['username'],
            'trigger_mid' => $node['mid'],
            'chain_length' => count($currentChain),
            'nodes' => array_reverse($currentChain)
        ];
    }
}

$pageTitle = 'Network & MLM Genealogy Reports';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid pb-5">
    <!-- Header Block -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body bg-dark text-white rounded p-4">
                    <h2 class="mb-1"><i class="fa fa-network-wired text-warning me-2"></i> Network & MLM Genealogy Reports</h2>
                    <p class="mb-0 text-muted">Audits unilevel levels, slab-matching achievements, structural balance of leg volumes, and anti-stacking abuse vectors.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 1: Unilevel Level Commission & Ranks -->
    <div class="row">
        <!-- 1. Unilevel Level Commission Breakdown -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-chart-bar text-primary me-2"></i> Unilevel Commission Breakdown (Levels 1 to 12)</h5>
                    <span class="badge bg-primary px-3 py-2">Total Paid: $<?php echo number_format($totalLevelIncome, 2); ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th>Transactions</th>
                                    <th>Total Commission Paid</th>
                                    <th>Share (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($l = 1; $l <= 12; $l++):
                                    $lvlData = $levelBreakdown[$l];
                                ?>
                                    <tr>
                                        <td><strong>Level <?php echo $l; ?></strong></td>
                                        <td><?php echo $lvlData['count']; ?> payouts</td>
                                        <td class="font-weight-bold text-dark">$<?php echo number_format($lvlData['total'], 2); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress me-2 flex-grow-1" style="height: 6px; max-width: 80px;">
                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $lvlData['percentage']; ?>%"></div>
                                                </div>
                                                <span style="font-size: 11px; font-weight: bold;"><?php echo $lvlData['percentage']; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Sequential Slab & Rank Achievement -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-trophy text-warning me-2"></i> Sequential Slab & Rank Achievement</h5>
                </div>
                <div class="card-body">
                    <h6 class="text-muted font-weight-bold mb-3">Rank Accomplishments Summary</h6>
                    <div class="row text-center mb-4">
                        <?php foreach (array_slice($rankAchievements, 0, 4) as $ra): ?>
                            <div class="col-3 border-end">
                                <h6 class="text-muted text-uppercase mb-1" style="font-size: 10px;"><?php echo htmlspecialchars($ra['name']); ?></h6>
                                <h3 class="text-dark font-weight-bold mb-0"><?php echo $ra['count']; ?></h3>
                                <small class="text-muted" style="font-size: 10px;">$<?php echo number_format($ra['slab']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h6 class="text-muted font-weight-bold mb-2">Active Slab Matching Contracts (Remaining Days/100)</h6>
                    <?php if (empty($activeSchedules)): ?>
                        <p class="text-center py-4 text-muted" style="font-size: 13px;">No active daily rank-matching contracts are currently registered.</p>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>User</th>
                                        <th>Slab Tier</th>
                                        <th>Daily Income</th>
                                        <th>Days Left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeSchedules as $sched): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sched['username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($sched['mid']); ?></small>
                                            </td>
                                            <td><span class="badge bg-info">$<?php echo number_format($sched['slab_amount']); ?></span></td>
                                            <td class="text-success fw-bold">$<?php echo number_format($sched['daily_income'], 2); ?></td>
                                            <td class="font-weight-bold text-dark"><?php echo max(0, 100 - $sched['days_passed']); ?> days left</td>
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

    <!-- Row 2: Leg Volume & Abuse/Anti-Stacking Logs -->
    <div class="row">
        <!-- 3. Power Leg vs. Matching Leg Volume Report -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-scale-unbalanced text-info me-2"></i> Power Leg vs. Matching Leg Volume Report</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($legVolumeReports)): ?>
                        <p class="text-center py-5 text-muted">No leader accounts found with active/completed matching volume currently.</p>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>Leader User</th>
                                        <th>Power Leg (Carry)</th>
                                        <th>Matching Leg (Carry)</th>
                                        <th>Matched</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($legVolumeReports as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['mid']); ?></small>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format($item['power_leg'], 2); ?></strong><br>
                                                <small class="text-muted">Carry: $<?php echo number_format($item['power_carry'], 2); ?></small>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format($item['matching_leg'], 2); ?></strong><br>
                                                <small class="text-muted">Carry: $<?php echo number_format($item['rest_carry'], 2); ?></small>
                                            </td>
                                            <td class="text-success font-weight-bold">
                                                $<?php echo number_format($item['matched_business'], 2); ?>
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

        <!-- 4. Abuse & Anti-Stacking Audit Log -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-shield-circle-exclamation text-danger me-2"></i> Abuse & Anti-Stacking Audit Log</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($abusiveChains)): ?>
                        <div class="text-center py-5 text-success">
                            <i class="fa fa-circle-check mb-3" style="font-size: 54px;"></i>
                            <h6 class="font-weight-bold">Zero Abuse Detected</h6>
                            <p class="text-muted" style="font-size: 13px;">No suspicious single-referral stacking chains exceeding the 3-consecutive node limit were found in the active structure.</p>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 12px; margin-top: -10px;">The Single-Referral Filter detects chains where accounts are stacked in a straight vertical line. The following chains exceed compliance limits:</p>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>Trigger Account</th>
                                        <th>Chain Length</th>
                                        <th>Stacked Chain Trace</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($abusiveChains as $chain): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($chain['trigger_user']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($chain['trigger_mid']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo $chain['chain_length']; ?> levels</span>
                                            </td>
                                            <td>
                                                <div class="text-muted" style="font-size: 11px;">
                                                    <?php foreach ($chain['nodes'] as $node): ?>
                                                        <i class="fa fa-arrow-down-long mx-1 text-danger"></i>
                                                        <strong><?php echo htmlspecialchars($node['username']); ?></strong>
                                                        (<?php echo htmlspecialchars($node['mid']); ?>)
                                                    <?php endforeach; ?>
                                                </div>
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