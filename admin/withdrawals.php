<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance()->getConnection();

$pageTitle = 'Withdrawals Management';
include __DIR__ . '/includes/header.php';

// Fetch all withdrawal transactions with username
$stmt = $db->query("
    SELECT t.*, u.username
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'WITHDRAWAL'
    ORDER BY t.created_at DESC
");
$withdrawals = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Withdrawals List</h3>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr class="table-dark">
                        <th>Transaction ID</th>
                        <th>Date & Time</th>
                        <th>Username</th>
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
                            <td><strong><?php echo htmlspecialchars($w['username']); ?></strong></td>
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
