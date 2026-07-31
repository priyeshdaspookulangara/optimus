<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

// --------------------------------------------------------
// Report 1: Active Package Distribution Report
// --------------------------------------------------------
$totalActiveCapital = (float)$db->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM investments
    WHERE status = 'active'
")->fetchColumn();

$packageDistRaw = $db->query("
    SELECT
        p.amount as tier_amount,
        p.name as package_name,
        COUNT(i.id) as count,
        COALESCE(SUM(i.amount), 0) as total_valuation
    FROM packages p
    LEFT JOIN investments i ON p.id = i.package_id AND i.status = 'active'
    GROUP BY p.id
    ORDER BY p.amount ASC
")->fetchAll();

$packageDistribution = [];
foreach ($packageDistRaw as $row) {
    $valuation = (float)$row['total_valuation'];
    $percentage = $totalActiveCapital > 0 ? round(($valuation / $totalActiveCapital) * 100, 1) : 0;
    $packageDistribution[] = [
        'name' => $row['package_name'],
        'tier_amount' => (float)$row['tier_amount'],
        'count' => (int)$row['count'],
        'valuation' => $valuation,
        'percentage' => $percentage
    ];
}

// --------------------------------------------------------
// Report 2: Daily ROI Payout Log (Disbursements Ledger)
// --------------------------------------------------------
$dailyRoiLog = $db->query("
    SELECT
        DATE(created_at) as payout_date,
        COUNT(id) as transaction_count,
        SUM(amount) as total_disbursed
    FROM transactions
    WHERE type = 'ROI'
    GROUP BY DATE(created_at)
    ORDER BY payout_date DESC
    LIMIT 30
")->fetchAll();

// --------------------------------------------------------
// Report 3: Matured & Expired Contracts Report
// --------------------------------------------------------
$maturedContracts = $db->query("
    SELECT
        i.id as investment_id,
        i.amount,
        i.roi_earned,
        i.days_passed,
        i.created_at,
        i.last_roi_at,
        i.status,
        u.username,
        u.mid
    FROM investments i
    JOIN users u ON i.user_id = u.id
    WHERE i.status IN ('completed', 'capped') OR i.days_passed >= 400 OR i.roi_earned >= i.amount * 2.0
    ORDER BY i.last_roi_at DESC
")->fetchAll();

$pageTitle = 'Investment & ROI Performance';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid pb-5">
    <!-- Header Block -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body bg-dark text-white rounded p-4">
                    <h2 class="mb-1"><i class="fa fa-chart-pie text-info me-2"></i> Investment & ROI Performance Reports</h2>
                    <p class="mb-0 text-muted">A comprehensive look at passive capital distribution, daily interest disbursements, and matured contracts requiring active upgrades.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Package Distribution Report -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-layer-group text-primary me-2"></i> Active Package Distribution Report</h5>
                    <span class="badge bg-primary px-3 py-2" style="font-size: 13px;">Total Active Passive Capital: $<?php echo number_format($totalActiveCapital, 2); ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Package Tier</th>
                                    <th>Active Accounts</th>
                                    <th>Total Tier Valuation</th>
                                    <th>Capital Concentration (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($packageDistribution as $dist): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($dist['name']); ?></strong><br>
                                            <small class="text-muted">Unit Cost: $<?php echo number_format($dist['tier_amount'], 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary" style="font-size: 12px;"><?php echo $dist['count']; ?> active</span>
                                        </td>
                                        <td class="font-weight-bold text-dark">
                                            $<?php echo number_format($dist['valuation'], 2); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress me-2 flex-grow-1" style="height: 8px; max-width: 150px;">
                                                    <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $dist['percentage']; ?>%"></div>
                                                </div>
                                                <span class="font-weight-bold" style="font-size: 13px;"><?php echo $dist['percentage']; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Daily Payout Log & Matured Contracts -->
    <div class="row">
        <!-- Daily ROI Payout Log -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-clock-rotate-left text-success me-2"></i> Daily ROI Payout Log (0.50% Ledger)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($dailyRoiLog)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa fa-receipt mb-3" style="font-size: 48px;"></i>
                            <p>No daily interest disbursements have been triggered yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Disbursement Date</th>
                                        <th>Accounts Paid</th>
                                        <th>Total Volume Distributed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailyRoiLog as $log): ?>
                                        <tr>
                                            <td><?php echo date('d M, Y', strtotime($log['payout_date'])); ?></td>
                                            <td><?php echo $log['transaction_count']; ?> accounts</td>
                                            <td class="text-success font-weight-bold">+$<?php echo number_format($log['total_disbursed'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Matured & Expired Contracts -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-circle-check text-danger me-2"></i> Matured & Expired Contracts Report</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($maturedContracts)): ?>
                        <div class="text-center py-5 text-success">
                            <i class="fa fa-shield-circle-check mb-3" style="font-size: 54px;"></i>
                            <h6 class="font-weight-bold">All Contracts Active</h6>
                            <p class="text-muted" style="font-size: 13px;">No packages have completed their 200% passive earnings caps or exceeded their maximum lifecycles yet.</p>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 12px; margin-top: -10px;">The following accounts have successfully hit their caps and must purchase upgrades to resume receiving team passive benefits:</p>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Earned (Days)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maturedContracts as $mc): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($mc['username']); ?></strong><br>
                                                <small class="text-muted">MID: <?php echo htmlspecialchars($mc['mid']); ?></small>
                                            </td>
                                            <td class="font-weight-bold">$<?php echo number_format($mc['amount'], 2); ?></td>
                                            <td class="text-success">
                                                $<?php echo number_format($mc['roi_earned'], 2); ?><br>
                                                <small class="text-muted"><?php echo $mc['days_passed']; ?>/400 days</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger text-uppercase" style="font-size: 10px;"><?php echo htmlspecialchars($mc['status']); ?></span>
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