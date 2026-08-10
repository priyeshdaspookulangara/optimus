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

// Fetch individual daily ROI transactions
$stmtROI = $db->prepare("
    SELECT t.*, p.name as package_name
    FROM transactions t
    LEFT JOIN investments i ON t.investment_id = i.id
    LEFT JOIN packages p ON i.package_id = p.id
    WHERE t.user_id = ? AND t.type = 'ROI'
    ORDER BY COALESCE(t.roi_date, t.created_at) DESC, t.created_at DESC
");
$stmtROI->execute([$userId]);
$roi_transactions = $stmtROI->fetchAll();

$pageTitle = 'ROI Income';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<style>
    td { color: #000 !important; }
</style>

<div class="container-fluid content-inner pb-0">
    <!-- Section 1: Investments Summary -->
    <div class="row mb-4">
      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">TRADE PROFIT - ROI INVESTMENTS</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Investment$</th>
                    <th>Days Passed</th>
                    <th>Total ROI Earned$</th>
                    <th>View</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($investments as $index => $inv): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($inv['created_at'])); ?></td>
                      <td><?php echo number_format($inv['amount'], 2); ?></td>
                      <td><?php echo $inv['days_passed']; ?></td>
                      <td><?php echo number_format($inv['roi_earned'], 2); ?></td>
                      <td><a href="roi_view.php?id=<?php echo $inv['id']; ?>" class="btn btn-primary btn-sm text-white">View Breakdown</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Individual Daily ROI Transactions -->
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">DAILY ROI PAYOUTS LEDGER</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="roiHistory" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>ROI Date</th>
                    <th>Package</th>
                    <th>ROI Amount</th>
                    <th>Description</th>
                    <th>Logged At</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($roi_transactions as $index => $t): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td>
                        <span class="badge bg-success text-white font-monospace" style="font-size: 0.9rem;">
                          <?php echo !empty($t['roi_date']) ? date('Y-m-d', strtotime($t['roi_date'])) : date('Y-m-d', strtotime($t['created_at'])); ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars($t['package_name'] ?? 'Investment #' . $t['investment_id']); ?></td>
                      <td class="text-success font-monospace fw-bold">$<?php echo number_format($t['amount'], 2); ?></td>
                      <td><?php echo htmlspecialchars($t['description']); ?></td>
                      <td><small class="text-muted"><?php echo date('d M, Y h:i:s a', strtotime($t['created_at'])); ?></small></td>
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
      $('#roiHistory').DataTable();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
