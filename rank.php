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

// Fetch Rank Income Transactions
$stmt = $db->prepare("
    SELECT * FROM transactions
    WHERE user_id = ? AND type = 'RANK_INCOME'
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$rank_transactions = $stmt->fetchAll();

$pageTitle = 'Rank Income History';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card">
          <div class="card-header">
            <h4 class="card-title mb-0">Rank Income</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Rank</th>
                    <th>Received$</th>
                    <th>View</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($rank_transactions as $index => $t): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($t['created_at'])); ?></td>
                      <td>
                        <?php
                            // Extract rank name from description if possible, or just show 'Achieved'
                            echo htmlspecialchars($t['description']);
                        ?>
                      </td>
                      <td class="text-success">$<?php echo number_format($t['amount'], 2); ?></td>
                      <td><button class="btn btn-primary btn-sm text-white" disabled>View</button></td>
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
