<?php
/**
 * Master Administrative Engine - Rank & Match Daily Income Catch-Up Handler
 * Database: jeoczvkk_optimus
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Central Business Configuration matching config.php
$config = [
    'host' => 'localhost',
    'name' => 'jeoczvkk_optimus',
    'user' => 'jeoczvkk_jeoczvkk',
    'pass' => 'pearl$Pearl$',
    'ranks' => [
        ['name' => 'Mentor',       'matching' => 500,     'daily_income' => 0.25,  'days' => 100],
        ['name' => 'Pioneer',      'matching' => 1000,    'daily_income' => 2.50,  'days' => 100],
        ['name' => 'Elite',        'matching' => 2500,    'daily_income' => 6.25,  'days' => 100],
        ['name' => 'Titan',        'matching' => 5000,    'daily_income' => 12.50, 'days' => 100],
        ['name' => 'Master',       'matching' => 10000,   'daily_income' => 25.00, 'days' => 100],
        ['name' => 'Grand Master', 'matching' => 25000,   'daily_income' => 62.50, 'days' => 100],
        ['name' => 'Icon',         'matching' => 50000,   'daily_income' => 125.00, 'days' => 100],
        ['name' => 'Legend',       'matching' => 100000,  'daily_income' => 250.00, 'days' => 100],
        ['name' => 'Director',     'matching' => 250000,  'daily_income' => 625.00, 'days' => 100],
        ['name' => 'Ambassador',   'matching' => 500000,  'daily_income' => 1250.00, 'days' => 100],
        ['name' => 'Chairman',     'matching' => 1000000, 'daily_income' => 4000.00, 'days' => 100],
        ['name' => 'President',    'matching' => 2500000, 'daily_income' => 10000.00, 'days' => 100],
    ]
];

// Try to load central database connection if available, fallback to manual connection config
try {
    require_once dirname(__DIR__) . '/includes/db.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    $charset = 'utf8mb4';
    $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
    } catch (\PDOException $ex) {
        die("Database Connection Failed: " . $ex->getMessage());
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = "";
$messageType = "info"; // info, success, danger

// Handle Form Submissions & Tasks Routing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task = $_POST['task'] ?? '';

    try {
        switch ($task) {
            case 'clear_rank_income':
                $stmt = $pdo->prepare("DELETE FROM transactions WHERE type = 'RANK_INCOME'");
                $stmt->execute();
                $deletedCount = $stmt->rowCount();

                // Reset days_passed for matching schedules
                $pdo->exec("UPDATE matching_schedules SET days_passed = 0, status = 'active'");

                $message = "Successfully cleared all Rank Income transactions ($deletedCount records deleted) and reset matching schedules.";
                $messageType = "success";
                break;

            case 'run_rank_income':
                // Fetch active matching schedules
                $stmt = $pdo->query("SELECT s.*, u.username FROM matching_schedules s JOIN users u ON s.user_id = u.id WHERE s.status = 'active'");
                $schedules = $stmt->fetchAll();

                $processedEntries = 0;
                $currentExecutionTimestamp = date('Y-m-d H:i:s');

                $checkTx = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND investment_id = ? AND type = 'RANK_INCOME' AND roi_date = ?");
                $insertTx = $pdo->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, net_amount, description, roi_date, created_at) VALUES (?, ?, 'RANK_INCOME', ?, ?, ?, ?, ?)");
                $updateSched = $pdo->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");

                foreach ($schedules as $sched) {
                    $dailyIncome = (float)$sched['daily_income'];
                    $maxDays = (int)($sched['max_days'] ?? 100);
                    $daysPassed = (int)$sched['days_passed'];

                    if ($daysPassed >= $maxDays) {
                        $updateSched->execute([$daysPassed, 'completed', $sched['id']]);
                        continue;
                    }

                    // Rank income starts strictly from the exact date the match was made (created_at of matching schedule) to current date
                    $startDate = date('Y-m-d', strtotime($sched['created_at']));
                    $endDate = date('Y-m-d');

                    $currentDateObj = new DateTime($startDate);
                    $endDateObj = new DateTime($endDate);
                    $endDateObj->modify('+1 day');

                    $interval = new DateInterval('P1D');
                    $datePeriod = new DatePeriod($currentDateObj, $interval, $endDateObj);

                    foreach ($datePeriod as $date) {
                        if ($daysPassed >= $maxDays) break;

                        $targetDateStr = $date->format('Y-m-d');
                        if ($targetDateStr > date('Y-m-d')) continue;

                        $checkTx->execute([$sched['user_id'], $sched['id'], $targetDateStr]);
                        if ($checkTx->fetchColumn() == 0) {
                            $daysPassed++;
                            $desc = "Daily Matching Income for Slab $" . number_format($sched['slab_amount'], 2) . " (Day {$daysPassed}/{$maxDays})";
                            $insertTx->execute([$sched['user_id'], $sched['id'], $dailyIncome, $dailyIncome, $desc, $targetDateStr, $currentExecutionTimestamp]);

                            $status = ($daysPassed >= $maxDays) ? 'completed' : 'active';
                            $updateSched->execute([$daysPassed, $status, $sched['id']]);
                            $processedEntries++;
                        }
                    }
                }
                $message = "Rank & Match Income catch-up successfully executed at $currentExecutionTimestamp. Generated $processedEntries missing daily entries across " . count($schedules) . " active matching schedules.";
                $messageType = "success";
                break;

            case 'check_schedules':
                $stmt = $pdo->query("SELECT id, days_passed, max_days FROM matching_schedules WHERE status = 'active'");
                $activeScheds = $stmt->fetchAll();
                $completedCount = 0;

                foreach ($activeScheds as $as) {
                    if ($as['days_passed'] >= $as['max_days']) {
                        $upd = $pdo->prepare("UPDATE matching_schedules SET status = 'completed' WHERE id = ?");
                        $upd->execute([$as['id']]);
                        $completedCount++;
                    }
                }
                $message = "Matching schedules check complete. $completedCount contracts reached their maximum days limit and were marked completed.";
                $messageType = "success";
                break;

            default:
                $message = "Unknown or unsupported task requested.";
                $messageType = "danger";
                break;
        }
    } catch (\Exception $e) {
        $message = "Error executing task: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Fetch System Metrics across all matching schedules
$totalSchedules = $pdo->query("SELECT COUNT(*) FROM matching_schedules")->fetchColumn() ?: 0;
$activeSchedules = $pdo->query("SELECT COUNT(*) FROM matching_schedules WHERE status = 'active'")->fetchColumn() ?: 0;
$totalRankIncomePaid = $pdo->query("SELECT SUM(net_amount) FROM transactions WHERE type = 'RANK_INCOME'")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculate Rank & Match Daily Income - jeoczvkk_optimus</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --text-color: #f8fafc;
            --accent-color: #38bdf8;
            --border-color: rgba(56, 189, 248, 0.2);
            --btn-bg: #0284c7;
            --btn-hover: #0ea5e9;
            --success-bg: #10b981;
            --success-hover: #059669;
            --danger-bg: #e11d48;
            --danger-hover: #f43f5e;
            --warning-bg: #f59e0b;
            --warning-hover: #d97706;
        }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            backdrop-filter: blur(12px);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 0.9rem;
            color: #94a3b8;
        }
        .stat-card .value {
            font-size: 1.75rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-top: 10px;
        }
        .card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        h2 {
            margin-top: 0;
            color: var(--accent-color);
            font-size: 1.25rem;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid var(--success-bg);
            color: #34d399;
        }
        .alert.danger {
            background: rgba(225, 29, 72, 0.15);
            border: 1px solid var(--danger-bg);
            color: #fb7185;
        }
        .alert.info {
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid var(--accent-color);
            color: #bae6fd;
        }
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        button {
            background-color: var(--btn-bg);
            color: white;
            border: none;
            padding: 12px 20px;
            font-size: 0.95rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
            width: 100%;
            font-weight: 600;
            text-align: center;
        }
        button:hover {
            background-color: var(--btn-hover);
        }
        button.success {
            background-color: var(--success-bg);
        }
        button.success:hover {
            background-color: var(--success-hover);
        }
        button.warning {
            background-color: var(--warning-bg);
        }
        button.warning:hover {
            background-color: var(--warning-hover);
        }
        button.danger {
            background-color: var(--danger-bg);
        }
        button.danger:hover {
            background-color: var(--danger-hover);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.9rem;
        }
        th {
            color: var(--accent-color);
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Rank & Match Daily Income Engine</h1>
        <span>Database: <strong><?php echo htmlspecialchars($config['name']); ?></strong></span>
    </header>

    <?php if (!empty($message)): ?>
        <div class="alert <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- System Metrics Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Active Matching Contracts</h3>
            <div class="value"><?php echo number_format($activeSchedules); ?> / <?php echo number_format($totalSchedules); ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Rank Income Paid</h3>
            <div class="value">$<?php echo number_format($totalRankIncomePaid, 2); ?></div>
        </div>
    </div>

    <!-- Task Control Center Card -->
    <div class="card" style="margin-bottom: 30px;">
        <h2>Rank Income Task Dispatcher</h2>
        <p>Select any administrative task below to catch-up or process daily match incomes using the exact <strong>roi_date</strong> column.</p>

        <div class="actions-grid">
            <!-- 1. Run Historical Rank Income Catch-up -->
            <form method="POST">
                <input type="hidden" name="task" value="run_rank_income">
                <button type="submit" class="success">Run Rank Income Catch-Up</button>
            </form>

            <!-- 2. Check Completed Contracts -->
            <form method="POST">
                <input type="hidden" name="task" value="check_schedules">
                <button type="submit">Check & Complete Finished Contracts</button>
            </form>

            <!-- 3. Clear All Rank Income Transactions -->
            <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to delete all Rank Income transactions across all users?');">
                <input type="hidden" name="task" value="clear_rank_income">
                <button type="submit" class="danger">Clear All Rank Income Transactions</button>
            </form>
        </div>
    </div>

    <!-- Transactions Log Table -->
    <div class="card">
        <h2>Recent Rank Income Transactions (All Customers)</h2>
        <?php
        $stmt = $pdo->query("SELECT t.id, t.user_id, u.username, t.investment_id, t.amount, t.net_amount, t.description, t.roi_date, t.created_at FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE t.type = 'RANK_INCOME' ORDER BY t.id DESC LIMIT 20");
        $transactions = $stmt->fetchAll();
        ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>ROI Date (Target Date)</th>
                    <th>Execution Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($transactions) > 0): ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tx['id']); ?></td>
                        <td><?php echo htmlspecialchars($tx['username'] ?? 'User ID ' . $tx['user_id']); ?></td>
                        <td style="color: #34d399; font-weight: bold;">$<?php echo number_format($tx['net_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($tx['description']); ?></td>
                        <td><span style="background-color: #0284c7; color: white; padding: 4px 8px; border-radius: 4px;"><?php echo htmlspecialchars($tx['roi_date'] ?? 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($tx['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #94a3b8;">No Rank Income transactions recorded yet. Use the action dispatcher above to process tasks.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
