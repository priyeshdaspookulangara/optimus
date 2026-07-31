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

// Build dynamic query
$query = "SELECT DISTINCT u.*,
          (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND type = 'ROI') as total_roi,
          (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND type = 'LEVEL_INCOME') as total_level,
          (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND type = 'RANK_INCOME') as total_rank
          FROM users u";
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
        <form method="post" onsubmit="return confirm('WARNING: This will permanently delete all members (except root user), their downlines, genealogy trees, and all historical transactions, investments, matching schedules, and incomes! This action is irreversible. Are you absolutely sure?');">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="clear_system">
            <button type="submit" class="btn btn-danger"><i class="fa fa-trash-alt me-1"></i>Reset System (Clear All Except Root)</button>
        </form>
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
                        <th>Total Earnings</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): ?>
                    <?php
                        $roi = (float)$m['total_roi'];
                        $level = (float)$m['total_level'];
                        $rank = (float)$m['total_rank'];
                        $sumEarnings = $roi + $level + $rank;
                    ?>
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
                            <button type="button" class="btn btn-sm btn-outline-success font-weight-bold" data-bs-toggle="modal" data-bs-target="#earningsModal<?php echo $m['id']; ?>">
                                $<?php echo number_format($sumEarnings, 2); ?> <i class="fa fa-chart-pie ms-1"></i>
                            </button>

                            <!-- Earnings Composition Modal -->
                            <div class="modal fade" id="earningsModal<?php echo $m['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content text-dark">
                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title"><i class="fa fa-chart-pie me-2"></i> Earnings Composition Report</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <div class="text-center mb-4">
                                                <h6 class="text-muted text-uppercase mb-1" style="font-size: 12px;">Total Accumulated Earnings</h6>
                                                <h2 class="text-success font-weight-bold">$<?php echo number_format($sumEarnings, 2); ?></h2>
                                                <small class="text-muted">Account: <strong><?php echo htmlspecialchars($m['username']); ?></strong> (<?php echo htmlspecialchars($m['mid']); ?>)</small>
                                            </div>

                                            <ul class="list-group list-group-flush">
                                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                    <span><i class="fa fa-circle text-primary me-2" style="font-size: 8px;"></i> Passive Trade Profits (ROI)</span>
                                                    <span class="font-weight-bold">$<?php echo number_format($roi, 2); ?></span>
                                                </li>
                                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                    <span><i class="fa fa-circle text-success me-2" style="font-size: 8px;"></i> Unilevel Referral Commissions</span>
                                                    <span class="font-weight-bold">$<?php echo number_format($level, 2); ?></span>
                                                </li>
                                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                    <span><i class="fa fa-circle text-warning me-2" style="font-size: 8px;"></i> Slab Matching Ranks Income</span>
                                                    <span class="font-weight-bold">$<?php echo number_format($rank, 2); ?></span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close Report</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars($m['status']) == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo htmlspecialchars(strtoupper($m['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <?php if($m['status'] == 'active'): ?>
                                    <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
                                <?php else: ?>
                                    <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
