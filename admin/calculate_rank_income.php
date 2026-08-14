<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Ensure admin CSRF token is set
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();

// Helper function to calculate expected days paid till date
function getExpectedDaysPaid($createdAt, $maxDays = 100) {
    $createdAtTs = strtotime($createdAt);
    $createdHour = (int)date('H', $createdAtTs);

    if ($createdHour < 5) {
        // Created before 5:00 AM: Start payout on the same day
        $startDateStr = date('Y-m-d', $createdAtTs);
    } else {
        // Created at/after 5:00 AM: Start payout on the next day
        $startDateStr = date('Y-m-d', $createdAtTs + 86400);
    }

    $todayDateStr = date('Y-m-d');
    // If the current time is before 5:00 AM, the current day's cycle has not yet run,
    // so we set the evaluation ceiling to yesterday.
    if ((int)date('H') < 5) {
        $todayDateStr = date('Y-m-d', strtotime('yesterday'));
    }

    if ($startDateStr > $todayDateStr) {
        return 0;
    }

    $diffInSeconds = strtotime($todayDateStr) - strtotime($startDateStr);
    $diffInDays = (int)round($diffInSeconds / 86400) + 1;

    return min($diffInDays, $maxDays);
}

$successMsg = '';
$errorMsg = '';

// Handle actions (Bulk Process or Single Process)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMsg = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'bulk_process') {
            try {
                // Fetch all schedules with status 'active' or where expected days might be greater than days_passed
                $stmt = $db->query("SELECT * FROM matching_schedules WHERE status = 'active'");
                $schedules = $stmt->fetchAll();

                $totalProcessedDays = 0;
                $totalProcessedSchedules = 0;

                foreach ($schedules as $sched) {
                    $expected = getExpectedDaysPaid($sched['created_at'], (int)$sched['max_days']);
                    $pending = $expected - (int)$sched['days_passed'];

                    if ($pending > 0) {
                        $processed = $engine->processMatchingSchedulePayouts((int)$sched['id'], $pending);
                        if ($processed > 0) {
                            $totalProcessedDays += $processed;
                            $totalProcessedSchedules++;
                        }
                    }
                }

                if ($totalProcessedDays > 0) {
                    $successMsg = "Successfully processed " . $totalProcessedDays . " days of Rank Income across " . $totalProcessedSchedules . " matching contracts in bulk!";
                } else {
                    $successMsg = "All matching schedules are already up-to-date. No pending rank incomes found to process.";
                }
            } catch (Exception $e) {
                $errorMsg = "Error during bulk processing: " . $e->getMessage();
            }
        } elseif ($action === 'single_process') {
            $schedId = (int)($_POST['schedule_id'] ?? 0);
            try {
                $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE id = ?");
                $stmt->execute([$schedId]);
                $sched = $stmt->fetch();

                if (!$sched) {
                    throw new Exception("Matching schedule not found.");
                }

                $expected = getExpectedDaysPaid($sched['created_at'], (int)$sched['max_days']);
                $pending = $expected - (int)$sched['days_passed'];

                if ($pending > 0) {
                    $processed = $engine->processMatchingSchedulePayouts($schedId, $pending);
                    $successMsg = "Successfully processed " . $processed . " pending days of Rank Income for Schedule #" . $schedId . ".";
                } else {
                    $successMsg = "Schedule #" . $schedId . " is already up-to-date.";
                }
            } catch (Exception $e) {
                $errorMsg = "Error during single processing: " . $e->getMessage();
            }
        }
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$queryStr = "
    SELECT ms.*, u.username, u.mid as user_mid
    FROM matching_schedules ms
    JOIN users u ON ms.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $queryStr .= " AND (u.username LIKE ? OR u.mid LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($statusFilter)) {
    $queryStr .= " AND ms.status = ?";
    $params[] = $statusFilter;
}

$queryStr .= " ORDER BY ms.created_at DESC";

$stmt = $db->prepare($queryStr);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$pageTitle = 'Calculate Rank Income';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Calculate Rank Income Till Date</h3>
    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to process pending rank incomes in bulk for all active contracts?');">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
        <input type="hidden" name="action" value="bulk_process">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-calculator me-2"></i> Bulk Process All Pending
        </button>
    </form>
</div>

<?php if (!empty($successMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa fa-check-circle me-2"></i> <?php echo htmlspecialchars($successMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($errorMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Search and Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fa fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by Username or MID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">-- All Statuses --</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed Only</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-secondary w-100"><i class="fa fa-filter me-2"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Matching Schedules Ledger -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 text-dark fw-bold">Slab Matching Contracts (Expected vs. Paid Payouts)</h5>
        <span class="badge bg-secondary"><?php echo count($schedules); ?> Contracts Found</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>User (MID)</th>
                        <th>Slab Amount ($)</th>
                        <th>Daily Payout</th>
                        <th>Matching Date</th>
                        <th class="text-center">Paid Days</th>
                        <th class="text-center">Expected Days</th>
                        <th class="text-center">Pending Days</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">No matching schedules found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $sched):
                            $expected = getExpectedDaysPaid($sched['created_at'], (int)$sched['max_days']);
                            $pending = max(0, $expected - (int)$sched['days_passed']);
                            $isPending = $pending > 0 && $sched['status'] === 'active';
                        ?>
                            <tr class="<?php echo $isPending ? 'table-warning' : ''; ?>">
                                <td class="ps-3">#<?php echo $sched['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($sched['username']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($sched['user_mid'] ?? 'N/A'); ?></small>
                                </td>
                                <td>$<?php echo number_format($sched['slab_amount'], 2); ?></td>
                                <td class="text-success fw-bold">$<?php echo number_format($sched['daily_income'], 2); ?></td>
                                <td>
                                    <?php echo date('Y-m-d H:i:s', strtotime($sched['created_at'])); ?><br>
                                    <small class="text-muted">
                                        <?php
                                        $hour = (int)date('H', strtotime($sched['created_at']));
                                        if ($hour < 5) {
                                            echo '<span class="badge bg-success">Before 5:00 AM</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">At/After 5:00 AM</span>';
                                        }
                                        ?>
                                    </small>
                                </td>
                                <td class="text-center fw-bold"><?php echo $sched['days_passed']; ?> / <?php echo $sched['max_days']; ?></td>
                                <td class="text-center fw-bold"><?php echo $expected; ?></td>
                                <td class="text-center">
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-danger fs-6"><?php echo $pending; ?> Days</span>
                                    <?php else: ?>
                                        <span class="badge bg-success fs-6">0 Days</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($sched['status'] === 'completed'): ?>
                                        <span class="badge bg-dark">Completed</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <?php if ($isPending): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to process <?php echo $pending; ?> pending days for this contract?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                            <input type="hidden" name="action" value="single_process">
                                            <input type="hidden" name="schedule_id" value="<?php echo $sched['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fa fa-play me-1"></i> Process <?php echo $pending; ?> Days
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled>
                                            <i class="fa fa-check me-1"></i> Up to date
                                        </button>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
