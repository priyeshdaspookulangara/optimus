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
            <div class="card-header">Income Payout Summary</div>
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
            <div class="card-header">Recent Business Volume</div>
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
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">Top 10 Investors</div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Total Invested ($)</th>
                            <th>Join Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $topInvestors = $db->query("SELECT username, email, total_investment, created_at FROM users ORDER BY total_investment DESC LIMIT 10")->fetchAll();
                        foreach($topInvestors as $top):
                        ?>
                        <tr>
                            <td><?php echo $top['username']; ?></td>
                            <td><?php echo $top['email']; ?></td>
                            <td class="fw-bold">$<?php echo number_format($top['total_investment'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($top['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
