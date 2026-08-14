<?php
/**
 * Master Administrative & Automation Engine (All-in-One Task Handler)
 * Database: jeoczvkk_optimus
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Central Business Configuration matching config.php with accurate ROI settings
$config = [
    'host' => 'localhost',
    'name' => 'jeoczvkk_optimus',
    'user' => 'jeoczvkk_jeoczvkk',
    'pass' => 'pearl$Pearl$',
    'roi' => [
        'daily_rate' => 0.0050, // 0.50%
        'max_days' => 400,
        'cap_multiplier' => 2.0, // 200%
    ],
    'packages' => [0, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000],
    'level_percentages' => [
        1 => 5, 2 => 2, 3 => 2, 4 => 1, 5 => 1, 6 => 1,
        7 => 0.50, 8 => 0.50, 9 => 0.50, 10 => 0.50, 11 => 0.50, 12 => 0.50
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
            case 'clear_roi':
                $stmt = $pdo->prepare("DELETE FROM transactions WHERE type = 'ROI'");
                $stmt->execute();
                $deletedCount = $stmt->rowCount();
                $message = "Successfully cleared all ROI transactions ($deletedCount records deleted).";
                $messageType = "success";
                break;

            case 'run_poi':
                $stmt = $pdo->query("SELECT id, user_id, amount, status, created_at FROM investments WHERE status = 'active'");
                $investments = $stmt->fetchAll();

                $processedEntries = 0;
                $dailyRate = $config['roi']['daily_rate'];
                $maxDays = $config['roi']['max_days'];
                $currentExecutionTimestamp = date('Y-m-d H:i:s');

                // Using roi_date for correct checks and insertion
                $checkTx = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE investment_id = ? AND type = 'ROI' AND roi_date = ?");
                $insertTx = $pdo->prepare("INSERT INTO transactions (user_id, investment_id, type, amount, net_amount, roi_date, created_at) VALUES (?, ?, 'ROI', ?, ?, ?, ?)");

                foreach ($investments as $inv) {
                    $poiAmount = $inv['amount'] * $dailyRate;

                    $startDate = max(date('Y-m-d', strtotime($inv['created_at'])), date('Y-m-d', strtotime("-$maxDays days")));
                    $endDate = date('Y-m-d');

                    $currentDateObj = new DateTime($startDate);
                    $endDateObj = new DateTime($endDate);
                    $endDateObj->modify('+1 day');

                    $interval = new DateInterval('P1D');
                    $datePeriod = new DatePeriod($currentDateObj, $interval, $endDateObj);

                    foreach ($datePeriod as $date) {
                        $targetDateStr = $date->format('Y-m-d');
                        if ($targetDateStr > date('Y-m-d')) continue;

                        $checkTx->execute([$inv['id'], $targetDateStr]);
                        if ($checkTx->fetchColumn() == 0) {
                            $insertTx->execute([$inv['user_id'], $inv['id'], $poiAmount, $poiAmount, $targetDateStr, $currentExecutionTimestamp]);
                            $processedEntries++;
                        }
                    }
                }
                $message = "Historical backfill & batch ROI successfully executed at $currentExecutionTimestamp. Generated $processedEntries missing daily entries across " . count($investments) . " active investments with accurate ROI Dates.";
                $messageType = "success";
                break;

            case 'process_levels':
                // Task placeholder for multi-level commission distribution engine
                $message = "Level commission distribution engine triggered successfully (Task stub ready for level bonuses).";
                $messageType = "success";
                break;

            case 'check_caps':
                // Task placeholder for checking 200% investment cap limits and auto-closing completed investments
                $stmt = $pdo->query("SELECT id, amount FROM investments WHERE status = 'active'");
                $activeInvests = $stmt->fetchAll();
                $cappedCount = 0;

                foreach ($activeInvests as $ai) {
                    $maxCap = $ai['amount'] * $config['roi']['cap_multiplier'];
                    $paidStmt = $pdo->prepare("SELECT SUM(net_amount) FROM transactions WHERE investment_id = ? AND type = 'ROI'");
                    $paidStmt->execute([$ai['id']]);
                    $totalPaid = $paidStmt->fetchColumn() ?: 0;

                    if ($totalPaid >= $maxCap) {
                        $upd = $pdo->prepare("UPDATE investments SET status = 'completed' WHERE id = ?");
                        $upd->execute([$ai['id']]);
                        $cappedCount++;
                    }
                }
                $message = "Investment cap check complete. $cappedCount investments reached their 200% cap and were marked completed.";
                $messageType = "success";
                break;

            case 'optimize_db':
                $pdo->query("OPTIMIZE TABLE transactions, investments, users");
                $message = "Database tables optimized successfully.";
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

// Fetch System Metrics across all users/investments
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalInvestments = $pdo->query("SELECT COUNT(*) FROM investments")->fetchColumn();
$activeInvestments = $pdo->query("SELECT COUNT(*) FROM investments WHERE status = 'active'")->fetchColumn();
$totalRoiPaid = $pdo->query("SELECT SUM(net_amount) FROM transactions WHERE type = 'ROI'")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Administrative Engine - jeoczvkk_optimus</title>
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
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
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
        <h1>Master Administrative Engine</h1>
        <span>Database: <strong><?php echo htmlspecialchars($config['name']); ?></strong></span>
    </header>

    <?php if (!empty($message)): ?>
        <div class="alert <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- System Metrics Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Customers</h3>
            <div class="value"><?php echo number_format($totalUsers); ?></div>
        </div>
        <div class="stat-card">
            <h3>Active Investments</h3>
            <div class="value"><?php echo number_format($activeInvestments); ?> / <?php echo number_format($totalInvestments); ?></div>
        </div>
        <div class="stat-card">
            <h3>Total ROI Distributed</h3>
            <div class="value">$<?php echo number_format($totalRoiPaid, 2); ?></div>
        </div>
    </div>

    <!-- Task Control Center Card -->
    <div class="card" style="margin-bottom: 30px;">
        <h2>Engine Task Dispatcher</h2>
        <p>Select any administrative task below to execute it immediately against the platform database.</p>

        <div class="actions-grid">
            <!-- 1. Run Historical ROI Catch-up -->
            <form method="POST">
                <input type="hidden" name="task" value="run_poi">
                <button type="submit" class="success">Run Historical ROI Catch-Up</button>
            </form>

            <!-- 2. Process Level Commissions -->
            <form method="POST">
                <input type="hidden" name="task" value="process_levels">
                <button type="submit" class="warning">Process Level Commissions</button>
            </form>

            <!-- 3. Check Investment 200% Caps -->
            <form method="POST">
                <input type="hidden" name="task" value="check_caps">
                <button type="submit">Check & Close Capped Invs</button>
            </form>

            <!-- 4. Database Maintenance / Optimize -->
            <form method="POST">
                <input type="hidden" name="task" value="optimize_db">
                <button type="submit" style="background-color: #6366f1;">Optimize Database Tables</button>
            </form>

            <!-- 5. Clear All ROI Transactions -->
            <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to delete all ROI transactions across all users?');">
                <input type="hidden" name="task" value="clear_roi">
                <button type="submit" class="danger">Clear All ROI Transactions</button>
            </form>
        </div>
    </div>

    <!-- Transactions Log Table -->
    <div class="card">
        <h2>Recent ROI Transactions (All Customers)</h2>
        <?php
        $stmt = $pdo->query("SELECT id, user_id, investment_id, amount, net_amount, roi_date, created_at FROM transactions WHERE type = 'ROI' ORDER BY id DESC LIMIT 20");
        $transactions = $stmt->fetchAll();
        ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User ID</th>
                    <th>Investment ID</th>
                    <th>Amount</th>
                    <th>Net Amount</th>
                    <th>ROI Date</th>
                    <th>Execution Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($transactions) > 0): ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tx['id']); ?></td>
                        <td><?php echo htmlspecialchars($tx['user_id']); ?></td>
                        <td><?php echo htmlspecialchars($tx['investment_id']); ?></td>
                        <td><?php echo htmlspecialchars($tx['amount']); ?></td>
                        <td><?php echo htmlspecialchars($tx['net_amount']); ?></td>
                        <td><span class="badge" style="background-color: #0284c7; color: white; padding: 4px 8px; border-radius: 4px;"><?php echo htmlspecialchars($tx['roi_date'] ?? 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($tx['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #94a3b8;">No ROI transactions recorded yet. Use the action dispatcher above to process tasks.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
