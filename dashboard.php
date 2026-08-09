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

// Get highest daily income from matching_schedules payouts to determine displayed rank
$stmtMaxIncome = $db->prepare("SELECT COALESCE(MAX(daily_income), 0) as max_daily_income FROM matching_schedules WHERE user_id = ?");
$stmtMaxIncome->execute([$userId]);
$maxDailyIncRes = $stmtMaxIncome->fetch();
$maxDailyIncome = (float)($maxDailyIncRes['max_daily_income'] ?? 0);

$highestPayoutRankId = 0;
if ($maxDailyIncome > 0) {
    foreach ($config['ranks'] as $idx => $rankConf) {
        if (abs((float)$rankConf['daily_income'] - $maxDailyIncome) < 0.001) {
            $highestPayoutRankId = $idx + 1;
            break;
        }
    }
}

$displayRankId = ($highestPayoutRankId > 0) ? $highestPayoutRankId : (int)$user['rank_id'];
$rankName = ($displayRankId > 0) ? $config['ranks'][$displayRankId-1]['name'] : 'None';


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

// Fetch Active/Completed matching contracts/schedules for dashboard rank section
$stmtSched = $db->prepare("
    SELECT * FROM matching_schedules
    WHERE user_id = ?
    ORDER BY status ASC, slab_amount DESC
");
$stmtSched->execute([$userId]);
$matching_schedules = $stmtSched->fetchAll();

// Fetch Rank Income Transactions
$stmtRankTrans = $db->prepare("
    SELECT * FROM transactions
    WHERE user_id = ? AND type = 'RANK_INCOME'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmtRankTrans->execute([$userId]);
$rank_transactions = $stmtRankTrans->fetchAll();

$hasRankIncome = (!empty($matching_schedules) || !empty($rank_transactions));

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner dashboard-inner pb-5 p-3">
    <!-- Team Referral Link Share Section (Two Panes) -->
    <?php
    $midCode = !empty($user['mid']) ? $user['mid'] : $user['id'];
    ?>
    <div class="container-fluid mb-4 p-0">
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card h-100 shadow-sm" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <h4 class="card-title text-white mb-2" style="font-size: 1.2rem; font-weight: 700; text-transform: uppercase !important;">
                                <i class="fa-solid fa-arrow-left text-warning me-2"></i> Left Link Share Section
                            </h4>
                            <p class="mb-4" style="font-size: 0.85rem; color: #cca354 !important;">
                                Use this link to recruit and place new members into your Left Unilevel/Binary Team.
                            </p>
                        </div>
                        <div>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control text-white border-0" id="left_share_url" value="https://optimusinfinity.com/register.php?ref=<?php echo htmlspecialchars($midCode); ?>&pos=L" readonly style="background-color: #2d1840 !important; font-size: 0.85rem; font-family: monospace;">
                                <button class="btn btn-outline-warning" type="button" id="copy_left_btn" style="border-top-right-radius: 5px; border-bottom-right-radius: 5px; font-weight: 600;">
                                    <i class="fa-solid fa-copy me-1"></i> Copy Left
                                </button>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="https://api.whatsapp.com/send?text=Join%20my%20Left%20Team%20on%20Optimus%20Infinity!%20Register%20here:%20https%3A%2F%2Foptimusinfinity.com%2Fregister.php%3Fref%3D<?php echo urlencode($midCode); ?>%26pos%3DL" target="_blank" class="btn btn-sm btn-success w-100 py-2 fw-bold" style="border-radius: 6px;">
                                    <i class="fa-brands fa-whatsapp me-1"></i> Share on WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card h-100 shadow-sm" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <h4 class="card-title text-white mb-2" style="font-size: 1.2rem; font-weight: 700; text-transform: uppercase !important;">
                                <i class="fa-solid fa-arrow-right text-warning me-2"></i> Right Link Share Section
                            </h4>
                            <p class="mb-4" style="font-size: 0.85rem; color: #cca354 !important;">
                                Use this link to recruit and place new members into your Right Unilevel/Binary Team.
                            </p>
                        </div>
                        <div>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control text-white border-0" id="right_share_url" value="https://optimusinfinity.com/register.php?ref=<?php echo htmlspecialchars($midCode); ?>&pos=R" readonly style="background-color: #2d1840 !important; font-size: 0.85rem; font-family: monospace;">
                                <button class="btn btn-outline-warning" type="button" id="copy_right_btn" style="border-top-right-radius: 5px; border-bottom-right-radius: 5px; font-weight: 600;">
                                    <i class="fa-solid fa-copy me-1"></i> Copy Right
                                </button>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="https://api.whatsapp.com/send?text=Join%20my%20Right%20Team%20on%20Optimus%20Infinity!%20Register%20here:%20https%3A%2F%2Foptimusinfinity.com%2Fregister.php%3Fref%3D<?php echo urlencode($midCode); ?>%26pos%3DR" target="_blank" class="btn btn-sm btn-success w-100 py-2 fw-bold" style="border-radius: 6px;">
                                    <i class="fa-brands fa-whatsapp me-1"></i> Share on WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                    $isAchieved = ($displayRankId >= $rIndex);
                    $isCurrent = ($displayRankId == $rIndex);

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

    <!-- Conditional Section: Rank & Matching Slab Contracts -->
    <?php if ($hasRankIncome): ?>
    <div class="row mt-4 p-0">
        <div class="col-12">
            <div class="card shadow-sm" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff;">
                <div class="card-header border-bottom border-secondary bg-transparent py-3">
                    <h4 class="card-title text-white mb-0" style="font-size: 1.2rem; font-weight: 700; text-transform: uppercase;">
                        <i class="fa-solid fa-medal text-warning me-2"></i> My Rank & Slab Matching Contracts
                    </h4>
                </div>
                <div class="card-body p-4">
                    <!-- Current Rank Info -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="p-3 rounded h-100" style="background-color: #2d1840; border-left: 4px solid #cca354;">
                                <span class="text-uppercase text-muted d-block" style="font-size: 11px; letter-spacing: 1px;">Current Achieved Rank</span>
                                <h3 class="text-warning fw-bold mt-2 mb-0" style="font-size: 20px;">
                                    <i class="fa-solid fa-crown text-warning me-2"></i> <?php echo htmlspecialchars($rankName); ?>
                                </h3>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded h-100" style="background-color: #2d1840; border-left: 4px solid #28a745;">
                                <span class="text-uppercase text-muted d-block" style="font-size: 11px; letter-spacing: 1px;">Total Rank Income Earned</span>
                                <h3 class="text-success fw-bold mt-2 mb-0" style="font-size: 20px;">
                                    $<?php echo number_format($stats['total_rank'], 2); ?>
                                </h3>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($matching_schedules)): ?>
                    <h5 class="text-white mb-3" style="font-size: 1rem; font-weight: 600;"><i class="fa-solid fa-file-contract text-info me-2"></i> Active Slab Contracts</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-dark table-borderless align-middle mb-0" style="background-color: #2d1840; border-radius: 8px; overflow: hidden;">
                            <thead>
                                <tr class="text-muted" style="font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.1); background-color: rgba(0,0,0,0.2);">
                                    <th class="ps-3 py-2">#</th>
                                    <th class="py-2">Slab Match Tier</th>
                                    <th class="py-2">Daily ROI (USD)</th>
                                    <th class="py-2">Days Passed</th>
                                    <th class="py-2">Max Days</th>
                                    <th class="py-2">Status</th>
                                    <th class="pe-3 py-2 text-end">Initiated Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($matching_schedules as $index => $sched): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); color: #fff !important;">
                                    <td class="ps-3 py-3 text-white-50" style="color: #fff !important;"><?php echo $index + 1; ?></td>
                                    <td class="py-3" style="color: #fff !important;"><span class="badge bg-primary" style="font-size: 12px;">$<?php echo number_format($sched['slab_amount'], 2); ?></span></td>
                                    <td class="py-3 text-success fw-bold" style="color: #28a745 !important;">$<?php echo number_format($sched['daily_income'], 2); ?> / day</td>
                                    <td class="py-3" style="color: #fff !important;"><?php echo $sched['days_passed']; ?></td>
                                    <td class="py-3" style="color: #fff !important;"><?php echo $sched['max_days']; ?></td>
                                    <td class="py-3" style="color: #fff !important;">
                                        <?php if($sched['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3 py-3 text-end text-muted" style="font-size: 0.85rem; color: #aaa !important;"><?php echo date('d M, Y', strtotime($sched['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($rank_transactions)): ?>
                    <h5 class="text-white mb-3" style="font-size: 1rem; font-weight: 600;"><i class="fa-solid fa-history text-danger me-2"></i> Recent Payout History</h5>
                    <div class="table-responsive">
                        <table class="table table-dark table-borderless align-middle mb-0" style="background-color: #2d1840; border-radius: 8px; overflow: hidden;">
                            <thead>
                                <tr class="text-muted" style="font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.1); background-color: rgba(0,0,0,0.2);">
                                    <th class="ps-3 py-2">#</th>
                                    <th class="py-2">Date</th>
                                    <th class="py-2">Description</th>
                                    <th class="pe-3 py-2 text-end">Received$</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rank_transactions as $index => $t): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); color: #fff !important;">
                                    <td class="ps-3 py-3 text-white-50" style="color: #fff !important;"><?php echo $index + 1; ?></td>
                                    <td class="py-3 text-muted" style="font-size: 0.85rem; color: #aaa !important;"><?php echo date('d M, Y h:i a', strtotime($t['created_at'])); ?></td>
                                    <td class="py-3 text-white" style="font-size: 0.85rem; color: #fff !important;"><?php echo htmlspecialchars($t['description']); ?></td>
                                    <td class="pe-3 py-3 text-end text-success fw-bold" style="color: #28a745 !important;">$<?php echo number_format($t['amount'], 2); ?></td>
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
    <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyLeftBtn = document.getElementById('copy_left_btn');
    var copyRightBtn = document.getElementById('copy_right_btn');

    if (copyLeftBtn) {
        copyLeftBtn.addEventListener('click', function () {
            var textToCopy = document.getElementById('left_share_url').value;
            copyTextToClipboard(textToCopy, copyLeftBtn, '<i class="fa-solid fa-copy me-1"></i> Copy Left');
        });
    }

    if (copyRightBtn) {
        copyRightBtn.addEventListener('click', function () {
            var textToCopy = document.getElementById('right_share_url').value;
            copyTextToClipboard(textToCopy, copyRightBtn, '<i class="fa-solid fa-copy me-1"></i> Copy Right');
        });
    }

    function copyTextToClipboard(text, btn, originalHtml) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showFeedback(btn, originalHtml);
            }).catch(function () {
                fallbackCopy(text, btn, originalHtml);
            });
        } else {
            fallbackCopy(text, btn, originalHtml);
        }
    }

    function fallbackCopy(text, btn, originalHtml) {
        var tempTextarea = document.createElement("textarea");
        tempTextarea.value = text;
        tempTextarea.style.position = "fixed";
        document.body.appendChild(tempTextarea);
        tempTextarea.select();
        try {
            document.execCommand("copy");
            showFeedback(btn, originalHtml);
        } catch (err) {
            console.error("Fallback copy failed", err);
        }
        document.body.removeChild(tempTextarea);
    }

    function showFeedback(btn, originalHtml) {
        btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Copied!';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-success', 'text-white');
        setTimeout(function () {
            btn.innerHTML = originalHtml;
            btn.classList.remove('btn-success', 'text-white');
            btn.classList.add('btn-outline-warning');
        }, 2000);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
