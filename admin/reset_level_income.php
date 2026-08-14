<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
$config = require __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// CSRF Token setup
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$searchQuery = trim($_GET['search'] ?? '');
$targetUser = null;
$userLevelIncomes = [];

if (!empty($searchQuery)) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR mid = ?");
    $stmt->execute([$searchQuery, $searchQuery]);
    $targetUser = $stmt->fetch();

    if ($targetUser) {
        $stmtIncomes = $db->prepare("
            SELECT t.*, u.username as originating_user
            FROM transactions t
            LEFT JOIN users u ON t.related_user_id = u.id
            WHERE t.user_id = ? AND t.type = 'LEVEL_INCOME'
            ORDER BY t.created_at DESC
        ");
        $stmtIncomes->execute([$targetUser['id']]);
        $userLevelIncomes = $stmtIncomes->fetchAll();
    } else {
        $message = "No member found matching Username or MID: " . htmlspecialchars($searchQuery);
        $messageType = "danger";
    }
}

/**
 * Local helper to fetch allowable income based on the 300% ID Cap multiplier.
 */
function localGetAllowableAmount($db, $config, $userId, $amountToAdd) {
    $stmt = $db->prepare("SELECT total_investment, (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned FROM users WHERE id = ?");
    $stmt->execute([$userId, $userId]);
    $user = $stmt->fetch();

    if (!$user) return 0;

    $maxCap = $user['total_investment'] * $config['id_cap_multiplier'];
    $remainingCap = $maxCap - $user['total_earned'];

    if ($remainingCap <= 0) return 0;
    return min($amountToAdd, $remainingCap);
}

/**
 * Local helper to log transaction entry.
 */
function localLogTransaction($db, $userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null) {
    $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);
    $netAmount = $isDebit ? -($amount + $fee) : ($amount - $fee);

    $stmt = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description]);
}

/**
 * Local level income distribution traversing strictly via the sponsor_id (referral / ref ID) chain.
 */
function localDistributeLevelIncome($db, $config, $userId, $investmentAmount) {
    $currentId = $userId;
    for ($level = 1; $level <= 12; $level++) {
        $stmt = $db->prepare("SELECT sponsor_id FROM users WHERE id = ?");
        $stmt->execute([$currentId]);
        $u = $stmt->fetch();
        if (!$u || empty($u['sponsor_id'])) {
            break;
        }
        $parentId = $u['sponsor_id'];

        if (isset($config['level_percentages'][$level])) {
            $percentage = $config['level_percentages'][$level];
            $commission = ($investmentAmount * $percentage) / 100;

            $allowable = localGetAllowableAmount($db, $config, $parentId, $commission);
            if ($allowable > 0) {
                localLogTransaction($db, $parentId, 'LEVEL_INCOME', $allowable, 0, "Level {$level} income from user ID: {$userId}", $userId, null, $level);
            }
        }
        $currentId = $parentId;
    }
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF validation failed.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'reset_single' && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];

        $db->beginTransaction();
        try {
            // Get user details
            $stmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $uDetails = $stmtUser->fetch();

            if ($uDetails) {
                // Delete Level Income transactions for this user
                $stmtDel = $db->prepare("DELETE FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME'");
                $stmtDel->execute([$userId]);

                $db->commit();
                $message = "Successfully deleted all LEVEL_INCOME transactions for member: <strong>" . htmlspecialchars($uDetails['username']) . "</strong>.";
                $messageType = "success";

                // Refresh search
                $targetUser = null;
                $userLevelIncomes = [];
                $searchQuery = '';
            } else {
                $db->rollBack();
                $message = "Member not found.";
                $messageType = "danger";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "Failed to reset level income: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($action === 'reset_all') {
        $confirmString = trim($_POST['confirm_string'] ?? '');
        if ($confirmString !== 'RESET ALL') {
            $message = "Confirmation failed. You must type <strong>RESET ALL</strong> exactly to execute a global reset.";
            $messageType = "danger";
        } else {
            $db->beginTransaction();
            try {
                // Delete all LEVEL_INCOME transactions across the entire system
                $db->exec("DELETE FROM transactions WHERE type = 'LEVEL_INCOME'");
                $db->commit();

                $message = "<strong>Global Reset Successful!</strong> All LEVEL_INCOME transactions have been deleted system-wide.";
                $messageType = "success";

                $targetUser = null;
                $userLevelIncomes = [];
                $searchQuery = '';
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "Global level income reset failed: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    } elseif ($action === 'recalculate_all') {
        $confirmString = trim($_POST['confirm_string'] ?? '');
        if ($confirmString !== 'RECALCULATE ALL') {
            $message = "Confirmation failed. You must type <strong>RECALCULATE ALL</strong> exactly to execute a global recalculation.";
            $messageType = "danger";
        } else {
            $db->beginTransaction();
            try {
                // Delete all LEVEL_INCOME transactions across the entire system
                $db->exec("DELETE FROM transactions WHERE type = 'LEVEL_INCOME'");

                // Fetch all investments chronologically
                $stmtInvest = $db->query("SELECT id, user_id, amount, created_at FROM investments ORDER BY created_at ASC, id ASC");
                $investments = $stmtInvest->fetchAll();

                $recalculatedCount = 0;

                foreach ($investments as $inv) {
                    $stmtMax = $db->query("SELECT MAX(id) FROM transactions");
                    $prevMaxId = $stmtMax->fetchColumn() ?: 0;

                    // Call the local independent level income distribution function
                    localDistributeLevelIncome($db, $config, $inv['user_id'], $inv['amount']);

                    // Update newly created level income transactions to match the investment's created_at
                    $stmtUpdate = $db->prepare("UPDATE transactions SET created_at = ? WHERE id > ? AND type = 'LEVEL_INCOME'");
                    $stmtUpdate->execute([$inv['created_at'], $prevMaxId]);

                    $recalculatedCount++;
                }

                $db->commit();
                $message = "<strong>Global Recalculation Successful!</strong> All level incomes have been wiped and chronologically recalculated for {$recalculatedCount} investments.";
                $messageType = "success";

                $targetUser = null;
                $userLevelIncomes = [];
                $searchQuery = '';
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "Global recalculation failed: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// Fetch some stats for overview
$stmtStats = $db->prepare("SELECT COUNT(*) as total_count, COALESCE(SUM(amount), 0) as total_amount FROM transactions WHERE type = 'LEVEL_INCOME'");
$stmtStats->execute();
$overallStats = $stmtStats->fetch();

$pageTitle = 'Reset Level Income';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid p-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="fa fa-undo-alt text-warning me-2"></i>Reset & Recalculate Level Income</h2>
                <span class="badge bg-dark p-2 text-white">System Level Income Transactions</span>
            </div>
            <p class="text-muted">Search and delete specific level income logs for a member, perform a global system-wide level income reset, or recalculate all level incomes chronologically.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <!-- Overview Cards -->
        <div class="col-md-4">
            <div class="card bg-primary text-white card-stat p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Total Level Logs</h5>
                        <h3><?php echo number_format($overallStats['total_count']); ?></h3>
                    </div>
                    <i class="fa fa-list-alt fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white card-stat p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Total Level Payout</h5>
                        <h3>$<?php echo number_format($overallStats['total_amount'], 2); ?></h3>
                    </div>
                    <i class="fa fa-dollar-sign fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Search and Single Reset Column -->
        <div class="col-lg-8 mb-4">
            <div class="card p-4 shadow-sm">
                <h4 class="card-title mb-3"><i class="fa fa-search me-2 text-info"></i>Search Member Level Income</h4>
                <form method="get" class="row g-3">
                    <div class="col-md-9">
                        <input type="text" name="search" class="form-control" placeholder="Enter Username or Member Code (MID)..." value="<?php echo htmlspecialchars($searchQuery); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-info text-white w-100"><i class="fa fa-search me-2"></i>Search</button>
                    </div>
                </form>

                <?php if ($targetUser): ?>
                    <div class="mt-4 p-3 border rounded bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-0">Member: <strong><?php echo htmlspecialchars($targetUser['username']); ?></strong> (<?php echo htmlspecialchars($targetUser['mid'] ?? 'MID: '.$targetUser['id']); ?>)</h5>
                                <p class="text-muted mb-0">Total Level Income Transactions Found: <?php echo count($userLevelIncomes); ?></p>
                            </div>
                            <form method="post" onsubmit="return confirm('WARNING: Are you absolutely sure you want to permanently delete all level income records for <?php echo htmlspecialchars($targetUser['username']); ?>? This action is irreversible.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="reset_single">
                                <input type="hidden" name="user_id" value="<?php echo $targetUser['id']; ?>">
                                <button type="submit" class="btn btn-danger"><i class="fa fa-trash-alt me-2"></i>Reset This Member's Level Income</button>
                            </form>
                        </div>

                        <?php if (!empty($userLevelIncomes)): ?>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-striped align-middle table-sm mt-2">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Originating User</th>
                                            <th>Level</th>
                                            <th>Amount</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userLevelIncomes as $inc): ?>
                                            <tr>
                                                <td><?php echo date('d M, Y h:i a', strtotime($inc['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($inc['originating_user'] ?? 'System/N/A'); ?></td>
                                                <td>Level <?php echo htmlspecialchars($inc['level']); ?></td>
                                                <td class="text-success fw-bold">$<?php echo number_format($inc['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($inc['description']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">No Level Income transactions logged for this member.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Global Operations Column -->
        <div class="col-lg-4 mb-4">
            <!-- Global Recalculate Card -->
            <div class="card p-4 border border-success shadow-sm mb-4">
                <h4 class="card-title text-success mb-3"><i class="fa fa-sync me-2"></i>Global Recalculate</h4>
                <p class="text-muted">Wipes out all level incomes and chronologically regenerates all level income transactions for all system investments. Recommended for refreshing/correcting entire level commissions history.</p>

                <form method="post" onsubmit="return confirm('DANGER WARNING: You are about to wipe and chronologically RECALCULATE all Level Income transactions system-wide. Are you absolutely certain? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="recalculate_all">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Type "RECALCULATE ALL" to confirm:</label>
                        <input type="text" name="confirm_string" class="form-control" placeholder="RECALCULATE ALL" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold"><i class="fa fa-sync me-2"></i>EXECUTE GLOBAL RECALCULATION</button>
                </form>
            </div>

            <!-- Global Reset Card -->
            <div class="card p-4 border border-danger shadow-sm">
                <h4 class="card-title text-danger mb-3"><i class="fa fa-exclamation-triangle me-2"></i>Global Reset (Delete Only)</h4>
                <p class="text-muted">Deleting all Level Income records across the entire system is an irreversible database operation. This will only clear the records without rebuilding them.</p>

                <form method="post" onsubmit="return confirm('DANGER WARNING: You are about to delete ALL Level Income transactions system-wide. Are you absolutely certain? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="reset_all">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Type "RESET ALL" to confirm:</label>
                        <input type="text" name="confirm_string" class="form-control" placeholder="RESET ALL" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-danger w-100 py-2 fw-bold"><i class="fa fa-radiation me-2"></i>EXECUTE GLOBAL RESET</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
