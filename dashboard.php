<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch User Data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Fetch Earnings Data
$stmt = $db->prepare("SELECT
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'ROI' AND DATE(created_at) = CURDATE()) as today_roi,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earning,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME' AND DATE(created_at) = CURDATE()) as today_level_income,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND DATE(created_at) = CURDATE()) as today_rank_income,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME') as total_level_income,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME') as total_rank_income,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'ROI') as total_roi,
    (SELECT COALESCE(SUM(net_amount), 0) FROM transactions WHERE user_id = ?) as wallet_balance,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'WITHDRAWAL') as total_withdrawn
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$stats = $stmt->fetch();

// Team Stats
$stmt = $db->prepare("SELECT COUNT(*) as team_count FROM genealogy WHERE parent_id = ?");
$stmt->execute([$userId]);
$team = $stmt->fetch();

$stmt = $db->prepare("SELECT SUM(total_investment) as team_investment FROM users u JOIN genealogy g ON u.id = g.user_id WHERE g.parent_id = ?");
$stmt->execute([$userId]);
$team_inv = $stmt->fetch();

$config = require __DIR__ . '/includes/config.php';
$rankName = ($user['rank_id'] > 0) ? $config['ranks'][$user['rank_id']-1]['name'] : 'None';

// Ceiling Limit Calculation (300% of total investment)
$maxCap = $user['total_investment'] * $config['id_cap_multiplier'];
$ceilingBalance = max(0, $maxCap - $stats['total_earning']);
$progressPercent = ($maxCap > 0) ? min(100, ($stats['total_earning'] / $maxCap) * 100) : 0;

?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>App Dashboard</title>
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/core/libs.min.css">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/coinex.min.css?v=4.1.0">
  <style>
    body { background: #fff; }
    .overview-box { background: #3f2259; color: #fff; padding: 20px; border-radius: 12px; text-align: center; flex: 1; margin: 5px; }
    .overview-row { display: flex; justify-content: space-between; margin-bottom: 15px; }
    .text-primary { color: #cca354 !important; }
  </style>
</head>
<body>
  <div class="container-fluid p-4">
    <div class="row">
        <div class="col-md-12">
            <h2>Welcome, <?php echo htmlspecialchars($user['username']); ?> (<?php echo $rankName; ?>)</h2>
            <hr>
        </div>
    </div>

    <div class="account-overview">
        <div class="overview-row">
            <div class="overview-box">
                <h3>TODAY ROI $</h3>
                <p class="text-primary"><?php echo number_format($stats['today_roi'], 2); ?></p>
            </div>
            <div class="overview-box">
                <h3>TODAY %</h3>
                <p class="text-primary">0.5%</p>
            </div>
            <div class="overview-box">
                <h3>TOTAL EARNING $</h3>
                <p class="text-primary"><?php echo number_format($stats['total_earning'], 2); ?></p>
            </div>
        </div>
        <div class="overview-row">
            <div class="overview-box">
                <h3>TEAM INVESTMENT $</h3>
                <p class="text-primary"><?php echo number_format($team_inv['team_investment'] ?? 0, 2); ?></p>
            </div>
            <div class="overview-box">
                <h3>MY INVESTMENT</h3>
                <p class="text-primary"><?php echo number_format($user['total_investment'], 2); ?></p>
            </div>
        </div>
        <div class="overview-row">
            <div class="overview-box">
                <h3 class="text-upercase">CURRENT POWER LEG</h3>
                <p class="text-primary"><?php echo number_format(max($user['left_leg_business'], $user['right_leg_business']), 2); ?></p>
            </div>
            <div class="overview-box">
                <h3 class="text-upercase">CURRENT WEAKER LEG</h3>
                <p class="text-primary"><?php echo number_format(min($user['left_leg_business'], $user['right_leg_business']), 2); ?></p>
            </div>
        </div>
    </div>

    <div class="container-fluid mt-4">
        <div class="card bg-dark p-4 text-center">
            <h3 style="color: #cca354 !important;">CEILING LIMIT BALANCE $<?php echo number_format($ceilingBalance, 2); ?></h3>
            <div class="progress" style="height: 30px">
                <div class="progress-bar bg-success" style="width: <?php echo $progressPercent; ?>%"><?php echo round($progressPercent); ?>%</div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-4">
            <div class="overview-box">
                <h3>WITHDRAWAL WALLET</h3>
                <p class="text-primary">$<?php echo number_format($stats['wallet_balance'], 2); ?></p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="overview-box">
                <h3>TOTAL WITHDRAWN</h3>
                <p class="text-primary">$<?php echo number_format($stats['total_withdrawn'], 2); ?></p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="overview-box">
                <h3>TEAM MEMBERS</h3>
                <p class="text-primary"><?php echo $team['team_count']; ?></p>
            </div>
        </div>
    </div>
  </div>
</body>
</html>
