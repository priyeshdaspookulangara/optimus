<?php
// Enable complete error reporting for debugging and diagnosing any runtime exceptions or errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';
require_once __DIR__ . '/../includes/recalc_utils.php';

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

$maxDays = 100;

// Handle POST actions (CSRF protected)
if (isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: correct_rank_income.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    $action = $_POST['action'];

    if ($action === 'correct_single') {
        $schedId = (int)$_POST['schedule_id'];
        $db->beginTransaction();
        try {
            correctMatchingScheduleRankIncome($db, $engine, $schedId, $maxDays);
            $db->commit();
            header("Location: correct_rank_income.php?success=corrected_single&id=" . $schedId);
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: correct_rank_income.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    } elseif ($action === 'correct_all') {
        $db->beginTransaction();
        try {
            // Fetch all matching schedules
            $stmt = $db->query("SELECT id FROM matching_schedules");
            $schedules = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($schedules as $schedId) {
                correctMatchingScheduleRankIncome($db, $engine, $schedId, $maxDays);
            }

            $db->commit();
            header("Location: correct_rank_income.php?success=corrected_all");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: correct_rank_income.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

$search = $_GET['search'] ?? '';

// Query to load matching schedules with user details
$query = "
    SELECT ms.*, u.username, u.email, u.mid
    FROM matching_schedules ms
    JOIN users u ON ms.user_id = u.id
";
$params = [];

if ($search !== '') {
    $query .= " WHERE u.username LIKE ? OR u.email LIKE ? OR u.mid LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY ms.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$schedulesList = $stmt->fetchAll();

$pageTitle = 'Recalculate Rank Income';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Recalculate Rank Income Engine</h3>
                    <p class="text-muted mb-0">Re-verify, clear, and perfectly rebuild daily Rank Matching payouts based on 5:00 AM calculation thresholds.</p>
                </div>
                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to RE-CALCULATE and CORRECT ALL Rank Income transactions for every matching contract in the system? Existing Rank Income records for matching schedules will be wiped and chronologically re-generated.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="correct_all">
                    <button type="submit" class="btn btn-danger btn-lg text-white">
                        <i class="fa fa-sync-alt me-2"></i>Correct All Rank Income
                    </button>
                </form>
            </div>

            <!-- Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i>
                    <?php
                    if ($_GET['success'] === 'corrected_single') {
                        echo "Successfully corrected and chronologically re-generated Rank Income records for Matching Contract #" . htmlspecialchars($_GET['id'] ?? '') . ".";
                    } elseif ($_GET['success'] === 'corrected_all') {
                        echo "Successfully cleared and corrected Rank Income records for ALL system matching contracts.";
                    } else {
                        echo "Action completed successfully.";
                    }
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>
                    Error: <?php echo htmlspecialchars($_GET['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Informational Alert of Thresholds -->
            <div class="card border-info bg-light-info mb-4">
                <div class="card-body">
                    <h5 class="card-title text-info"><i class="fa fa-info-circle me-2"></i>Rank Income Rule Engine Thresholds</h5>
                    <ul class="mb-0 text-dark">
                        <li><strong>Duration Limit:</strong> Up to <?php echo $maxDays; ?> days per contract slab.</li>
                        <li><strong>Calculation Time:</strong> 05:00 AM.</li>
                        <li><strong>First Payout Rule:</strong> If contract is generated before 05:00 AM, first payout is same-day morning 5 AM. If generated on/after 05:00 AM, first payout is next-day morning 5 AM.</li>
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
                            <a href="correct_rank_income.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Matching Contracts Ledger -->
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title text-white mb-0">Matching Contracts Ledger & Rank Income Analysis</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Member Details</th>
                                    <th>Slab Amount</th>
                                    <th>Daily Income</th>
                                    <th>Commence Date</th>
                                    <th class="text-center">Current Days</th>
                                    <th class="text-center">Expected Days</th>
                                    <th class="text-end">Current Income</th>
                                    <th class="text-end">Expected Income</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedulesList)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">No matching schedules found matching search criteria.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($schedulesList as $sched):
                                        // Calculate Expected
                                        $expectedDates = getExpectedRankPayoutDates($sched['created_at']);
                                        $expectedDays = count($expectedDates);
                                        if ($expectedDays > $maxDays) {
                                            $expectedDays = $maxDays;
                                        }

                                        // Calculate expected direct income
                                        $expectedIncome = $expectedDays * $sched['daily_income'];

                                        // Calculate current income from description mapping
                                        $slabDesc = "Slab \$" . number_format($sched['slab_amount'], 2);
                                        $stmtCurrentIncome = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL AND description LIKE ?");
                                        $stmtCurrentIncome->execute([$sched['user_id'], "%" . $slabDesc . "%"]);
                                        $currentIncome = (float)$stmtCurrentIncome->fetch(PDO::FETCH_ASSOC)['total'];

                                        // Mismatch warning flag
                                        $hasMismatch = ($sched['days_passed'] != $expectedDays || abs($currentIncome - $expectedIncome) > 0.01);
                                    ?>
                                        <tr class="<?php echo $hasMismatch ? 'table-warning' : ''; ?>">
                                            <td>#<?php echo $sched['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sched['username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($sched['mid']); ?> | <?php echo htmlspecialchars($sched['email']); ?></small>
                                            </td>
                                            <td>
                                                <strong class="text-primary">$<?php echo number_format($sched['slab_amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <strong>$<?php echo number_format($sched['daily_income'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i:s', strtotime($sched['created_at'])); ?></small>
                                            </td>
                                            <td class="text-center font-monospace fw-bold"><?php echo $sched['days_passed']; ?></td>
                                            <td class="text-center font-monospace fw-bold text-success"><?php echo $expectedDays; ?></td>
                                            <td class="text-end font-monospace fw-bold">$<?php echo number_format($currentIncome, 2); ?></td>
                                            <td class="text-end font-monospace fw-bold text-success">$<?php echo number_format($expectedIncome, 2); ?></td>
                                            <td class="text-center">
                                                <span class="badge <?php
                                                    if ($sched['status'] === 'active') echo 'bg-success';
                                                    elseif ($sched['status'] === 'completed') echo 'bg-info';
                                                    else echo 'bg-secondary';
                                                ?>">
                                                    <?php echo strtoupper($sched['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to correct Rank Income for this contract? Existing entries for Contract #<?php echo $sched['id']; ?> will be cleared and regenerated.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="action" value="correct_single">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $sched['id']; ?>">
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
