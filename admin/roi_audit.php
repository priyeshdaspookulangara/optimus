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
$config = require __DIR__ . '/../includes/config.php';

$successMsg = '';
$errorMsg = '';

// Helper function to calculate expected ROI days based on 05:00 AM milestones
function calculateExpectedRoiDays($createdAtStr, $maxDays = 400) {
    $created = new DateTime($createdAtStr);
    $now = new DateTime();

    // Milestone happens at 05:00:00 every morning
    $first5am = clone $created;
    $first5am->setTime(5, 0, 0);

    // If creation was after 5:00 AM, the first milestone is the next day.
    if ($created > $first5am) {
        $first5am->modify('+1 day');
    }

    // If the first 5:00 AM milestone hasn't occurred yet, then 0 days have passed.
    if ($first5am > $now) {
        return 0;
    }

    $interval = $first5am->diff($now);
    $days = $interval->days + 1; // plus the first milestone day itself

    return min($days, $maxDays);
}

// Helper function to calculate the allowable ID Cap amount
function getAllowableAmountForUser($db, $userId, $amountToAdd, $idCapMultiplier) {
    $stmt = $db->prepare("SELECT total_investment FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $totalInvestment = (float)($user['total_investment'] ?? 0);

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total_earned FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')");
    $stmt->execute([$userId]);
    $earned = $stmt->fetch();
    $totalEarned = (float)($earned['total_earned'] ?? 0);

    $maxCap = $totalInvestment * $idCapMultiplier;
    $remainingCap = $maxCap - $totalEarned;

    if ($remainingCap <= 0) return 0.00;
    return min($amountToAdd, $remainingCap);
}

// Rebuild investment's ROI transactions chronologically
function fixInvestmentROI($db, $investmentId, $config) {
    $stmt = $db->prepare("
        SELECT i.*, u.id as user_id, u.total_investment
        FROM investments i
        JOIN users u ON i.user_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$investmentId]);
    $inv = $stmt->fetch();
    if (!$inv) {
        throw new Exception("Investment not found.");
    }

    $isNested = $db->inTransaction();
    if (!$isNested) {
        $db->beginTransaction();
    }
    try {
        // 1. Delete existing 'ROI' transactions for this investment
        $stmt = $db->prepare("DELETE FROM transactions WHERE investment_id = ? AND type = 'ROI'");
        $stmt->execute([$investmentId]);

        // 2. Reset the investment's fields
        $stmt = $db->prepare("UPDATE investments SET roi_earned = 0.00, total_earned = 0.00, days_passed = 0, last_roi_at = NULL, status = 'active' WHERE id = ?");
        $stmt->execute([$investmentId]);

        $created = new DateTime($inv['created_at']);
        $now = new DateTime();

        $first5am = clone $created;
        $first5am->setTime(5, 0, 0);
        if ($created > $first5am) {
            $first5am->modify('+1 day');
        }

        $currentMilestone = clone $first5am;
        $maxDays = (int)$config['roi']['max_days'];

        while ($currentMilestone <= $now) {
            $milestoneDateStr = $currentMilestone->format('Y-m-d');

            // Fetch current investment state
            $stmt = $db->prepare("SELECT * FROM investments WHERE id = ?");
            $stmt->execute([$investmentId]);
            $currentInv = $stmt->fetch();

            if ($currentInv['status'] === 'completed' || $currentInv['status'] === 'capped') {
                break;
            }

            $dailyRate = (float)$config['roi']['daily_rate'];
            $roiAmount = (float)$currentInv['amount'] * $dailyRate;

            // Check Max Cap 200% over 400 days
            $maxROI = (float)$currentInv['amount'] * (float)$config['roi']['cap_multiplier'];
            $newROIEarned = (float)$currentInv['roi_earned'] + $roiAmount;
            $newDaysPassed = (int)$currentInv['days_passed'] + 1;

            if ($newROIEarned >= $maxROI || $newDaysPassed >= $maxDays) {
                $roiAmount = max(0.0, $maxROI - (float)$currentInv['roi_earned']);
                $newROIEarned = $maxROI;
                $status = 'completed';
            } else {
                $status = 'active';
            }

            // Check dynamic ID Cap (300% of user's total investment)
            $allowableROI = getAllowableAmountForUser($db, $currentInv['user_id'], $roiAmount, (float)$config['id_cap_multiplier']);
            if ($allowableROI > 0) {
                // Insert transaction with historical timestamp (05:00:00 of that milestone day)
                $txCreatedAt = $milestoneDateStr . ' 05:00:00';
                $stmtInsert = $db->prepare("
                    INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at)
                    VALUES (?, NULL, ?, NULL, 'ROI', ?, 0.00, ?, ?, ?)
                ");
                $stmtInsert->execute([
                    $currentInv['user_id'],
                    $investmentId,
                    $allowableROI,
                    $allowableROI,
                    "Daily ROI for investment ID: {$investmentId} (Day {$newDaysPassed}/{$maxDays})",
                    $txCreatedAt
                ]);

                // Update investment
                $stmtUpdate = $db->prepare("
                    UPDATE investments
                    SET roi_earned = ?, total_earned = total_earned + ?, days_passed = ?, last_roi_at = ?, status = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$newROIEarned, $allowableROI, $newDaysPassed, $milestoneDateStr, $status, $investmentId]);

                if ($allowableROI < $roiAmount) {
                    $stmtUpdateCapped = $db->prepare("UPDATE investments SET status = 'capped' WHERE id = ?");
                    $stmtUpdateCapped->execute([$investmentId]);
                }
            } else {
                $stmtUpdateCapped = $db->prepare("UPDATE investments SET status = 'capped' WHERE id = ?");
                $stmtUpdateCapped->execute([$investmentId]);
            }

            $currentMilestone->modify('+1 day');
        }

        if (!$isNested) {
            $db->commit();
        }
        return true;
    } catch (Exception $e) {
        if (!$isNested && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Handle Form POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMsg = "CSRF verification failed.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'trigger_cron') {
            try {
                $engine = new MLMEngine();
                $engine->processDailyROI();
                $successMsg = "Daily ROI Process executed successfully. All pending daily ROI payouts have been credited.";
            } catch (Exception $ex) {
                $errorMsg = "Error executing daily ROI process: " . $ex->getMessage();
            }
        } elseif ($action === 'fix_single') {
            $invId = isset($_POST['investment_id']) ? (int)$_POST['investment_id'] : 0;
            if ($invId > 0) {
                try {
                    fixInvestmentROI($db, $invId, $config);
                    $successMsg = "Investment ID #{$invId} ROI history rebuilt and verified successfully!";
                } catch (Exception $ex) {
                    $errorMsg = "Error fixing investment ID #{$invId}: " . $ex->getMessage();
                }
            } else {
                $errorMsg = "Invalid investment selected.";
            }
        } elseif ($action === 'fix_all') {
            // Find all investments and identify which ones have discrepancies
            try {
                $stmt = $db->query("
                    SELECT i.id, i.created_at, i.days_passed, i.roi_earned, i.amount
                    FROM investments i
                ");
                $allInvestments = $stmt->fetchAll();
                $fixedCount = 0;

                foreach ($allInvestments as $inv) {
                    // Calculate expected days
                    $expectedDays = calculateExpectedRoiDays($inv['created_at'], (int)$config['roi']['max_days']);
                    $expectedROI = $expectedDays * $inv['amount'] * (float)$config['roi']['daily_rate'];
                    $maxROI = $inv['amount'] * (float)$config['roi']['cap_multiplier'];
                    if ($expectedROI > $maxROI) {
                        $expectedROI = $maxROI;
                    }

                    // Check transactions
                    $txStmt = $db->prepare("
                        SELECT COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as tx_sum
                        FROM transactions
                        WHERE investment_id = ? AND type = 'ROI'
                    ");
                    $txStmt->execute([$inv['id']]);
                    $txData = $txStmt->fetch();
                    $actualCount = (int)$txData['tx_count'];
                    $actualSum = (float)$txData['tx_sum'];

                    $isMismatched = (
                        (int)$inv['days_passed'] !== $expectedDays ||
                        abs((float)$inv['roi_earned'] - $expectedROI) > 0.01 ||
                        $actualCount !== $expectedDays ||
                        abs($actualSum - (float)$inv['roi_earned']) > 0.01
                    );

                    if ($isMismatched) {
                        fixInvestmentROI($db, $inv['id'], $config);
                        $fixedCount++;
                    }
                }

                $successMsg = "Global ROI Audit complete. Successfully rebuilt and synchronized {$fixedCount} mismatched investments.";
            } catch (Exception $ex) {
                $errorMsg = "Error executing global rebuild: " . $ex->getMessage();
            }
        }
    }
}

// Fetch all investments for auditing
$stmt = $db->query("
    SELECT i.*, u.username, u.mid, u.created_at as join_date, p.name as package_name
    FROM investments i
    JOIN users u ON i.user_id = u.id
    JOIN packages p ON i.package_id = p.id
    ORDER BY i.created_at DESC
");
$investments = $stmt->fetchAll();

// Track cron status
$cronStmt = $db->query("SELECT MAX(created_at) as last_run_at FROM transactions WHERE type = 'ROI'");
$lastCronRun = $cronStmt->fetch();
$lastCronRunAt = $lastCronRun['last_run_at'] ? $lastCronRun['last_run_at'] : null;

$today = date('Y-m-d');
$now = new DateTime();
$fiveAmToday = new DateTime($today . ' 05:00:00');
$cronExecutedToday = false;

if ($lastCronRunAt) {
    $lastRunDate = date('Y-m-d', strtotime($lastCronRunAt));
    if ($lastRunDate === $today) {
        $cronExecutedToday = true;
    }
}

$pageTitle = 'ROI Audit & Verification';
include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="fa fa-shield-halved text-primary me-2"></i> ROI Audit & Verification</h2>
        <p class="text-muted">Analyze member investments, audit chronological daily 05:00 AM ROI payouts, verify ledger transaction consistency, and repair corrupted balances automatically.</p>
    </div>
</div>

<?php if (!empty($successMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa fa-circle-check me-2"></i> <?php echo htmlspecialchars($successMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($errorMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- System Information & Controls -->
<div class="row mb-4">
    <!-- Cron Status Card -->
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <h5 class="card-title text-secondary"><i class="fa fa-clock me-2"></i> Daily 05:00 AM Cron Status</h5>
                <hr>
                <p class="mb-2"><strong>Last Credited Date:</strong> <span class="text-primary"><?php echo $lastCronRunAt ? date('Y-m-d H:i:s', strtotime($lastCronRunAt)) : 'Never'; ?></span></p>
                <p class="mb-3">
                    <strong>Status for Today (<?php echo $today; ?>):</strong>
                    <?php if ($cronExecutedToday): ?>
                        <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> Success / Credited</span>
                    <?php elseif ($now < $fiveAmToday): ?>
                        <span class="badge bg-info"><i class="fa fa-circle-pause me-1"></i> Scheduled at 05:00 AM</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="fa fa-circle-xmark me-1"></i> Delayed / Missed</span>
                    <?php endif; ?>
                </p>

                <form method="POST" onsubmit="return confirm('Are you sure you want to trigger the manual daily ROI process? This will run the calculation and pay out all active contracts for today.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="trigger_cron">
                    <button type="submit" class="btn btn-primary w-100"><i class="fa fa-play me-2"></i> Run Daily ROI Now</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="col-md-8">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title text-secondary mb-0"><i class="fa fa-calculator me-2"></i> Real-time System Audit Summary</h5>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to execute a global ROI repair? This will automatically rebuild the entire history chronologically for all mismatched investments under transaction controls.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                        <input type="hidden" name="action" value="fix_all">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fa fa-wrench me-2"></i> Auto-Repair All Discrepancies</button>
                    </form>
                </div>
                <hr>
                <div class="row text-center mt-3">
                    <?php
                    $totalInvCount = count($investments);
                    $mismatchCount = 0;
                    $correctCount = 0;

                    foreach ($investments as $inv) {
                        $expectedDays = calculateExpectedRoiDays($inv['created_at'], (int)$config['roi']['max_days']);
                        $expectedROI = $expectedDays * $inv['amount'] * (float)$config['roi']['daily_rate'];
                        $maxROI = $inv['amount'] * (float)$config['roi']['cap_multiplier'];
                        if ($expectedROI > $maxROI) {
                            $expectedROI = $maxROI;
                        }

                        $txStmt = $db->prepare("
                            SELECT COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as tx_sum
                            FROM transactions
                            WHERE investment_id = ? AND type = 'ROI'
                        ");
                        $txStmt->execute([$inv['id']]);
                        $txData = $txStmt->fetch();
                        $actualCount = (int)$txData['tx_count'];
                        $actualSum = (float)$txData['tx_sum'];

                        $isMismatched = (
                            (int)$inv['days_passed'] !== $expectedDays ||
                            abs((float)$inv['roi_earned'] - $expectedROI) > 0.01 ||
                            $actualCount !== $expectedDays ||
                            abs($actualSum - (float)$inv['roi_earned']) > 0.01
                        );

                        if ($isMismatched) {
                            $mismatchCount++;
                        } else {
                            $correctCount++;
                        }
                    }
                    ?>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded mb-3">
                            <h6 class="text-muted">Total Investments</h6>
                            <h3 class="fw-bold text-dark mb-0"><?php echo $totalInvCount; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded mb-3">
                            <h6 class="text-success">Verified & Correct</h6>
                            <h3 class="fw-bold text-success mb-0"><?php echo $correctCount; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded mb-3">
                            <h6 class="text-danger">Mismatches Found</h6>
                            <h3 class="fw-bold text-danger mb-0"><?php echo $mismatchCount; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Audit Grid -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom-0">
        <h5 class="mb-0 text-dark"><i class="fa fa-list me-2"></i> Audit Ledgers & Reconciliations</h5>
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-secondary btn-sm active" id="btn-filter-all">All</button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="btn-filter-mismatch">Discrepancies Only (<?php echo $mismatchCount; ?>)</button>
            <button type="button" class="btn btn-outline-success btn-sm" id="btn-filter-correct">Correct Only (<?php echo $correctCount; ?>)</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered align-middle" id="audit-table">
                <thead class="table-dark">
                    <tr>
                        <th>Member Info</th>
                        <th>Investment Details</th>
                        <th>Days Passed</th>
                        <th>ROI Earned</th>
                        <th>Ledger Transactions</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investments as $inv):
                        // Compute expectations
                        $expectedDays = calculateExpectedRoiDays($inv['created_at'], (int)$config['roi']['max_days']);
                        $expectedROI = $expectedDays * $inv['amount'] * (float)$config['roi']['daily_rate'];
                        $maxROI = $inv['amount'] * (float)$config['roi']['cap_multiplier'];
                        if ($expectedROI > $maxROI) {
                            $expectedROI = $maxROI;
                        }

                        // Compute actuals
                        $txStmt = $db->prepare("
                            SELECT COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as tx_sum
                            FROM transactions
                            WHERE investment_id = ? AND type = 'ROI'
                        ");
                        $txStmt->execute([$inv['id']]);
                        $txData = $txStmt->fetch();
                        $actualCount = (int)$txData['tx_count'];
                        $actualSum = (float)$txData['tx_sum'];

                        $isMismatched = (
                            (int)$inv['days_passed'] !== $expectedDays ||
                            abs((float)$inv['roi_earned'] - $expectedROI) > 0.01 ||
                            $actualCount !== $expectedDays ||
                            abs($actualSum - (float)$inv['roi_earned']) > 0.01
                        );
                    ?>
                    <tr class="audit-row <?php echo $isMismatched ? 'row-mismatch table-danger-soft' : 'row-correct'; ?>">
                        <td>
                            <strong class="text-dark"><?php echo htmlspecialchars($inv['username']); ?></strong><br>
                            <span class="badge bg-secondary mb-1"><?php echo htmlspecialchars($inv['mid']); ?></span><br>
                            <small class="text-muted">Joined: <?php echo date('Y-m-d', strtotime($inv['join_date'])); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?php echo htmlspecialchars($inv['package_name']); ?></span><br>
                            <strong>$<?php echo number_format($inv['amount'], 2); ?></strong><br>
                            <small class="text-muted">Purchased: <?php echo date('Y-m-d H:i', strtotime($inv['created_at'])); ?></small>
                        </td>
                        <td>
                            <span class="text-muted">Recorded:</span> <strong><?php echo $inv['days_passed']; ?></strong><br>
                            <span class="text-muted">Expected:</span> <strong><?php echo $expectedDays; ?></strong>
                        </td>
                        <td>
                            <span class="text-muted">Recorded:</span> <strong>$<?php echo number_format($inv['roi_earned'], 2); ?></strong><br>
                            <span class="text-muted">Expected:</span> <strong>$<?php echo number_format($expectedROI, 2); ?></strong>
                        </td>
                        <td>
                            <span class="text-muted">Count:</span> <strong><?php echo $actualCount; ?></strong><br>
                            <span class="text-muted">Total Sum:</span> <strong>$<?php echo number_format($actualSum, 2); ?></strong>
                        </td>
                        <td>
                            <?php if ($isMismatched): ?>
                                <span class="badge bg-danger"><i class="fa fa-triangle-exclamation"></i> Mismatch</span>
                            <?php else: ?>
                                <span class="badge bg-success"><i class="fa fa-circle-check"></i> Correct</span>
                            <?php endif; ?>
                            <br>
                            <span class="badge bg-light text-dark border mt-1"><?php echo strtoupper($inv['status']); ?></span>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to rebuild and repair the entire ROI transaction log for this investment?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                <input type="hidden" name="action" value="fix_single">
                                <input type="hidden" name="investment_id" value="<?php echo $inv['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-wrench"></i> Repair</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .table-danger-soft {
        background-color: #fce8e6 !important;
    }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const btnAll = document.getElementById("btn-filter-all");
    const btnMismatch = document.getElementById("btn-filter-mismatch");
    const btnCorrect = document.getElementById("btn-filter-correct");
    const rows = document.querySelectorAll(".audit-row");

    btnAll.addEventListener("click", function() {
        btnAll.classList.add("active");
        btnMismatch.classList.remove("active");
        btnCorrect.classList.remove("active");
        rows.forEach(r => r.style.display = "");
    });

    btnMismatch.addEventListener("click", function() {
        btnAll.classList.remove("active");
        btnMismatch.classList.add("active");
        btnCorrect.classList.remove("active");
        rows.forEach(r => {
            if (r.classList.contains("row-mismatch")) {
                r.style.display = "";
            } else {
                r.style.display = "none";
            }
        });
    });

    btnCorrect.addEventListener("click", function() {
        btnAll.classList.remove("active");
        btnMismatch.classList.remove("active");
        btnCorrect.classList.add("active");
        rows.forEach(r => {
            if (r.classList.contains("row-correct")) {
                r.style.display = "";
            } else {
                r.style.display = "none";
            }
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
