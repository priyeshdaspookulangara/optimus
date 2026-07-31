<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

$abnormalities = [];

// Check 1: Negative Wallet Balances
$stmt1 = $db->query("
    SELECT u.id, u.username, u.mid, COALESCE(SUM(t.net_amount), 0) as balance
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    GROUP BY u.id
    HAVING balance < 0
");
$negBalances = $stmt1->fetchAll();
foreach ($negBalances as $row) {
    $abnormalities[] = [
        'type' => 'Negative Wallet Balance',
        'severity' => 'Critical',
        'description' => "User has a running balance of <strong>$" . number_format($row['balance'], 2) . "</strong>, which is below 0.",
        'user' => $row
    ];
}

// Check 2: Total Earnings Exceeding 300% ID Cap
$idCapMultiplier = $config['id_cap_multiplier'] ?? 3.0;
$stmt2 = $db->query("
    SELECT u.id, u.username, u.mid, u.total_investment,
           (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned
    FROM users u
");
$capCheckUsers = $stmt2->fetchAll();
foreach ($capCheckUsers as $row) {
    $maxCap = $row['total_investment'] * $idCapMultiplier;
    $totalEarned = (float)$row['total_earned'];
    if ($totalEarned > $maxCap && $row['total_investment'] > 0) {
        $abnormalities[] = [
            'type' => 'ID Cap Violation',
            'severity' => 'High',
            'description' => "User has earned <strong>$" . number_format($totalEarned, 2) . "</strong>, which exceeds their maximum 300% ID cap limit of <strong>$" . number_format($maxCap, 2) . "</strong> (Investment: $" . number_format($row['total_investment'], 2) . ").",
            'user' => $row
        ];
    }
}

// Check 3: Total Investment Field Discrepancy
$stmt3 = $db->query("
    SELECT u.id, u.username, u.mid, u.total_investment,
           COALESCE((SELECT SUM(amount) FROM investments WHERE user_id = u.id AND status IN ('active', 'completed')), 0) as actual_sum
    FROM users u
");
$investDiscrepancies = $stmt3->fetchAll();
foreach ($investDiscrepancies as $row) {
    if (abs((float)$row['total_investment'] - (float)$row['actual_sum']) > 0.01) {
        $abnormalities[] = [
            'type' => 'Investment Stats Discrepancy',
            'severity' => 'Medium',
            'description' => "Database column <code>total_investment</code> is <strong>$" . number_format($row['total_investment'], 2) . "</strong>, but actual active/completed investments in the investments table total <strong>$" . number_format($row['actual_sum'], 2) . "</strong>.",
            'user' => $row
        ];
    }
}

// Check 4: Missing Genealogy Nodes
$stmt4 = $db->query("
    SELECT u.id, u.username, u.mid
    FROM users u
    WHERE u.id > 1 AND NOT EXISTS (SELECT 1 FROM genealogy WHERE user_id = u.id)
");
$missingGenealogy = $stmt4->fetchAll();
foreach ($missingGenealogy as $row) {
    $abnormalities[] = [
        'type' => 'Missing Genealogy Node',
        'severity' => 'High',
        'description' => "User is present in the <code>users</code> table, but has no corresponding lineage records in the <code>genealogy</code> tree structure.",
        'user' => $row
    ];
}

// Check 5: Overextended Matching Schedule Days
$stmt5 = $db->query("
    SELECT m.id as sched_id, m.slab_amount, m.days_passed, m.max_days, u.id, u.username, u.mid
    FROM matching_schedules m
    JOIN users u ON m.user_id = u.id
    WHERE m.days_passed > m.max_days
");
$overextendedScheds = $stmt5->fetchAll();
foreach ($overextendedScheds as $row) {
    $abnormalities[] = [
        'type' => 'Overextended Contract Days',
        'severity' => 'Medium',
        'description' => "Matching contract ID: <strong>" . $row['sched_id'] . "</strong> ($" . number_format($row['slab_amount'], 2) . " Slab) has run for <strong>" . $row['days_passed'] . "</strong> days (Max limit is " . $row['max_days'] . " days).",
        'user' => $row
    ];
}

$pageTitle = 'System Abnormalities Scan';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body bg-dark text-white rounded p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1"><i class="fa fa-bug text-danger me-2"></i> System Abnormalities Scan</h2>
                            <p class="mb-0 text-muted">This analyzer scans the database for architectural anomalies, negative e-wallet balances, broken genealogy structures, and over-limit payouts.</p>
                        </div>
                        <div class="text-end">
                            <?php if (empty($abnormalities)): ?>
                                <span class="badge bg-success p-3" style="font-size: 16px;">
                                    <i class="fa fa-circle-check me-1"></i> System Healthy & Secure
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger p-3" style="font-size: 16px;">
                                    <i class="fa fa-triangle-exclamation me-1"></i> <?php echo count($abnormalities); ?> Abnormality Detected
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm border-0 p-3">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-stethoscope text-primary me-2"></i> Comprehensive Security & Integrity Log</h5>
                    <button onclick="window.location.reload();" class="btn btn-sm btn-outline-primary"><i class="fa fa-refresh me-1"></i> Re-Scan</button>
                </div>
                <div class="card-body">
                    <?php if (empty($abnormalities)): ?>
                        <div class="text-center py-5">
                            <i class="fa fa-shield-heart text-success mb-3" style="font-size: 64px;"></i>
                            <h4 class="text-success">All Systems Operational</h4>
                            <p class="text-muted">No negative balances, statistics deviations, cap breaches, or orphan nodes detected. Your database represents a healthy network state.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Severity</th>
                                        <th>Anomaly Class</th>
                                        <th>Affected User</th>
                                        <th>Detailed Diagnostic Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($abnormalities as $item):
                                        $severityBadge = 'bg-secondary';
                                        if ($item['severity'] === 'Critical') $severityBadge = 'bg-danger';
                                        elseif ($item['severity'] === 'High') $severityBadge = 'bg-warning text-dark';
                                        elseif ($item['severity'] === 'Medium') $severityBadge = 'bg-info text-dark';
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?php echo $severityBadge; ?>" style="font-size: 12px; width: 80px; display: inline-block; text-align: center;">
                                                    <?php echo $item['severity']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['type']); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['user']['username']); ?></strong><br>
                                                <small class="text-muted">MID: <?php echo htmlspecialchars($item['user']['mid'] ?? 'None'); ?></small><br>
                                                <small class="text-muted">ID: <?php echo htmlspecialchars($item['user']['id']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo $item['description']; ?>
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
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>