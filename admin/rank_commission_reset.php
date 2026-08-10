<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication check before running state-changing operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';
$engine = new MLMEngine();

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMsg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'] ?? '';
        $mode = $_POST['mode'] ?? 'single';
        $userQuery = trim($_POST['user_query'] ?? '');

        if ($mode === 'single' && empty($userQuery)) {
            $errorMsg = "Please provide a Username or Member Code (MID) for Single Member mode.";
        } else {
            // Find target users if mode is single
            $targetUserIds = [];
            if ($mode === 'single') {
                $stmt = $db->prepare("SELECT id, username FROM users WHERE username = ? OR mid = ?");
                $stmt->execute([$userQuery, $userQuery]);
                $user = $stmt->fetch();
                if (!$user) {
                    $errorMsg = "Member not found. Please verify Username or Member Code (MID).";
                } else {
                    $targetUserIds[] = $user['id'];
                }
            }

            if (empty($errorMsg)) {
                if ($action === 'roi_reset') {
                    // ROI DAILY INCOME RESET AND RECALCULATION TILL DATE
                    $db->beginTransaction();
                    try {
                        // 1. Fetch investments to process
                        if ($mode === 'single') {
                            $stmt = $db->prepare("SELECT i.*, u.username FROM investments i JOIN users u ON i.user_id = u.id WHERE i.user_id = ?");
                            $stmt->execute([$targetUserIds[0]]);
                        } else {
                            $stmt = $db->prepare("SELECT i.*, u.username FROM investments i JOIN users u ON i.user_id = u.id");
                            $stmt->execute();
                        }
                        $investmentsToProcess = $stmt->fetchAll();

                        $countInvestments = 0;
                        $countInserts = 0;

                        // Check if column 'roi_date' exists in 'transactions'
                        $checkCol = $db->query("SHOW COLUMNS FROM transactions LIKE 'roi_date'")->fetch();
                        $hasRoiDate = !empty($checkCol);

                        foreach ($investmentsToProcess as $inv) {
                            // Delete existing ROI transactions for this investment
                            $stmtDel = $db->prepare("DELETE FROM transactions WHERE investment_id = ? AND type = 'ROI'");
                            $stmtDel->execute([$inv['id']]);

                            // Determine dates from investment creation date to today
                            $createdTime = strtotime($inv['created_at']);
                            $createdDate = date('Y-m-d', $createdTime);
                            $createdHour = (int)date('H', $createdTime);

                            // Apply 05:00 AM daily ROI calculation threshold
                            if ($createdHour < 5) {
                                $startDateStr = $createdDate;
                            } else {
                                $startDateStr = date('Y-m-d', strtotime($createdDate . ' +1 day'));
                            }

                            $endDateStr = date('Y-m-d'); // Today

                            // Generate all daily dates from $startDateStr to $endDateStr
                            $dates = [];
                            $currentDateStr = $startDateStr;
                            while ($currentDateStr <= $endDateStr) {
                                $dates[] = $currentDateStr;
                                $currentDateStr = date('Y-m-d', strtotime($currentDateStr . ' +1 day'));
                            }

                            // Reset metrics locally
                            $roi_earned = 0.00;
                            $days_passed = 0;
                            $last_roi_at = null;
                            $status = 'active';

                            $dailyRate = $config['roi']['daily_rate'] ?? 0.0050;
                            $maxROI = $inv['amount'] * ($config['roi']['cap_multiplier'] ?? 2.0);
                            $maxDays = $config['roi']['max_days'] ?? 400;

                            // Run day-by-day calculation and make inserts
                            foreach ($dates as $payoutDate) {
                                $roiAmount = $inv['amount'] * $dailyRate;

                                // Check if adding this daily ROI exceeds max cap or max days
                                if (($roi_earned + $roiAmount) >= $maxROI || ($days_passed + 1) >= $maxDays) {
                                    $roiAmount = max(0, $maxROI - $roi_earned);
                                    $roi_earned = $maxROI;
                                    $days_passed++;
                                    $last_roi_at = $payoutDate;
                                    $status = 'completed';

                                    if ($roiAmount > 0) {
                                        $desc = "Daily ROI for investment ID: {$inv['id']} (Day {$days_passed}/{$maxDays}) [Reset/Recalculated]";
                                        $createdAtField = $payoutDate . ' 05:00:00';

                                        if ($hasRoiDate) {
                                            $stmtIns = $db->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, fee, net_amount, description, created_at, roi_date) VALUES (?, ?, 'ROI', ?, 0, ?, ?, ?, ?)");
                                            $stmtIns->execute([$inv['user_id'], $inv['id'], $roiAmount, $roiAmount, $desc, $createdAtField, $payoutDate]);
                                        } else {
                                            $stmtIns = $db->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, fee, net_amount, description, created_at) VALUES (?, ?, 'ROI', ?, 0, ?, ?, ?)");
                                            $stmtIns->execute([$inv['user_id'], $inv['id'], $roiAmount, $roiAmount, $desc, $createdAtField]);
                                        }
                                        $countInserts++;
                                    }
                                    break; // Max reached
                                } else {
                                    $roi_earned += $roiAmount;
                                    $days_passed++;
                                    $last_roi_at = $payoutDate;
                                    $status = 'active';

                                    $desc = "Daily ROI for investment ID: {$inv['id']} (Day {$days_passed}/{$maxDays}) [Reset/Recalculated]";
                                    $createdAtField = $payoutDate . ' 05:00:00';

                                    if ($hasRoiDate) {
                                        $stmtIns = $db->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, fee, net_amount, description, created_at, roi_date) VALUES (?, ?, 'ROI', ?, 0, ?, ?, ?, ?)");
                                        $stmtIns->execute([$inv['user_id'], $inv['id'], $roiAmount, $roiAmount, $desc, $createdAtField, $payoutDate]);
                                    } else {
                                        $stmtIns = $db->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, fee, net_amount, description, created_at) VALUES (?, ?, 'ROI', ?, 0, ?, ?, ?)");
                                        $stmtIns->execute([$inv['user_id'], $inv['id'], $roiAmount, $roiAmount, $desc, $createdAtField]);
                                    }
                                    $countInserts++;
                                }
                            }

                            // Update investment record in db
                            $stmtUpd = $db->prepare("UPDATE investments SET roi_earned = ?, total_earned = ?, days_passed = ?, last_roi_at = ?, status = ? WHERE id = ?");
                            $stmtUpd->execute([$roi_earned, $roi_earned, $days_passed, $last_roi_at, $status, $inv['id']]);

                            $countInvestments++;
                        }

                        $db->commit();
                        $successMsg = "Successfully reset and recalculated ROI daily income till date for <strong>{$countInvestments}</strong> investment(s). Made <strong>{$countInserts}</strong> chronological daily transaction inserts.";
                    } catch (Exception $e) {
                        $db->rollBack();
                        $errorMsg = "ROI Recalculation failed: " . $e->getMessage();
                    }

                } elseif ($action === 'rank_reset') {
                    // RANK COMMISSION RESET & RE-EVALUATION
                    $db->beginTransaction();
                    try {
                        if ($mode === 'single') {
                            $targetId = $targetUserIds[0];

                            // Delete RANK_INCOME transactions for this member
                            $stmtDelTrans = $db->prepare("DELETE FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME'");
                            $stmtDelTrans->execute([$targetId]);

                            // Reset matching schedules for this member
                            $stmtDelSched = $db->prepare("DELETE FROM matching_schedules WHERE user_id = ?");
                            $stmtDelSched->execute([$targetId]);

                            // Reset user rank to 0
                            $stmtResetUser = $db->prepare("UPDATE users SET rank_id = 0 WHERE id = ?");
                            $stmtResetUser->execute([$targetId]);

                            // Trigger upline ranks evaluation to organic rebuilt matching schedules
                            $engine->updateUplineRanks($targetId);

                            $successMsg = "Successfully reset Rank Commissions and recreated Matching Schedules for member <strong>" . htmlspecialchars($userQuery) . "</strong>.";
                        } else {
                            // Delete ALL RANK_INCOME transactions
                            $db->exec("DELETE FROM transactions WHERE type = 'RANK_INCOME'");

                            // Delete ALL matching schedules
                            $db->exec("DELETE FROM matching_schedules");

                            // Reset ALL users' ranks to 0
                            $db->exec("UPDATE users SET rank_id = 0");

                            // Loop and evaluate upline ranks for all active users to regenerate matching schedules
                            $stmtUsers = $db->prepare("SELECT id FROM users WHERE status = 'active' ORDER BY id ASC");
                            $stmtUsers->execute();
                            $allActiveUsers = $stmtUsers->fetchAll();

                            foreach ($allActiveUsers as $activeU) {
                                $engine->updateUplineRanks($activeU['id']);
                            }

                            $successMsg = "Successfully reset Rank Commissions and matching schedules globally for ALL active members.";
                        }

                        // Check if admin chose to simulate payouts till date
                        $simulatePayouts = isset($_POST['simulate_payouts']) && $_POST['simulate_payouts'] == '1';
                        if ($simulatePayouts) {
                            // Run rank income payout processor to trigger payouts for matching schedules up to today
                            // Since they were deleted/re-created, they are at 0 days passed.
                            // We can simulate daily payouts from their creation date (or the appropriate date) up to today.
                            // Let's do a basic chronological daily payout simulation from created_at to today.
                            if ($mode === 'single') {
                                $stmtScheds = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ?");
                                $stmtScheds->execute([$targetId]);
                            } else {
                                $stmtScheds = $db->prepare("SELECT * FROM matching_schedules");
                                $stmtScheds->execute();
                            }
                            $schedules = $stmtScheds->fetchAll();
                            $simulatedPayoutsCount = 0;

                            foreach ($schedules as $sched) {
                                $createdTime = strtotime($sched['created_at']);
                                $createdDate = date('Y-m-d', $createdTime);
                                $endDateStr = date('Y-m-d'); // Today

                                // Daily payouts dates
                                $currentDateStr = $createdDate;
                                $schedDaysPassed = 0;
                                $schedStatus = 'active';

                                while ($currentDateStr <= $endDateStr && $schedDaysPassed < $sched['max_days']) {
                                    $dailyIncome = (float)$sched['daily_income'];
                                    $schedDaysPassed++;
                                    $schedStatus = ($schedDaysPassed >= $sched['max_days']) ? 'completed' : 'active';

                                    // Record transactional rank income
                                    $desc = "Daily Matching Income for Slab \$" . number_format($sched['slab_amount'], 2) . " (Day " . $schedDaysPassed . "/100) [Reset/Simulated]";
                                    $payoutTimestamp = $currentDateStr . ' 05:00:00';

                                    $stmtIns = $db->prepare("INSERT INTO transactions (user_id, type, amount, fee, net_amount, description, created_at) VALUES (?, 'RANK_INCOME', ?, 0, ?, ?, ?)");
                                    $stmtIns->execute([$sched['user_id'], $dailyIncome, $dailyIncome, $desc, $payoutTimestamp]);

                                    $simulatedPayoutsCount++;
                                    $currentDateStr = date('Y-m-d', strtotime($currentDateStr . ' +1 day'));
                                }

                                // Update matching schedule with days passed & status
                                $stmtUpdSched = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
                                $stmtUpdSched->execute([$schedDaysPassed, $schedStatus, $sched['id']]);
                            }
                            $successMsg .= " Also simulated <strong>{$simulatedPayoutsCount}</strong> chronological Rank Income payout inserts till date.";
                        }

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $errorMsg = "Rank commission reset failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

$pageTitle = 'Rank Commission & ROI Reset';
include __DIR__ . '/includes/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3>Rank Commission & ROI Reset Control Center</h3>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i> Back to Dashboard</a>
        </div>

        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?php echo $successMsg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($errorMsg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- ROI Daily Income Reset Section -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0 text-white"><i class="fa fa-redo-alt me-2"></i> ROI Daily Income Reset & Recalculator</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text text-muted">
                            This tool deletes existing ROI daily transactions (type <code>ROI</code>) and chronologically recalculates daily payouts from the investment's creation date (accounting for the 05:00 AM calculation threshold) up to today. For each daily interval, a new chronological transaction is inserted.
                        </p>
                        <form method="post" action="rank_commission_reset.php" onsubmit="return confirm('WARNING: This will delete previous ROI transactions for target investments and insert fresh ones day-by-day. Are you sure you want to proceed?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                            <input type="hidden" name="action" value="roi_reset">

                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Scope / Target Selection:</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="roi_mode_single" value="single" checked onclick="toggleQueryInput('roi')">
                                    <label class="form-check-label" for="roi_mode_single">
                                        Single Member
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="roi_mode_all" value="all" onclick="toggleQueryInput('roi')">
                                    <label class="form-check-label" for="roi_mode_all">
                                        All Members (Global)
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3" id="roi_query_container">
                                <label for="roi_user_query" class="form-label">Username or Member Code (MID):</label>
                                <input type="text" class="form-control" name="user_query" id="roi_user_query" placeholder="e.g. admin or OPT12345">
                                <small class="text-muted">Target username or unique Member ID to recalculate.</small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-calculator me-1"></i> Reset & Recalculate ROI</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Rank Commission & Sched Reset Section -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0 text-white"><i class="fa fa-sync-alt me-2"></i> Rank Commission & Matching Schedule Reset</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text text-muted">
                            This tool deletes existing <code>RANK_INCOME</code> transactions, removes previous matching schedules, resets target ranks to 0, and cleanly reconstructs correct rank tiers and schedules organically from current downline team volume.
                        </p>
                        <form method="post" action="rank_commission_reset.php" onsubmit="return confirm('WARNING: This will reset rank tiers and matching contracts for target members. This cannot be undone. Do you wish to continue?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                            <input type="hidden" name="action" value="rank_reset">

                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Scope / Target Selection:</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="rank_mode_single" value="single" checked onclick="toggleQueryInput('rank')">
                                    <label class="form-check-label" for="rank_mode_single">
                                        Single Member
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="rank_mode_all" value="all" onclick="toggleQueryInput('rank')">
                                    <label class="form-check-label" for="rank_mode_all">
                                        All Members (Global)
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3" id="rank_query_container">
                                <label for="rank_user_query" class="form-label">Username or Member Code (MID):</label>
                                <input type="text" class="form-control" name="user_query" id="rank_user_query" placeholder="e.g. admin or OPT12345">
                                <small class="text-muted">Target username or unique Member ID to reset rank/schedules.</small>
                            </div>

                            <div class="mb-4 form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="simulate_payouts" id="simulate_payouts" value="1" checked>
                                <label class="form-check-label font-weight-bold" for="simulate_payouts">Simulate Rank Income payouts till date chronologically</label>
                                <br><small class="text-muted">If checked, the system will insert a daily RANK_INCOME transaction for each day elapsed since contract generation.</small>
                            </div>

                            <button type="submit" class="btn btn-danger w-100"><i class="fa fa-refresh me-1"></i> Reset & Reconstruct Ranks</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleQueryInput(section) {
    var isSingle = document.getElementById(section + '_mode_single').checked;
    var container = document.getElementById(section + '_query_container');
    if (isSingle) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
}
// Initial sync
toggleQueryInput('roi');
toggleQueryInput('rank');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
