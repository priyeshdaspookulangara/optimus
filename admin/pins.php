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

$newlyGeneratedData = null;
if (isset($_SESSION['newly_generated_pins'])) {
    $newlyGeneratedData = $_SESSION['newly_generated_pins'];
    unset($_SESSION['newly_generated_pins']);
}

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

    $stmtPkg = $db->prepare("SELECT name FROM packages WHERE id = ?");
    $stmtPkg->execute([$packageId]);
    $pkgRow = $stmtPkg->fetch();
    $packageName = $pkgRow ? $pkgRow['name'] : 'Package';

    $newlyGenerated = [];

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
        $newlyGenerated[] = $pin;
    }

    $_SESSION['newly_generated_pins'] = [
        'pins' => $newlyGenerated,
        'package_name' => $packageName,
        'assigned_to' => $assignUsername
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>PIN Management</h3>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePinModal">
        <i class="fa fa-magic me-2"></i> Generate PINs
    </button>
</div>

<!-- Filters & Actions Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-md-4 d-flex gap-2">
                <a href="pins.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All PINs</a>
                <a href="pins.php?status=unused" class="btn btn-sm <?php echo $statusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused PINs</a>
                <a href="pins.php?status=used" class="btn btn-sm <?php echo $statusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used PINs Only</a>
            </div>
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" id="pinSearchInput" class="form-control" placeholder="Search PIN, Username, or Member ID...">
                </div>
            </div>
            <div class="col-md-4 text-md-end d-flex gap-2 justify-content-md-end align-items-center">
                <button type="button" id="btnBulkWhatsApp" class="btn btn-sm btn-success" disabled>
                    <i class="fab fa-whatsapp me-1"></i> Send Selected (<span id="selectedCount">0</span>)
                </button>
                <button type="button" id="btnBulkSMS" class="btn btn-sm btn-info text-white" disabled>
                    <i class="fa-solid fa-comment-sms me-1"></i> Send Selected
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle" id="pinsTable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllPins">
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
                    <tr class="pin-row"
                        data-pin="<?php echo htmlspecialchars($p['pin_code']); ?>"
                        data-pkg="<?php echo htmlspecialchars($p['package_name']); ?>"
                        data-assigned="<?php echo htmlspecialchars($p['assigned_to_user'] ?? ''); ?>"
                        data-assigned-mid="<?php echo htmlspecialchars($p['assigned_to_mid'] ?? ''); ?>"
                        data-used="<?php echo htmlspecialchars($p['used_by_user'] ?? ''); ?>"
                        data-used-mid="<?php echo htmlspecialchars($p['used_by_mid'] ?? ''); ?>">
                        <td>
                            <input type="checkbox" class="form-check-input pin-checkbox"
                                   value="<?php echo htmlspecialchars($p['pin_code']); ?>"
                                   data-pkg="<?php echo htmlspecialchars($p['package_name']); ?>"
                                   data-assigned="<?php echo htmlspecialchars($p['assigned_to_user'] ?? ''); ?>">
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

<?php if ($newlyGeneratedData): ?>
<!-- Newly Generated PINs Modal -->
<div class="modal fade" id="newlyGeneratedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fa fa-circle-check me-2"></i> Newly Generated PINs (<?php echo count($newlyGeneratedData['pins']); ?>)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Package:</strong> <?php echo htmlspecialchars($newlyGeneratedData['package_name']); ?>
                    </div>
                    <?php if (!empty($newlyGeneratedData['assigned_to'])): ?>
                    <div class="col-md-6">
                        <strong>Assigned To:</strong> <?php echo htmlspecialchars($newlyGeneratedData['assigned_to']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Generated PIN Codes:</label>
                    <textarea id="newlyGeneratedText" class="form-control font-monospace" rows="6" readonly><?php echo htmlspecialchars(implode("\n", $newlyGeneratedData['pins'])); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <?php
                $genWaText = "🌟 *OPTIMUS INFINITY - ACTIVATION PIN(S)* 🌟\n\nDear Partner,\n\nYour Package Activation PIN(s) have been successfully generated!\n\n📦 *PACKAGE:* " . $newlyGeneratedData['package_name'] . "\n\n🔑 *PIN CODES:*\n" . implode("\n", $newlyGeneratedData['pins']) . "\n\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";
                $genWaUrl = "https://api.whatsapp.com/send?text=" . urlencode($genWaText);

                $genSmsText = "OPTIMUS INFINITY - ACTIVATION PINS\n\nPackage: " . $newlyGeneratedData['package_name'] . "\nPINs:\n" . implode("\n", $newlyGeneratedData['pins']);
                $genSmsUrl = "sms:?body=" . urlencode($genSmsText);
                ?>
                <button type="button" class="btn btn-secondary" id="btnCopyGenerated"><i class="fa fa-copy me-1"></i> Copy PINs</button>
                <a href="<?php echo $genWaUrl; ?>" target="_blank" class="btn btn-success"><i class="fab fa-whatsapp me-1"></i> Send via WhatsApp</a>
                <a href="<?php echo $genSmsUrl; ?>" class="btn btn-info text-white"><i class="fa-solid fa-comment-sms me-1"></i> Send via SMS</a>
                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show newly generated modal if present
    const newlyGeneratedModalEl = document.getElementById('newlyGeneratedModal');
    if (newlyGeneratedModalEl) {
        const modal = new bootstrap.Modal(newlyGeneratedModalEl);
        modal.show();

        const btnCopyGenerated = document.getElementById('btnCopyGenerated');
        if (btnCopyGenerated) {
            btnCopyGenerated.addEventListener('click', function() {
                const textarea = document.getElementById('newlyGeneratedText');
                textarea.select();
                navigator.clipboard.writeText(textarea.value).then(() => {
                    const originalText = btnCopyGenerated.innerHTML;
                    btnCopyGenerated.innerHTML = '<i class="fa fa-check me-1"></i> Copied!';
                    setTimeout(() => {
                        btnCopyGenerated.innerHTML = originalText;
                    }, 2000);
                });
            });
        }
    }

    // Search filter
    const pinSearchInput = document.getElementById('pinSearchInput');
    if (pinSearchInput) {
        pinSearchInput.addEventListener('keyup', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#pinsTable tbody tr.pin-row');

            rows.forEach(row => {
                const pin = (row.getAttribute('data-pin') || '').toLowerCase();
                const pkg = (row.getAttribute('data-pkg') || '').toLowerCase();
                const assigned = (row.getAttribute('data-assigned') || '').toLowerCase();
                const assignedMid = (row.getAttribute('data-assigned-mid') || '').toLowerCase();
                const used = (row.getAttribute('data-used') || '').toLowerCase();
                const usedMid = (row.getAttribute('data-used-mid') || '').toLowerCase();

                if (pin.includes(query) || pkg.includes(query) || assigned.includes(query) || assignedMid.includes(query) || used.includes(query) || usedMid.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Checkbox handling
    const selectAllPins = document.getElementById('selectAllPins');
    const pinCheckboxes = document.querySelectorAll('.pin-checkbox');
    const selectedCountSpan = document.getElementById('selectedCount');
    const btnBulkWhatsApp = document.getElementById('btnBulkWhatsApp');
    const btnBulkSMS = document.getElementById('btnBulkSMS');

    function updateSelectionState() {
        const selected = document.querySelectorAll('.pin-checkbox:checked');
        const count = selected.length;

        if (selectedCountSpan) {
            selectedCountSpan.textContent = count;
        }

        if (btnBulkWhatsApp && btnBulkSMS) {
            btnBulkWhatsApp.disabled = (count === 0);
            btnBulkSMS.disabled = (count === 0);
        }
    }

    if (selectAllPins) {
        selectAllPins.addEventListener('change', function() {
            const visibleCheckboxes = document.querySelectorAll('#pinsTable tbody tr:not([style*="display: none"]) .pin-checkbox');
            visibleCheckboxes.forEach(cb => {
                cb.checked = selectAllPins.checked;
            });
            updateSelectionState();
        });
    }

    pinCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionState);
    });

    if (btnBulkWhatsApp) {
        btnBulkWhatsApp.addEventListener('click', function() {
            const selected = document.querySelectorAll('.pin-checkbox:checked');
            if (selected.length === 0) return;

            let pinsList = [];
            selected.forEach(cb => {
                const pinCode = cb.value;
                const pkg = cb.getAttribute('data-pkg') || '';
                pinsList.push(`• ${pinCode} (${pkg})`);
            });

            const waText = `🌟 *OPTIMUS INFINITY - ACTIVATION PIN(S)* 🌟\n\nDear Partner,\n\nHere are your requested activation PINs:\n\n` + pinsList.join('\n') + `\n\nThank you for choosing Optimus Infinity. Let's scale new heights together! 🚀`;
            const waUrl = `https://api.whatsapp.com/send?text=` + encodeURIComponent(waText);
            window.open(waUrl, '_blank');
        });
    }

    if (btnBulkSMS) {
        btnBulkSMS.addEventListener('click', function() {
            const selected = document.querySelectorAll('.pin-checkbox:checked');
            if (selected.length === 0) return;

            let pinsList = [];
            selected.forEach(cb => {
                pinsList.push(cb.value);
            });

            const smsText = `OPTIMUS INFINITY ACTIVATION PINS:\n` + pinsList.join('\n');
            const smsUrl = `sms:?body=` + encodeURIComponent(smsText);
            window.location.href = smsUrl;
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
