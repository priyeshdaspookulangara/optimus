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

// Fetch propagated matching payouts received from downline members
// Type is 'RANK_INCOME' and related_user_id is NOT NULL (representing another user who triggered it)
$stmtIncomes = $db->prepare("
    SELECT t.*, u.username as source_username
    FROM transactions t
    LEFT JOIN users u ON t.related_user_id = u.id
    WHERE t.user_id = ? AND t.type = 'RANK_INCOME' AND t.related_user_id IS NOT NULL
    ORDER BY t.created_at DESC
");
$stmtIncomes->execute([$userId]);
$propagated_transactions = $stmtIncomes->fetchAll();

$pageTitle = 'Matches From Others';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="card-title mb-0">Propagated Matching Incomes (Received from Downline)</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="propagatedTable" class="table table-striped" style="width:100%">
                <thead>
                  <tr style="background-color: #3f2259; color: white;">
                    <th>#</th>
                    <th>Date & Time</th>
                    <th>From Downline</th>
                    <th>Description</th>
                    <th>Received Amount (USD)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(empty($propagated_transactions)): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No propagated matching incomes received yet. When your downline matches slabs, those payouts will propagate up to you here!</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach($propagated_transactions as $index => $t): ?>
                      <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo date('d M, Y h:i:s a', strtotime($t['created_at'])); ?></td>
                        <td>
                          <span class="badge bg-info" style="font-size: 13px;">
                            <i class="fa fa-user me-1"></i><?php echo htmlspecialchars($t['source_username'] ?? 'Downline Member'); ?>
                          </span>
                        </td>
                        <td><?php echo htmlspecialchars($t['description']); ?></td>
                        <td class="text-success font-weight-bold" style="font-size: 15px;">+$<?php echo number_format($t['amount'], 2); ?></td>
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
      $('#propagatedTable').DataTable();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
