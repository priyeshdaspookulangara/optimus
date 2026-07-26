<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$pageTitle = 'Business Reports';
include __DIR__ . '/includes/header.php';

// Daily Business Volume (Last 30 days)
$dailyVolume = $db->query("SELECT DATE(created_at) as date, SUM(amount) as volume
                           FROM investments
                           GROUP BY DATE(created_at)
                           ORDER BY date DESC LIMIT 30")->fetchAll();

// Total Income Payouts by Type
$incomeStats = $db->query("SELECT type, SUM(amount) as total
                           FROM transactions
                           WHERE type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')
                           GROUP BY type")->fetchAll();
?>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Income Payout Summary</span>
                <a href="print_report.php?type=payouts" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-print me-1"></i>Print Report</a>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Income Type</th>
                            <th>Total Payout ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($incomeStats as $stat): ?>
                        <tr>
                            <td><?php echo $stat['type']; ?></td>
                            <td><strong>$<?php echo number_format($stat['total'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Recent Business Volume</span>
                <a href="print_report.php?type=volume" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-print me-1"></i>Print Report</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>New Investment Volume ($)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($dailyVolume as $v): ?>
                            <tr>
                                <td><?php echo $v['date']; ?></td>
                                <td class="text-success fw-bold">$<?php echo number_format($v['volume'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Top 10 Investors</span>
                <a href="print_report.php?type=investors" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-print me-1"></i>Print Report</a>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Total Invested ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $topInvestors = $db->query("SELECT username, email, total_investment, created_at FROM users ORDER BY total_investment DESC LIMIT 10")->fetchAll();
                        foreach($topInvestors as $top):
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($top['username']); ?></td>
                            <td class="fw-bold text-success">$<?php echo number_format($top['total_investment'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Member Withdrawals Summary</span>
                <a href="print_report.php?type=withdrawals" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-print me-1"></i>Print Report</a>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Requested Amount</th>
                            <th>Fee (Gas)</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $withdrawalSummary = $db->query("SELECT COUNT(*) as count, SUM(amount) as total_amount, SUM(fee) as total_fee FROM transactions WHERE type = 'WITHDRAWAL'")->fetch();
                        ?>
                        <tr>
                            <td><strong>$<?php echo number_format($withdrawalSummary['total_amount'] ?? 0, 2); ?></strong></td>
                            <td class="text-danger">$<?php echo number_format($withdrawalSummary['total_fee'] ?? 0, 2); ?></td>
                            <td><?php echo $withdrawalSummary['count'] ?? 0; ?> requests</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
