<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch User Data for header
$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Fetch withdrawals requested by the current user
$stmtW = $db->prepare("
    SELECT * FROM transactions
    WHERE user_id = ? AND type = 'WITHDRAWAL'
    ORDER BY created_at DESC
");
$stmtW->execute([$userId]);
$my_withdrawals = $stmtW->fetchAll();

$pageTitle = 'My Withdrawals';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3>My Withdrawals History</h3>
            <a href="withdraw_fund.php" class="btn btn-warning text-white fw-bold"><i class="fa fa-money-bill-wave me-2"></i>Withdraw Funds</a>
        </div>
      </div>

      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-body">
            <div class="table-responsive">
              <table id="withdrawalTable" class="table table-striped" style="width:100%">
                <thead>
                  <tr style="background-color: #3f2259; color: white;">
                    <th>#</th>
                    <th>Date & Time</th>
                    <th>Requested Amount</th>
                    <th>Gas Fee</th>
                    <th>Net Amount Received</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($my_withdrawals)): ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted py-4">No withdrawals requested yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach($my_withdrawals as $index => $w): ?>
                      <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo date('d M, Y h:i:s a', strtotime($w['created_at'])); ?></td>
                        <td class="text-danger font-weight-bold">-$<?php echo number_format($w['amount'], 2); ?></td>
                        <td class="text-secondary">$<?php echo number_format($w['fee'], 2); ?></td>
                        <td class="text-success font-weight-bold">$<?php echo number_format($w['amount'] - $w['fee'], 2); ?></td>
                        <td><?php echo htmlspecialchars($w['description']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
      $('#withdrawalTable').DataTable();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
