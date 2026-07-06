<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch Level Income Transactions
$stmt = $db->prepare("
    SELECT t.*, u.username as from_username
    FROM transactions t
    LEFT JOIN users u ON t.related_user_id = u.id
    WHERE t.user_id = ? AND t.type = 'LEVEL_INCOME'
    ORDER BY t.created_at DESC
");
$stmt->execute([$userId]);
$level_transactions = $stmt->fetchAll();

$pageTitle = 'Level Income';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">Level Income</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive mt-3">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Credit</th>
                    <th>From</th>
                    <th>Level</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($level_transactions as $index => $t): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($t['created_at'])); ?></td>
                      <td class="text-success">$<?php echo number_format($t['amount'], 2); ?></td>
                      <td>
                        <?php echo $t['related_user_id']; ?><br>
                        <?php echo htmlspecialchars($t['from_username'] ?? 'Unknown'); ?>
                      </td>
                      <td><?php echo $t['level']; ?></td>
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

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
      $('#example').DataTable();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
