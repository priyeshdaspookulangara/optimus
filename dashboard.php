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
// Ceiling Limit Calculation (200% of total investment, depending only on ROI)
$maxCap = $user['total_investment'] * $config['roi']['cap_multiplier'];
$ceilingBalance = max(0, $maxCap - $stats['total_roi']);
$progressPercent = ($maxCap > 0) ? min(100, ($stats['total_roi'] / $maxCap) * 100) : 0;


// Fetch dynamic unilevel legs business
$engine = new MLMEngine();
$legStats = $engine->getLegsBusiness($userId);

// Conferred Rank Details
$conferredRankName = 'None';
$conferredRankMatching = 0.00;
if ($user['rank_id'] > 0 && isset($config['ranks'][$user['rank_id'] - 1])) {
    $conferredRankName = $config['ranks'][$user['rank_id'] - 1]['name'];
    $conferredRankMatching = (float)$config['ranks'][$user['rank_id'] - 1]['matching'];
}

// Biggest Matching Slab
$stmtSlab = $db->prepare("SELECT COALESCE(MAX(slab_amount), 0) as max_slab FROM matching_schedules WHERE user_id = ?");
$stmtSlab->execute([$userId]);
$slabRes = $stmtSlab->fetch();
$biggestSlab = (float)($slabRes['max_slab'] ?? 0);

// Determine the biggest of them
$biggestOfAllType = 'None';
$biggestOfAllName = 'None';
$biggestOfAllVal = 0.00;

if ($conferredRankMatching > 0 || $biggestSlab > 0) {
    if ($conferredRankMatching >= $biggestSlab) {
        $biggestOfAllType = 'Conferred Rank';
        $biggestOfAllName = $conferredRankName . " (\$" . number_format($conferredRankMatching, 2) . ")";
        $biggestOfAllVal = $conferredRankMatching;
    } else {
        $biggestOfAllType = 'Matching Slab';
        $biggestOfAllName = "\$" . number_format($biggestSlab, 2) . " Slab";
        $biggestOfAllVal = $biggestSlab;
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
                <div class="overview-box" style="background-color: #2a1740; border: 1px solid #cca354;">
                    <h3 class="text-uppercase">BIGGEST CONFERRED RANK</h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo htmlspecialchars($conferredRankName); ?></p>
                    <small class="text-muted d-block mt-1" style="font-size: 11px;">Target: $<?php echo number_format($conferredRankMatching, 2); ?></small>
                </div>
                <div class="overview-box" style="background-color: #2a1740; border: 1px solid #cca354;">
                    <h3 class="text-uppercase">BIGGEST MATCHING SLAB</h3>
                    <p class="text-primary mt-2 fw-bold"><?php echo $biggestSlab > 0 ? '$' . number_format($biggestSlab, 2) : 'None'; ?></p>
                    <small class="text-muted d-block mt-1" style="font-size: 11px;">Active/Completed Contract</small>
                </div>
                <div class="overview-box" style="background: linear-gradient(135deg, #3f2259 0%, #2d1840 100%); border: 2px solid #cca354;">
                    <h3 class="text-warning fw-bold text-uppercase"><i class="fa-solid fa-crown text-warning me-1"></i> BIGGEST OF ALL</h3>
                    <p class="text-success mt-2 fw-bold" style="color: #4fc2da !important;"><?php echo htmlspecialchars($biggestOfAllName); ?></p>
                    <small class="text-white d-block mt-1" style="font-size: 11px; font-weight: 500;">Type: <?php echo htmlspecialchars($biggestOfAllType); ?></small>
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

    <!-- Team Referral Link Share Section (Two Panes) -->
    <?php
    $midCode = !empty($user['mid']) ? $user['mid'] : $user['id'];
    ?>
    <div class="container-fluid mb-4">
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

    <div class="container-fluid">
        <button type="button" class="btn btn-secondary w-100 mb-2 text-uppercase ceiling-limit-btn" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%);">
            <h3 class="text-center pt-5 pb-5 mb-3 text-uppercase" style="color: #3f2259 !important;">ROI CEILING LIMIT BALANCE $<?php echo number_format($ceilingBalance, 2); ?></h3>
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
