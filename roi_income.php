<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch Investments with ROI summary
$stmt = $db->prepare("
    SELECT i.*, p.name as package_name
    FROM investments i
    JOIN packages p ON i.package_id = p.id
    WHERE i.user_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$userId]);
$investments = $stmt->fetchAll();

$pageTitle = 'ROI Income';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<style>
    td { color: #000 !important; }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">TRADE PROFIT - ROI</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Investment$</th>
                    <th>Days</th>
                    <th>Total ROI$</th>
                    <th>View</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($investments as $index => $inv): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($inv['created_at'])); ?></td>
                      <td><?php echo number_format($inv['amount'], 3); ?></td>
                      <td><?php echo $inv['days_passed']; ?></td>
                      <td><?php echo number_format($inv['roi_earned'], 2); ?></td>
                      <td><a href="roi_view.php?id=<?php echo $inv['id']; ?>" class="btn btn-primary btn-sm text-white">View</a></td>
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
