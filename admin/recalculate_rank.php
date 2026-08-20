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

$pageTitle = 'Recalculate Rank & Income';
include __DIR__ . '/includes/header.php';

// Helper function to get allowable amount (300% ID cap removed)
function getAllowableAmount($db, $config, $userId, $amountToAdd) {
    return $amountToAdd;
}

// Local Helper: Calculate unilevel leg business volumes and apply Sequential Slab-Matching Hierarchy
function localGetLegsBusiness($db, $userId) {
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

// Local Helper: Log Transaction directly
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

$midQuery = $_GET['mid'] ?? '';
$user = null;
$matches = [];
$legStats = null;
$slabBreakdown = [];
$schedules = [];
$existingSchedulesBySlab = [];
$actualDirectRankIncome = 0.00;
$actualDirectRankIncomeCount = 0;
$expectedDirectRankIncome = 0.00;
$qualifiedRankId = 0;
$qualifiedRankName = 'None';
$recalcSuccess = null;
$recalcError = null;

// Search Logic
if (!empty($midQuery)) {
    // Search by mid exact first, then username/email
    $stmt = $db->prepare("SELECT * FROM users WHERE mid = ?");
    $stmt->execute([$midQuery]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // If not exact mid, search like
        $stmt = $db->prepare("SELECT * FROM users WHERE mid LIKE ? OR username LIKE ? OR email LIKE ?");
        $stmt->execute(["%$midQuery%", "%$midQuery%", "%$midQuery%"]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($matches) === 1) {
            $user = $matches[0];
            $matches = [];
        }
    }
}

// Load data for selected user
if ($user) {
    $legStats = localGetLegsBusiness($db, $user['id']);
    $matchedBusiness = (float)$legStats['matched_business'];
    $slabBreakdown = $legStats['slab_breakdown'] ?? [];

    // Evaluate Qualified Rank ID & Name
    foreach ($config['ranks'] as $idx => $rank) {
        if ($matchedBusiness >= $rank['matching']) {
            $qualifiedRankId = $idx + 1;
            $qualifiedRankName = $rank['name'];
        } else {
            break;
        }
    }

    // Load current matching schedules
    $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ? ORDER BY slab_amount ASC, id ASC");
    $stmt->execute([$user['id']]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group schedules by slab
    foreach ($config['ranks'] as $rank) {
        $existingSchedulesBySlab[$rank['matching']] = [
            'active' => 0,
            'completed' => 0,
            'total' => 0,
            'schedules' => []
        ];
    }
    foreach ($schedules as $sched) {
        $slab = (int)$sched['slab_amount'];
        if (!isset($existingSchedulesBySlab[$slab])) {
            $existingSchedulesBySlab[$slab] = [
                'active' => 0,
                'completed' => 0,
                'total' => 0,
                'schedules' => []
            ];
        }
        $existingSchedulesBySlab[$slab]['total']++;
        if ($sched['status'] == 'active') {
            $existingSchedulesBySlab[$slab]['active']++;
        } else {
            $existingSchedulesBySlab[$slab]['completed']++;
        }
        $existingSchedulesBySlab[$slab]['schedules'][] = $sched;
    }

    // Direct rank income transactions
    $stmt = $db->prepare("SELECT SUM(amount) as total_amount, COUNT(*) as tx_count FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL");
    $stmt->execute([$user['id']]);
    $directTxStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $actualDirectRankIncome = (float)($directTxStats['total_amount'] ?? 0);
    $actualDirectRankIncomeCount = (int)($directTxStats['tx_count'] ?? 0);

    // Expected direct rank income
    foreach ($schedules as $sched) {
        $expectedDirectRankIncome += (float)$sched['days_passed'] * (float)$sched['daily_income'];
    }
}

// Handle Form Action (Recalculate & Correction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if (isset($_POST['action']) && $_POST['action'] === 'pay_schedule' && isset($_POST['schedule_id'])) {
        // CSRF Verification
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
            $recalcError = "CSRF token validation failed.";
        } else {
            $schedId = (int)$_POST['schedule_id'];
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE id = ? AND user_id = ? AND status = 'active'");
                $stmt->execute([$schedId, $user['id']]);
                $sched = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sched) {
                    throw new Exception("Active matching schedule not found.");
                }

                $dailyIncome = (float)$sched['daily_income'];
                $allowable = getAllowableAmount($db, $config, $user['id'], $dailyIncome);

                if ($allowable > 0) {
                    // Log direct transaction
                    localLogTransaction(
                        $db,
                        $user['id'],
                        'RANK_INCOME',
                        $allowable,
                        0,
                        "Daily Matching Income for Slab \$" . number_format($sched['slab_amount'], 2) . " (Day " . ($sched['days_passed'] + 1) . "/100) [Manual Trigger]"
                    );

                    $newDaysPassed = $sched['days_passed'] + 1;
                    $status = ($newDaysPassed >= $sched['max_days']) ? 'completed' : 'active';

                    $stmtUpdateSched = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
                    $stmtUpdateSched->execute([$newDaysPassed, $status, $sched['id']]);

                    // Propagate uplines
                    $stmtUplines = $db->prepare("
                        SELECT g.parent_id, u.username, u.status
                        FROM genealogy g
                        JOIN users u ON g.parent_id = u.id
                        WHERE g.user_id = ?
                        ORDER BY g.level ASC
                    ");
                    $stmtUplines->execute([$user['id']]);
                    $uplines = $stmtUplines->fetchAll();

                    $origUsername = $user['username'];
                    $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
                    $consecutiveSingleCount = 0;

                    foreach ($uplines as $upline) {
                        // Check single referral orphan node limit
                        $stmtReferrals->execute([$upline['parent_id']]);
                        $refData = $stmtReferrals->fetch();
                        $refCount = (int)$refData['ref_count'];

                        if ($refCount === 1) {
                            $consecutiveSingleCount++;
                        } else {
                            $consecutiveSingleCount = 0;
                        }

                        if ($consecutiveSingleCount > 3) {
                            break;
                        }

                        if ($upline['status'] === 'active') {
                            $uplineAllowable = getAllowableAmount($db, $config, $upline['parent_id'], $allowable);
                            if ($uplineAllowable > 0) {
                                localLogTransaction(
                                    $db,
                                    $upline['parent_id'],
                                    'RANK_INCOME',
                                    $uplineAllowable,
                                    0,
                                    "Daily Propagated Match Income from " . $origUsername . " (Slab \$" . number_format($sched['slab_amount'], 2) . ") [Manual Trigger]",
                                    $user['id']
                                );
                            }
                        }

                        if ($consecutiveSingleCount === 3) {
                            break;
                        }
                    }

                    $db->commit();
                    $recalcSuccess = "Daily rank payout manually processed for Schedule ID: {$schedId}. Direct & propagated incomes updated.";
                } else {
                    $db->rollBack();
                    $recalcError = "Failed to pay.";
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $recalcError = "Error during manual payout: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['perform_recalc'])) {
        // CSRF Verification
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
            $recalcError = "CSRF token validation failed.";
        } else {
            $db->beginTransaction();
            try {
                $logMessages = [];

                // Action 1: Update Rank ID
                if (isset($_POST['update_rank_id']) && $_POST['update_rank_id'] == '1') {
                    if ($user['rank_id'] != $qualifiedRankId) {
                        $stmt = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                        $stmt->execute([$qualifiedRankId, $user['id']]);
                        $oldRankName = ($user['rank_id'] > 0 && isset($config['ranks'][$user['rank_id']-1])) ? $config['ranks'][$user['rank_id']-1]['name'] : 'None';
                        $logMessages[] = "Updated Rank: changed from '{$oldRankName}' to '{$qualifiedRankName}' (Rank ID: {$qualifiedRankId}).";
                    } else {
                        $logMessages[] = "Rank is already correct ({$qualifiedRankName}).";
                    }
                }

                // Action 2: Sync Matching Schedules
                if (isset($_POST['sync_schedules']) && $_POST['sync_schedules'] == '1') {
                    $insertedCount = 0;
                    foreach ($config['ranks'] as $rankConf) {
                        $slab = (int)$rankConf['matching'];
                        $required = $slabBreakdown[$slab] ?? 0;
                        $existing = $existingSchedulesBySlab[$slab]['total'] ?? 0;

                        if ($required > $existing) {
                            $diff = $required - $existing;
                            $dailyIncome = $rankConf['daily_income'];
                            $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                            for ($i = 0; $i < $diff; $i++) {
                                $stmtInsert->execute([$user['id'], $slab, $dailyIncome]);
                                $insertedCount++;
                            }
                        }
                    }
                    if ($insertedCount > 0) {
                        $logMessages[] = "Schedules Synced: Created {$insertedCount} missing matching schedules.";
                    } else {
                        $logMessages[] = "Schedules Synced: No under-allocated matching schedules found.";
                    }
                }

                // Action 3: Prune excess schedules
                if (isset($_POST['prune_schedules']) && $_POST['prune_schedules'] == '1') {
                    $deletedCount = 0;
                    foreach ($config['ranks'] as $rankConf) {
                        $slab = (int)$rankConf['matching'];
                        $required = $slabBreakdown[$slab] ?? 0;
                        $existing = $existingSchedulesBySlab[$slab]['total'] ?? 0;

                        if ($required < $existing) {
                            $excess = $existing - $required;
                            // Select up to excess active schedules starting from the newest
                            $stmtSelectActive = $db->prepare("SELECT id FROM matching_schedules WHERE user_id = ? AND slab_amount = ? AND status = 'active' ORDER BY id DESC LIMIT ?");
                            $stmtSelectActive->bindValue(1, $user['id'], PDO::PARAM_INT);
                            $stmtSelectActive->bindValue(2, $slab, PDO::PARAM_INT);
                            $stmtSelectActive->bindValue(3, $excess, PDO::PARAM_INT);
                            $stmtSelectActive->execute();
                            $activeToPrune = $stmtSelectActive->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($activeToPrune)) {
                                $inClause = implode(',', array_fill(0, count($activeToPrune), '?'));
                                $stmtDelete = $db->prepare("DELETE FROM matching_schedules WHERE id IN ($inClause)");
                                $stmtDelete->execute($activeToPrune);
                                $deletedCount += count($activeToPrune);
                            }
                        }
                    }
                    if ($deletedCount > 0) {
                        $logMessages[] = "Pruned: Deleted {$deletedCount} excess active matching schedules to restore organic status.";
                    } else {
                        $logMessages[] = "Pruned: No excess active matching schedules found or pruned.";
                    }
                }

                // Action 4: Reconcile direct transactions
                if (isset($_POST['reconcile_transactions']) && $_POST['reconcile_transactions'] == '1') {
                    // Fetch fresh schedule stats after previous operations
                    $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $freshScheds = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $freshExpected = 0.00;
                    foreach ($freshScheds as $fs) {
                        $freshExpected += (float)$fs['days_passed'] * (float)$fs['daily_income'];
                    }

                    $stmt = $db->prepare("SELECT SUM(amount) as total_amount FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL");
                    $stmt->execute([$user['id']]);
                    $freshActStats = $stmt->fetch(PDO::FETCH_ASSOC);
                    $freshActual = (float)($freshActStats['total_amount'] ?? 0);

                    if ($freshExpected > $freshActual) {
                        $diffAmount = $freshExpected - $freshActual;
                        localLogTransaction(
                            $db,
                            $user['id'],
                            'RANK_INCOME',
                            $diffAmount,
                            0,
                            "Rank Income Reconciliation: Added missing direct rank income of \$" . number_format($diffAmount, 2) . " to align with matching schedules"
                        );
                        $logMessages[] = "Reconciled Transactions: Created missing direct transaction log of \$" . number_format($diffAmount, 2) . " to match expected payouts.";
                    } else {
                        $logMessages[] = "Reconciled Transactions: Direct rank income is already fully reconciled.";
                    }
                }

                $db->commit();
                $recalcSuccess = "Recalculation and Corrections applied successfully!<br>" . implode("<br>", array_map('htmlspecialchars', $logMessages));
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $recalcError = "Error during recalculation: " . $e->getMessage();
            }
        }
    }

    // Refresh display values
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $legStats = localGetLegsBusiness($db, $user['id']);
    $matchedBusiness = (float)$legStats['matched_business'];
    $slabBreakdown = $legStats['slab_breakdown'] ?? [];

    $qualifiedRankId = 0;
    $qualifiedRankName = 'None';
    foreach ($config['ranks'] as $idx => $rank) {
        if ($matchedBusiness >= $rank['matching']) {
            $qualifiedRankId = $idx + 1;
            $qualifiedRankName = $rank['name'];
        } else {
            break;
        }
    }

    $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ? ORDER BY slab_amount ASC, id ASC");
    $stmt->execute([$user['id']]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existingSchedulesBySlab = [];
    foreach ($config['ranks'] as $rank) {
        $existingSchedulesBySlab[$rank['matching']] = [
            'active' => 0,
            'completed' => 0,
            'total' => 0,
            'schedules' => []
        ];
    }
    foreach ($schedules as $sched) {
        $slab = (int)$sched['slab_amount'];
        if (!isset($existingSchedulesBySlab[$slab])) {
            $existingSchedulesBySlab[$slab] = [
                'active' => 0,
                'completed' => 0,
                'total' => 0,
                'schedules' => []
            ];
        }
        $existingSchedulesBySlab[$slab]['total']++;
        if ($sched['status'] == 'active') {
            $existingSchedulesBySlab[$slab]['active']++;
        } else {
            $existingSchedulesBySlab[$slab]['completed']++;
        }
        $existingSchedulesBySlab[$slab]['schedules'][] = $sched;
    }

    $stmt = $db->prepare("SELECT SUM(amount) as total_amount, COUNT(*) as tx_count FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL");
    $stmt->execute([$user['id']]);
    $directTxStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $actualDirectRankIncome = (float)($directTxStats['total_amount'] ?? 0);
    $actualDirectRankIncomeCount = (int)($directTxStats['tx_count'] ?? 0);

    $expectedDirectRankIncome = 0.00;
    foreach ($schedules as $sched) {
        $expectedDirectRankIncome += (float)$sched['days_passed'] * (float)$sched['daily_income'];
    }
}
?>

<style>
    .table td, .table th { color: #000 !important; }
    .badge-unmatched { background-color: #6c757d; }
    .status-alert { font-size: 15px; border-radius: 8px; }
</style>

<div class="mb-4">
    <h3>Recalculate Rank & Rank Income</h3>
    <p class="text-muted">Search for a member by their Member ID (MID), Username, or Email, analyze their organic business, and correct rank status, matching contracts/schedules, and transaction balances.</p>
</div>

<!-- Search Form -->
<form method="get" class="row g-2 align-items-center bg-white p-3 rounded shadow-sm border mb-4">
    <div class="col-md-9">
        <div class="input-group">
            <span class="input-group-text"><i class="fa fa-search text-muted"></i></span>
            <input type="text" name="mid" class="form-control" placeholder="Enter Member MID, Username, or Email..." value="<?php echo htmlspecialchars($midQuery); ?>" required autocomplete="off">
        </div>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>Search Member</button>
    </div>
</form>

<?php if (!empty($recalcSuccess)): ?>
    <div class="alert alert-success status-alert shadow-sm mb-4">
        <h5 class="alert-heading"><i class="fa fa-check-circle me-1"></i> Success!</h5>
        <p class="mb-0"><?php echo $recalcSuccess; ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($recalcError)): ?>
    <div class="alert alert-danger status-alert shadow-sm mb-4">
        <h5 class="alert-heading"><i class="fa fa-exclamation-triangle me-1"></i> Error</h5>
        <p class="mb-0"><?php echo htmlspecialchars($recalcError); ?></p>
    </div>
<?php endif; ?>

<!-- Multiple matches selector -->
<?php if (!empty($matches)): ?>
    <div class="card mb-4 shadow-sm border-warning">
        <div class="card-header bg-warning text-dark font-weight-bold d-flex align-items-center">
            <i class="fa fa-users me-2"></i> Multiple Members Found (<?php echo count($matches); ?> matches)
        </div>
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>MID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matches as $m): ?>
                        <tr>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid']); ?></strong></td>
                            <td><?php echo htmlspecialchars($m['username']); ?></td>
                            <td><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $m['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo strtoupper($m['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="recalculate_rank.php?mid=<?php echo urlencode($m['mid']); ?>" class="btn btn-sm btn-primary">Select Member</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif (!empty($midQuery) && !$user): ?>
    <div class="alert alert-warning shadow-sm mb-4">
        <i class="fa fa-info-circle me-1"></i> No members found matching "<strong><?php echo htmlspecialchars($midQuery); ?></strong>". Please try again.
    </div>
<?php endif; ?>

<!-- Member Recalculation Panel -->
<?php if ($user): ?>
    <div class="row g-4">
        <!-- Profile & Overview Card -->
        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold">
                    <i class="fa fa-user-circle me-1"></i> Member Overview
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h4 class="mb-1"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h4>
                        <span class="badge bg-primary px-3 py-2" style="font-size: 14px;">MID: <?php echo htmlspecialchars($user['mid']); ?></span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Username</span>
                            <span class="font-weight-bold"><?php echo htmlspecialchars($user['username']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Email</span>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Status</span>
                            <span class="badge <?php echo $user['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo strtoupper($user['status']); ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Total Personal Investment</span>
                            <strong class="text-success">$<?php echo number_format($user['total_investment'], 2); ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Joined On</span>
                            <span><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Legs & Rank Analysis Card -->
        <div class="col-lg-8">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-sitemap me-1"></i> Legs & Rank Analysis</span>
                    <span class="badge bg-secondary">Real-time Computation</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block uppercase font-weight-bold">Power Leg Volume</small>
                                <h3 class="mb-0 text-primary">$<?php echo number_format($legStats['power_leg'], 2); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block uppercase font-weight-bold">Matching Leg Volume</small>
                                <h3 class="mb-0 text-primary">$<?php echo number_format($legStats['matching_leg'], 2); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block uppercase font-weight-bold">Matched Business Volume</small>
                                <h3 class="mb-0 text-success">$<?php echo number_format($legStats['matched_business'], 2); ?></h3>
                            </div>
                        </div>
                    </div>

                    <!-- Rank Match Comparison -->
                    <div class="row align-items-center g-3 bg-light p-3 rounded border mb-0">
                        <div class="col-sm-5 text-center">
                            <small class="text-muted d-block">Current DB Stored Rank</small>
                            <h4 class="mb-0 text-danger">
                                <?php echo ($user['rank_id'] > 0 && isset($config['ranks'][$user['rank_id']-1])) ? htmlspecialchars($config['ranks'][$user['rank_id']-1]['name']) : 'None'; ?>
                                <small class="text-muted d-block" style="font-size: 13px;">Rank ID: <?php echo $user['rank_id']; ?></small>
                            </h4>
                        </div>
                        <div class="col-sm-2 text-center">
                            <i class="fa fa-chevron-right fa-2x text-muted d-none d-sm-inline"></i>
                            <i class="fa fa-chevron-down fa-2x text-muted d-inline d-sm-none"></i>
                        </div>
                        <div class="col-sm-5 text-center">
                            <small class="text-muted d-block">Organic Qualified Rank</small>
                            <h4 class="mb-0 text-success">
                                <?php echo htmlspecialchars($qualifiedRankName); ?>
                                <small class="text-muted d-block" style="font-size: 13px;">Rank ID: <?php echo $qualifiedRankId; ?></small>
                            </h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Matching Slabs and Contracts Comparison -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold">
                    <i class="fa fa-list-alt me-1"></i> Dynamic Slab Matching & Contracts Verification
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank Tier</th>
                                    <th>Required Slab Business ($)</th>
                                    <th class="text-center">Organic Required Contracts</th>
                                    <th class="text-center">DB Registered Contracts</th>
                                    <th>Status</th>
                                    <th>Reconciliation Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($config['ranks'] as $idx => $rConf):
                                    $slab = (int)$rConf['matching'];
                                    $req = (int)($slabBreakdown[$slab] ?? 0);
                                    $exist = (int)($existingSchedulesBySlab[$slab]['total'] ?? 0);

                                    $rowClass = '';
                                    $statusBadge = '';
                                    $actionText = '';

                                    if ($req == $exist) {
                                        $statusBadge = '<span class="badge bg-success"><i class="fa fa-check me-1"></i>Aligned</span>';
                                        $actionText = '<span class="text-muted">No correction needed</span>';
                                    } elseif ($req > $exist) {
                                        $rowClass = 'table-danger';
                                        $diff = $req - $exist;
                                        $statusBadge = '<span class="badge bg-danger"><i class="fa fa-exclamation-triangle me-1"></i>Under-allocated</span>';
                                        $actionText = "<span class='text-danger font-weight-bold'><i class='fa fa-plus-circle me-1'></i>Will generate {$diff} contract(s)</span>";
                                    } else {
                                        $rowClass = 'table-warning';
                                        $diff = $exist - $req;
                                        $statusBadge = '<span class="badge bg-warning text-dark"><i class="fa fa-info-circle me-1"></i>Over-allocated</span>';
                                        $actionText = "<span class='text-warning font-weight-bold'><i class='fa fa-minus-circle me-1'></i>Prune option available ({$diff} excess contract(s))</span>";
                                    }
                                ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td><strong><?php echo htmlspecialchars($rConf['name']); ?></strong></td>
                                    <td>$<?php echo number_format($slab); ?></td>
                                    <td class="text-center"><strong><?php echo $req; ?></strong></td>
                                    <td class="text-center"><strong><?php echo $exist; ?></strong></td>
                                    <td><?php echo $statusBadge; ?></td>
                                    <td><?php echo $actionText; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Direct Rank Income Reconciliation Card -->
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold">
                    <i class="fa fa-calculator me-1"></i> Rank Income Balance Reconciler
                </div>
                <div class="card-body">
                    <p class="text-muted">Expected direct rank income is computed based on the total progressed <code>days_passed</code> on matching contracts. Actual direct rank income represents direct transactions in the database.</p>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block">Expected Rank Income</small>
                                <h4 class="mb-0 text-primary">$<?php echo number_format($expectedDirectRankIncome, 2); ?></h4>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded text-center border">
                                <small class="text-muted d-block">Actual Recorded Direct Income</small>
                                <h4 class="mb-0 <?php echo $actualDirectRankIncome == $expectedDirectRankIncome ? 'text-success' : 'text-danger'; ?>">
                                    $<?php echo number_format($actualDirectRankIncome, 2); ?>
                                    <small class="text-muted d-block" style="font-size: 12px;">Across <?php echo $actualDirectRankIncomeCount; ?> log(s)</small>
                                </h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($expectedDirectRankIncome > $actualDirectRankIncome):
                        $missingAmt = $expectedDirectRankIncome - $actualDirectRankIncome;
                    ?>
                        <div class="alert alert-warning mb-0 border-warning">
                            <i class="fa fa-exclamation-circle me-1"></i> <strong>Discrepancy Detected!</strong> User is missing <strong>$<?php echo number_format($missingAmt, 2); ?></strong> in direct rank income transaction logs. The correction form will safely inject a reconciliation transaction.
                        </div>
                    <?php elseif ($expectedDirectRankIncome < $actualDirectRankIncome): ?>
                        <div class="alert alert-info mb-0 border-info">
                            <i class="fa fa-info-circle me-1"></i> User has surplus direct rank income transaction logs in comparison to the recorded contract progress. No actions are strictly required unless manual adjustments are needed.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mb-0 border-success">
                            <i class="fa fa-check-circle me-1"></i> Direct rank income transactions are perfectly in sync with matching schedule contract progress!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Correction Action Form -->
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm border-primary">
                <div class="card-header bg-primary text-white font-weight-bold">
                    <i class="fa fa-wrench me-1"></i> Apply Selected Corrections
                </div>
                <div class="card-body">
                    <form method="post" onsubmit="return confirm('Are you sure you want to run these corrections? Selected databases and balances will be updated immediately.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                        <input type="hidden" name="perform_recalc" value="1">

                        <p class="text-muted mb-3">Check the adjustments you would like the system to perform for <strong><?php echo htmlspecialchars($user['username']); ?></strong>:</p>

                        <!-- Option 1: Update Rank ID -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="update_rank_id" value="1" id="chkRank" <?php echo ($user['rank_id'] != $qualifiedRankId) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chkRank">
                                <strong>Update Rank ID in DB</strong>
                                <small class="text-muted d-block">Will change Rank ID from <code><?php echo $user['rank_id']; ?></code> to <code><?php echo $qualifiedRankId; ?></code> (<?php echo htmlspecialchars($qualifiedRankName); ?>)</small>
                            </label>
                        </div>

                        <!-- Option 2: Sync Underallocated Schedules -->
                        <?php
                        $underCount = 0;
                        foreach ($config['ranks'] as $rConf) {
                            $slab = (int)$rConf['matching'];
                            $req = (int)($slabBreakdown[$slab] ?? 0);
                            $exist = (int)($existingSchedulesBySlab[$slab]['total'] ?? 0);
                            if ($req > $exist) {
                                $underCount += ($req - $exist);
                            }
                        }
                        ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="sync_schedules" value="1" id="chkSync" <?php echo ($underCount > 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chkSync">
                                <strong>Generate Missing Matching Schedules</strong>
                                <small class="text-muted d-block">Will create <code><?php echo $underCount; ?></code> missing matching schedule contract(s) dynamically</small>
                            </label>
                        </div>

                        <!-- Option 3: Prune Overallocated Schedules -->
                        <?php
                        $overCount = 0;
                        foreach ($config['ranks'] as $rConf) {
                            $slab = (int)$rConf['matching'];
                            $req = (int)($slabBreakdown[$slab] ?? 0);
                            $exist = (int)($existingSchedulesBySlab[$slab]['total'] ?? 0);
                            if ($exist > $req) {
                                $overCount += ($exist - $req);
                            }
                        }
                        ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="prune_schedules" value="1" id="chkPrune" <?php echo ($overCount > 0) ? '' : 'disabled'; ?>>
                            <label class="form-check-label" for="chkPrune">
                                <strong>Prune Excess Active Matching Schedules</strong>
                                <small class="text-muted d-block">Will delete up to <code><?php echo $overCount; ?></code> excess active schedule contract(s) to match organic volumes (If available)</small>
                            </label>
                        </div>

                        <!-- Option 4: Reconcile Transactions -->
                        <?php
                        $hasMissingIncome = ($expectedDirectRankIncome > $actualDirectRankIncome);
                        ?>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="reconcile_transactions" value="1" id="chkReconcile" <?php echo $hasMissingIncome ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chkReconcile">
                                <strong>Inject Missing Rank Income Transactions</strong>
                                <small class="text-muted d-block">Will generate a safe reconciliation transaction entry of <code>$<?php echo number_format(max(0, $expectedDirectRankIncome - $actualDirectRankIncome), 2); ?></code> into transactions table</small>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2"><i class="fa fa-magic me-1"></i>Run Selected Corrections</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Matching Contracts Schedule Detail List -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold">
                    <i class="fa fa-file-invoice-dollar me-1"></i> Registered Matching Contracts / Schedules
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Schedule ID</th>
                                    <th>Slab Amount ($)</th>
                                    <th>Daily Income ($)</th>
                                    <th class="text-center">Days Passed</th>
                                    <th>Max Days</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedules)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No matching schedule contracts registered for this member.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($schedules as $sched): ?>
                                    <tr>
                                        <td><code>#<?php echo $sched['id']; ?></code></td>
                                        <td><strong>$<?php echo number_format($sched['slab_amount'], 2); ?></strong></td>
                                        <td>$<?php echo number_format($sched['daily_income'], 2); ?></td>
                                        <td class="text-center"><?php echo $sched['days_passed']; ?></td>
                                        <td><?php echo $sched['max_days']; ?></td>
                                        <td>
                                            <span class="badge <?php echo $sched['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo strtoupper($sched['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($sched['created_at'])); ?></td>
                                        <td>
                                            <?php if ($sched['status'] === 'active' && $sched['days_passed'] < $sched['max_days']): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to manually trigger a 1-day payout for this contract? This will distribute direct rank income and propagate to eligible uplines.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="action" value="pay_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $sched['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="fa fa-hand-holding-usd me-1"></i>Pay 1 Day</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
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
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
