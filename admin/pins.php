<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'generate') {
    $packageId = $_POST['package_id'];
    $count = (int)$_POST['count'];

    $stmt = $db->prepare("INSERT INTO pins (pin_code, package_id) VALUES (?, ?)");
    for ($i = 0; $i < $count; $i++) {
        $pin = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
        $stmt->execute([$pin, $packageId]);
    }
    header("Location: pins.php?success=generated");
    exit();
}

$pageTitle = 'PIN Management';
include __DIR__ . '/includes/header.php';

$stmt = $db->query("SELECT p.*, pkg.name as package_name, u.username as used_by_user
                    FROM pins p
                    JOIN packages pkg ON p.package_id = pkg.id
                    LEFT JOIN users u ON p.used_by = u.id
                    ORDER BY p.created_at DESC");
$pins = $stmt->fetchAll();

$stmt = $db->query("SELECT * FROM packages ORDER BY amount ASC");
$packages = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>PIN Management</h3>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePinModal">
        <i class="fa fa-magic me-2"></i> Generate PINs
    </button>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>PIN Code</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Used By</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pins as $p): ?>
                    <tr>
                        <td><code><?php echo $p['pin_code']; ?></code></td>
                        <td><?php echo $p['package_name']; ?></td>
                        <td>
                            <span class="badge <?php echo $p['status'] == 'unused' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo strtoupper($p['status']); ?>
                            </span>
                        </td>
                        <td><?php echo $p['used_by_user'] ?? '-'; ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Generate PIN Modal -->
<div class="modal fade" id="generatePinModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="generate">
            <div class="modal-header">
                <h5 class="modal-title">Generate Activation PINs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Select Package</label>
                    <select name="package_id" class="form-select" required>
                        <?php foreach($packages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>"><?php echo $pkg['name']; ?> ($<?php echo number_format($pkg['amount'], 2); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Quantity to Generate</label>
                    <input type="number" name="count" class="form-control" value="1" min="1" max="100" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Generate Now</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
