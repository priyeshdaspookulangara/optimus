<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch unused PINs assigned to current user
$stmt = $db->prepare("
    SELECT p.*, pkg.name as package_name, pkg.amount
    FROM pins p
    JOIN packages pkg ON p.package_id = pkg.id
    WHERE p.assigned_to = ? AND p.status = 'unused'
    ORDER BY p.created_at DESC
");
$stmt->execute([$userId]);
$unusedPins = $stmt->fetchAll();

// Fetch used PINs used by OR assigned to current user
$stmt = $db->prepare("
    SELECT p.*, pkg.name as package_name, pkg.amount, u.username as used_by_user
    FROM pins p
    JOIN packages pkg ON p.package_id = pkg.id
    LEFT JOIN users u ON p.used_by = u.id
    WHERE (p.assigned_to = ? OR p.used_by = ?) AND p.status = 'used'
    ORDER BY p.created_at DESC
");
$stmt->execute([$userId, $userId]);
$usedPins = $stmt->fetchAll();

$pageTitle = 'My PINs';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<style>
    td { color: #000 !important; }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs mb-4" id="pinTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active text-uppercase" id="unused-tab" data-bs-toggle="tab" data-bs-target="#unused-pins" type="button" role="tab">Unused PINs</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-uppercase" id="used-tab" data-bs-toggle="tab" data-bs-target="#used-pins" type="button" role="tab">Used PINs History</button>
                </li>
            </ul>

            <div class="tab-content" id="pinTabsContent">
                <!-- Unused PINs -->
                <div class="tab-pane fade show active" id="unused-pins" role="tabpanel">
                    <div class="card p-3">
                        <div class="card-header">
                            <h4 class="card-title mb-0">My Unused PINs</h4>
                            <p class="text-muted mb-0">Use these PIN codes to activate packages or share them with downlines.</p>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="unusedPinsTable" class="table table-striped" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>PIN Code</th>
                                            <th>Associated Package</th>
                                            <th>Value ($)</th>
                                            <th>Created At</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($unusedPins as $pin): ?>
                                        <tr>
                                            <td><code style="font-size: 1.1rem;"><?php echo htmlspecialchars($pin['pin_code']); ?></code></td>
                                            <td><?php echo htmlspecialchars($pin['package_name']); ?></td>
                                            <td><strong>$<?php echo number_format($pin['amount'], 2); ?></strong></td>
                                            <td><?php echo date('d M, Y h:i a', strtotime($pin['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-warning text-white copy-pin-btn" data-pin="<?php echo $pin['pin_code']; ?>">Copy PIN</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Used PINs -->
                <div class="tab-pane fade" id="used-pins" role="tabpanel">
                    <div class="card p-3">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Used PINs History</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="usedPinsTable" class="table table-striped" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>PIN Code</th>
                                            <th>Associated Package</th>
                                            <th>Value ($)</th>
                                            <th>Used By</th>
                                            <th>Date Used</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($usedPins as $pin): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($pin['pin_code']); ?></code></td>
                                            <td><?php echo htmlspecialchars($pin['package_name']); ?></td>
                                            <td>$<?php echo number_format($pin['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($pin['used_by_user'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('d M, Y h:i a', strtotime($pin['created_at'])); ?></td>
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
    </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
      $('#unusedPinsTable').DataTable();
      $('#usedPinsTable').DataTable();

      $('.copy-pin-btn').click(function () {
          var pin = $(this).data('pin');
          var $btn = $(this);
          var temp = $("<input>");
          $("body").append(temp);
          temp.val(pin).select();
          document.execCommand("copy");
          temp.remove();

          $btn.html("Copied!");
          setTimeout(function() {
              $btn.html("Copy PIN");
          }, 1500);
      });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
