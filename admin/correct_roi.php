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
$engine = new MLMEngine();
$config = require __DIR__ . '/../includes/config.php';

$dailyRate = $config['roi']['daily_rate'] ?? 0.0050;
$maxDays = $config['roi']['max_days'] ?? 400;
$capMultiplier = $config['roi']['cap_multiplier'] ?? 2.0;

/**
 * Helper to calculate expected payout dates
 */
function getExpectedPayoutDates($createdAt, $nowStr = null) {
    if ($nowStr === null) {
        $nowStr = date('Y-m-d H:i:s');
    }
    $creationDateStr = date('Y-m-d', strtotime($createdAt));
    $creationTimeStr = date('H:i:s', strtotime($createdAt));

    // If investment is made before 05:00:00, first payout is on creation date itself
    if ($creationTimeStr < '05:00:00') {
        $firstPayoutDate = $creationDateStr;
    } else {
        // Otherwise, first payout is the next day morning
        $firstPayoutDate = date('Y-m-d', strtotime($creationDateStr . ' +1 day'));
    }

    $nowDateStr = date('Y-m-d', strtotime($nowStr));
    $nowTimeStr = date('H:i:s', strtotime($nowStr));

    // If current time is before 05:00:00, last possible payout date is yesterday
    if ($nowTimeStr < '05:00:00') {
        $lastPayoutDate = date('Y-m-d', strtotime($nowDateStr . ' -1 day'));
    } else {
        // Otherwise, last payout date is today
        $lastPayoutDate = $nowDateStr;
    }

    $payoutDates = [];
    $currentPtr = $firstPayoutDate;
    while ($currentPtr <= $lastPayoutDate) {
        $payoutDates[] = $currentPtr;
        $currentPtr = date('Y-m-d', strtotime($currentPtr . ' +1 day'));
    }
    return $payoutDates;
}

/**
 * Perform correction for a single investment
 */
function correctInvestmentROI($db, $engine, $invId, $dailyRate, $maxDays, $capMultiplier) {
    // Fetch investment
    $stmt = $db->prepare("SELECT i.*, u.id as user_id FROM investments i JOIN users u ON i.user_id = u.id WHERE i.id = ?");
    $stmt->execute([$invId]);
    $inv = $stmt->fetch();
    if (!$inv) return false;

    $payoutDates = getExpectedPayoutDates($inv['created_at']);
    $maxROI = $inv['amount'] * $capMultiplier;

    // Delete existing ROI transactions for this investment
    $stmtDel = $db->prepare("DELETE FROM transactions WHERE investment_id = ? AND type = 'ROI'");
    $stmtDel->execute([$invId]);

    $roiEarned = 0.00;
    $totalEarned = 0.00;
    $daysPassed = 0;
    $lastRoiAt = null;
    $status = 'active';

    foreach ($payoutDates as $payoutDate) {
        if ($daysPassed >= $maxDays) {
            $status = 'completed';
            break;
        }
        if ($roiEarned >= $maxROI) {
            $status = 'completed';
            break;
        }

        $roiAmount = $inv['amount'] * $dailyRate;
        if ($roiEarned + $roiAmount >= $maxROI) {
            $roiAmount = $maxROI - $roiEarned;
            $roiEarned = $maxROI;
            $status = 'completed';
        } else {
            $roiEarned += $roiAmount;
        }

        $daysPassed++;
        $lastRoiAt = $payoutDate;

        // ID Cap allowance check
        $allowableROI = $engine->getAllowableAmount($inv['user_id'], $roiAmount);
        if ($allowableROI > 0) {
            $createdAtFormatted = $payoutDate . ' 05:00:00';
            $description = "Daily ROI for investment ID: " . $invId . " (Corrected)";

            $stmtIns = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, roi_date, created_at) VALUES (?, NULL, ?, NULL, 'ROI', ?, 0.00, ?, ?, ?, ?)");
            $stmtIns->execute([
                $inv['user_id'],
                $invId,
                $allowableROI,
                $allowableROI,
                $description,
                $payoutDate,
                $createdAtFormatted
            ]);

            $totalEarned += $allowableROI;

            if ($allowableROI < $roiAmount) {
                $status = 'capped';
            }
        } else {
            $status = 'capped';
        }
    }

    // Update investment status and stats
    $stmtUp = $db->prepare("UPDATE investments SET roi_earned = ?, total_earned = ?, days_passed = ?, last_roi_at = ?, status = ? WHERE id = ?");
    $stmtUp->execute([$roiEarned, $totalEarned, $daysPassed, $lastRoiAt, $status, $invId]);

    return true;
}

// Handle POST actions (CSRF protected)
if (isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: correct_roi.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    $action = $_POST['action'];

    if ($action === 'correct_single') {
        $invId = (int)$_POST['investment_id'];
        $db->beginTransaction();
        try {
            correctInvestmentROI($db, $engine, $invId, $dailyRate, $maxDays, $capMultiplier);
            $db->commit();
            header("Location: correct_roi.php?success=corrected_single&id=" . $invId);
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: correct_roi.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    } elseif ($action === 'correct_all') {
        $db->beginTransaction();
        try {
            // Fetch all investments
            $stmt = $db->query("SELECT id FROM investments");
            $investments = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($investments as $invId) {
                correctInvestmentROI($db, $engine, $invId, $dailyRate, $maxDays, $capMultiplier);
            }

            $db->commit();
            header("Location: correct_roi.php?success=corrected_all");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: correct_roi.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

$search = $_GET['search'] ?? '';

// Query to load investments with user details
$query = "
    SELECT i.*, u.username, u.email, u.mid, p.name as package_name
    FROM investments i
    JOIN users u ON i.user_id = u.id
    JOIN packages p ON i.package_id = p.id
";
$params = [];

if ($search !== '') {
    $query .= " WHERE u.username LIKE ? OR u.email LIKE ? OR u.mid LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY i.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$investments = $stmt->fetchAll();

$pageTitle = 'Correct ROI Engine';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Correct ROI Engine</h3>
                    <p class="text-muted mb-0">Re-verify, clear, and perfectly rebuild daily ROI payouts based on 5:00 AM calculation thresholds.</p>
                </div>
                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to RE-CALCULATE and CORRECT ALL ROI transactions for every investment in the system? Existing ROI records will be wiped and chronologically re-generated.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="correct_all">
                    <button type="submit" class="btn btn-warning btn-lg text-dark">
                        <i class="fa fa-sync-alt me-2"></i>Correct All System ROI
                    </button>
                </form>
            </div>

            <!-- Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa fa-check-circle me-2"></i>
                    <?php
                    if ($_GET['success'] === 'corrected_single') {
                        echo "Successfully corrected and chronologically re-generated ROI records for Investment ID #" . htmlspecialchars($_GET['id'] ?? '') . ".";
                    } elseif ($_GET['success'] === 'corrected_all') {
                        echo "Successfully cleared and corrected ROI records for ALL system investments.";
                    } else {
                        echo "Action completed successfully.";
                    }
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa fa-times-circle me-2"></i>
                    Error: <?php echo htmlspecialchars($_GET['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Informational Alert of Thresholds -->
            <div class="card border-info bg-light-info mb-4">
                <div class="card-body">
                    <h5 class="card-title text-info"><i class="fa fa-info-circle me-2"></i>ROI Rule Engine Thresholds</h5>
                    <ul class="mb-0 text-dark">
                        <li><strong>Daily Rate:</strong> <?php echo number_format($dailyRate * 100, 2); ?>% of investment package amount.</li>
                        <li><strong>Duration Limit:</strong> Up to <?php echo $maxDays; ?> days or <?php echo $capMultiplier * 100; ?>% ROI cap.</li>
                        <li><strong>Calculation Time:</strong> 05:00 AM.</li>
                        <li><strong>First Payout Rule:</strong> If invested before 05:00 AM, first payout is same-day morning 5 AM. If invested on/after 05:00 AM, first payout is next-day morning 5 AM.</li>
                    </ul>
                </div>
            </div>

            <!-- Filter Search -->
            <div class="card mb-4 shadow-sm">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-9">
                            <input type="text" name="search" class="form-control" placeholder="Search by Username, Email, or MID (Member ID)..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>Search</button>
                            <a href="correct_roi.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Investments List Table -->
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title text-white mb-0">Investment Ledger & ROI Analysis</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Member Details</th>
                                    <th>Package</th>
                                    <th>Investment Date</th>
                                    <th class="text-center">Current Days</th>
                                    <th class="text-center">Expected Days</th>
                                    <th class="text-end">Current ROI</th>
                                    <th class="text-end">Expected ROI</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($investments)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">No investments found matching search criteria.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($investments as $inv):
                                        // Calculate Expected
                                        $expectedDates = getExpectedPayoutDates($inv['created_at']);
                                        $expectedDays = count($expectedDates);
                                        if ($expectedDays > $maxDays) {
                                            $expectedDays = $maxDays;
                                        }

                                        // Calculate Expected ROI (capped at 200%)
                                        $expectedROI = 0.00;
                                        $maxROI = $inv['amount'] * $capMultiplier;
                                        for ($d = 0; $d < $expectedDays; $d++) {
                                            $dayAmt = $inv['amount'] * $dailyRate;
                                            if ($expectedROI + $dayAmt >= $maxROI) {
                                                $expectedROI = $maxROI;
                                                break;
                                            } else {
                                                $expectedROI += $dayAmt;
                                            }
                                        }

                                        // Mismatch warning flag
                                        $hasMismatch = ($inv['days_passed'] != $expectedDays || abs($inv['roi_earned'] - $expectedROI) > 0.01);
                                    ?>
                                        <tr class="<?php echo $hasMismatch ? 'table-warning' : ''; ?>">
                                            <td>#<?php echo $inv['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($inv['username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($inv['mid']); ?> | <?php echo htmlspecialchars($inv['email']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($inv['package_name']); ?></strong><br>
                                                <small class="text-primary">$<?php echo number_format($inv['amount'], 2); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i:s', strtotime($inv['created_at'])); ?></small>
                                            </td>
                                            <td class="text-center font-monospace fw-bold"><?php echo $inv['days_passed']; ?></td>
                                            <td class="text-center font-monospace fw-bold text-success"><?php echo $expectedDays; ?></td>
                                            <td class="text-end font-monospace fw-bold">$<?php echo number_format($inv['roi_earned'], 2); ?></td>
                                            <td class="text-end font-monospace fw-bold text-success">$<?php echo number_format($expectedROI, 2); ?></td>
                                            <td class="text-center">
                                                <span class="badge <?php
                                                    if ($inv['status'] === 'active') echo 'bg-success';
                                                    elseif ($inv['status'] === 'completed') echo 'bg-info';
                                                    else echo 'bg-danger';
                                                ?>">
                                                    <?php echo strtoupper($inv['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to correct ROI for this investment? Existing ROI entries for Investment #<?php echo $inv['id']; ?> will be cleared and regenerated.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="action" value="correct_single">
                                                    <input type="hidden" name="investment_id" value="<?php echo $inv['id']; ?>">
                                                    <button type="submit" class="btn btn-sm <?php echo $hasMismatch ? 'btn-danger' : 'btn-outline-primary'; ?>">
                                                        <i class="fa fa-magic me-1"></i>Correct
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

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
