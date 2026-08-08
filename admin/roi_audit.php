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
$dailyRate = $config['roi']['daily_rate']; // e.g. 0.0050 for 0.5%

$success_msg = '';
$error_msg = '';

// Handle manual trigger action
if (isset($_POST['action']) && $_POST['action'] === 'trigger_roi') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error_msg = "CSRF token validation failed.";
    } else {
        try {
            $engine = new MLMEngine();
            $engine->processDailyROI();
            header("Location: roi_audit.php?success=roi_triggered");
            exit();
        } catch (Exception $e) {
            $error_msg = "Error during manual daily ROI calculation: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === 'roi_triggered') {
    $success_msg = "Daily ROI calculation process was executed successfully!";
}

// 1. Cron/Time stats setup
$server_time = date('Y-m-d H:i:s');
$current_hour = (int)date('H');

// Expected run for today is 05:00 AM.
// If today's time is >= 05:00:00, last expected run is today at 05:00 AM, next expected run is tomorrow at 05:00 AM.
// If today's time is < 05:00:00, last expected run was yesterday at 05:00 AM, next expected run is today at 05:00 AM.
if ($current_hour >= 5) {
    $last_expected_run = date('Y-m-d 05:00:00');
    $next_expected_run = date('Y-m-d 05:00:00', strtotime('+1 day'));
} else {
    $last_expected_run = date('Y-m-d 05:00:00', strtotime('-1 day'));
    $next_expected_run = date('Y-m-d 05:00:00');
}

// Query to check if any active investments have last_roi_at = today (signaling run completed today)
$today_date = date('Y-m-d');
$stmtToday = $db->prepare("SELECT COUNT(*) as count FROM investments WHERE last_roi_at = ?");
$stmtToday->execute([$today_date]);
$today_roi_runs = $stmtToday->fetch()['count'];

$today_run_status = ($today_roi_runs > 0) ? 'COMPLETED' : 'PENDING / NOT RUN';

// 2. Fetch investments and aggregate ROI transactions
$query = "
    SELECT
        i.id as investment_id,
        i.user_id,
        u.username,
        u.email,
        u.mid as user_mid,
        p.name as package_name,
        i.amount as investment_amount,
        i.days_passed as expected_days,
        i.roi_earned as investment_roi_earned,
        i.created_at as purchase_date,
        i.status as investment_status,
        i.last_roi_at,
        COALESCE(t.actual_days, 0) as actual_days,
        COALESCE(t.actual_sum, 0.00) as actual_sum
    FROM investments i
    JOIN users u ON i.user_id = u.id
    JOIN packages p ON i.package_id = p.id
    LEFT JOIN (
        SELECT investment_id, COUNT(*) as actual_days, SUM(amount) as actual_sum
        FROM transactions
        WHERE type = 'ROI'
        GROUP BY investment_id
    ) t ON i.id = t.investment_id
    ORDER BY i.created_at DESC
";
$stmt = $db->query($query);
$investments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate system-wide summary metrics
$total_investments = count($investments);
$perfect_matches = 0;
$discrepancies = 0;
$capped_investments = 0;

foreach ($investments as $inv) {
    if ($inv['investment_status'] === 'capped') {
        $capped_investments++;
        continue;
    }

    $expected_sum = $inv['expected_days'] * $inv['investment_amount'] * $dailyRate;
    $actual_sum = (float)$inv['actual_sum'];

    $is_days_match = ((int)$inv['expected_days'] === (int)$inv['actual_days']);
    $is_sum_match = (abs($expected_sum - $actual_sum) < 0.01);

    if ($is_days_match && $is_sum_match) {
        $perfect_matches++;
    } else {
        $discrepancies++;
    }
}

$pageTitle = 'ROI Payout Audit & Tracker';
include __DIR__ . '/includes/header.php';
?>

<div class="row">
    <div class="col-md-12 mb-4 d-flex justify-content-between align-items-center">
        <h3>ROI Payout Audit & Daily Scheduler Tracker</h3>
        <span class="badge bg-dark p-2 text-white"><i class="fa fa-clock me-1"></i> Server Time: <?php echo $server_time; ?></span>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa fa-check-circle me-1"></i> <?php echo htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa fa-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Daily Calculation Info Section -->
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card border-primary h-100">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fa fa-info-circle me-1"></i> Daily ROI Morning Scheduler</h5>
                <span class="badge bg-light text-primary fw-bold">Daily rate: <?php echo ($dailyRate * 100); ?>%</span>
            </div>
            <div class="card-body">
                <p class="card-text">
                    The ROI daily payout calculation is configured to execute automatically every morning at <strong>5:00 AM (05:00)</strong>.
                </p>
                <div class="row mt-3 text-center">
                    <div class="col-md-4 border-end">
                        <small class="text-muted d-block uppercase font-weight-bold">Last Expected Run</small>
                        <strong class="text-dark d-block" style="font-size: 1.1rem;"><?php echo date('d M, Y h:i A', strtotime($last_expected_run)); ?></strong>
                    </div>
                    <div class="col-md-4 border-end">
                        <small class="text-muted d-block uppercase font-weight-bold">Today's Run Status</small>
                        <?php if ($today_run_status === 'COMPLETED'): ?>
                            <strong class="text-success d-block" style="font-size: 1.1rem;"><i class="fa fa-check-circle"></i> COMPLETED</strong>
                            <small class="text-muted"><?php echo $today_roi_runs; ?> plan(s) processed today</small>
                        <?php else: ?>
                            <strong class="text-warning d-block" style="font-size: 1.1rem;"><i class="fa fa-hourglass-half"></i> NOT RUN YET</strong>
                            <?php if ($current_hour >= 5): ?>
                                <small class="text-danger fw-bold"><i class="fa fa-exclamation-circle"></i> Attention: Time is past 5:00 AM!</small>
                            <?php else: ?>
                                <small class="text-muted">Expected at 05:00 AM</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block uppercase font-weight-bold">Next Expected Run</small>
                        <strong class="text-secondary d-block" style="font-size: 1.1rem;"><?php echo date('d M, Y h:i A', strtotime($next_expected_run)); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fa fa-cogs me-1"></i> Manual Operations</h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-center">
                <p class="text-muted small">
                    If today's scheduler did not run or needs to be executed manually for testing/correction, you can trigger it safely. The system blocks double-paying on the same day.
                </p>
                <form method="post" onsubmit="return confirm('Are you sure you want to trigger the Daily ROI processing right now?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="trigger_roi">
                    <button type="submit" class="btn btn-warning w-100 text-dark fw-bold py-2 shadow-sm">
                        <i class="fa fa-play-circle me-1"></i> Run Daily ROI Calculation Now
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Summary Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-info text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-white-50">Total Checked Plans</h6>
                <h2 class="mb-0"><?php echo $total_investments; ?></h2>
                <i class="fa fa-box position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 2.5rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-white-50">Consistent (Perfect Match)</h6>
                <h2 class="mb-0"><?php echo $perfect_matches; ?></h2>
                <i class="fa fa-check-double position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 2.5rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-white-50">Discrepancy / Mismatch</h6>
                <h2 class="mb-0"><?php echo $discrepancies; ?></h2>
                <i class="fa fa-times-circle position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 2.5rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-secondary text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-white-50">Capped Plans</h6>
                <h2 class="mb-0"><?php echo $capped_investments; ?></h2>
                <i class="fa fa-ban position-absolute top-0 end-0 m-3 opacity-25" style="font-size: 2.5rem;"></i>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Audit Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 text-dark"><i class="fa fa-list me-1"></i> Investments & ROI Audit Ledger</h5>
        <span class="text-muted small">Daily rate is <?php echo ($dailyRate * 100); ?>% of package value. Max duration: <?php echo $config['roi']['max_days'] ?? 400; ?> days.</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Investment ID</th>
                        <th>User (MID)</th>
                        <th>Package Detail</th>
                        <th class="text-center">Expected Days</th>
                        <th class="text-center">Actual ROI Days</th>
                        <th>Expected ROI Sum</th>
                        <th>Actual ROI Sum</th>
                        <th class="text-center">Status Check</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($investments)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No investments found in the system.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($investments as $inv):
                            $purchase_ts = strtotime($inv['purchase_date']);
                            $calendar_days = floor((time() - $purchase_ts) / 86400);
                            if ($calendar_days < 0) $calendar_days = 0;

                            $expected_sum = $inv['expected_days'] * $inv['investment_amount'] * $dailyRate;
                            $actual_sum = (float)$inv['actual_sum'];

                            $is_days_match = ((int)$inv['expected_days'] === (int)$inv['actual_days']);
                            $is_sum_match = (abs($expected_sum - $actual_sum) < 0.01);

                            $status_class = '';
                            $status_badge = '';

                            if ($inv['investment_status'] === 'capped') {
                                $status_badge = '<span class="badge bg-secondary"><i class="fa fa-ban me-1"></i> CAPPED</span>';
                                $status_desc = 'Early cap limit triggered due to system-wide 300% ID limit.';
                            } elseif ($is_days_match && $is_sum_match) {
                                $status_badge = '<span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> MATCHED</span>';
                                $status_desc = 'Days and payout sum match perfectly.';
                            } else {
                                $status_badge = '<span class="badge bg-danger"><i class="fa fa-exclamation-triangle me-1"></i> MISMATCH</span>';
                                $status_desc = 'Discrepancy detected between database expected counts and ledger records.';
                            }
                        ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo $inv['investment_id']; ?></strong>
                                    <small class="text-muted d-block"><?php echo date('Y-m-d', $purchase_ts); ?></small>
                                </td>
                                <td>
                                    <strong class="text-primary"><?php echo htmlspecialchars($inv['username']); ?></strong>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($inv['user_mid']); ?></small>
                                </td>
                                <td>
                                    <strong>$<?php echo number_format($inv['investment_amount'], 2); ?></strong>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($inv['package_name']); ?></small>
                                </td>
                                <td class="text-center fw-bold">
                                    <?php echo $inv['expected_days']; ?>
                                    <span class="text-muted d-block" style="font-size: 11px;">Calendar days elapsed: <?php echo $calendar_days; ?></span>
                                </td>
                                <td class="text-center fw-bold <?php echo $is_days_match ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $inv['actual_days']; ?>
                                    <span class="text-muted d-block" style="font-size: 11px;">Ledger count</span>
                                </td>
                                <td>
                                    <strong class="text-dark">$<?php echo number_format($expected_sum, 2); ?></strong>
                                    <small class="text-muted d-block"><?php echo $inv['expected_days']; ?> &times; $<?php echo number_format($inv['investment_amount'] * $dailyRate, 4); ?></small>
                                </td>
                                <td>
                                    <strong class="<?php echo $is_sum_match ? 'text-dark' : 'text-danger fw-bold'; ?>">$<?php echo number_format($actual_sum, 2); ?></strong>
                                    <small class="text-muted d-block">From transaction records</small>
                                </td>
                                <td class="text-center">
                                    <div class="mb-1"><?php echo $status_badge; ?></div>
                                    <span class="text-muted d-block" style="font-size: 10px; max-width: 150px; margin: 0 auto; line-height: 1.2;"><?php echo $status_desc; ?></span>
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
