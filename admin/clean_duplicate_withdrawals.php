<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'Clean Duplicate Withdrawals';
include __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';
$duplicates_found = [];
$deleted_count = 0;

// Identify duplicates
try {
    // We search for withdrawals where the same user has multiple entries with the exact same amount and net_amount on the same day.
    $stmt = $db->query("
        SELECT
            t1.user_id,
            u.username,
            u.mid,
            t1.amount,
            DATE(t1.created_at) as withdrawal_date,
            COUNT(*) as occurrences,
            MIN(t1.id) as keep_id
        FROM
            transactions t1
        JOIN
            users u ON t1.user_id = u.id
        WHERE
            t1.type = 'WITHDRAWAL'
        GROUP BY
            t1.user_id, t1.amount, DATE(t1.created_at)
        HAVING
            COUNT(*) > 1
    ");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as $group) {
        // Fetch all transaction records in this group to display
        $stmtDetail = $db->prepare("
            SELECT id, created_at, amount, fee, net_amount, description
            FROM transactions
            WHERE user_id = ? AND type = 'WITHDRAWAL' AND amount = ? AND DATE(created_at) = ?
            ORDER BY id ASC
        ");
        $stmtDetail->execute([$group['user_id'], $group['amount'], $group['withdrawal_date']]);
        $txs = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

        $duplicates_found[] = [
            'group' => $group,
            'transactions' => $txs
        ];
    }
} catch (Exception $e) {
    $error_msg = "Error scanning for duplicates: " . $e->getMessage();
}

// Handle deletion action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $db->beginTransaction();
        try {
            foreach ($duplicates_found as $dup) {
                $keepId = $dup['group']['keep_id'];
                $userId = $dup['group']['user_id'];
                $amount = $dup['group']['amount'];
                $wDate = $dup['group']['withdrawal_date'];

                // Delete all other transactions in this group except the keep_id
                $stmtDel = $db->prepare("
                    DELETE FROM transactions
                    WHERE user_id = ?
                      AND type = 'WITHDRAWAL'
                      AND amount = ?
                      AND DATE(created_at) = ?
                      AND id != ?
                ");
                $stmtDel->execute([$userId, $amount, $wDate, $keepId]);
                $deleted_count += $stmtDel->rowCount();
            }

            $db->commit();
            $success_msg = "Successfully cleaned duplicate withdrawals! Deleted {$deleted_count} repeated transaction records.";

            // Refresh list after deletion
            $duplicates_found = [];
        } catch (Exception $e) {
            $db->rollBack();
            $error_msg = "Error deleting duplicates: " . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-4">
                <div class="card-header border-0 bg-transparent pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="text-dark fw-bold mb-1">Clean Duplicate Withdrawals</h2>
                        <p class="text-muted">Locates and deletes duplicate WITHDRAWAL transactions (same user, same amount, same day) resulting from multi-clicks or recalculation loops while retaining the original transaction record.</p>
                    </div>
                </div>

                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <strong>Success!</strong> <?php echo htmlspecialchars($success_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> <?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($duplicates_found)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fa fa-info-circle fa-2x mb-2 text-info"></i>
                            <h4 class="text-dark">No Duplicate Withdrawals Found</h4>
                            <p class="mb-0 text-muted">All withdrawal records appear correct and unique.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-4">
                            <strong>Warning!</strong> Found <strong><?php echo count($duplicates_found); ?> group(s)</strong> of duplicate withdrawals. Preview the repeated entries below before executing the cleanup.
                        </div>

                        <form method="post" class="mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                            <input type="hidden" name="action" value="clean">
                            <button type="submit" class="btn btn-danger btn-lg px-4" onclick="return confirm('Are you sure you want to permanently delete the redundant repeated withdrawal records? This action is irreversible.');">
                                <i class="fa fa-trash me-2"></i>Delete Duplicate Withdrawals (Keep Only Original)
                            </button>
                        </form>

                        <?php foreach ($duplicates_found as $idx => $dup): ?>
                            <div class="card border border-warning mb-4 shadow-sm">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 text-dark">
                                        Group #<?php echo $idx + 1; ?>:
                                        <strong><?php echo htmlspecialchars($dup['group']['username']); ?> (MID: <?php echo htmlspecialchars($dup['group']['mid']); ?>)</strong>
                                    </h5>
                                    <span class="badge bg-warning text-dark" style="font-size: 14px;">
                                        Amount: $<?php echo number_format($dup['group']['amount'], 2); ?> | Date: <?php echo $dup['group']['withdrawal_date']; ?>
                                    </span>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-striped table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>TX ID</th>
                                                <th>Created At</th>
                                                <th>Amount</th>
                                                <th>Fee</th>
                                                <th>Net Amount</th>
                                                <th>Description</th>
                                                <th>Action State</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dup['transactions'] as $txIndex => $tx):
                                                $isKeep = ($tx['id'] == $dup['group']['keep_id']);
                                            ?>
                                                <tr class="<?php echo $isKeep ? 'table-success' : 'table-danger opacity-75'; ?>">
                                                    <td>#<?php echo $tx['id']; ?></td>
                                                    <td><?php echo date('d M, Y h:i:s a', strtotime($tx['created_at'])); ?></td>
                                                    <td>$<?php echo number_format($tx['amount'], 2); ?></td>
                                                    <td>$<?php echo number_format($tx['fee'], 2); ?></td>
                                                    <td>$<?php echo number_format($tx['net_amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                                    <td>
                                                        <?php if ($isKeep): ?>
                                                            <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> KEEP ORIGINAL</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger"><i class="fa fa-times-circle me-1"></i> WILL BE DELETED</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
