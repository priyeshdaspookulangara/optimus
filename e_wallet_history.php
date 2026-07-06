<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$pageTitle = 'E-Wallet History';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2">
                <div class="card-header"><h4 class="card-title mb-0">E-Wallet History</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="historyTable" class="table table-striped" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Fee</th>
                                    <th>Net Amount</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($transactions as $t): ?>
                                <tr>
                                    <td><?php echo $t['created_at']; ?></td>
                                    <td><span class="badge bg-info"><?php echo $t['type']; ?></span></td>
                                    <td>$<?php echo number_format($t['amount'], 2); ?></td>
                                    <td>$<?php echo number_format($t['fee'], 2); ?></td>
                                    <td class="<?php echo ($t['net_amount'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                        $<?php echo number_format($t['net_amount'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>$(document).ready(function(){ $('#historyTable').DataTable(); });</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
