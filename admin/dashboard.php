<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/includes/header.php';

// Fetch Global Stats
$stmt = $db->query("SELECT COUNT(*) as total_users FROM users");
$totalUsers = $stmt->fetch()['total_users'];

$stmt = $db->query("SELECT SUM(amount) as total_invested FROM investments");
$totalInvested = $stmt->fetch()['total_invested'] ?? 0;

$stmt = $db->query("SELECT SUM(amount) as total_roi FROM transactions WHERE type = 'ROI'");
$totalROI = $stmt->fetch()['total_roi'] ?? 0;

$stmt = $db->query("SELECT SUM(amount) as total_withdrawn FROM transactions WHERE type = 'WITHDRAWAL'");
$totalWithdrawn = $stmt->fetch()['total_withdrawn'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as active_investments FROM investments WHERE status = 'active'");
$activeInvestments = $stmt->fetch()['active_investments'];

$stmt = $db->query("SELECT COUNT(*) as unused_pins FROM pins WHERE status = 'unused'");
$unusedPins = $stmt->fetch()['unused_pins'];
?>

<div class="row">
    <div class="col-md-12 mb-4">
        <h3>Dashboard Overview</h3>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-primary text-white">
            <div class="card-body">
                <h5>Total Members</h5>
                <h2><?php echo number_format($totalUsers); ?></h2>
                <i class="fa fa-users position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-success text-white">
            <div class="card-body">
                <h5>Total Business Volume</h5>
                <h2>$<?php echo number_format($totalInvested, 2); ?></h2>
                <i class="fa fa-chart-line position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-info text-white">
            <div class="card-body">
                <h5>Total ROI Paid</h5>
                <h2>$<?php echo number_format($totalROI, 2); ?></h2>
                <i class="fa fa-money-bill-wave position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-warning text-white">
            <div class="card-body">
                <h5>Total Withdrawals</h5>
                <h2>$<?php echo number_format($totalWithdrawn, 2); ?></h2>
                <i class="fa fa-hand-holding-usd position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-danger text-white">
            <div class="card-body">
                <h5>Active Plans</h5>
                <h2><?php echo number_format($activeInvestments); ?></h2>
                <i class="fa fa-folder-open position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card card-stat bg-secondary text-white">
            <div class="card-body">
                <h5>Available PINs</h5>
                <h2><?php echo number_format($unusedPins); ?></h2>
                <i class="fa fa-key position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">Recent Transactions</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $db->query("SELECT t.*, u.username FROM transactions t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 10");
                            while($row = $stmt->fetch()):
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['type']); ?></span></td>
                                <td>$<?php echo number_format($row['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
