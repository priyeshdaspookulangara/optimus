<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME' ORDER BY created_at DESC");
$stmt->execute([$userId]);
$level_transactions = $stmt->fetchAll();

$pageTitle = 'Level Income';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2">
                <div class="card-header"><h4 class="card-title mb-0">Level Income History</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="levelTable" class="table table-striped" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>From User ID</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($level_transactions as $t): ?>
                                <tr>
                                    <td><?php echo $t['created_at']; ?></td>
                                    <td class="text-success">$<?php echo number_format($t['amount'], 2); ?></td>
                                    <td><?php echo $t['related_user_id']; ?></td>
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

<script>$(document).ready(function(){ $('#levelTable').DataTable(); });</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
