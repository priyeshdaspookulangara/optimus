<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("
    SELECT i.*, p.name as package_name
    FROM investments i
    JOIN packages p ON i.package_id = p.id
    WHERE i.user_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$userId]);
$investments = $stmt->fetchAll();

$pageTitle = 'Invest History';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2">
                <div class="card-header"><h4 class="card-title mb-0">Investment History</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="investTable" class="table table-striped" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Package</th>
                                    <th>Amount</th>
                                    <th>ROI Earned</th>
                                    <th>Total Earned</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($investments as $inv): ?>
                                <tr>
                                    <td><?php echo $inv['created_at']; ?></td>
                                    <td><?php echo htmlspecialchars($inv['package_name']); ?></td>
                                    <td>$<?php echo number_format($inv['amount'], 2); ?></td>
                                    <td>$<?php echo number_format($inv['roi_earned'], 2); ?></td>
                                    <td>$<?php echo number_format($inv['total_earned'], 2); ?></td>
                                    <td><?php echo $inv['days_passed']; ?> / 400</td>
                                    <td>
                                        <span class="badge <?php
                                            echo ($inv['status'] == 'active') ? 'bg-success' : (($inv['status'] == 'completed') ? 'bg-primary' : 'bg-danger');
                                        ?>">
                                            <?php echo ucfirst($inv['status']); ?>
                                        </span>
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
</div>

<script>$(document).ready(function(){ $('#investTable').DataTable(); });</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
