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

// Fetch Active/Completed matching contracts/schedules
$stmtSched = $db->prepare("
    SELECT * FROM matching_schedules
    WHERE user_id = ?
    ORDER BY status ASC, slab_amount DESC
");
$stmtSched->execute([$userId]);
$matching_schedules = $stmtSched->fetchAll();

// Fetch Rank Income Transactions
$stmt = $db->prepare("
    SELECT * FROM transactions
    WHERE user_id = ? AND type = 'RANK_INCOME'
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$rank_transactions = $stmt->fetchAll();

$pageTitle = 'Rank & Matching Contracts';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <!-- Section 1: Active Matching Contracts / Daily ROI Contracts -->
    <div class="row mb-4">
      <div class="col-lg-12">
        <div class="card">
          <div class="card-header">
            <h4 class="card-title mb-0">My Matching Slab Contracts</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-bordered table-striped" style="width:100%">
                <thead>
                  <tr style="background-color: #3f2259; color: white;">
                    <th>#</th>
                    <th>Slab Match Tier</th>
                    <th>Daily ROI (USD)</th>
                    <th>Days Passed</th>
                    <th>Max Days</th>
                    <th>Status</th>
                    <th>Initiated Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(empty($matching_schedules)): ?>
                    <tr>
                      <td colspan="7" class="text-center text-muted">No active slab matching contracts yet. Accumulate team volume on power and matching legs to trigger contracts!</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach($matching_schedules as $index => $sched): ?>
                      <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><span class="badge bg-primary" style="font-size: 14px;">$<?php echo number_format($sched['slab_amount'], 2); ?></span></td>
                        <td class="text-success font-weight-bold">$<?php echo number_format($sched['daily_income'], 2); ?> / day</td>
                        <td><?php echo $sched['days_passed']; ?></td>
                        <td><?php echo $sched['max_days']; ?></td>
                        <td>
                          <?php if($sched['status'] == 'active'): ?>
                            <span class="badge bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Completed</span>
                          <?php endif; ?>
                        </td>
                        <td><?php echo date('d M, Y', strtotime($sched['created_at'])); ?></td>
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

    <!-- Section 2: Historical Payout Transactions -->
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card">
          <div class="card-header">
            <h4 class="card-title mb-0">Daily Rank / Matching Income Payout History</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Description</th>
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
                            // Extract description
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
