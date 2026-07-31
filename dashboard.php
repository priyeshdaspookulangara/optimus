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

// Fetch latest 15 income transactions for detailed modal split breakdown ("from where")
$stmtIncomes = $db->prepare("
    SELECT t.*, u.username as source_username, u.mid as source_mid
    FROM transactions t
    LEFT JOIN users u ON t.related_user_id = u.id
    WHERE t.user_id = ? AND t.type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')
    ORDER BY t.created_at DESC
    LIMIT 15
");
$stmtIncomes->execute([$userId]);
$recentIncomes = $stmtIncomes->fetchAll(PDO::FETCH_ASSOC);

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
                <div class="overview-box" style="cursor: pointer;"
                     onclick="document.getElementById('earningsBreakdownModal').style.display = 'flex';"
                     title="Click to view full breakdown">
                    <h3>TOTAL EARNING $ <i class="fa-solid fa-circle-info ms-1 text-info" style="font-size: 14px;"></i></h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo number_format($stats['total_earning'], 2); ?></p>
                    <small class="text-muted d-block mt-1" style="font-size: 11px;">Click to view composition</small>
                </div>
            </div>
            <div class="overview-row">
                <div class="overview-box">
                    <h3>TOTAL LEVEL INCOME $</h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo number_format($stats['total_level'], 2); ?></p>
                </div>
                <div class="overview-box">
                    <h3>TOTAL RANK INCOME $</h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo number_format($stats['total_rank'], 2); ?></p>
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
                    <p class="text-success mt-2 fw-bold"><?php echo number_format($legStats['matched_business'], 2); ?></p>
                </div>
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-upercase">POWER CARRY FORWARD</h3>
                    <p class="text-warning mt-2 fw-bold"><?php echo number_format($legStats['power_carry_forward'], 2); ?></p>
                </div>
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-upercase">WEAKER CARRY FORWARD</h3>
                    <p class="text-warning mt-2 fw-bold"><?php echo number_format($legStats['rest_carry_forward'], 2); ?></p>
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

<!-- Earnings Breakdown Modal (Independent Pure CSS/JS Modal Overlay) -->
<div id="earningsBreakdownModal" onclick="if (event.target === this) { this.style.display = 'none'; }" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background-color: #2d1840; border: 2px solid #504793; border-radius: 12px; width: 100%; max-width: 500px; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.5); position: relative; color: white;">

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #504793; padding-bottom: 10px;">
            <h5 style="color: white; font-weight: bold; margin: 0; font-size: 18px;">
                <i class="fa-solid fa-chart-pie me-2 text-warning"></i> Earnings Composition
            </h5>
            <button type="button" onclick="document.getElementById('earningsBreakdownModal').style.display = 'none';" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <div style="text-align: center; margin-bottom: 20px;">
            <span style="text-transform: uppercase; color: #adb5bd; display: block; font-size: 12px; letter-spacing: 1px;">Lifetime Total Earnings</span>
            <h2 style="color: #198754; font-weight: bold; margin-top: 5px; font-size: 32px;">$<?php echo number_format($stats['total_earning'], 2); ?></h2>
        </div>

        <div style="background-color: #3f2259; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <div>
                    <span style="display: block; font-weight: bold; color: white; font-size: 13px;"><i class="fa-solid fa-coins text-warning me-2"></i>Daily Trade Profit (ROI)</span>
                    <small style="color: #adb5bd; font-size: 11px;">0.50% Daily returns from your packages</small>
                </div>
                <span class="badge bg-dark text-success fs-6 fw-bold" style="padding: 6px 12px; border-radius: 4px;">$<?php echo number_format($stats['total_roi'], 2); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <div>
                    <span style="display: block; font-weight: bold; color: white; font-size: 13px;"><i class="fa-solid fa-network-wired text-info me-2"></i>Level Generation Income</span>
                    <small style="color: #adb5bd; font-size: 11px;">Commissions distributed over 12 generations</small>
                </div>
                <span class="badge bg-dark text-info fs-6 fw-bold" style="padding: 6px 12px; border-radius: 4px;">$<?php echo number_format($stats['total_level'], 2); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="display: block; font-weight: bold; color: white; font-size: 13px;"><i class="fa-solid fa-award text-danger me-2"></i>Slab Matching (Rank Income)</span>
                    <small style="color: #adb5bd; font-size: 11px;">Daily rank income from matched unilevel business</small>
                </div>
                <span class="badge bg-dark text-danger fs-6 fw-bold" style="padding: 6px 12px; border-radius: 4px;">$<?php echo number_format($stats['total_rank'], 2); ?></span>
            </div>
        </div>

        <hr style="border-color: #504793; margin: 15px 0;">

        <h6 style="color: white; font-weight: bold; margin-bottom: 10px; font-size: 13px;"><i class="fa-solid fa-list-check text-warning me-2"></i> Recent Earnings Trace (From Where)</h6>
        <div style="max-height: 180px; overflow-y: auto; padding-right: 5px;">
            <?php if (empty($recentIncomes)): ?>
                <p style="text-align: center; color: #adb5bd; font-size: 11px; margin: 15px 0;">No recent income transactions registered yet.</p>
            <?php else: ?>
                <?php foreach ($recentIncomes as $inc): ?>
                    <div style="background-color: #3f2259; border: 1px solid #504793; border-radius: 6px; padding: 8px; margin-bottom: 8px; font-size: 11px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                            <span style="color: white; font-weight: bold;">
                                <?php if ($inc['type'] === 'ROI'): ?>
                                    <span class="badge bg-primary" style="font-size: 9px; padding: 2px 5px;">ROI</span>
                                <?php elseif ($inc['type'] === 'LEVEL_INCOME'): ?>
                                    <span class="badge bg-success" style="font-size: 9px; padding: 2px 5px;">Level <?php echo $inc['level']; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-danger" style="font-size: 9px; padding: 2px 5px;">Rank / Match</span>
                                <?php endif; ?>
                                <span style="margin-left: 5px; color: #cca354; font-weight: bold;">$<?php echo number_format($inc['amount'], 2); ?></span>
                            </span>
                            <span style="color: #adb5bd; font-size: 9px;"><?php echo date('d M, h:i A', strtotime($inc['created_at'])); ?></span>
                        </div>
                        <div style="color: #ced4da; font-size: 10px; line-height: 1.3;">
                            <?php echo htmlspecialchars($inc['description']); ?>
                            <?php if (!empty($inc['source_username'])): ?>
                                <span style="color: #0dcaf0;">(From: <?php echo htmlspecialchars($inc['source_username']); ?> / <?php echo htmlspecialchars($inc['source_mid']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="text-align: center; color: #adb5bd; font-size: 10px; margin-top: 15px;">
            <i class="fa-solid fa-lock me-1"></i> Values are calculated in real-time from audit-logged financial events.
        </div>

        <div style="display: flex; justify-content: center; margin-top: 15px; border-top: 1px solid #504793; padding-top: 10px;">
            <button type="button" onclick="document.getElementById('earningsBreakdownModal').style.display = 'none';" style="background-color: #504793; color: white; border: none; border-radius: 20px; padding: 8px 30px; font-weight: bold; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('earningsBreakdownModal');
    if (modal) {
        document.body.appendChild(modal);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
