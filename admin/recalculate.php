<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();
$config = require __DIR__ . '/../includes/config.php';

$pageTitle = 'Recalculate System Commissions';
include __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';
$recalc_log = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action == 'recalculate') {
            $recalc_log[] = "Initializing Global Recalculation by chronological joining order...";

            $db->beginTransaction();
            try {
                // Step 1: Backup existing matching schedules (contracts)
                $recalc_log[] = "Backing up existing matching schedules...";
                $stmtBackup = $db->query("SELECT * FROM matching_schedules");
                $backupSchedules = $stmtBackup->fetchAll(PDO::FETCH_ASSOC);

                $backup = [];
                foreach ($backupSchedules as $sched) {
                    $uid = $sched['user_id'];
                    $slab = (int)$sched['slab_amount'];
                    $backup[$uid][$slab][] = [
                        'days_passed' => $sched['days_passed'],
                        'status' => $sched['status']
                    ];
                }
                $recalc_log[] = "Successfully backed up " . count($backupSchedules) . " matching contract(s).";

                // Step 2: Clear LEVEL_INCOME and RANK_INCOME transactions
                $recalc_log[] = "Clearing all LEVEL_INCOME and RANK_INCOME transactions...";
                $db->exec("DELETE FROM transactions WHERE type IN ('LEVEL_INCOME', 'RANK_INCOME')");

                // Step 3: Clear all matching schedules
                $recalc_log[] = "Clearing all matching schedules...";
                $db->exec("DELETE FROM matching_schedules");

                // Step 4: Reset all users' ranks back to zero
                $recalc_log[] = "Resetting all user rank IDs back to 0...";
                $db->exec("UPDATE users SET rank_id = 0");

                // Step 5: Fetch all investments chronologically in order of joining/creation
                $recalc_log[] = "Fetching all package investments in chronological order...";
                $stmtInvestments = $db->query("SELECT * FROM investments ORDER BY id ASC");
                $investments = $stmtInvestments->fetchAll(PDO::FETCH_ASSOC);

                $recalc_log[] = "Processing " . count($investments) . " investment(s) chronologically...";

                foreach ($investments as $inv) {
                    $userId = $inv['user_id'];
                    $amount = (float)$inv['amount'];

                    $recalc_log[] = "Processing Investment ID #{$inv['id']} of \${$amount} for User ID #{$userId}...";

                    // Distribute level commission upwards
                    $engine->distributeLevelIncome($userId, $amount);

                    // Re-calculate unilevel leg business, ranks, and matching schedules sequentially
                    $engine->updateUplineRanks($userId);
                }

                // Step 6: Restore days_passed and status for matching schedules from backup
                $recalc_log[] = "Restoring matching contract progression (days_passed & status) and historical payout transactions from backup...";
                $stmtAllScheds = $db->query("SELECT * FROM matching_schedules ORDER BY id ASC");
                $newSchedules = $stmtAllScheds->fetchAll(PDO::FETCH_ASSOC);

                $restoredCount = 0;
                $userSlabCounters = [];

                foreach ($newSchedules as $ns) {
                    $uid = $ns['user_id'];
                    $slab = (int)$ns['slab_amount'];

                    if (!isset($userSlabCounters[$uid][$slab])) {
                        $userSlabCounters[$uid][$slab] = 0;
                    }
                    $index = $userSlabCounters[$uid][$slab];

                    if (isset($backup[$uid][$slab][$index])) {
                        $bData = $backup[$uid][$slab][$index];
                        $stmtUpdate = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
                        $stmtUpdate->execute([$bData['days_passed'], $bData['status'], $ns['id']]);

                        // Regenerate the deleted RANK_INCOME transactions to restore user balances perfectly
                        $daysPassed = (int)$bData['days_passed'];
                        $dailyIncome = (float)$ns['daily_income'];
                        if ($daysPassed > 0) {
                            $stmtTx = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at) VALUES (?, NULL, NULL, 0, 'RANK_INCOME', ?, 0.00, ?, ?, NOW())");
                            for ($i = 1; $i <= $daysPassed; $i++) {
                                $desc = "Slab \$" . number_format($slab, 2) . " matching payout - Day " . $i . "/" . $ns['max_days'];
                                $stmtTx->execute([$uid, $dailyIncome, $dailyIncome, $desc]);
                            }
                        }
                        $restoredCount++;
                    }

                    $userSlabCounters[$uid][$slab]++;
                }

                $recalc_log[] = "Restored " . $restoredCount . " matching contract(s) progression and regenerated transaction histories successfully.";

                $db->commit();
                $success_msg = "Global system recalculation by joining order completed successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error during recalculation: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card bg-dark text-white p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2><i class="fa fa-sync-alt me-2 text-warning"></i>Clear & Recalculate Commissions</h2>
                </div>
                <p class="text-muted">
                    This utility clears all Level Income and Matching Rank Income logs, resets ranks, and sequentially reconstructs leg volumes and payouts **chronologically in the exact order of user package joinings** to maintain perfect mathematical synchronization.
                </p>

                <?php if ($success_msg): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form method="post" class="mt-4" onsubmit="return confirm('WARNING: This will clear all level and rank income transaction records and rebuild them sequentially. Are you sure you want to proceed?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="recalculate">
                    <button type="submit" class="btn btn-danger fw-bold px-4 py-2">
                        <i class="fa fa-exclamation-triangle me-2"></i>Recalculate All System Commissions
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($recalc_log)): ?>
            <div class="col-lg-12">
                <div class="card p-3">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fa fa-terminal me-2"></i>Recalculation Output Log</h5>
                    </div>
                    <div class="card-body bg-dark text-light font-monospace p-3" style="max-height: 400px; overflow-y: auto; font-size: 13px;">
                        <?php foreach ($recalc_log as $log): ?>
                            <div>&gt; <?php echo htmlspecialchars($log); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
