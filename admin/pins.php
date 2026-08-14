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

// Build dynamic query
$query = "SELECT p.*, pkg.name as package_name, u.username as used_by_user, u.mid as used_by_mid, u_ass.username as assigned_to_user, u_ass.mid as assigned_to_mid
          FROM pins p
          JOIN packages pkg ON p.package_id = pkg.id
          LEFT JOIN users u ON p.used_by = u.id
          LEFT JOIN users u_ass ON p.assigned_to = u_ass.id";

$params = [];
if ($statusFilter === 'used') {
    $query .= " WHERE p.status = 'used'";
} elseif ($statusFilter === 'unused') {
    $query .= " WHERE p.status = 'unused'";
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

<!-- Filters Bar -->
<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex gap-2">
        <a href="pins.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All PINs</a>
        <a href="pins.php?status=unused" class="btn btn-sm <?php echo $statusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused PINs</a>
        <a href="pins.php?status=used" class="btn btn-sm <?php echo $statusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used PINs Only</a>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="selectedCountBadge" class="badge bg-secondary d-none">0 Selected</span>
        <button type="button" id="btnSendMultipleWA" class="btn btn-sm btn-success d-none" title="Share selected via WhatsApp">
            <i class="fab fa-whatsapp me-1"></i> Send Selected to WhatsApp
        </button>
        <button type="button" id="btnSendMultipleSMS" class="btn btn-sm btn-info text-white d-none" title="Share selected via SMS">
            <i class="fa-solid fa-comment-sms me-1"></i> Send Selected to SMS
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle" id="pinsTable">
                <thead>
                    <tr>
                        <th width="40" class="text-center">
                            <input type="checkbox" id="selectAllPins" class="form-check-input">
                        </th>
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
                    <?php foreach($pins as $p): ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="pin-checkbox form-check-input" data-pin="<?php echo htmlspecialchars($p['pin_code']); ?>" data-package="<?php echo htmlspecialchars($p['package_name']); ?>">
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
document.addEventListener("DOMContentLoaded", function() {
    const selectAllCheckbox = document.getElementById("selectAllPins");
    const pinCheckboxes = document.querySelectorAll(".pin-checkbox");
    const selectedCountBadge = document.getElementById("selectedCountBadge");
    const btnSendMultipleWA = document.getElementById("btnSendMultipleWA");
    const btnSendMultipleSMS = document.getElementById("btnSendMultipleSMS");

    function updateMultiShareState() {
        const checkedBoxes = document.querySelectorAll(".pin-checkbox:checked");
        const count = checkedBoxes.length;

        if (count > 0) {
            selectedCountBadge.textContent = count + " Selected";
            selectedCountBadge.classList.remove("d-none");
            btnSendMultipleWA.classList.remove("d-none");
            btnSendMultipleSMS.classList.remove("d-none");
        } else {
            selectedCountBadge.classList.add("d-none");
            btnSendMultipleWA.classList.add("d-none");
            btnSendMultipleSMS.classList.add("d-none");
        }

        // Keep Select All checkbox state in sync
        selectAllCheckbox.checked = (count === pinCheckboxes.length && pinCheckboxes.length > 0);
    }

    selectAllCheckbox.addEventListener("change", function() {
        const isChecked = selectAllCheckbox.checked;
        pinCheckboxes.forEach(cb => {
            cb.checked = isChecked;
        });
        updateMultiShareState();
    });

    pinCheckboxes.forEach(cb => {
        cb.addEventListener("change", function() {
            updateMultiShareState();
        });
    });

    btnSendMultipleWA.addEventListener("click", function() {
        const checkedBoxes = document.querySelectorAll(".pin-checkbox:checked");
        if (checkedBoxes.length === 0) return;

        let waText = "🌟 *OPTIMUS INFINITY - ACTIVATION PINS* 🌟\n\nDear Partner,\n\nYour Package Activation PINs have been successfully generated!\n\n";
        checkedBoxes.forEach((cb, index) => {
            const pinCode = cb.getAttribute("data-pin");
            const packageName = cb.getAttribute("data-package");
            waText += `${index + 1}. 🔑 *PIN:* ${pinCode}  (📦 *Package:* ${packageName})\n`;
        });
        waText += "\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";

        const waUrl = "https://api.whatsapp.com/send?text=" + encodeURIComponent(waText);
        window.open(waUrl, "_blank");
    });

    btnSendMultipleSMS.addEventListener("click", function() {
        const checkedBoxes = document.querySelectorAll(".pin-checkbox:checked");
        if (checkedBoxes.length === 0) return;

        let smsText = "OPTIMUS INFINITY - ACTIVATION PINS\n\n";
        checkedBoxes.forEach((cb, index) => {
            const pinCode = cb.getAttribute("data-pin");
            const packageName = cb.getAttribute("data-package");
            smsText += `${index + 1}. PIN: ${pinCode} (Pkg: ${packageName})\n`;
        });
        smsText += "\nThank you, Optimus Infinity!";

        const smsUrl = "sms:?body=" + encodeURIComponent(smsText);
        window.open(smsUrl, "_self");
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
