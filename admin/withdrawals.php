<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance()->getConnection();

$pageTitle = 'Withdrawals Management';
include __DIR__ . '/includes/header.php';

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// Build dynamic query
$query = "SELECT t.*, u.username, u.mid
          FROM transactions t
          JOIN users u ON t.user_id = u.id
          WHERE t.type = 'WITHDRAWAL'";

$params = [];
if ($startDate !== '') {
    $query .= " AND DATE(t.created_at) >= ?";
    $params[] = $startDate;
}
if ($endDate !== '') {
    $query .= " AND DATE(t.created_at) <= ?";
    $params[] = $endDate;
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Withdrawals List</h3>
    </div>

    <!-- Dynamic Date Range Filter Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text">Start Date</span>
                <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>">
            </div>
        </div>
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text">End Date</span>
                <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>">
            </div>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="withdrawals.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr class="table-dark">
                        <th>Transaction ID</th>
                        <th>Date & Time</th>
                        <th>Member (MID)</th>
                        <th>Requested Amount</th>
                        <th>Gas Fee</th>
                        <th>Net Amount Paid</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($withdrawals)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No withdrawals requested yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($withdrawals as $w): ?>
                        <tr>
                            <td>#<?php echo $w['id']; ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($w['created_at'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($w['username']); ?></strong> <span class="badge bg-secondary" style="font-size: 11px;"><?php echo htmlspecialchars($w['mid'] ?? 'None'); ?></span></td>
                            <td class="text-danger">$<?php echo number_format($w['amount'], 2); ?></td>
                            <td class="text-secondary">$<?php echo number_format($w['fee'], 2); ?></td>
                            <td class="fw-bold text-success">$<?php echo number_format($w['amount'] - $w['fee'], 2); ?></td>
                            <td><?php echo htmlspecialchars($w['description']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
