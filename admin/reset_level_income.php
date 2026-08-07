<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication Check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$successMessage = '';
$errorMessage = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMessage = "CSRF token validation failed.";
    } elseif ($_POST['action'] === 'reset_single') {
        $userId = (int)$_POST['user_id'];

        try {
            $db->beginTransaction();

            // Check if user exists
            $stmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();

            if (!$user) {
                throw new Exception("Selected member does not exist.");
            }

            // Delete LEVEL_INCOME transactions for this user
            $stmtDelete = $db->prepare("DELETE FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME'");
            $stmtDelete->execute([$userId]);
            $count = $stmtDelete->rowCount();

            $db->commit();
            $successMessage = "Successfully reset level income for user '" . htmlspecialchars($user['username']) . "'. Deleted $count level income transaction(s).";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'reset_all') {
        try {
            $db->beginTransaction();

            // Delete ALL LEVEL_INCOME transactions
            $stmtDelete = $db->prepare("DELETE FROM transactions WHERE type = 'LEVEL_INCOME'");
            $stmtDelete->execute();
            $count = $stmtDelete->rowCount();

            $db->commit();
            $successMessage = "Successfully reset level income for ALL members. Deleted $count level income transaction(s) globally.";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    }
}

// Search input
$searchQuery = $_GET['search'] ?? '';
$members = [];

if (!empty($searchQuery)) {
    $stmt = $db->prepare("
        SELECT id, username, mid, email, total_investment, status,
               (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = users.id AND type = 'LEVEL_INCOME') as total_level_income
        FROM users
        WHERE username LIKE ? OR mid LIKE ?
        ORDER BY username ASC
    ");
    $stmt->execute(["%$searchQuery%", "%$searchQuery%"]);
    $members = $stmt->fetchAll();
} else {
    // If no search query, fetch top 15 earners of level income for overview
    $stmt = $db->prepare("
        SELECT id, username, mid, email, total_investment, status,
               (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = users.id AND type = 'LEVEL_INCOME') as total_level_income
        FROM users
        ORDER BY total_level_income DESC, username ASC
        LIMIT 15
    ");
    $stmt->execute();
    $members = $stmt->fetchAll();
}

$pageTitle = 'Reset Level Income';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Reset Level Income Tool</h3>
        <form method="post" class="d-inline" onsubmit="return confirm('WARNING: This will permanently delete ALL Level Income transactions for ALL users in the entire system! This action is irreversible. Are you absolutely sure?');">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="reset_all">
            <button type="submit" class="btn btn-danger"><i class="fa fa-exclamation-triangle me-1"></i> Reset Level Income for ALL Users</button>
        </form>
    </div>

    <!-- Search/Filter -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-9">
            <input type="text" name="search" class="form-control" placeholder="Search user to reset by username or MID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>Search</button>
            <a href="reset_level_income.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if (!empty($successMessage)): ?>
    <div class="alert alert-success"><strong>Success:</strong> <?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fa fa-info-circle me-1"></i> Instructions & Safety Safeguards</h5>
    </div>
    <div class="card-body">
        <p class="mb-1">This tool allows you to securely reset / clear the level income transactions of a specific member or all members globally.</p>
        <p class="mb-0 text-muted">Resetting level income deletes all associated level income logs in the transactions table, restoring the wallet contributions of those specific rows to zero. <strong>All database updates are secured under transactional integrity.</strong></p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?php echo empty($searchQuery) ? 'Top Level Income Earners' : 'Search Results'; ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>MID (Member Code)</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Total Invested</th>
                        <th>Total Level Income</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No users found. Try searching with a different term.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($members as $m): ?>
                        <tr>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'None'); ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($m['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($m['email']); ?></td>
                            <td>
                                <span class="badge <?php echo htmlspecialchars($m['status']) == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo htmlspecialchars(strtoupper($m['status'])); ?>
                                </span>
                            </td>
                            <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                            <td class="text-success fw-bold">$<?php echo number_format($m['total_level_income'], 2); ?></td>
                            <td>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently reset and delete all level income for <?php echo htmlspecialchars($m['username']); ?>? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                    <input type="hidden" name="action" value="reset_single">
                                    <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" <?php echo $m['total_level_income'] <= 0 ? 'disabled' : ''; ?>>
                                        <i class="fa fa-trash-alt me-1"></i> Reset Income
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
