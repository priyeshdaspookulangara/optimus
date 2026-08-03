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
$statusFilter = $_GET['status'] ?? '';

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

if ($statusFilter !== '') {
    $conditions[] = "u.status = ?";
    $params[] = $statusFilter;
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
        <form method="post" onsubmit="return confirm('WARNING: This will permanently delete all members (except root user), their downlines, genealogy trees, and all historical transactions, investments, matching schedules, and incomes! This action is irreversible. Are you absolutely sure?');">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="clear_system">
            <button type="submit" class="btn btn-danger"><i class="fa fa-trash-alt me-1"></i>Reset System (Clear All Except Root)</button>
        </form>
    </div>

    <!-- Dynamic Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-2">
            <input type="text" name="search" class="form-control" placeholder="Search username..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">-- All Statuses --</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
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
        <div class="col-md-2">
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
                    <?php foreach($members as $m): ?>
                    <tr>
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
                            <?php if ($m['status'] == 'active'): ?>
                                <span class="badge bg-success">ACTIVE</span>
                            <?php elseif ($m['status'] == 'inactive'): ?>
                                <span class="badge bg-secondary text-white">INACTIVE</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?php echo htmlspecialchars(strtoupper($m['status'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <?php if ($m['id'] == 1): ?>
                                <span class="text-muted">System Root</span>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <?php if ($m['status'] == 'active'): ?>
                                        <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-danger me-1">Suspend</button>
                                        <button type="submit" name="status" value="inactive" class="btn btn-sm btn-outline-warning text-dark">Deactivate</button>
                                    <?php elseif ($m['status'] == 'inactive'): ?>
                                        <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success me-1">Activate</button>
                                        <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success me-1">Activate</button>
                                        <button type="submit" name="status" value="inactive" class="btn btn-sm btn-outline-warning text-dark">Deactivate</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
