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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: pins.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    if ($_POST['action'] == 'generate') {
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
    } elseif ($_POST['action'] == 'share_whatsapp') {
        $target = trim($_POST['recipient_target'] ?? '');
        $selectedPins = $_POST['selected_pins'] ?? [];

        if (empty($selectedPins)) {
            header("Location: pins.php?error=" . urlencode("No PINs selected."));
            exit();
        }

        // Lookup recipient info
        $phone = '';
        $recipientName = 'Partner';
        if (!empty($target)) {
            $stmt = $db->prepare("SELECT phone, username, mid, full_name FROM users WHERE mid = ? OR phone = ? OR username = ?");
            $stmt->execute([$target, $target, $target]);
            $user = $stmt->fetch();
            if ($user) {
                $phone = $user['phone'] ?? '';
                $recipientName = $user['full_name'] ?: $user['username'];
            } else {
                // If not found, check if target contains digits and looks like a phone number
                $cleanTarget = preg_replace('/[^0-9]/', '', $target);
                if (strlen($cleanTarget) >= 7) {
                    $phone = $target;
                }
            }
        }

        // Load selected pins details
        $placeholders = implode(',', array_fill(0, count($selectedPins), '?'));
        $stmt = $db->prepare("SELECT p.pin_code, pkg.name as package_name
                              FROM pins p
                              JOIN packages pkg ON p.package_id = pkg.id
                              WHERE p.pin_code IN ($placeholders)");
        $stmt->execute($selectedPins);
        $pinsData = $stmt->fetchAll();

        if (empty($pinsData)) {
            header("Location: pins.php?error=" . urlencode("No valid PINs found."));
            exit();
        }

        // Format the WhatsApp text
        $waText = "🌟 *OPTIMUS INFINITY - ACTIVATION PINS* 🌟\n\n";
        $waText .= "Dear " . $recipientName . ",\n\n";
        $waText .= "Your Package Activation PIN list has been successfully generated!\n\n";

        $idx = 1;
        foreach ($pinsData as $pinRow) {
            $waText .= $idx . ") 🔑 *PIN CODE:* " . $pinRow['pin_code'] . "\n   📦 *PACKAGE:* " . $pinRow['package_name'] . "\n\n";
            $idx++;
        }

        $waText .= "Total PINs: " . count($pinsData) . "\n\n";
        $waText .= "Thank you for choosing Optimus Infinity. Let's scale new heights together! 🚀";

        // Clean phone number for WhatsApp API
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        $waUrl = "https://api.whatsapp.com/send?";
        if (!empty($cleanPhone)) {
            $waUrl .= "phone=" . urlencode($cleanPhone) . "&";
        }
        $waUrl .= "text=" . urlencode($waText);

        header("Location: " . $waUrl);
        exit();
    }
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
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" id="shareSelectedBtn" data-bs-toggle="modal" data-bs-target="#sharePinModal" disabled>
            <i class="fab fa-whatsapp me-2"></i> Share Selected via WhatsApp
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generatePinModal">
            <i class="fa fa-magic me-2"></i> Generate PINs
        </button>
    </div>
</div>

<!-- Success/Error Alert Messages -->
<?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show text-dark" role="alert">
        <strong>Success!</strong> Action completed successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show text-dark" role="alert">
        <strong>Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Filters Bar -->
<div class="mb-3 d-flex gap-2">
    <a href="pins.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All PINs</a>
    <a href="pins.php?status=unused" class="btn btn-sm <?php echo $statusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused PINs</a>
    <a href="pins.php?status=used" class="btn btn-sm <?php echo $statusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used PINs Only</a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
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
                            <input type="checkbox" class="pin-select-chk" data-pin="<?php echo htmlspecialchars($p['pin_code']); ?>" data-package="<?php echo htmlspecialchars($p['package_name']); ?>">
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

<!-- Share PIN Modal -->
<div class="modal fade" id="sharePinModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content" action="pins.php" target="_blank" id="whatsappShareForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="share_whatsapp">
            <div class="modal-header">
                <h5 class="modal-title text-dark">Share Selected PINs via WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-dark">
                <div class="mb-3">
                    <label class="form-label font-weight-bold">Recipient (MID, Username, or Phone)</label>
                    <input type="text" name="recipient_target" class="form-control text-dark" placeholder="e.g. OPT59655 or +1234567890" required>
                    <small class="text-muted d-block mt-1">
                        Enter a Member ID (MID), Username, or direct Phone Number (e.g. 1234567890).
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label font-weight-bold">Selected PINs Preview</label>
                    <ul class="list-group list-group-flush border rounded text-dark" id="selectedPinsList" style="max-height: 200px; overflow-y: auto;">
                        <!-- Populate dynamically using JavaScript -->
                    </ul>
                </div>

                <!-- Dynamic hidden inputs for selected pins -->
                <div id="selectedPinsInputs"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-success" id="confirmShareBtn"><i class="fab fa-whatsapp me-2"></i>Share via WhatsApp</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Select All Checkbox logic
    $('#selectAll').on('change', function() {
        $('.pin-select-chk').prop('checked', this.checked).trigger('change');
    });

    // Enable/disable the Share Selected button and update modal content
    $(document).on('change', '.pin-select-chk', function() {
        var checkedCount = $('.pin-select-chk:checked').length;
        if (checkedCount > 0) {
            $('#shareSelectedBtn').prop('disabled', false);
        } else {
            $('#shareSelectedBtn').prop('disabled', true);
        }

        // Update Select All checkbox state based on individual checkboxes
        var totalCount = $('.pin-select-chk').length;
        $('#selectAll').prop('checked', checkedCount === totalCount);
    });

    // Populate modal when Share Selected button is clicked
    $('#shareSelectedBtn').on('click', function() {
        var selectedPinsContainer = $('#selectedPinsList');
        selectedPinsContainer.empty();

        var selectedInputContainer = $('#selectedPinsInputs');
        selectedInputContainer.empty();

        $('.pin-select-chk:checked').each(function() {
            var pin = $(this).data('pin');
            var package_name = $(this).data('package');

            // Append to preview list in modal
            selectedPinsContainer.append('<li class="list-group-item d-flex justify-content-between align-items-center py-2 text-dark"><span>🔑 <code>' + pin + '</code></span> <span class="badge bg-secondary">' + package_name + '</span></li>');

            // Append hidden inputs to the form
            selectedInputContainer.append('<input type="hidden" name="selected_pins[]" value="' + pin + '">');
        });
    });

    // When the WhatsApp share form is submitted, close the modal shortly after so the page state remains clean
    $('#whatsappShareForm').on('submit', function() {
        setTimeout(function() {
            var modalEl = document.getElementById('sharePinModal');
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
                modal.hide();
            }
        }, 1000);
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
