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

// Handle manual run trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cron'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $_SESSION['error'] = "CSRF token validation failed.";
        header("Location: cron_jobs.php");
        exit();
    }

    require_once __DIR__ . '/../includes/engine.php';

    // Insert initial run log
    $stmt = $db->prepare("INSERT INTO cron_logs (command, status, start_time) VALUES (?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute(['manual_trigger', 'running']);
    $cronLogId = $db->lastInsertId();

    ob_start();

    echo "[".date('Y-m-d H:i:s')."] Starting manually triggered daily income processing...\n";

    try {
        $engine = new MLMEngine();

        echo "Processing Daily ROI... ";
        $engine->processDailyROI();
        echo "Done.\n";

        echo "Processing Daily Rank Income... ";
        $engine->processRankIncome();
        echo "Done.\n";

        echo "[".date('Y-m-d H:i:s')."] Manual daily processing completed successfully.\n";

        $output = ob_get_clean();

        // Update log to success
        $stmt = $db->prepare("UPDATE cron_logs SET end_time = CURRENT_TIMESTAMP, status = ?, output = ? WHERE id = ?");
        $stmt->execute(['success', $output, $cronLogId]);

        $_SESSION['success'] = "Daily cron job processed manually successfully!";

    } catch (Exception $e) {
        echo "Error during manual cron processing: " . $e->getMessage() . "\n";

        $output = ob_get_clean();

        // Update log to failed
        $stmt = $db->prepare("UPDATE cron_logs SET end_time = CURRENT_TIMESTAMP, status = ?, output = ?, error_message = ? WHERE id = ?");
        $stmt->execute(['failed', $output, $e->getMessage(), $cronLogId]);

        $_SESSION['error'] = "Manual daily processing failed: " . $e->getMessage();
    }

    header("Location: cron_jobs.php");
    exit();
}

$pageTitle = 'Cron Job Tracking';
include __DIR__ . '/includes/header.php';

// Fetch summary stats
$stmt = $db->query("SELECT COUNT(*) as total_runs FROM cron_logs");
$totalRuns = $stmt->fetch()['total_runs'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as success_runs FROM cron_logs WHERE status = 'success'");
$successRuns = $stmt->fetch()['success_runs'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as failed_runs FROM cron_logs WHERE status = 'failed'");
$failedRuns = $stmt->fetch()['failed_runs'] ?? 0;

$stmt = $db->query("SELECT * FROM cron_logs ORDER BY start_time DESC LIMIT 1");
$lastRun = $stmt->fetch();

function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    $time = strtotime($timestamp);
    $diff = time() - $time;
    if ($diff < 1) return 'Just now';
    $intervals = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
        1        => 'second'
    ];
    foreach ($intervals as $secs => $str) {
        $d = $diff / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . $str . ($r > 1 ? 's' : '') . ' ago';
        }
    }
    return 'Never';
}

// Pagination parameters
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Fetch total records for pagination
$stmt = $db->query("SELECT COUNT(*) as count FROM cron_logs");
$totalLogs = $stmt->fetch()['count'] ?? 0;
$totalPages = ceil($totalLogs / $limit);

// Fetch logs
$stmt = $db->prepare("SELECT * FROM cron_logs ORDER BY start_time DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Cron Job Tracking</h3>
    <form method="post" onsubmit="return confirm('Are you sure you want to run the daily income processing manually right now?');">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
        <button type="submit" name="run_cron" class="btn btn-success">
            <i class="fa fa-play me-2"></i> Trigger Cron Manually
        </button>
    </form>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card bg-white text-dark shadow-sm border-0 h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <h6 class="text-muted text-uppercase small">Last Cron Activity</h6>
                    <?php if ($lastRun): ?>
                        <h4 class="mb-1">
                            <?php if ($lastRun['status'] === 'success'): ?>
                                <span class="badge bg-success">Success</span>
                            <?php elseif ($lastRun['status'] === 'failed'): ?>
                                <span class="badge bg-danger">Failed</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Running</span>
                            <?php endif; ?>
                        </h4>
                        <p class="text-muted small mb-0 mt-2">
                            Started: <?php echo date('Y-m-d H:i:s', strtotime($lastRun['start_time'])); ?><br>
                            Ended: <?php echo $lastRun['end_time'] ? date('Y-m-d H:i:s', strtotime($lastRun['end_time'])) : 'N/A'; ?>
                        </p>
                    <?php else: ?>
                        <h4 class="text-muted">No runs logged yet</h4>
                    <?php endif; ?>
                </div>
                <div class="mt-3 pt-2 border-top">
                    <span class="small text-primary font-weight-bold"><i class="fa fa-clock me-1"></i><?php echo $lastRun ? timeAgo($lastRun['start_time']) : 'Never'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-4">
        <div class="card bg-primary text-white shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="fa fa-history fa-2x opacity-50"></i>
                </div>
                <div>
                    <h6 class="text-white-50 text-uppercase small mb-1">Total Executions</h6>
                    <h2 class="mb-0"><?php echo number_format($totalRuns); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-4">
        <div class="card bg-success text-white shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="fa fa-check-circle fa-2x opacity-50"></i>
                </div>
                <div>
                    <h6 class="text-white-50 text-uppercase small mb-1">Successful Executions</h6>
                    <h2 class="mb-0"><?php echo number_format($successRuns); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-4">
        <div class="card bg-danger text-white shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3">
                    <i class="fa fa-times-circle fa-2x opacity-50"></i>
                </div>
                <div>
                    <h6 class="text-white-50 text-uppercase small mb-1">Failed Executions</h6>
                    <h2 class="mb-0"><?php echo number_format($failedRuns); ?></h2>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 text-dark"><i class="fa fa-list me-2"></i>Cron Job Log History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">Log ID</th>
                        <th>Trigger/Command</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Error Message</th>
                        <th class="text-end" style="width: 150px;">Console Output</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="fa fa-info-circle me-1"></i> No cron job log history found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log):
                            $durationSecs = null;
                            if ($log['end_time'] && $log['start_time']) {
                                $durationSecs = strtotime($log['end_time']) - strtotime($log['start_time']);
                            }
                        ?>
                            <tr>
                                <td><code>#<?php echo htmlspecialchars($log['id']); ?></code></td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($log['command']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['start_time'])); ?></td>
                                <td><?php echo $log['end_time'] ? date('Y-m-d H:i:s', strtotime($log['end_time'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                <td>
                                    <?php
                                        if ($durationSecs !== null) {
                                            echo htmlspecialchars($durationSecs) . " s";
                                        } else {
                                            echo '<span class="text-muted">N/A</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($log['status'] === 'success'): ?>
                                        <span class="badge bg-success"><i class="fa fa-check me-1"></i>Success</span>
                                    <?php elseif ($log['status'] === 'failed'): ?>
                                        <span class="badge bg-danger"><i class="fa fa-times me-1"></i>Failed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fa fa-sync fa-spin me-1"></i>Running</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log['error_message']): ?>
                                        <span class="text-danger small font-monospace d-inline-block text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($log['error_message']); ?>">
                                            <?php echo htmlspecialchars($log['error_message']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#outputModal"
                                            data-output="<?php echo htmlspecialchars($log['output'] ?? 'No output captured.'); ?>"
                                            data-id="<?php echo htmlspecialchars($log['id']); ?>">
                                        <i class="fa fa-terminal me-1"></i> View Console
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="d-flex justify-content-center mt-4">
                <ul class="pagination pagination-sm">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">&laquo; Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next &raquo;</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for viewing output logs -->
<div class="modal fade" id="outputModal" tabindex="-1" aria-labelledby="outputModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="outputModalLabel">Terminal Output Logs (Run #<span id="modal-run-id"></span>)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-dark text-light p-3">
                <pre id="modal-output-content" class="mb-0" style="font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; white-space: pre-wrap; word-wrap: break-word; color: #a9ffaf;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var outputModal = document.getElementById('outputModal');
    if (outputModal) {
        outputModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var output = button.getAttribute('data-output');
            var runId = button.getAttribute('data-id');

            var modalTitleRunId = outputModal.querySelector('#modal-run-id');
            var modalOutputContent = outputModal.querySelector('#modal-output-content');

            modalTitleRunId.textContent = runId;
            modalOutputContent.textContent = output;
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
