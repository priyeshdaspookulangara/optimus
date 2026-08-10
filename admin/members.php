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

if (isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: members.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    if ($_POST['action'] == 'update_status') {
        $userId = $_POST['user_id'];
        $newStatus = $_POST['status'];
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        header("Location: members.php?success=status_updated");
        exit();
    } elseif ($_POST['action'] == 'clear_system') {
        // Clear all members except root (ID 1)
        $db->beginTransaction();
        try {
            // Disable foreign keys temporarily
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Delete from dependent tables
            $db->exec("DELETE FROM genealogy WHERE user_id > 1 OR parent_id > 1");
            $db->exec("DELETE FROM investments WHERE user_id > 1");
            $db->exec("DELETE FROM transactions WHERE user_id > 1 OR related_user_id > 1");
            $db->exec("DELETE FROM user_wallets WHERE user_id > 1");
            $db->exec("DELETE FROM matching_schedules WHERE user_id > 1");

            // Delete unused/used PINs created for or by non-root users
            $db->exec("DELETE FROM pins WHERE used_by > 1 OR assigned_to > 1");

            // Delete users except ID 1 (Root admin)
            $db->exec("DELETE FROM users WHERE id > 1");

            // Reset Root user (ID 1) MLM metrics
            $db->exec("UPDATE users SET
                rank_id = 0,
                total_investment = 0.00,
                left_leg_business = 0.00,
                right_leg_business = 0.00,
                rank_income_days = 0,
                sponsor_id = NULL,
                placement_id = NULL,
                status = 'active'
                WHERE id = 1
            ");

            // Re-enable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");

            $db->commit();
            header("Location: members.php?success=system_cleared");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: members.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    } elseif ($_POST['action'] == 'bulk_delete') {
        $selectedIdsStr = $_POST['selected_ids'] ?? '';
        if (empty($selectedIdsStr)) {
            header("Location: members.php?error=" . urlencode("No members selected for deletion."));
            exit();
        }

        $selectedIds = array_filter(array_map('intval', explode(',', $selectedIdsStr)));
        // Filter out ID 1 (root user) to be absolutely safe
        $selectedIds = array_diff($selectedIds, [1]);

        if (empty($selectedIds)) {
            header("Location: members.php?error=" . urlencode("Cannot delete root admin."));
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

        $db->beginTransaction();
        try {
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Delete from genealogy
            $stmtGen = $db->prepare("DELETE FROM genealogy WHERE user_id IN ($placeholders) OR parent_id IN ($placeholders)");
            $stmtGen->execute(array_merge($selectedIds, $selectedIds));

            // Delete from investments
            $stmtInv = $db->prepare("DELETE FROM investments WHERE user_id IN ($placeholders)");
            $stmtInv->execute($selectedIds);

            // Delete from transactions
            $stmtTx = $db->prepare("DELETE FROM transactions WHERE user_id IN ($placeholders) OR related_user_id IN ($placeholders)");
            $stmtTx->execute(array_merge($selectedIds, $selectedIds));

            // Delete from user_wallets
            $stmtWallets = $db->prepare("DELETE FROM user_wallets WHERE user_id IN ($placeholders)");
            $stmtWallets->execute($selectedIds);

            // Delete from matching_schedules
            $stmtScheds = $db->prepare("DELETE FROM matching_schedules WHERE user_id IN ($placeholders)");
            $stmtScheds->execute($selectedIds);

            // Delete from pins
            $stmtPins = $db->prepare("DELETE FROM pins WHERE used_by IN ($placeholders) OR assigned_to IN ($placeholders)");
            $stmtPins->execute(array_merge($selectedIds, $selectedIds));

            // Delete from users
            $stmtUsers = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
            $stmtUsers->execute($selectedIds);

            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $db->commit();

            header("Location: members.php?success=bulk_deleted&count=" . count($selectedIds));
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: members.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

$pageTitle = 'Member Management';
include __DIR__ . '/includes/header.php';

// Fetch lists for filters
$config = require __DIR__ . '/../includes/config.php';
$packagesList = $config['packages'];
$ranksList = $config['ranks'];

$search = $_GET['search'] ?? '';
$rankFilter = $_GET['rank_id'] ?? '';
$packageFilter = $_GET['package_amount'] ?? '';

// Build dynamic query
$query = "SELECT DISTINCT u.* FROM users u";
$params = [];
$joins = [];
$conditions = [];

if (!empty($packageFilter)) {
    $joins[] = "JOIN investments i ON u.id = i.user_id";
    $conditions[] = "i.amount = ?";
    $params[] = $packageFilter;
}

if ($search !== '') {
    $conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($rankFilter !== '') {
    $conditions[] = "u.rank_id = ?";
    $params[] = $rankFilter;
}

$joinStr = implode(" ", $joins);
$whereStr = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
$query .= " {$joinStr} {$whereStr} ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll();
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Members List</h3>
        <div class="d-flex gap-2">
            <!-- Standalone Bulk Delete Form -->
            <form method="post" id="bulk-delete-form" class="d-inline" onsubmit="return confirm('Are you sure you want to delete the selected members? All their historical transactions, genealogy data, and investments will be permanently removed!');">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="selected_ids" id="selected-ids-input" value="">
                <button type="submit" id="btn-bulk-delete" class="btn btn-danger d-none">
                    <i class="fa fa-trash me-1"></i>Delete Selected (<span id="checked-count">0</span>)
                </button>
            </form>

            <form method="post" onsubmit="return confirm('WARNING: This will permanently delete all members (except root user), their downlines, genealogy trees, and all historical transactions, investments, matching schedules, and incomes! This action is irreversible. Are you absolutely sure?');">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                <input type="hidden" name="action" value="clear_system">
                <button type="submit" class="btn btn-outline-danger"><i class="fa fa-trash-alt me-1"></i>Reset System (Clear All Except Root)</button>
            </form>
        </div>
    </div>

    <!-- Dynamic Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search username / email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-3">
            <select name="rank_id" class="form-select">
                <option value="">-- All Ranks --</option>
                <option value="0" <?php echo $rankFilter === '0' ? 'selected' : ''; ?>>None / No Rank</option>
                <?php foreach($ranksList as $idx => $rConf): ?>
                    <option value="<?php echo $idx + 1; ?>" <?php echo $rankFilter == ($idx + 1) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rConf['name']); ?> (Slab $<?php echo number_format($rConf['matching']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="package_amount" class="form-select">
                <option value="">-- All Packages --</option>
                <?php foreach($packagesList as $pkgAmt): ?>
                    <option value="<?php echo $pkgAmt; ?>" <?php echo $packageFilter == $pkgAmt ? 'selected' : ''; ?>>
                        Package $<?php echo number_format($pkgAmt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="members.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if(isset($_GET['success']) && $_GET['success'] == 'system_cleared'): ?>
    <div class="alert alert-success"><strong>System Reset Complete!</strong> All members (except root user) and their associated genealogy tree, packages, investments, and transactional data have been securely deleted.</div>
<?php elseif(isset($_GET['success']) && $_GET['success'] == 'bulk_deleted'): ?>
    <div class="alert alert-success"><strong>Bulk Delete Complete!</strong> Successfully deleted <strong><?php echo htmlspecialchars($_GET['count'] ?? '0'); ?></strong> selected member(s) and cleared their associated records.</div>
<?php elseif(isset($_GET['success'])): ?>
    <div class="alert alert-success">Action completed successfully.</div>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
    <div class="alert alert-danger">Error: <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th width="40" class="text-center">
                            <input type="checkbox" id="check-all" class="form-check-input">
                        </th>
                        <th>MID (Member Code)</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Rank</th>
                        <th>Total Invested</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m):
                        // Fetch the user's packages
                        $stmtPkg = $db->prepare("SELECT p.name, i.amount FROM investments i JOIN packages p ON i.package_id = p.id WHERE i.user_id = ?");
                        $stmtPkg->execute([$m['id']]);
                        $userPkgs = $stmtPkg->fetchAll();
                        $pkgNames = [];
                        foreach ($userPkgs as $up) {
                            $pkgNames[] = $up['name'] . " (\$" . number_format($up['amount'], 2) . ")";
                        }
                        $pkgStr = empty($pkgNames) ? 'None' : implode(', ', $pkgNames);
                        $fullAddress = trim(($m['address'] ?? '') . ' ' . ($m['state'] ?? '') . ' ' . ($m['country'] ?? ''));
                        if (empty($fullAddress)) {
                            $fullAddress = 'Not Provided';
                        }
                    ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="member-checkbox form-check-input" value="<?php echo $m['id']; ?>" <?php echo $m['id'] == 1 ? 'disabled title="Cannot delete root user"' : ''; ?>>
                        </td>
                        <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'None'); ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($m['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($m['email']); ?></td>
                        <td>
                            <span class="badge bg-info" style="font-size: 13px;">
                                <?php echo ($m['rank_id'] > 0 && isset($ranksList[$m['rank_id']-1])) ? htmlspecialchars($ranksList[$m['rank_id']-1]['name']) : 'None'; ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars($m['status']) == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo htmlspecialchars(strtoupper($m['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <div class="d-flex gap-2">
                                <!-- Preview Trigger Button -->
                                <button type="button" class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#previewModal"
                                    data-username="<?php echo htmlspecialchars($m['username']); ?>"
                                    data-mid="<?php echo htmlspecialchars($m['mid'] ?? 'None'); ?>"
                                    data-email="<?php echo htmlspecialchars($m['email']); ?>"
                                    data-phone="<?php echo htmlspecialchars($m['phone'] ?? 'Not Provided'); ?>"
                                    data-address="<?php echo htmlspecialchars($fullAddress); ?>"
                                    data-joined="<?php echo date('Y-m-d H:i:s', strtotime($m['created_at'])); ?>"
                                    data-packages="<?php echo htmlspecialchars($pkgStr); ?>">
                                    <i class="fa fa-eye"></i> Preview
                                </button>

                                <form method="post" class="d-inline mb-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <?php if($m['status'] == 'active'): ?>
                                        <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="previewModalLabel"><i class="fa fa-user me-2"></i>Member Profile Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-bordered mb-0 align-middle">
                    <tr>
                        <th class="bg-light" width="35%">Member ID (MID)</th>
                        <td id="preview-mid" class="font-monospace fw-bold text-primary"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Username</th>
                        <td id="preview-username" class="fw-bold"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Email</th>
                        <td id="preview-email"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Phone</th>
                        <td id="preview-phone"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Address</th>
                        <td id="preview-address"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Joining Date</th>
                        <td id="preview-joined"></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Joined Packages</th>
                        <td><span id="preview-packages" class="text-secondary fw-bold" style="white-space: normal; display: inline-block;"></span></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Checkbox toggling and bulk delete logic
    const checkAll = document.getElementById('check-all');
    const memberCheckboxes = document.querySelectorAll('.member-checkbox');
    const selectedIdsInput = document.getElementById('selected-ids-input');
    const checkedCountSpan = document.getElementById('checked-count');
    const btnBulkDelete = document.getElementById('btn-bulk-delete');

    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const isChecked = this.checked;
            memberCheckboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = isChecked;
                }
            });
            updateBulkDeleteState();
        });
    }

    memberCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkDeleteState);
    });

    function updateBulkDeleteState() {
        const checkedBoxes = document.querySelectorAll('.member-checkbox:checked');
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        if (selectedIdsInput) {
            selectedIdsInput.value = ids.join(',');
        }
        if (checkedCountSpan) {
            checkedCountSpan.textContent = ids.length;
        }
        if (btnBulkDelete) {
            if (ids.length > 0) {
                btnBulkDelete.classList.remove('d-none');
            } else {
                btnBulkDelete.classList.add('d-none');
            }
        }
    }

    // Modal populate logic
    const previewModal = document.getElementById('previewModal');
    if (previewModal) {
        previewModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;

            const username = button.getAttribute('data-username') || 'N/A';
            const mid = button.getAttribute('data-mid') || 'N/A';
            const email = button.getAttribute('data-email') || 'N/A';
            const phone = button.getAttribute('data-phone') || 'N/A';
            const address = button.getAttribute('data-address') || 'N/A';
            const joined = button.getAttribute('data-joined') || 'N/A';
            const packages = button.getAttribute('data-packages') || 'None';

            document.getElementById('preview-username').textContent = username;
            document.getElementById('preview-mid').textContent = mid;
            document.getElementById('preview-email').textContent = email;
            document.getElementById('preview-phone').textContent = phone;
            document.getElementById('preview-address').textContent = address;
            document.getElementById('preview-joined').textContent = joined;
            document.getElementById('preview-packages').textContent = packages;
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
