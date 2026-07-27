<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check before running state-changing operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: packages.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    $name = $_POST['name'];
    $amount = $_POST['amount'];

    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // Update
        $stmt = $db->prepare("UPDATE packages SET name = ?, amount = ? WHERE id = ?");
        $stmt->execute([$name, $amount, $_POST['id']]);
    } else {
        // Add
        $stmt = $db->prepare("INSERT INTO packages (name, amount) VALUES (?, ?)");
        $stmt->execute([$name, $amount]);
    }
    header("Location: packages.php?success=1");
    exit();
}

$pageTitle = 'Package Management';
include __DIR__ . '/includes/header.php';

$stmt = $db->query("SELECT * FROM packages ORDER BY amount ASC");
$packages = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Investment Packages</h3>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="clearForm()">
        <i class="fa fa-plus me-2"></i> Add New Package
    </button>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Package Name</th>
                        <th>Amount ($)</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($packages as $p): ?>
                    <tr>
                        <td><?php echo $p['id']; ?></td>
                        <td><?php echo $p['name']; ?></td>
                        <td><strong>$<?php echo number_format($p['amount'], 2); ?></strong></td>
                        <td><?php echo date('Y-m-d', strtotime($p['created_at'])); ?></td>
                        <td>
                            <button class="btn btn-sm btn-info text-white" onclick='editPackage(<?php echo json_encode($p); ?>)'>Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Package Modal -->
<div class="modal fade" id="packageModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="pkg_id">
                <div class="mb-3">
                    <label class="form-label">Package Name</label>
                    <input type="text" name="name" id="pkg_name" class="form-control" required placeholder="e.g. Bronze Plan">
                </div>
                <div class="mb-3">
                    <label class="form-label">Amount ($)</label>
                    <input type="number" step="0.01" name="amount" id="pkg_amount" class="form-control" required placeholder="50.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save Package</button>
            </div>
        </form>
    </div>
</div>

<script>
function clearForm() {
    document.getElementById('pkg_id').value = '';
    document.getElementById('pkg_name').value = '';
    document.getElementById('pkg_amount').value = '';
    document.getElementById('modalTitle').innerText = 'Add Package';
}

function editPackage(pkg) {
    document.getElementById('pkg_id').value = pkg.id;
    document.getElementById('pkg_name').value = pkg.name;
    document.getElementById('pkg_amount').value = pkg.amount;
    document.getElementById('modalTitle').innerText = 'Edit Package';
    new bootstrap.Modal(document.getElementById('packageModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
