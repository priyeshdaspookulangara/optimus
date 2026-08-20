<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

$pageTitle = 'Propagate Rank Income';
include __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';
$propagate_log = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action == 'propagate_all') {
            $propagate_log[] = "Initializing Rank Income Propagation up to 2 Upline Sponsor Levels...";

            $db->beginTransaction();
            try {
                // Fetch active matching schedules
                $stmtScheds = $db->query("
                    SELECT ms.*, u.username, u.status as user_status
                    FROM matching_schedules ms
                    JOIN users u ON ms.user_id = u.id
                    WHERE ms.status = 'active' OR ms.days_passed > 0
                    ORDER BY ms.id ASC
                ");
                $schedules = $stmtScheds->fetchAll(PDO::FETCH_ASSOC);

                $totalPropagatedCount = 0;

                $stmtUplines = $db->prepare("
                    SELECT g.parent_id, g.level, u.username, u.status, u.rank_id
                    FROM genealogy g
                    JOIN users u ON g.parent_id = u.id
                    WHERE g.user_id = ? AND g.level <= 2
                    ORDER BY g.level ASC
                ");

                $stmtCheckTx = $db->prepare("
                    SELECT COUNT(*) as tx_count
                    FROM transactions
                    WHERE user_id = ? AND related_user_id = ? AND type = 'RANK_INCOME' AND description LIKE ?
                ");

                $stmtTx = $db->prepare("
                    INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at)
                    VALUES (?, ?, NULL, ?, 'RANK_INCOME', ?, 0.00, ?, ?, NOW())
                ");

                foreach ($schedules as $sched) {
                    $uid = $sched['user_id'];
                    $uname = $sched['username'];
                    $slab = (float)$sched['slab_amount'];
                    $dailyIncome = (float)$sched['daily_income'];
                    $daysPassed = (int)$sched['days_passed'];

                    if ($daysPassed <= 0) continue;

                    // Required rank level matching this slab
                    $requiredRankId = 0;
                    foreach ($config['ranks'] as $idx => $rankConf) {
                        if ($rankConf['matching'] == $slab) {
                            $requiredRankId = $idx + 1;
                            break;
                        }
                    }

                    // Fetch 2 upline sponsor levels
                    $stmtUplines->execute([$uid]);
                    $uplines = $stmtUplines->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($uplines as $upline) {
                        $parent_id = $upline['parent_id'];
                        $level = (int)$upline['level'];
                        $pUsername = $upline['username'];
                        $pStatus = $upline['status'];

                        // Check qualification: active status and rank qualification
                        if ($pStatus === 'active') {
                            for ($day = 1; $day <= $daysPassed; $day++) {
                                $pDesc = "Daily Propagated Match Income from " . $uname . " (Slab \$" . number_format($slab, 2) . ") - Day " . $day;
                                $searchLike = "%Propagated Match Income from " . $uname . " (Slab \$" . number_format($slab, 2) . ") - Day " . $day . "%";

                                $stmtCheckTx->execute([$parent_id, $uid, $searchLike]);
                                $existing = $stmtCheckTx->fetch(PDO::FETCH_ASSOC);

                                if ((int)$existing['tx_count'] == 0) {
                                    $stmtTx->execute([$parent_id, $uid, $level, $dailyIncome, $dailyIncome, $pDesc]);
                                    $totalPropagatedCount++;
                                    $propagate_log[] = "Propagated \$" . number_format($dailyIncome, 2) . " to @{$pUsername} (Level {$level}) from @{$uname}'s Slab \$" . number_format($slab, 2) . " (Day {$day}).";
                                }
                            }
                        }
                    }
                }

                $db->commit();
                $success_msg = "Propagation completed successfully! Total transactions logged: " . $totalPropagatedCount;
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error during Rank Propagation: " . $e->getMessage();
            }
        }
    }
}

// Fetch active contract summary for preview table
$stmtSummary = $db->query("
    SELECT ms.*, u.username, u.rank_id
    FROM matching_schedules ms
    JOIN users u ON ms.user_id = u.id
    ORDER BY ms.id DESC
");
$allContracts = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card bg-dark text-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2><i class="fa fa-network-wired me-2 text-primary"></i>Propagate Rank Income Uplines</h2>
                </div>
                <p class="text-muted">
                    This page propagates daily matching rank income **up to 2 levels** to qualified active sponsor uplines for all active matching contracts.
                </p>

                <?php if ($success_msg): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="propagate_all">
                    <button type="submit" class="btn btn-primary fw-bold px-4 py-2">
                        <i class="fa fa-play me-2"></i>Run Rank Propagation (2 Upline Levels)
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($propagate_log)): ?>
            <div class="col-lg-12 mb-4">
                <div class="card p-3">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fa fa-terminal me-2"></i>Propagation Execution Log</h5>
                    </div>
                    <div class="card-body bg-dark text-light font-monospace p-3" style="max-height: 300px; overflow-y: auto; font-size: 13px;">
                        <?php foreach ($propagate_log as $log): ?>
                            <div>&gt; <?php echo htmlspecialchars($log); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-12">
            <div class="card p-4">
                <h4 class="mb-3">Active & Completed Matching Contracts</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#ID</th>
                                <th>Member</th>
                                <th>Slab Amount</th>
                                <th>Daily Income</th>
                                <th>Days Passed</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allContracts)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-3 text-muted">No matching schedules found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allContracts as $c): ?>
                                    <tr>
                                        <td>#<?php echo $c['id']; ?></td>
                                        <td><strong>@<?php echo htmlspecialchars($c['username']); ?></strong></td>
                                        <td>$<?php echo number_format($c['slab_amount'], 2); ?></td>
                                        <td>$<?php echo number_format($c['daily_income'], 2); ?></td>
                                        <td><?php echo $c['days_passed']; ?> / <?php echo $c['max_days']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $c['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo strtoupper($c['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
