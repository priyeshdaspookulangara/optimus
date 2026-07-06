<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$investmentId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$investmentId) {
    header("Location: invest_history.php");
    exit();
}

// Fetch Investment Details
$stmt = $db->prepare("SELECT i.*, p.name FROM investments i JOIN packages p ON i.package_id = p.id WHERE i.id = ? AND i.user_id = ?");
$stmt->execute([$investmentId, $userId]);
$inv = $stmt->fetch();

if (!$inv) {
    header("Location: invest_history.php");
    exit();
}

// Fetch ROI Transactions for this specific investment
$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND investment_id = ? AND type = 'ROI' ORDER BY created_at DESC");
$stmt->execute([$userId, $investmentId]);
$roi_history = $stmt->fetchAll();

$pageTitle = 'ROI View';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card">
          <div class="card-header d-flex justify-content-between">
            <h4 class="card-title mb-0">TRADE PROFIT - ROI</h4>
            <span class="badge bg-primary"><?php echo htmlspecialchars($inv['name']); ?></span>
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
                    <?php if($inv): ?>
                    <tr>
                      <td>1</td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($inv['created_at'])); ?></td>
                      <td><?php echo number_format($inv['amount'], 3); ?></td>
                      <td><?php echo $inv['days_passed']; ?></td>
                      <td><?php echo number_format($inv['roi_earned'], 0); ?></td>
                      <td><button class="btn btn-primary btn-sm text-white" disabled>View</button></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
              </table>
            </div>

            <h5 class="mt-5 mb-3">Daily ROI Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($roi_history as $r): ?>
                        <tr>
                            <td><?php echo $r['created_at']; ?></td>
                            <td>$<?php echo number_format($r['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($r['description']); ?></td>
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

<script>$(document).ready(function(){ $('#example').DataTable(); });</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
