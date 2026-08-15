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

    // Fetch package name for context/sharing
    $pkgStmt = $db->prepare("SELECT name FROM packages WHERE id = ?");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();
    $packageName = $package ? $package['name'] : 'Unknown Package';

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
    $newlyGenerated = [];
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
        $newlyGenerated[] = [
            'pin_code' => $pin,
            'package_name' => $packageName
        ];
    }

    // Save newly generated PINs to session for post-generation modal popup
    $_SESSION['newly_generated_pins'] = [
        'package_name' => $packageName,
        'pins' => $newlyGenerated
    ];

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

// Handle post-generation modal data
$hasNewlyGenerated = false;
$newlyGeneratedPinsData = null;
if (isset($_GET['success']) && $_GET['success'] === 'generated' && isset($_SESSION['newly_generated_pins'])) {
    $hasNewlyGenerated = true;
    $newlyGeneratedPinsData = $_SESSION['newly_generated_pins'];
    // Clear session immediately or keep it for rendering first
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>PIN Management</h3>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePinModal">
        <i class="fa fa-magic me-2"></i> Generate PINs
    </button>
</div>

<!-- Filters Bar -->
<div class="mb-3 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2">
        <a href="pins.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All PINs</a>
        <a href="pins.php?status=unused" class="btn btn-sm <?php echo $statusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused PINs</a>
        <a href="pins.php?status=used" class="btn btn-sm <?php echo $statusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used PINs Only</a>
    </div>
    <div>
        <button id="sendSelectedWa" class="btn btn-sm btn-success d-none" onclick="sendSelectedToWhatsapp()">
            <i class="fab fa-whatsapp me-1"></i> Send Selected (<span id="selectedCount">0</span>) to WhatsApp
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input" id="selectAllPins"></th>
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
                        <td>
                            <input type="checkbox" class="form-check-input pin-checkbox"
                                   data-pin="<?php echo htmlspecialchars($p['pin_code']); ?>"
                                   data-package="<?php echo htmlspecialchars($p['package_name']); ?>">
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

<!-- Post-Generation Share Modal -->
<?php if ($hasNewlyGenerated && $newlyGeneratedPinsData): ?>
<div class="modal fade" id="shareNewlyGeneratedModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fa fa-circle-check me-2"></i>PINs Generated Successfully!</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>You have successfully generated <strong><?php echo count($newlyGeneratedPinsData['pins']); ?> PINs</strong> for <strong><?php echo htmlspecialchars($newlyGeneratedPinsData['package_name']); ?></strong>.</p>
                <div class="border rounded p-3 mb-3 bg-light text-center" style="max-height: 200px; overflow-y: auto;">
                    <strong>Generated PINs:</strong>
                    <div class="mt-2">
                        <?php foreach($newlyGeneratedPinsData['pins'] as $np): ?>
                            <span class="badge bg-secondary font-monospace fs-6 m-1 p-2"><?php echo htmlspecialchars($np['pin_code']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="text-muted small">Would you like to share these PINs immediately as a group to WhatsApp or via SMS?</p>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No, close & view</button>
                <div class="d-flex gap-2">
                    <?php
                    // Pre-generate WhatsApp message for the newly generated group
                    $groupWaText = "🌟 *OPTIMUS INFINITY - ACTIVATION PINS* 🌟\n\nDear Partner,\n\nYour Package Activation PINs have been successfully generated!\n\n";
                    foreach($newlyGeneratedPinsData['pins'] as $idx => $np) {
                        $groupWaText .= ($idx + 1) . ". 🔑 *PIN:* " . $np['pin_code'] . " (" . $np['package_name'] . ")\n";
                    }
                    $groupWaText .= "\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";
                    $groupWaUrl = "https://api.whatsapp.com/send?text=" . urlencode($groupWaText);

                    // Pre-generate SMS body
                    $groupSmsText = "OPTIMUS INFINITY - ACTIVATION PINS\n\n";
                    foreach($newlyGeneratedPinsData['pins'] as $idx => $np) {
                        $groupSmsText .= ($idx + 1) . ". PIN: " . $np['pin_code'] . "\n";
                    }
                    $groupSmsText .= "\nThank you, Optimus Infinity!";
                    $groupSmsUrl = "sms:?body=" . urlencode($groupSmsText);
                    ?>
                    <a href="<?php echo $groupSmsUrl; ?>" class="btn btn-info text-white" onclick="closeShareModal()"><i class="fa-solid fa-comment-sms me-1"></i> SMS</a>
                    <a href="<?php echo $groupWaUrl; ?>" target="_blank" class="btn btn-success" onclick="closeShareModal()"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
    // Clear session so the modal won't show again on manual reload
    unset($_SESSION['newly_generated_pins']);
endif;
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAllPins');
    const pinCheckboxes = document.querySelectorAll('.pin-checkbox');
    const sendSelectedWaBtn = document.getElementById('sendSelectedWa');
    const selectedCountSpan = document.getElementById('selectedCount');

    // Show the Newly Generated Share modal automatically if it exists in DOM
    const shareModalEl = document.getElementById('shareNewlyGeneratedModal');
    if (shareModalEl) {
        const shareModal = new bootstrap.Modal(shareModalEl);
        shareModal.show();
    }

    window.closeShareModal = function() {
        if (shareModalEl) {
            const modalInstance = bootstrap.Modal.getInstance(shareModalEl);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    };

    function updateBulkButton() {
        const checkedBoxes = document.querySelectorAll('.pin-checkbox:checked');
        const count = checkedBoxes.length;
        selectedCountSpan.textContent = count;
        if (count > 0) {
            sendSelectedWaBtn.classList.remove('d-none');
        } else {
            sendSelectedWaBtn.classList.add('d-none');
        }
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            pinCheckboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateBulkButton();
        });
    }

    pinCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const allChecked = Array.from(pinCheckboxes).every(c => c.checked);
            const someChecked = Array.from(pinCheckboxes).some(c => c.checked);
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
            updateBulkButton();
        });
    });

    window.sendSelectedToWhatsapp = function() {
        const checkedBoxes = document.querySelectorAll('.pin-checkbox:checked');
        if (checkedBoxes.length === 0) return;

        let message = "🌟 *OPTIMUS INFINITY - ACTIVATION PINS* 🌟\n\nDear Partner,\n\nYour Package Activation PINs have been successfully generated!\n\n";

        checkedBoxes.forEach((cb, index) => {
            const pin = cb.getAttribute('data-pin');
            const pkg = cb.getAttribute('data-package');
            message += `${index + 1}. 🔑 *PIN:* ${pin} (${pkg})\n`;
        });

        message += "\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";

        const waUrl = "https://api.whatsapp.com/send?text=" + encodeURIComponent(message);
        window.open(waUrl, '_blank');
    };
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
