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
    (SELECT COALESCE(SUM(net_amount), 0) FROM transactions WHERE user_id = ?) as wallet_balance,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'WITHDRAWAL') as total_withdrawn,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'ROI') as total_roi,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'LEVEL_INCOME') as total_level,
    (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME') as total_rank
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
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

// Fetch dynamic unilevel legs business
$engine = new MLMEngine();
$legStats = $engine->getLegsBusiness($userId);

$childSlabData = null;
if ($legStats['matched_business'] == 0.00) {
    $stmtChild = $db->prepare("
        SELECT g.user_id, u.username, u.rank_id
        FROM genealogy g
        JOIN users u ON g.user_id = u.id
        WHERE g.parent_id = ? AND u.rank_id > 0
        ORDER BY g.level ASC, u.id ASC
        LIMIT 1
    ");
    $stmtChild->execute([$userId]);
    $rankedChild = $stmtChild->fetch();
    if ($rankedChild) {
        $childSlabData = $engine->getLegsBusiness($rankedChild['user_id']);
        $childSlabData['username'] = $rankedChild['username'];
        $childSlabData['rank_id'] = $rankedChild['rank_id'];
    }
}

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner dashboard-inner pb-5 p-3">
    <div class="row p-0">
        <div class="account-overview">
            <div class="overview-row">
                <div class="overview-box">
                    <h3>TODAY ROI $</h3>
                    <p class="text-primary mt-2"><?php echo number_format($stats['today_roi'], 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3>TODAY %</h3>
                    <p class="text-primary mt-2">0.5%</p>
                </div>
                <div class="overview-box" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#earningsBreakdownModal" title="Click to view full breakdown">
                    <h3>TOTAL EARNING $ <i class="fa-solid fa-circle-info ms-1 text-info" style="font-size: 14px;"></i></h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo number_format($stats['total_earning'], 2); ?></p>
                    <small class="text-muted d-block mt-1" style="font-size: 11px;">Click to view composition</small>
                </div>
            </div>
            <div class="overview-row">
                <div class="overview-box">
                    <h3>TEAM INVESTMENT $</h3>
                    <p class="text-primary mt-2"><?php echo number_format($team_inv['team_investment'] ?? 0, 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3>MY INVESTMENT</h3>
                    <p class="text-primary mt-2"><?php echo number_format($user['total_investment'], 2); ?></p>
                </div>
            </div>
            <div class="overview-row">
                <div class="overview-box">
                    <h3 class="text-upercase">CURRENT POWER LEG</h3>
                    <p class="text-primary mt-2"><?php echo number_format($legStats['power_leg'], 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3 class="text-upercase">CURRENT WEAKER LEG</h3>
                    <p class="text-primary mt-2"><?php echo number_format($legStats['matching_leg'], 2); ?></p>
                </div>
            </div>
            <div class="overview-row">
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-upercase">SLAB-MATCHED BUSINESS</h3>
                    <?php if ($childSlabData): ?>
                        <small class="text-warning d-block" style="font-size: 11px;">(Child: <?php echo htmlspecialchars($childSlabData['username']); ?>)</small>
                    <?php endif; ?>
                    <p class="text-success mt-2 fw-bold">
                        <?php
                        $dispMatched = $childSlabData ? $childSlabData['matched_business'] : $legStats['matched_business'];
                        echo number_format($dispMatched, 2);
                        ?>
                    </p>
                </div>
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-upercase">POWER CARRY FORWARD</h3>
                    <?php if ($childSlabData): ?>
                        <small class="text-warning d-block" style="font-size: 11px;">(Child: <?php echo htmlspecialchars($childSlabData['username']); ?>)</small>
                    <?php endif; ?>
                    <p class="text-warning mt-2 fw-bold">
                        <?php
                        $dispPower = $childSlabData ? $childSlabData['power_carry_forward'] : $legStats['power_carry_forward'];
                        echo number_format($dispPower, 2);
                        ?>
                    </p>
                </div>
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-upercase">WEAKER CARRY FORWARD</h3>
                    <?php if ($childSlabData): ?>
                        <small class="text-warning d-block" style="font-size: 11px;">(Child: <?php echo htmlspecialchars($childSlabData['username']); ?>)</small>
                    <?php endif; ?>
                    <p class="text-warning mt-2 fw-bold">
                        <?php
                        $dispRest = $childSlabData ? $childSlabData['rest_carry_forward'] : $legStats['rest_carry_forward'];
                        echo number_format($dispRest, 2);
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <button type="button" class="btn btn-secondary w-100 mb-2 text-uppercase ceiling-limit-btn" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%);">
            <h3 class="text-center pt-5 pb-5 mb-3 text-uppercase" style="color: #3f2259 !important;">CEILING LIMIT BALANCE $<?php echo number_format($ceilingBalance, 2); ?></h3>
            <div class="progress" style="height: 50px">
                <div class="progress-bar text-bg-success" style="width: <?php echo $progressPercent; ?>%"><?php echo round($progressPercent); ?>%</div>
            </div>
        </button>
    </div>

    <!-- Achievement Milestones / Rank Scroller -->
    <div class="achievement-section">
        <h5><i class="fa-solid fa-medal"></i>Achievement Milestones</h5>
        <div class="rank-scroller-wrap">
            <button type="button" class="rank-scroll-btn left" onclick="document.getElementById('rankScroller').scrollBy({left:-340,behavior:'smooth'})">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <div class="rank-scroller" id="rankScroller">
                <?php
                // Render the 12 dynamic ranks
                $ranksList = [
                    ['class' => 'rk-mentor',      'name' => 'Mentor',       'matching' => '500'],
                    ['class' => 'rk-pioneer',     'name' => 'Pioneer',      'matching' => '1,000'],
                    ['class' => 'rk-elite',       'name' => 'Elite',        'matching' => '2,500'],
                    ['class' => 'rk-titan',       'name' => 'Titan',        'matching' => '5,000'],
                    ['class' => 'rk-master',      'name' => 'Master',       'matching' => '10,000'],
                    ['class' => 'rk-grandmaster', 'name' => 'Grand Master', 'matching' => '25,000'],
                    ['class' => 'rk-icon',        'name' => 'Icon',         'matching' => '50,000'],
                    ['class' => 'rk-legend',      'name' => 'Legend',       'matching' => '100,000'],
                    ['class' => 'rk-director',    'name' => 'Director',     'matching' => '250,000'],
                    ['class' => 'rk-ambassador',  'name' => 'Ambassador',   'matching' => '500,000'],
                    ['class' => 'rk-chairman',    'name' => 'Chairman',     'matching' => '1,000,000'],
                    ['class' => 'rk-president',   'name' => 'President',    'matching' => '2,500,000'],
                ];

                foreach ($ranksList as $index => $r):
                    $rIndex = $index + 1;
                    $isAchieved = ($user['rank_id'] >= $rIndex);
                    $isCurrent = ($user['rank_id'] == $rIndex);

                    $cardClass = $r['class'];
                    if ($isAchieved) {
                        $cardClass .= ' rk-achieved';
                    }
                    if ($isCurrent) {
                        $cardClass .= ' rk-current';
                    }
                ?>
                    <div class="rank-card <?php echo $cardClass; ?>">
                        <div class="rk-content">
                            <div class="rk-name">
                                <?php echo htmlspecialchars($r['name']); ?>
                                <?php if ($isCurrent): ?>
                                    <span class="rk-badge-current">Current</span>
                                <?php elseif ($isAchieved): ?>
                                    <i class="fa-solid fa-circle-check rk-badge-check" title="Achieved"></i>
                                <?php endif; ?>
                            </div>
                            <div class="rk-target"><?php echo $r['matching']; ?> x <?php echo $r['matching']; ?></div>
                            <div class="rk-desc">Target Business</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="rank-scroll-btn right" onclick="document.getElementById('rankScroller').scrollBy({left:340,behavior:'smooth'})">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <div class="row p-0 mt-4">
        <div class="account-overview">
            <div class="overview-row">
                <div class="overview-box">
                    <h3>WITHDRAWAL WALLET $</h3>
                    <p class="text-primary mt-2"><?php echo number_format($stats['wallet_balance'], 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3>TOTAL WITHDRAWN $</h3>
                    <p class="text-primary mt-2"><?php echo number_format($stats['total_withdrawn'], 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3>MY TEAM MEMBERS</h3>
                    <p class="text-primary mt-2"><?php echo $team['team_count']; ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Earnings Breakdown Modal -->
<div class="modal fade" id="earningsBreakdownModal" tabindex="-1" aria-labelledby="earningsBreakdownModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-white" style="background-color: #2d1840; border: 2px solid #504793; border-radius: 12px;">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title text-white fw-bold" id="earningsBreakdownModalLabel">
                    <i class="fa-solid fa-chart-pie me-2 text-warning"></i> Earnings Composition
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <span class="text-uppercase text-muted d-block" style="font-size: 13px; letter-spacing: 1px;">Lifetime Total Earnings</span>
                    <h2 class="text-success fw-bold mt-1" style="font-size: 32px;">$<?php echo number_format($stats['total_earning'], 2); ?></h2>
                </div>
                <div class="p-3 rounded mb-3" style="background-color: #3f2259;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="d-block fw-bold text-white"><i class="fa-solid fa-coins text-warning me-2"></i>Daily Trade Profit (ROI)</span>
                            <small class="text-muted">0.50% Daily returns from your packages</small>
                        </div>
                        <span class="badge bg-dark text-success fs-6 fw-bold">$<?php echo number_format($stats['total_roi'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="d-block fw-bold text-white"><i class="fa-solid fa-network-wired text-info me-2"></i>Level Generation Income</span>
                            <small class="text-muted">Commissions distributed over 12 generations</small>
                        </div>
                        <span class="badge bg-dark text-info fs-6 fw-bold">$<?php echo number_format($stats['total_level'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <span class="d-block fw-bold text-white"><i class="fa-solid fa-award text-danger me-2"></i>Slab Matching (Rank Income)</span>
                            <small class="text-muted">Daily rank income from matched unilevel business</small>
                        </div>
                        <span class="badge bg-dark text-danger fs-6 fw-bold">$<?php echo number_format($stats['total_rank'], 2); ?></span>
                    </div>
                </div>
                <div class="text-center text-muted" style="font-size: 11px;">
                    <i class="fa-solid fa-lock me-1"></i> Values are calculated in real-time from audit-logged financial events.
                </div>
            </div>
            <div class="modal-footer border-top-0 d-flex justify-content-center">
                <button type="button" class="btn btn-secondary px-4 text-white" data-bs-dismiss="modal" style="background-color: #504793; border: none; border-radius: 20px;">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
