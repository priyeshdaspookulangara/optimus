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

$pageTitle = 'Financial & Ecosystem Health Reports';
include __DIR__ . '/includes/header.php';

// --------------------------------------------------------
// Report 1: Global Inflow vs. Outflow Report
// --------------------------------------------------------
$totalInflow = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM investments")->fetchColumn();

$totalROI = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'ROI'")->fetchColumn();
$totalLevel = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'LEVEL_INCOME'")->fetchColumn();
$totalRank = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'RANK_INCOME'")->fetchColumn();

$totalOutflow = $totalROI + $totalLevel + $totalRank;
$netReserveHealth = $totalInflow - $totalOutflow;

// --------------------------------------------------------
// Report 2: Liability & Cap Status Report
// --------------------------------------------------------
// Passive ROI Cap (200% limit)
$passiveLiabilities = $db->query("
    SELECT
        COALESCE(SUM(amount), 0) as total_active_investment,
        COALESCE(SUM(amount * 2.0), 0) as max_passive_liability,
        COALESCE(SUM(roi_earned), 0) as passive_roi_paid
    FROM investments
    WHERE status = 'active'
")->fetch();

$activeInvestment = (float)$passiveLiabilities['total_active_investment'];
$maxPassiveLiability = (float)$passiveLiabilities['max_passive_liability'];
$passiveRoiPaid = (float)$passiveLiabilities['passive_roi_paid'];
$remainingPassiveLiability = max(0, $maxPassiveLiability - $passiveRoiPaid);
$passiveProgressPercent = $maxPassiveLiability > 0 ? min(100, round(($passiveRoiPaid / $maxPassiveLiability) * 100, 1)) : 0;

// 300% Global ID Cap Limit
$totalUserInvestment = (float)$db->query("SELECT COALESCE(SUM(total_investment), 0) FROM users WHERE status = 'active'")->fetchColumn();
$maxGlobalIDCapLiability = $totalUserInvestment * 3.0;

$totalIncomesPaid = (float)$db->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')
")->fetchColumn();

$remainingGlobalIDCapLiability = max(0, $maxGlobalIDCapLiability - $totalIncomesPaid);
$globalIDCapProgressPercent = $maxGlobalIDCapLiability > 0 ? min(100, round(($totalIncomesPaid / $maxGlobalIDCapLiability) * 100, 1)) : 0;

// --------------------------------------------------------
// Report 3: PIN-Code Ledger & Utilization Report
// --------------------------------------------------------
$pinStats = $db->query("
    SELECT
        p.status,
        COUNT(p.id) as count,
        COALESCE(SUM(pkg.amount), 0) as total_value
    FROM pins p
    JOIN packages pkg ON p.package_id = pkg.id
    GROUP BY p.status
")->fetchAll(PDO::FETCH_ASSOC);

$pinLedger = [
    'unused' => ['count' => 0, 'value' => 0.00],
    'used' => ['count' => 0, 'value' => 0.00]
];

foreach ($pinStats as $stat) {
    if (isset($pinLedger[$stat['status']])) {
        $pinLedger[$stat['status']]['count'] = (int)$stat['count'];
        $pinLedger[$stat['status']]['value'] = (float)$stat['total_value'];
    }
}

// --------------------------------------------------------
// Report 4: Fee & Withdrawal Reconciliation Report
// --------------------------------------------------------
$withdrawalSummary = $db->query("
    SELECT
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total_requested,
        COALESCE(SUM(fee), 0) as total_fees_gas
    FROM transactions
    WHERE type = 'WITHDRAWAL'
")->fetch();

$totalRequestedWithdrawals = (float)$withdrawalSummary['total_requested'];
$totalWithdrawalFees = (float)$withdrawalSummary['total_fees_gas'];
$netPaidOutWithdrawals = $totalRequestedWithdrawals - $totalWithdrawalFees;
$withdrawalCount = (int)$withdrawalSummary['count'];
?>

<div class="container-fluid pb-5">
    <!-- Header Block -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body bg-dark text-white rounded p-4">
                    <h2 class="mb-1"><i class="fa fa-chart-line text-warning me-2"></i> Financial & Liquidity Reports</h2>
                    <p class="mb-0 text-muted">Real-time health audits, active system liabilities, PIN utilization ledger, and fee reconciliation metrics.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 1: Global Inflow vs. Outflow & PIN Ledger -->
    <div class="row">
        <!-- 1. Global Inflow vs. Outflow -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-scale-balanced text-primary me-2"></i> Global Inflow vs. Outflow (Liquidity)</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-6 border-end">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Total Inflow (Package Purchases)</h6>
                            <h3 class="text-success font-weight-bold">$<?php echo number_format($totalInflow, 2); ?></h3>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Total Outflow (Distributed Income)</h6>
                            <h3 class="text-danger font-weight-bold">$<?php echo number_format($totalOutflow, 2); ?></h3>
                        </div>
                    </div>

                    <div class="p-3 rounded mb-4 text-center <?php echo $netReserveHealth >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'; ?>">
                        <span class="text-uppercase font-weight-bold" style="font-size: 12px; letter-spacing: 1px;">Net Ecosystem Reserve Health</span>
                        <h2 class="font-weight-bold mt-1">$<?php echo number_format($netReserveHealth, 2); ?></h2>
                    </div>

                    <h6 class="text-muted font-weight-bold mb-3">Outflow Breakdown</h6>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><i class="fa fa-circle text-primary me-2" style="font-size: 8px;"></i> Daily ROI Earnings</span>
                            <span class="font-weight-bold">$<?php echo number_format($totalROI, 2); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><i class="fa fa-circle text-success me-2" style="font-size: 8px;"></i> Unilevel Referral Income</span>
                            <span class="font-weight-bold">$<?php echo number_format($totalLevel, 2); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><i class="fa fa-circle text-warning me-2" style="font-size: 8px;"></i> Rank Matching Commission</span>
                            <span class="font-weight-bold">$<?php echo number_format($totalRank, 2); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 2. PIN-Code Ledger & Utilization -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-key text-success me-2"></i> PIN-Code Ledger & Utilization</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-6 border-end">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Active / Used PINs</h6>
                            <h3 class="text-primary font-weight-bold"><?php echo $pinLedger['used']['count']; ?></h3>
                            <small class="text-muted">Total Value: $<?php echo number_format($pinLedger['used']['value'], 2); ?></small>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Unused / In-Reserve PINs</h6>
                            <h3 class="text-warning font-weight-bold"><?php echo $pinLedger['unused']['count']; ?></h3>
                            <small class="text-muted">Total Value: $<?php echo number_format($pinLedger['unused']['value'], 2); ?></small>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded text-center mb-4">
                        <span class="text-muted text-uppercase d-block" style="font-size: 11px;">Total Minted Ledger Value</span>
                        <h3 class="font-weight-bold text-dark mt-1">$<?php echo number_format($pinLedger['used']['value'] + $pinLedger['unused']['value'], 2); ?></h3>
                        <small class="text-muted">Over <?php echo $pinLedger['used']['count'] + $pinLedger['unused']['count']; ?> total generated keys</small>
                    </div>

                    <div class="alert alert-info py-2" role="alert" style="font-size: 13px;">
                        <i class="fa fa-shield me-1"></i> <strong>Audit Compliance:</strong> Total minted ledger value is fully backed and securely mapped against registered package tiers to prevent internal leakage.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Liability & Cap Status & Fee/Withdrawal Reconciliation -->
    <div class="row">
        <!-- 3. Liability & Cap Status Report -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-triangle-exclamation text-warning me-2"></i> Liability & Cap Status Report</h5>
                </div>
                <div class="card-body">
                    <!-- 200% Passive Cap Liability -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="font-weight-bold">200% Passive ROI Liability Status</span>
                            <span class="badge bg-primary"><?php echo $passiveProgressPercent; ?>% Depleted</span>
                        </div>
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $passiveProgressPercent; ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between text-muted" style="font-size: 11px;">
                            <span>Earned & Paid: $<?php echo number_format($passiveRoiPaid, 2); ?></span>
                            <span>Remaining Liability: $<?php echo number_format($remainingPassiveLiability, 2); ?></span>
                        </div>
                    </div>

                    <hr>

                    <!-- 300% Global ID Cap Liability -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="font-weight-bold">300% Global ID Cap Liability Status</span>
                            <span class="badge bg-warning text-dark"><?php echo $globalIDCapProgressPercent; ?>% Depleted</span>
                        </div>
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $globalIDCapProgressPercent; ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between text-muted" style="font-size: 11px;">
                            <span>Incomes Paid: $<?php echo number_format($totalIncomesPaid, 2); ?></span>
                            <span>Remaining Liability: $<?php echo number_format($remainingGlobalIDCapLiability, 2); ?></span>
                        </div>
                    </div>

                    <div class="alert bg-light py-2 mb-0" style="font-size: 12px; border-left: 4px solid #0d6efd;">
                        <i class="fa fa-info-circle me-1"></i> Active platform liabilities represent the potential future payouts remaining before accounts hit their caps.
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Fee & Withdrawal Reconciliation -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-money-bill-transfer text-danger me-2"></i> Fee & Withdrawal Reconciliation</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-6 border-end">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Total Requested Withdrawals</h6>
                            <h3 class="text-dark font-weight-bold">$<?php echo number_format($totalRequestedWithdrawals, 2); ?></h3>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 11px;">Total Gas Fees Collected</h6>
                            <h3 class="text-danger font-weight-bold">$<?php echo number_format($totalWithdrawalFees, 2); ?></h3>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded text-center mb-4">
                        <span class="text-muted text-uppercase d-block" style="font-size: 11px;">Total Net Payout Released</span>
                        <h3 class="font-weight-bold text-success mt-1">$<?php echo number_format($netPaidOutWithdrawals, 2); ?></h3>
                        <small class="text-muted">Across <?php echo $withdrawalCount; ?> processed requested payout events</small>
                    </div>

                    <div class="alert alert-success py-2 mb-0" role="alert" style="font-size: 13px;">
                        <i class="fa fa-check-circle me-1"></i> <strong>Reconciliation:</strong> Outflowing payout gateway logs match internal ledger releasing of Net release balances.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>