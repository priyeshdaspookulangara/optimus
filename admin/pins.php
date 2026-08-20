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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'generate') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: pins.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    $packageId = $_POST['package_id'];
    $count = (int)$_POST['count'];
    $assignUsername = trim($_POST['assign_username'] ?? '');

    $assignedToId = null;
    if (!empty($assignUsername)) {
        $userStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $userStmt->execute([$assignUsername]);
        $targetUser = $userStmt->fetch();
        if ($targetUser) {
            $assignedToId = $targetUser['id'];
        }
    }

    $stmt = $db->prepare("INSERT INTO pins (pin_code, package_id, assigned_to) VALUES (?, ?, ?)");
    for ($i = 0; $i < $count; $i++) {
        // Format: "OPT" + unique 6 digit combination
        $uniqueDigits = mt_rand(100000, 999999);
        $pin = "OPT" . $uniqueDigits;

        // Double check uniqueness (simplified retry for this scale)
        $chk = $db->prepare("SELECT id FROM pins WHERE pin_code = ?");
        $chk->execute([$pin]);
        if ($chk->fetch()) {
            $pin = "OPT" . mt_rand(100000, 999999);
        }

        $stmt->execute([$pin, $packageId, $assignedToId]);
    }
    header("Location: pins.php?success=generated");
    exit();
}

$pageTitle = 'PIN Management';
include __DIR__ . '/includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build dynamic query
$query = "SELECT p.*, pkg.name as package_name, u.username as used_by_user, u.mid as used_by_mid, u_ass.username as assigned_to_user, u_ass.mid as assigned_to_mid
          FROM pins p
          JOIN packages pkg ON p.package_id = pkg.id
          LEFT JOIN users u ON p.used_by = u.id
          LEFT JOIN users u_ass ON p.assigned_to = u_ass.id";

$whereClauses = [];
$params = [];

if ($statusFilter === 'used') {
    $whereClauses[] = "p.status = 'used'";
} elseif ($statusFilter === 'unused') {
    $whereClauses[] = "p.status = 'unused'";
}

if ($search !== '') {
    $whereClauses[] = "(p.pin_code LIKE ? OR u_ass.mid LIKE ? OR u_ass.username LIKE ? OR u.mid LIKE ? OR u.username LIKE ?)";
    $searchTerm = "%" . $search . "%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
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

<!-- Filters Bar & Search -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-center mb-3">
            <?php if (!empty($statusFilter)): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <?php endif; ?>
            <div class="col-md-6 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search PIN, Assigned MID/Username, Used MID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-6 col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-filter me-1"></i>Search</button>
                <?php if ($search !== ''): ?>
                    <a href="pins.php<?php echo $statusFilter ? '?status=' . urlencode($statusFilter) : ''; ?>" class="btn btn-outline-secondary">Clear Search</a>
                <?php endif; ?>
            </div>
            <div class="col-12 mt-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div class="d-flex gap-2">
                    <a href="pins.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All PINs</a>
                    <a href="pins.php?status=unused<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $statusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused PINs</a>
                    <a href="pins.php?status=used<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $statusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used PINs Only</a>
                </div>

                <!-- Bulk Actions -->
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary" id="selectedCountBadge">0 Selected</span>
                    <button type="button" class="btn btn-sm btn-success" id="btnBulkWA" onclick="shareBulkWA()" disabled>
                        <i class="fab fa-whatsapp me-1"></i> Send Selected to WhatsApp
                    </button>
                    <button type="button" class="btn btn-sm btn-info text-white" id="btnBulkSMS" onclick="shareBulkSMS()" disabled>
                        <i class="fa-solid fa-comment-sms me-1"></i> Send Selected to SMS
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" id="selectAllPins" class="form-check-input" onchange="toggleSelectAll(this)"></th>
                        <th>PIN Code</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Used By</th>
                        <th>Created At</th>
                        <th>Share / Send</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pins)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No PINs found matching your criteria.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($pins as $p): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input pin-checkbox" data-pin="<?php echo htmlspecialchars($p['pin_code']); ?>" data-package="<?php echo htmlspecialchars($p['package_name']); ?>" onchange="updateBulkButtons()">
                        </td>
                        <td><code><?php echo htmlspecialchars($p['pin_code']); ?></code></td>
                        <td><?php echo htmlspecialchars($p['package_name']); ?></td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars($p['status']) == 'unused' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo htmlspecialchars(strtoupper($p['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo $p['assigned_to_user'] ? htmlspecialchars($p['assigned_to_user']) . ' (' . htmlspecialchars($p['assigned_to_mid'] ?? 'None') . ')' : '<span class="text-muted">Public</span>'; ?></td>
                        <td><?php echo $p['used_by_user'] ? htmlspecialchars($p['used_by_user']) . ' (' . htmlspecialchars($p['used_by_mid'] ?? 'None') . ')' : '-'; ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></td>
                        <td>
                            <?php
                            $waText = "🌟 *OPTIMUS INFINITY - ACTIVATION PIN* 🌟\n\nDear Partner,\n\nYour Package Activation PIN has been successfully generated!\n\n🔑 *PIN CODE:* " . $p['pin_code'] . "\n📦 *PACKAGE:* " . $p['package_name'] . "\n\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";
                            $waUrl = "https://api.whatsapp.com/send?text=" . urlencode($waText);

                            $smsText = "OPTIMUS INFINITY - ACTIVATION PIN\n\nDear Partner,\n\nYour Package Activation PIN is: " . $p['pin_code'] . "\nPackage: " . $p['package_name'] . "\n\nThank you, Optimus Infinity!";
                            $smsUrl = "sms:?body=" . urlencode($smsText);
                            ?>
                            <div class="d-flex gap-1">
                                <a href="<?php echo $waUrl; ?>" target="_blank" class="btn btn-sm btn-success" title="Share via WhatsApp"><i class="fab fa-whatsapp me-1"></i>WhatsApp</a>
                                <a href="<?php echo $smsUrl; ?>" class="btn btn-sm btn-info text-white" title="Share via SMS"><i class="fa-solid fa-comment-sms me-1"></i>SMS</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Generate PIN Modal -->
<div class="modal fade" id="generatePinModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
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
                <div class="mb-3">
                    <label class="form-label">Assign to Member (Optional - Username)</label>
                    <input type="text" name="assign_username" class="form-control" placeholder="e.g. shajithmm002">
                    <small class="text-muted">If specified, the PIN will be displayed under "My PINs" in their dashboard.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Generate Now</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.pin-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateBulkButtons();
}

function updateBulkButtons() {
    const selected = document.querySelectorAll('.pin-checkbox:checked');
    const count = selected.length;
    const badge = document.getElementById('selectedCountBadge');
    const btnWA = document.getElementById('btnBulkWA');
    const btnSMS = document.getElementById('btnBulkSMS');

    badge.textContent = count + ' Selected';
    if (count > 0) {
        btnWA.removeAttribute('disabled');
        btnSMS.removeAttribute('disabled');
    } else {
        btnWA.setAttribute('disabled', 'disabled');
        btnSMS.setAttribute('disabled', 'disabled');
        document.getElementById('selectAllPins').checked = false;
    }
}

function getSelectedPins() {
    const selected = document.querySelectorAll('.pin-checkbox:checked');
    const items = [];
    selected.forEach(cb => {
        items.push({
            pin: cb.getAttribute('data-pin'),
            package: cb.getAttribute('data-package')
        });
    });
    return items;
}

function shareBulkWA() {
    const pins = getSelectedPins();
    if (pins.length === 0) return;

    let pinText = pins.map((item, index) => `${index + 1}. 🔑 *PIN:* ${item.pin} (${item.package})`).join('\n');
    let waText = `🌟 *OPTIMUS INFINITY - ACTIVATION PINS* 🌟\n\nDear Partner,\n\nHere are your requested Package Activation PINs:\n\n${pinText}\n\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀`;

    window.open("https://api.whatsapp.com/send?text=" + encodeURIComponent(waText), "_blank");
}

function shareBulkSMS() {
    const pins = getSelectedPins();
    if (pins.length === 0) return;

    let pinText = pins.map((item, index) => `${index + 1}. PIN: ${item.pin} (${item.package})`).join('\n');
    let smsText = `OPTIMUS INFINITY - ACTIVATION PINS\n\nDear Partner,\n\nHere are your requested Package Activation PINs:\n\n${pinText}\n\nThank you, Optimus Infinity!`;

    window.location.href = "sms:?body=" + encodeURIComponent(smsText);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
