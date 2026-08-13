<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Ensure CSRF token exists
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';
$packagesList = $config['packages'];

$search = $_GET['search'] ?? '';
$selectedUserId = $_GET['user_id'] ?? null;
$editInvestmentId = $_GET['edit_investment_id'] ?? null;
$addNewInvestmentTrigger = $_GET['add_new'] ?? null;

$usersFound = [];
$selectedUser = null;
$investments = [];
$selectedInvestment = null;
$successMsg = '';
$errorMsg = '';

// --- Local Engine Functions to keep the page completely independent of engine.php ---

function localLogTransaction($db, $userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null, $customNetAmount = null, $createdAt = null) {
    $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);
    if ($customNetAmount !== null) {
        $netAmount = $customNetAmount;
    } elseif ($isDebit) {
        $netAmount = -($amount + $fee);
    } else {
        $netAmount = $amount - $fee;
    }

    if ($createdAt !== null) {
        $stmt = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description, $createdAt]);
    } else {
        $stmt = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description]);
    }
}

function localGetAllowableAmount($db, $config, $userId, $amountToAdd) {
    return $amountToAdd;
}

function localDistributeLevelIncome($db, $config, $userId, $investmentAmount, $investmentId) {
    $stmt = $db->prepare("SELECT parent_id, level FROM genealogy WHERE user_id = ? AND level <= 12 ORDER BY level ASC");
    $stmt->execute([$userId]);
    $parents = $stmt->fetchAll();

    foreach ($parents as $parent) {
        $level = $parent['level'];
        if (isset($config['level_percentages'][$level])) {
            $percentage = $config['level_percentages'][$level];
            $commission = ($investmentAmount * $percentage) / 100;

            $allowable = localGetAllowableAmount($db, $config, $parent['parent_id'], $commission);
            if ($allowable > 0) {
                localLogTransaction($db, $parent['parent_id'], 'LEVEL_INCOME', $allowable, 0, "Level {$level} income from user ID: {$userId}", $userId, $investmentId, $level);
            }
        }
    }
}

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

        $legStats = localGetLegsBusiness($db, $ancestorId);
        $matchedBusiness = $legStats['matched_business'];
        $slabBreakdown = $legStats['slab_breakdown'] ?? [];

        $qualifiedRankId = localCheckRankQualification($config, $matchedBusiness);

        // Update Rank
        $updateRank = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
        $updateRank->execute([$qualifiedRankId ?? 0, $ancestorId]);
        if ($user) {
            $user['rank_id'] = $qualifiedRankId ?? 0;
        }

        foreach ($slabBreakdown as $slab => $requiredUnits) {
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
            } elseif ($requiredUnits < $existingUnits) {
                // Prune matching schedules if leg business was reduced
                $removeCount = $existingUnits - $requiredUnits;
                $stmtGetScheds = $db->prepare("SELECT id FROM matching_schedules WHERE user_id = ? AND slab_amount = ? ORDER BY id DESC LIMIT {$removeCount}");
                $stmtGetScheds->execute([$ancestorId, $slab]);
                $idsToDelete = $stmtGetScheds->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($idsToDelete)) {
                    $inQuery = implode(',', array_map('intval', $idsToDelete));
                    $db->exec("DELETE FROM transactions WHERE user_id = {$ancestorId} AND type = 'RANK_INCOME' AND description LIKE '%Slab \$" . number_format($slab, 2) . "%'");
                    $db->exec("DELETE FROM matching_schedules WHERE id IN ($inQuery)");
                }
            }
        }
    }
}

// Handle Investment Actions (Update/Create)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_investment') {
        // CSRF verification
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
            $errorMsg = "CSRF token validation failed.";
        } else {
            $investmentId = intval($_POST['investment_id']);
            $newAmount = floatval($_POST['amount']);
            $newCreatedAt = trim($_POST['created_at']);

            // Basic validation
            if (!in_array($newAmount, $packagesList)) {
                $errorMsg = "Invalid package amount selected.";
            } elseif (empty($newCreatedAt) || !strtotime($newCreatedAt)) {
                $errorMsg = "Invalid investment date/time format.";
            } else {
                $db->beginTransaction();
                try {
                    // 1. Fetch Investment details
                    $stmt = $db->prepare("SELECT * FROM investments WHERE id = ?");
                    $stmt->execute([$investmentId]);
                    $investment = $stmt->fetch();
                    if (!$investment) {
                        throw new Exception("Investment record not found.");
                    }
                    $userId = $investment['user_id'];

                    // 2. Fetch User details
                    $stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmtUser->execute([$userId]);
                    $selectedUser = $stmtUser->fetch();
                    if (!$selectedUser) {
                        throw new Exception("User not found.");
                    }

                    // 3. Clear ALL transactions generated by that investment
                    // (including ROI, LEVEL_INCOME, and INVESTMENT)
                    $stmtDel = $db->prepare("DELETE FROM transactions WHERE investment_id = ?");
                    $stmtDel->execute([$investmentId]);

                    // Fallback legacy cleanup for level incomes that did not have investment_id populated
                    $stmtLgc = $db->prepare("DELETE FROM transactions WHERE related_user_id = ? AND type = 'LEVEL_INCOME' AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) <= 300");
                    $stmtLgc->execute([$userId, $investment['created_at']]);

                    // 4. Find the package ID corresponding to the new amount
                    $stmtPkg = $db->prepare("SELECT id FROM packages WHERE amount = ?");
                    $stmtPkg->execute([$newAmount]);
                    $package = $stmtPkg->fetch();
                    if (!$package) {
                        // Auto-create if not found (for safety)
                        $stmtInsPkg = $db->prepare("INSERT INTO packages (name, amount) VALUES (?, ?)");
                        $stmtInsPkg->execute(["Package \${$newAmount}", $newAmount]);
                        $packageId = $db->lastInsertId();
                    } else {
                        $packageId = $package['id'];
                    }

                    // 5. Update investment record & reset progress
                    $stmtUpdInv = $db->prepare("UPDATE investments SET
                        package_id = ?,
                        amount = ?,
                        roi_earned = 0.00,
                        total_earned = 0.00,
                        days_passed = 0,
                        last_roi_at = NULL,
                        status = 'active',
                        created_at = ?
                        WHERE id = ?");
                    $stmtUpdInv->execute([$packageId, $newAmount, $newCreatedAt, $investmentId]);

                    // 6. Update user's total investment in users table
                    $stmtUpdUser = $db->prepare("UPDATE users SET total_investment = (SELECT COALESCE(SUM(amount), 0) FROM investments WHERE user_id = ?) WHERE id = ?");
                    $stmtUpdUser->execute([$userId, $userId]);

                    // 7. Log the updated INVESTMENT transaction
                    localLogTransaction($db, $userId, 'INVESTMENT', $newAmount, 0, "Purchased package \${$newAmount} (Recalculated)", null, $investmentId, null, null, $newCreatedAt);

                    // 8. Distribute/Recalculate Level Income
                    localDistributeLevelIncome($db, $config, $userId, $newAmount, $investmentId);

                    // 9. Chronologically recalculate ROI based on the investment date (no 300% ID Cap, with 05:00 AM threshold)
                    $dailyRate = $config['roi']['daily_rate'];
                    $maxDays = $config['roi']['max_days'];
                    $capMultiplier = $config['roi']['cap_multiplier'];
                    $maxROI = $newAmount * $capMultiplier;

                    $investmentHourMinute = date('H:i:s', strtotime($newCreatedAt));
                    $investmentDateOnly = date('Y-m-d', strtotime($newCreatedAt));

                    // 05:00 AM threshold rule
                    if ($investmentHourMinute < '05:00:00') {
                        $currentDate = $investmentDateOnly;
                    } else {
                        $currentDate = date('Y-m-d', strtotime($investmentDateOnly . ' +1 day'));
                    }

                    $todayDate = date('Y-m-d');
                    $daysPassed = 0;
                    $roiEarned = 0.00;
                    $status = 'active';
                    $lastRoiAt = null;

                    while ($currentDate <= $todayDate) {
                        if ($daysPassed >= $maxDays || $roiEarned >= $maxROI) {
                            $status = 'completed';
                            break;
                        }

                        $roiAmount = $newAmount * $dailyRate;
                        if ($roiEarned + $roiAmount > $maxROI) {
                            $roiAmount = $maxROI - $roiEarned;
                        }

                        $allowableROI = localGetAllowableAmount($db, $config, $userId, $roiAmount);

                        if ($allowableROI > 0) {
                            // Log ROI transaction with chronological date
                            localLogTransaction($db, $userId, 'ROI', $allowableROI, 0, "Daily ROI for investment ID: {$investmentId}", null, $investmentId, null, null, "$currentDate 05:00:00");

                            $roiEarned += $allowableROI;
                            $daysPassed += 1;
                            $lastRoiAt = $currentDate;

                            if ($allowableROI < $roiAmount) {
                                $status = 'capped';
                                break; // Stop paying once capped
                            }
                        } else {
                            $status = 'capped';
                            break; // Stop paying once capped
                        }

                        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                    }

                    // Update investment with recalculated ROI stats
                    $stmtUpdInvStats = $db->prepare("UPDATE investments SET
                        roi_earned = ?,
                        total_earned = ?,
                        days_passed = ?,
                        last_roi_at = ?,
                        status = ?
                        WHERE id = ?");
                    $stmtUpdInvStats->execute([$roiEarned, $roiEarned, $daysPassed, $lastRoiAt, $status, $investmentId]);

                    // 10. Recalculate leg businesses, ranks, and matching schedules for all ancestors
                    localUpdateUplineRanks($db, $config, $userId);

                    $db->commit();
                    $successMsg = "Investment amount updated successfully! Commissions and ROI recalculated starting from {$newCreatedAt} (with 05:00 AM threshold and with no 300% ID Cap).";

                    // Clear state to reload fresh details
                    $editInvestmentId = null;
                } catch (Exception $e) {
                    $db->rollBack();
                    $errorMsg = "Error: " . $e->getMessage();
                }
            }
        }
    } elseif ($_POST['action'] === 'add_investment') {
        // CSRF verification
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
            $errorMsg = "CSRF token validation failed.";
        } else {
            $userId = intval($_POST['user_id']);
            $newAmount = floatval($_POST['amount']);
            $newCreatedAt = trim($_POST['created_at']);

            // Basic validation
            if (!in_array($newAmount, $packagesList)) {
                $errorMsg = "Invalid package amount selected.";
            } elseif (empty($newCreatedAt) || !strtotime($newCreatedAt)) {
                $errorMsg = "Invalid investment date/time format.";
            } else {
                $db->beginTransaction();
                try {
                    // Find package ID
                    $stmtPkg = $db->prepare("SELECT id FROM packages WHERE amount = ?");
                    $stmtPkg->execute([$newAmount]);
                    $package = $stmtPkg->fetch();
                    if (!$package) {
                        // Auto-create if not found (for safety)
                        $stmtInsPkg = $db->prepare("INSERT INTO packages (name, amount) VALUES (?, ?)");
                        $stmtInsPkg->execute(["Package \${$newAmount}", $newAmount]);
                        $packageId = $db->lastInsertId();
                    } else {
                        $packageId = $package['id'];
                    }

                    // Insert investment record
                    $stmtAddInv = $db->prepare("INSERT INTO investments (user_id, package_id, amount, roi_earned, total_earned, days_passed, last_roi_at, status, created_at) VALUES (?, ?, ?, 0.00, 0.00, 0, NULL, 'active', ?)");
                    $stmtAddInv->execute([$userId, $packageId, $newAmount, $newCreatedAt]);
                    $investmentId = $db->lastInsertId();

                    // Update user total investment in users table
                    $stmtUpdUser = $db->prepare("UPDATE users SET total_investment = (SELECT COALESCE(SUM(amount), 0) FROM investments WHERE user_id = ?) WHERE id = ?");
                    $stmtUpdUser->execute([$userId, $userId]);

                    // Log initial INVESTMENT transaction
                    localLogTransaction($db, $userId, 'INVESTMENT', $newAmount, 0, "Purchased package \${$newAmount} (Recalculated)", null, $investmentId, null, null, $newCreatedAt);

                    // Distribute/Recalculate Level Income
                    localDistributeLevelIncome($db, $config, $userId, $newAmount, $investmentId);

                    // Chronologically calculate ROI based on the investment date (no 300% ID Cap, with 05:00 AM threshold)
                    $dailyRate = $config['roi']['daily_rate'];
                    $maxDays = $config['roi']['max_days'];
                    $capMultiplier = $config['roi']['cap_multiplier'];
                    $maxROI = $newAmount * $capMultiplier;

                    $investmentHourMinute = date('H:i:s', strtotime($newCreatedAt));
                    $investmentDateOnly = date('Y-m-d', strtotime($newCreatedAt));

                    // 05:00 AM threshold rule
                    if ($investmentHourMinute < '05:00:00') {
                        $currentDate = $investmentDateOnly;
                    } else {
                        $currentDate = date('Y-m-d', strtotime($investmentDateOnly . ' +1 day'));
                    }

                    $todayDate = date('Y-m-d');
                    $daysPassed = 0;
                    $roiEarned = 0.00;
                    $status = 'active';
                    $lastRoiAt = null;

                    while ($currentDate <= $todayDate) {
                        if ($daysPassed >= $maxDays || $roiEarned >= $maxROI) {
                            $status = 'completed';
                            break;
                        }

                        $roiAmount = $newAmount * $dailyRate;
                        if ($roiEarned + $roiAmount > $maxROI) {
                            $roiAmount = $maxROI - $roiEarned;
                        }

                        $allowableROI = localGetAllowableAmount($db, $config, $userId, $roiAmount);

                        if ($allowableROI > 0) {
                            // Log ROI transaction with chronological date
                            localLogTransaction($db, $userId, 'ROI', $allowableROI, 0, "Daily ROI for investment ID: {$investmentId}", null, $investmentId, null, null, "$currentDate 05:00:00");

                            $roiEarned += $allowableROI;
                            $daysPassed += 1;
                            $lastRoiAt = $currentDate;

                            if ($allowableROI < $roiAmount) {
                                $status = 'capped';
                                break; // Stop paying once capped
                            }
                        } else {
                            $status = 'capped';
                            break; // Stop paying once capped
                        }

                        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                    }

                    // Update investment with calculated ROI stats
                    $stmtUpdInvStats = $db->prepare("UPDATE investments SET
                        roi_earned = ?,
                        total_earned = ?,
                        days_passed = ?,
                        last_roi_at = ?,
                        status = ?
                        WHERE id = ?");
                    $stmtUpdInvStats->execute([$roiEarned, $roiEarned, $daysPassed, $lastRoiAt, $status, $investmentId]);

                    // Recalculate leg businesses, ranks, and matching schedules for all ancestors
                    localUpdateUplineRanks($db, $config, $userId);

                    $db->commit();
                    $successMsg = "New investment of \${$newAmount} successfully added! Commissions and ROI recalculated starting from {$newCreatedAt} (with 05:00 AM threshold and with no 300% ID Cap).";

                    // Clear state to reload fresh details
                    $addNewInvestmentTrigger = null;
                } catch (Exception $e) {
                    $db->rollBack();
                    $errorMsg = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// User Search logic
if (!empty($search)) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR mid = ?");
    $stmt->execute([$search, $search]);
    $usersFound = $stmt->fetchAll();

    if (count($usersFound) === 1) {
        $selectedUserId = $usersFound[0]['id'];
    }
}

// Selected User Details & Investments
if ($selectedUserId) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$selectedUserId]);
    $selectedUser = $stmt->fetch();

    if ($selectedUser) {
        $stmt = $db->prepare("SELECT * FROM investments WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$selectedUserId]);
        $investments = $stmt->fetchAll();
    }
}

// Selected Investment for Edit
if ($editInvestmentId) {
    $stmt = $db->prepare("SELECT * FROM investments WHERE id = ?");
    $stmt->execute([$editInvestmentId]);
    $selectedInvestment = $stmt->fetch();
}

$pageTitle = 'Change Investment';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h3>Change User Investment & Recalculate Commissions</h3>
            <a href="members.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Back to Members</a>
        </div>
    </div>

    <!-- Notifications -->
    <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong><i class="fa fa-check-circle me-1"></i> Success!</strong> <?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="fa fa-exclamation-triangle me-1"></i> Error!</strong> <?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search Section -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0"><i class="fa fa-search me-1"></i> Search User</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-9">
                    <input type="text" name="search" class="form-control form-control-lg" placeholder="Enter Username or Member Code (MID) e.g., OPT10092" value="<?php echo htmlspecialchars($search); ?>" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fa fa-search me-1"></i> Search</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Search Results / Multiple Matches -->
    <?php if (!empty($search) && count($usersFound) > 1): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0"><i class="fa fa-list me-1"></i> Multiple Matches Found</h5>
            </div>
            <div class="card-body">
                <p>Please select the correct user from the list below:</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>MID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Total Invested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersFound as $u): ?>
                                <tr>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($u['mid']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($u['full_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td>$<?php echo number_format($u['total_investment'], 2); ?></td>
                                    <td>
                                        <a href="change_investment.php?user_id=<?php echo $u['id']; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm btn-primary">
                                            Select User
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif (!empty($search) && count($usersFound) === 0): ?>
        <div class="alert alert-warning">
            <i class="fa fa-info-circle me-1"></i> No users found matching "<strong><?php echo htmlspecialchars($search); ?></strong>".
        </div>
    <?php endif; ?>

    <!-- Selected User Info & Investments list -->
    <?php if ($selectedUser): ?>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card border-dark h-100">
                    <div class="card-header bg-dark text-white">
                        <h5 class="card-title mb-0"><i class="fa fa-user-circle me-1"></i> User Profile</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-4">
                            <tr>
                                <th class="text-muted" style="width: 40%;">Username:</th>
                                <td><strong><?php echo htmlspecialchars($selectedUser['username']); ?></strong></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Member ID:</th>
                                <td><strong class="text-primary"><?php echo htmlspecialchars($selectedUser['mid']); ?></strong></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Email:</th>
                                <td><?php echo htmlspecialchars($selectedUser['email']); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Status:</th>
                                <td>
                                    <span class="badge <?php echo $selectedUser['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo strtoupper($selectedUser['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Total Invested:</th>
                                <td><strong class="text-success" style="font-size: 1.1rem;">$<?php echo number_format($selectedUser['total_investment'], 2); ?></strong></td>
                            </tr>
                        </table>

                        <a href="change_investment.php?user_id=<?php echo $selectedUserId; ?>&add_new=1&search=<?php echo urlencode($search); ?>" class="btn btn-success w-100"><i class="fa fa-plus-circle me-1"></i> Add New Investment</a>
                    </div>
                </div>
            </div>

            <div class="col-md-8 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0"><i class="fa fa-wallet me-1"></i> Investments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($investments)): ?>
                            <div class="alert alert-info mb-4">
                                <i class="fa fa-info-circle me-1"></i> This user does not have any recorded investments.
                            </div>
                            <div class="border p-3 rounded bg-light">
                                <h6><strong><i class="fa fa-plus-circle text-success me-1"></i> Create First Investment</strong></h6>
                                <p class="text-muted small">Fill out the form below to quickly configure and add a brand new investment package for this user.</p>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                    <input type="hidden" name="action" value="add_investment">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label font-weight-bold">Amount ($):</label>
                                            <select name="amount" class="form-select" required>
                                                <?php foreach ($packagesList as $pkgAmt): ?>
                                                    <option value="<?php echo $pkgAmt; ?>">$<?php echo number_format($pkgAmt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label font-weight-bold">Creation Date & Time:</label>
                                            <input type="text" name="created_at" class="form-control" value="<?php echo date('Y-m-d H:i:s'); ?>" required>
                                        </div>
                                        <div class="col-12 text-end">
                                            <button type="submit" class="btn btn-success"><i class="fa fa-plus-circle me-1"></i> Add Investment</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Amount</th>
                                            <th>ROI Earned</th>
                                            <th>Days Passed</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($investments as $inv): ?>
                                            <tr class="<?php echo ($editInvestmentId == $inv['id']) ? 'table-primary' : ''; ?>">
                                                <td>#<?php echo $inv['id']; ?></td>
                                                <td><strong>$<?php echo number_format($inv['amount'], 2); ?></strong></td>
                                                <td>$<?php echo number_format($inv['roi_earned'], 2); ?></td>
                                                <td><?php echo $inv['days_passed']; ?> / <?php echo $config['roi']['max_days']; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $inv['status'] === 'active' ? 'bg-success' : ($inv['status'] === 'completed' ? 'bg-secondary' : 'bg-danger'); ?>">
                                                        <?php echo strtoupper($inv['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($inv['created_at']); ?></td>
                                                <td>
                                                    <a href="change_investment.php?user_id=<?php echo $selectedUserId; ?>&edit_investment_id=<?php echo $inv['id']; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fa fa-edit me-1"></i> Edit
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Form Section (If addNewInvestmentTrigger is active) -->
        <?php if ($addNewInvestmentTrigger && !empty($investments)): ?>
            <div class="card border-success mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="fa fa-plus-circle me-1"></i> Add New Investment for <?php echo htmlspecialchars($selectedUser['username']); ?></h5>
                </div>
                <div class="card-body font-size-14">
                    <div class="alert alert-warning">
                        <strong><i class="fa fa-info-circle me-1"></i> Dynamic Recalculation Info:</strong> Creating a new investment will:
                        <ul class="mb-0 mt-1">
                            <li>Chronologically generate and distribute correct Level Income commissions for all qualified uplines.</li>
                            <li>Chronologically recalculate and insert Daily ROI transactions day-by-day starting from the chosen creation date up to today, respecting the <strong>05:00 AM daily threshold</strong> and with <strong>no 300% ID Cap</strong>.</li>
                            <li>Recalculate dynamic leg volumes, ranks, and matching schedules for all uplines.</li>
                        </ul>
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                        <input type="hidden" name="action" value="add_investment">
                        <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold">Investment Package Amount ($):</label>
                                <select name="amount" class="form-select form-select-lg" required>
                                    <?php foreach ($packagesList as $pkgAmt): ?>
                                        <option value="<?php echo $pkgAmt; ?>">
                                            Package $<?php echo number_format($pkgAmt); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label font-weight-bold">Investment Creation Date & Time:</label>
                                <input type="text" name="created_at" class="form-control form-control-lg" value="<?php echo date('Y-m-d H:i:s'); ?>" required>
                                <small class="text-muted">Format: YYYY-MM-DD HH:MM:SS (e.g., 2023-10-01 04:30:00). Note: Investments before 05:00 AM start ROI payouts same day, else next day.</small>
                            </div>

                            <div class="col-12 text-end mt-4">
                                <a href="change_investment.php?user_id=<?php echo $selectedUserId; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-secondary btn-lg me-2">Cancel</a>
                                <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-plus-circle me-1"></i> Add Investment</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Form Section (If selected) -->
        <?php if ($selectedInvestment): ?>
            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fa fa-tools me-1"></i> Edit Investment #<?php echo $selectedInvestment['id']; ?></h5>
                </div>
                <div class="card-body font-size-14">
                    <div class="alert alert-warning">
                        <strong><i class="fa fa-exclamation-triangle me-1"></i> Warning!</strong> Changing this investment's amount or date will:
                        <ul class="mb-0 mt-1">
                            <li>Permanently delete and clear all generated transactions associated with this specific investment (ROI, Level Income, Investment record).</li>
                            <li>Chronologically regenerate and distribute correct Level Income commissions for all qualified uplines.</li>
                            <li>Chronologically recalculate and insert Daily ROI transactions day-by-day starting from the new/original investment creation date up to today, respecting the <strong>05:00 AM calculation threshold</strong> and with <strong>no 300% ID Cap</strong>.</li>
                            <li>Recalculate dynamic leg volumes, ranks, and matching schedules for all uplines.</li>
                        </ul>
                    </div>

                    <form method="post" onsubmit="return confirm('Are you absolutely sure you want to change this investment? This will trigger a complete chronological recalculation of all commissions and ROI for this investment.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                        <input type="hidden" name="action" value="change_investment">
                        <input type="hidden" name="investment_id" value="<?php echo $selectedInvestment['id']; ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold">Investment Package Amount ($):</label>
                                <select name="amount" class="form-select form-select-lg" required>
                                    <?php foreach ($packagesList as $pkgAmt): ?>
                                        <option value="<?php echo $pkgAmt; ?>" <?php echo (floatval($pkgAmt) === floatval($selectedInvestment['amount'])) ? 'selected' : ''; ?>>
                                            Package $<?php echo number_format($pkgAmt); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label font-weight-bold">Investment Creation Date & Time:</label>
                                <input type="text" name="created_at" class="form-control form-control-lg" value="<?php echo htmlspecialchars($selectedInvestment['created_at']); ?>" required>
                                <small class="text-muted">Format: YYYY-MM-DD HH:MM:SS (e.g., 2023-10-01 04:30:00). Note: Investments before 05:00 AM start ROI payouts same day, else next day.</small>
                            </div>

                            <div class="col-12 text-end mt-4">
                                <a href="change_investment.php?user_id=<?php echo $selectedUserId; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-secondary btn-lg me-2">Cancel</a>
                                <button type="submit" class="btn btn-danger btn-lg"><i class="fa fa-sync-alt me-1"></i> Update & Recalculate</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
