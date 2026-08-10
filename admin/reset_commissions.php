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
$config = require __DIR__ . '/../includes/config.php';

$pageTitle = 'Reset & Recalculate Commissions';
include __DIR__ . '/includes/header.php';

// Helper function to get allowable amount subject to 300% ID Cap
function getLocalAllowableAmount($userId, $amountToAdd, $db, $config) {
    $stmt = $db->prepare("SELECT total_investment, (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned FROM users WHERE id = ?");
    $stmt->execute([$userId, $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return 0;
    $multiplier = isset($config['id_cap_multiplier']) ? $config['id_cap_multiplier'] : 3.0;
    $maxCap = $user['total_investment'] * $multiplier;
    $remainingCap = $maxCap - $user['total_earned'];

    if ($remainingCap <= 0) return 0;
    return min($amountToAdd, $remainingCap);
}

// standalone local implementation of logTransaction
function localLogTransaction($db, $userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null, $customNetAmount = null) {
    $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);
    if ($customNetAmount !== null) {
        $netAmount = $customNetAmount;
    } elseif ($isDebit) {
        $netAmount = -($amount + $fee);
    } else {
        $netAmount = $amount - $fee;
    }

    $stmt = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description]);
}

// standalone local implementation of checkRankQualification
function localCheckRankQualification($config, $matchingBusiness) {
    $qualifiedRankId = null;
    foreach ($config['ranks'] as $id => $rank) {
        if ($matchingBusiness >= $rank['matching']) {
            $qualifiedRankId = $id + 1;
        } else {
            break;
        }
    }
    return $qualifiedRankId;
}

// standalone local implementation of getLegsBusiness
function localGetLegsBusiness($db, $config, $userId) {
    $stmt = $db->prepare("
        SELECT u.id, u.username,
               (u.total_investment + COALESCE((
                   SELECT SUM(downline.total_investment)
                   FROM genealogy g
                   JOIN users downline ON g.user_id = downline.id
                   WHERE g.parent_id = u.id
               ), 0)) as total_leg_business
        FROM users u
        WHERE u.sponsor_id = ?
    ");
    $stmt->execute([$userId]);
    $legs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($legs)) {
        return [
            'power_leg' => 0.00,
            'matching_leg' => 0.00,
            'matched_business' => 0.00,
            'power_carry_forward' => 0.00,
            'rest_carry_forward' => 0.00,
            'slab_breakdown' => []
        ];
    }

    $volumes = array_column($legs, 'total_leg_business');
    $powerLegRaw = max($volumes);
    $totalVolume = array_sum($volumes);
    $restLegRaw = $totalVolume - $powerLegRaw;

    $vPower = $powerLegRaw;
    $vRest = $restLegRaw;
    $totalMatched = 0.00;
    $slabBreakdown = [];

    $slabs = [500000, 250000, 100000, 50000, 25000, 10000, 5000, 2500, 1000, 500];
    foreach ($slabs as $slab) {
        $m = min($vPower, $vRest);
        if ($m >= $slab) {
            $units = (int)floor($m / $slab);
            $matchedVolume = $units * $slab;

            $totalMatched += $matchedVolume;
            $vPower -= $matchedVolume;
            $vRest -= $matchedVolume;

            $slabBreakdown[$slab] = $units;
        } else {
            $slabBreakdown[$slab] = 0;
        }
    }

    return [
        'power_leg' => (float)$powerLegRaw,
        'matching_leg' => (float)$restLegRaw,
        'matched_business' => (float)$totalMatched,
        'power_carry_forward' => (float)$vPower,
        'rest_carry_forward' => (float)$vRest,
        'slab_breakdown' => $slabBreakdown
    ];
}

// standalone local implementation of distributeLevelIncome
function localDistributeLevelIncome($db, $config, $userId, $investmentAmount) {
    $stmt = $db->prepare("SELECT parent_id, level FROM genealogy WHERE user_id = ? AND level <= 12 ORDER BY level ASC");
    $stmt->execute([$userId]);
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($parents as $parent) {
        $level = $parent['level'];
        if (isset($config['level_percentages'][$level])) {
            $percentage = $config['level_percentages'][$level];
            $commission = ($investmentAmount * $percentage) / 100;

            $allowable = getLocalAllowableAmount($parent['parent_id'], $commission, $db, $config);
            if ($allowable > 0) {
                localLogTransaction($db, $parent['parent_id'], 'LEVEL_INCOME', $allowable, 0, "Level {$level} income from user ID: {$userId}", $userId, null, $level);
            }
        }
    }
}

// standalone local implementation of updateUplineRanks
function localUpdateUplineRanks($db, $config, $userId) {
    $stmt = $db->prepare("SELECT parent_id FROM genealogy WHERE user_id = ? ORDER BY level ASC");
    $stmt->execute([$userId]);
    $ancestors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targets = array_merge([['parent_id' => $userId]], $ancestors);

    foreach ($targets as $target) {
        $ancestorId = $target['parent_id'];
        if (empty($ancestorId)) continue;

        $stmtUser = $db->prepare("SELECT id, rank_id, status FROM users WHERE id = ?");
        $stmtUser->execute([$ancestorId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) continue;

        $legStats = localGetLegsBusiness($db, $config, $ancestorId);
        $matchedBusiness = $legStats['matched_business'];
        $slabBreakdown = $legStats['slab_breakdown'] ?? [];

        $qualifiedRankId = localCheckRankQualification($config, $matchedBusiness);

        if ($qualifiedRankId !== null && $qualifiedRankId > $user['rank_id']) {
            $updateRank = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
            $updateRank->execute([$qualifiedRankId, $ancestorId]);
            $user['rank_id'] = $qualifiedRankId;
        }

        foreach ($slabBreakdown as $slab => $requiredUnits) {
            if ($requiredUnits > 0) {
                $stmtSched = $db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
                $stmtSched->execute([$ancestorId, $slab]);
                $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);
                $existingUnits = (int)$existing['count'];

                if ($requiredUnits > $existingUnits) {
                    $dailyIncome = 0.00;
                    foreach ($config['ranks'] as $rankConf) {
                        if ($rankConf['matching'] == $slab) {
                            $dailyIncome = $rankConf['daily_income'];
                            break;
                        }
                    }

                    $newUnits = $requiredUnits - $existingUnits;
                    $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                    for ($i = 0; $i < $newUnits; $i++) {
                        $stmtInsert->execute([$ancestorId, $slab, $dailyIncome]);
                    }
                }
            }
        }
    }
}

$success_msg = '';
$error_msg = '';
$recalc_log = [];

// Handle Post Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action == 'reset_commissions') {
            $targetUserId = $_POST['user_id'];
            $resetInvestments = isset($_POST['reset_investments']) ? (bool)$_POST['reset_investments'] : false;

            $db->beginTransaction();
            try {
                // Fetch target user info
                $stmt = $db->prepare("SELECT username, mid FROM users WHERE id = ?");
                $stmt->execute([$targetUserId]);
                $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$targetUser) {
                    throw new Exception("User not found.");
                }

                // 1. Delete transactions where target user is the related_user_id (commissions they resulted)
                $stmtDelTx = $db->prepare("DELETE FROM transactions WHERE related_user_id = ? AND type IN ('LEVEL_INCOME', 'RANK_INCOME')");
                $stmtDelTx->execute([$targetUserId]);
                $deletedTxCount = $stmtDelTx->rowCount();

                $msg = "Successfully deleted {$deletedTxCount} propagated commission transaction(s) where " . htmlspecialchars($targetUser['username']) . " (MID: " . htmlspecialchars($targetUser['mid']) . ") was the originator.";

                // 2. Optionally delete/reset user's investments
                if ($resetInvestments) {
                    $stmtDelInvest = $db->prepare("DELETE FROM investments WHERE user_id = ?");
                    $stmtDelInvest->execute([$targetUserId]);
                    $deletedInvestCount = $stmtDelInvest->rowCount();

                    $stmtUpdateUser = $db->prepare("UPDATE users SET total_investment = 0.00 WHERE id = ?");
                    $stmtUpdateUser->execute([$targetUserId]);

                    $msg .= " Also deleted {$deletedInvestCount} investment(s) and set user's total investment to $0.00.";
                }

                $db->commit();
                $success_msg = $msg . " Recalculating system to restore perfect data integrity...";

                // Automatically trigger system-wide recalculate after a reset
                $_POST['action'] = 'recalculate'; // fallthrough to recalculate automatically
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error: " . $e->getMessage();
            }
        }

        // Global Recalculate
        if ($_POST['action'] == 'recalculate') {
            $recalc_log[] = "Initializing Global Recalculation...";

            $db->beginTransaction();
            try {
                // Step A: Backup existing matching schedules
                $recalc_log[] = "Backing up existing matching schedules (contracts)...";
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
                $recalc_log[] = "Backed up " . count($backupSchedules) . " schedule(s).";

                // Step B: Clear commission-related tables
                $recalc_log[] = "Clearing LEVEL_INCOME and RANK_INCOME transactions...";
                $db->exec("DELETE FROM transactions WHERE type IN ('LEVEL_INCOME', 'RANK_INCOME')");

                $recalc_log[] = "Clearing all matching schedules...";
                $db->exec("DELETE FROM matching_schedules");

                $recalc_log[] = "Resetting member ranks and total investment statistics...";
                $db->exec("UPDATE users SET rank_id = 0, total_investment = 0.00");

                // Correct total investments based on existing investments
                $recalc_log[] = "Re-aligning total investments per user...";
                $db->exec("UPDATE users u SET u.total_investment = COALESCE((SELECT SUM(i.amount) FROM investments i WHERE i.user_id = u.id), 0.00)");

                // Step C: Chronological processing of all investments
                $recalc_log[] = "Fetching all investments chronologically...";
                $stmtInvestments = $db->query("SELECT * FROM investments ORDER BY id ASC");
                $investments = $stmtInvestments->fetchAll(PDO::FETCH_ASSOC);
                $recalc_log[] = "Processing " . count($investments) . " investment(s) to rebuild tree structures, level commissions, and qualified rank tiers...";

                foreach ($investments as $inv) {
                    $invUserId = $inv['user_id'];
                    $invAmount = $inv['amount'];

                    // Re-distribute Level Income (12 generations)
                    localDistributeLevelIncome($db, $config, $invUserId, $invAmount);

                    // Re-evaluate leg business, update ranks, and insert matching schedules
                    localUpdateUplineRanks($db, $config, $invUserId);
                }

                // Step D: Restore matching schedules' days_passed and status from backup
                $recalc_log[] = "Restoring matching schedule progress and status states from backup...";
                $stmtNewScheds = $db->query("SELECT * FROM matching_schedules ORDER BY id ASC");
                $newScheds = $stmtNewScheds->fetchAll(PDO::FETCH_ASSOC);

                $counters = [];
                $stmtUpdateSched = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");

                $restoredCount = 0;
                foreach ($newScheds as $newSched) {
                    $uid = $newSched['user_id'];
                    $slab = (int)$newSched['slab_amount'];

                    if (!isset($counters[$uid][$slab])) {
                        $counters[$uid][$slab] = 0;
                    }
                    $idx = $counters[$uid][$slab];
                    $counters[$uid][$slab]++;

                    if (isset($backup[$uid][$slab][$idx])) {
                        $old = $backup[$uid][$slab][$idx];
                        $stmtUpdateSched->execute([$old['days_passed'], $old['status'], $newSched['id']]);
                        $restoredCount++;
                    }
                }
                $recalc_log[] = "Successfully synchronized progress on {$restoredCount} matching schedule(s).";

                // Step E: Re-generate daily RANK_INCOME payouts (including propagation)
                $recalc_log[] = "Simulating and regenerating daily Rank Income and Propagated Matching payouts based on restored schedule progress...";

                // Fetch the updated matching schedules
                $stmtFinalScheds = $db->query("SELECT * FROM matching_schedules WHERE days_passed > 0 ORDER BY id ASC");
                $finalScheds = $stmtFinalScheds->fetchAll(PDO::FETCH_ASSOC);

                // LIMIT upward propagation specifically to TWO consecutive referrers (g.level <= 2)
                $txStmtUplines = $db->prepare("
                    SELECT g.parent_id, u.username, u.status, g.level
                    FROM genealogy g
                    JOIN users u ON g.parent_id = u.id
                    WHERE g.user_id = ? AND g.level <= 2
                    ORDER BY g.level ASC
                ");

                $txStmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");

                $generatedPayoutsCount = 0;
                foreach ($finalScheds as $sched) {
                    $schedUserId = $sched['user_id'];
                    $slabAmount = $sched['slab_amount'];
                    $dailyIncome = (float)$sched['daily_income'];
                    $daysPassed = (int)$sched['days_passed'];

                    // Get Username of Earner
                    $txStmtUser->execute([$schedUserId]);
                    $origUserObj = $txStmtUser->fetch(PDO::FETCH_ASSOC);
                    $origUsername = $origUserObj ? $origUserObj['username'] : "user ID $schedUserId";

                    // Simulate payouts day-by-day to accurately apply the 300% ID Cap sequentially
                    for ($day = 1; $day <= $daysPassed; $day++) {
                        $allowable = getLocalAllowableAmount($schedUserId, $dailyIncome, $db, $config);
                        if ($allowable > 0) {
                            // Log matching income for primary earner
                            localLogTransaction(
                                $db,
                                $schedUserId,
                                'RANK_INCOME',
                                $allowable,
                                0,
                                "Daily Matching Income for Slab \$" . number_format($slabAmount, 2) . " (Day {$day}/100)"
                            );
                            $generatedPayoutsCount++;

                            // Fetch and propagate specifically to up to two consecutive sponsors
                            $txStmtUplines->execute([$schedUserId]);
                            $uplines = $txStmtUplines->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($uplines as $upline) {
                                if ($upline['status'] === 'active') {
                                    $uplineAllowable = getLocalAllowableAmount($upline['parent_id'], $allowable, $db, $config);
                                    if ($uplineAllowable > 0) {
                                        localLogTransaction(
                                            $db,
                                            $upline['parent_id'],
                                            'RANK_INCOME',
                                            $uplineAllowable,
                                            0,
                                            "Daily Propagated Match Income from " . $origUsername . " (Slab \$" . number_format($slabAmount, 2) . ")",
                                            $schedUserId
                                        );
                                        $generatedPayoutsCount++;
                                    }
                                }
                            }
                        }
                    }
                }
                $recalc_log[] = "Generated {$generatedPayoutsCount} simulated Rank Income and propagated matching transactions.";

                $db->commit();
                $recalc_log[] = "Database transaction successfully committed!";
                $success_msg = ($success_msg ?: "System Recalculation Completed successfully!") . " All ranks, structures, level incomes, and matching schedules are perfectly synchronized with 2-level upward referrer propagation.";
            } catch (Exception $e) {
                $db->rollBack();
                $recalc_log[] = "CRITICAL ERROR: " . $e->getMessage();
                $recalc_log[] = "Database transaction rolled back to previous state.";
                $error_msg = "Recalculation Failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch Search Results
$searchQuery = $_GET['search'] ?? '';
$searchResults = [];
if (!empty($searchQuery)) {
    $stmtSearch = $db->prepare("SELECT id, username, email, mid, total_investment, rank_id FROM users WHERE username LIKE ? OR email LIKE ? OR mid LIKE ?");
    $stmtSearch->execute(["%$searchQuery%", "%$searchQuery%", "%$searchQuery%"]);
    $searchResults = $stmtSearch->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Selected User details if any
$selectedUser = null;
$resultedLevelIncome = 0.00;
$resultedRankIncome = 0.00;
$resultedTransactions = [];

if (isset($_GET['user_id'])) {
    $selUserId = $_GET['user_id'];
    $stmtSel = $db->prepare("SELECT u.* FROM users u WHERE u.id = ?");
    $stmtSel->execute([$selUserId]);
    $selectedUser = $stmtSel->fetch(PDO::FETCH_ASSOC);

    if ($selectedUser) {
        // Find Rank name
        $rankName = 'None';
        if ($selectedUser['rank_id'] > 0 && isset($config['ranks'][$selectedUser['rank_id'] - 1])) {
            $rankName = $config['ranks'][$selectedUser['rank_id'] - 1]['name'];
        }
        $selectedUser['rank_name'] = $rankName;

        // Calculate Total Level Income resulted upwards
        $stmtLvl = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) as total FROM transactions WHERE related_user_id = ? AND type = 'LEVEL_INCOME'");
        $stmtLvl->execute([$selUserId]);
        $resultedLevelIncome = (float)$stmtLvl->fetch(PDO::FETCH_ASSOC)['total'];

        // Calculate Total Rank Income resulted upwards
        $stmtRnk = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) as total FROM transactions WHERE related_user_id = ? AND type = 'RANK_INCOME'");
        $stmtRnk->execute([$selUserId]);
        $resultedRankIncome = (float)$stmtRnk->fetch(PDO::FETCH_ASSOC)['total'];

        // Get detailed list of transactions resulted
        $stmtTx = $db->prepare("
            SELECT t.*, u.username as receiver_username, u.mid as receiver_mid
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE t.related_user_id = ? AND t.type IN ('LEVEL_INCOME', 'RANK_INCOME')
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
        $stmtTx->execute([$selUserId]);
        $resultedTransactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="row">
    <div class="col-md-12 mb-3">
        <h3>Reset & Recalculate Commissions</h3>
        <p class="text-muted">Search for a member to reset the level and matching commissions they resulted upwards, or run a global system recalculation to restore organic rank states, level payouts, and matching schedules from scratch.</p>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success">
        <strong>Success!</strong> <?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger">
        <strong>Error!</strong> <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<?php if (!empty($recalc_log)): ?>
    <div class="card mb-4 bg-dark text-light border-0">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span>System Recalculation Console Log</span>
            <button class="btn btn-sm btn-outline-light" onclick="document.getElementById('consoleLog').classList.toggle('d-none')">Toggle Console</button>
        </div>
        <div class="card-body p-3 font-monospace" id="consoleLog" style="max-height: 250px; overflow-y: auto; font-size: 13px;">
            <?php foreach ($recalc_log as $logLine): ?>
                <div class="mb-1 text-info">> <span class="text-light"><?php echo htmlspecialchars($logLine); ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Left Panel: Search & Global Recalculate -->
    <div class="col-md-5">
        <!-- Search Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white"><i class="fa fa-search me-2"></i>Search Member</div>
            <div class="card-body">
                <form method="get" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control" placeholder="Username, email, or MID..." value="<?php echo htmlspecialchars($searchQuery); ?>" required>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
                </form>

                <?php if (!empty($searchQuery)): ?>
                    <h5 class="mt-4">Search Results:</h5>
                    <?php if (empty($searchResults)): ?>
                        <div class="alert alert-warning py-2 mt-2">No members found matching "<?php echo htmlspecialchars($searchQuery); ?>"</div>
                    <?php else: ?>
                        <div class="list-group mt-2">
                            <?php foreach ($searchResults as $userRow): ?>
                                <a href="reset_commissions.php?search=<?php echo urlencode($searchQuery); ?>&user_id=<?php echo $userRow['id']; ?>" class="list-group-item list-group-item-action <?php echo (isset($selUserId) && $selUserId == $userRow['id']) ? 'active' : ''; ?>">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><strong><?php echo htmlspecialchars($userRow['username']); ?></strong> (<?php echo htmlspecialchars($userRow['mid']); ?>)</h6>
                                        <small class="<?php echo (isset($selUserId) && $selUserId == $userRow['id']) ? 'text-white' : 'text-muted'; ?>">$<?php echo number_format($userRow['total_investment'], 2); ?></small>
                                    </div>
                                    <small class="mb-1 d-block"><?php echo htmlspecialchars($userRow['email']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Global Recalculate Card -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark"><i class="fa fa-calculator me-2"></i>System Recalculation Engine</div>
            <div class="card-body">
                <p>Run a full database recalculation. This action will:</p>
                <ul class="small">
                    <li>Reset all levels and matching incomes.</li>
                    <li>Re-align total investments & unilevel leg businesses.</li>
                    <li>Re-build rank achievements based on actual matched slab business.</li>
                    <li>Re-process matching schedule durations and propagate matching daily payouts up to exactly two consecutive sponsors (Level 1 and 2).</li>
                </ul>
                <div class="alert alert-info py-2 small">Note: Your active ROI contracts and transaction records remain safe.</div>
                <form method="post" onsubmit="return confirm('WARNING: This will delete and recalculate ALL level incomes, ranks, and matching schedules across the entire network. Are you absolutely sure?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="recalculate">
                    <button type="submit" class="btn btn-warning w-100 text-dark fw-bold"><i class="fa fa-sync-alt me-2"></i>Recalculate System</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Panel: Manage Selected User & Resulted Commissions -->
    <div class="col-md-7">
        <?php if ($selectedUser): ?>
            <div class="card mb-4">
                <div class="card-header bg-dark text-white"><i class="fa fa-user me-2"></i>Member: <?php echo htmlspecialchars($selectedUser['username']); ?> (<?php echo htmlspecialchars($selectedUser['mid']); ?>)</div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6 border-end">
                            <span class="text-muted small d-block">Full Name</span>
                            <strong><?php echo htmlspecialchars($selectedUser['full_name'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted small d-block">Total Investment</span>
                            <strong class="text-success">$<?php echo number_format($selectedUser['total_investment'], 2); ?></strong>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 border-end">
                            <span class="text-muted small d-block">Rank State</span>
                            <span class="badge bg-info"><?php echo $selectedUser['rank_name'] ?: 'None'; ?></span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted small d-block">Joined Date</span>
                            <strong><?php echo date('Y-m-d', strtotime($selectedUser['created_at'])); ?></strong>
                        </div>
                    </div>

                    <hr>

                    <h5 class="text-primary mb-3">Commissions Resulted Upwards</h5>
                    <div class="row mb-4">
                        <div class="col-md-6 mb-2">
                            <div class="p-3 bg-light rounded text-center border">
                                <span class="text-muted small d-block">Resulted Level Income</span>
                                <h4 class="text-danger mb-0">$<?php echo number_format($resultedLevelIncome, 2); ?></h4>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="p-3 bg-light rounded text-center border">
                                <span class="text-muted small d-block">Resulted Rank Income</span>
                                <h4 class="text-danger mb-0">$<?php echo number_format($resultedRankIncome, 2); ?></h4>
                            </div>
                        </div>
                    </div>

                    <!-- Reset Action Form -->
                    <div class="p-3 bg-light rounded border border-danger mb-4">
                        <h6 class="text-danger"><i class="fa fa-exclamation-triangle me-2"></i>Danger Zone: Reset Commissions</h6>
                        <p class="small text-muted">This will delete all level income and rank matching payouts propagated upwards to sponsors because of this user. The system will automatically recalculate ranks and payouts for all affected members immediately after.</p>

                        <form method="post" onsubmit="return confirm('Are you sure you want to delete all commissions resulted upwards by this user? The system will recalculate immediately after to maintain consistency.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                            <input type="hidden" name="action" value="reset_commissions">

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="reset_investments" id="reset_investments" value="1">
                                <label class="form-check-label small text-danger" for="reset_investments">
                                    <strong>Also delete this user's active investments</strong> (This sets their total investment to $0.00 and permanently removes their investments so they no longer generate any future/recalculated commissions or leg volume).
                                </label>
                            </div>

                            <button type="submit" class="btn btn-danger btn-sm"><i class="fa fa-trash-alt me-2"></i>Reset Resulted Commissions</button>
                        </form>
                    </div>

                    <!-- Resulted Transactions Details -->
                    <h5>Most Recent Propagated Transactions Log</h5>
                    <?php if (empty($resultedTransactions)): ?>
                        <div class="alert alert-light text-center border py-4">No level or rank matching commissions currently logged from this user's activity.</div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                            <table class="table table-hover table-sm small">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Receiver</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resultedTransactions as $tx): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($tx['receiver_username']); ?></strong> (<?php echo htmlspecialchars($tx['receiver_mid']); ?>)</td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($tx['type']); ?></span></td>
                                            <td class="text-success fw-bold">$<?php echo number_format($tx['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fa fa-user-slash fa-3x text-muted mb-3"></i>
                    <h5>No Member Selected</h5>
                    <p class="text-muted">Search for a member on the left panel and select them to manage the commissions they resulted upwards.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>