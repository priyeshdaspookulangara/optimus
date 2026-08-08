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

// Fetch Active/Completed matching contracts/schedules
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
");
$stmtRankTrans->execute([$userId]);
$rank_transactions = $stmtRankTrans->fetchAll();

// Check for Conferred Table dynamically and fetch records
$conferredRecords = [];
$conferredTableName = null;
try {
    $stmtCheck = $db->query("SHOW TABLES LIKE 'conferred_ranks'");
    if ($stmtCheck->rowCount() > 0) {
        $conferredTableName = 'conferred_ranks';
    } else {
        $stmtCheck2 = $db->query("SHOW TABLES LIKE 'conferred'");
        if ($stmtCheck2->rowCount() > 0) {
            $conferredTableName = 'conferred';
        }
    }
} catch (Exception $e) {}

if ($conferredTableName) {
    try {
        $colsStmt = $db->query("SHOW COLUMNS FROM {$conferredTableName}");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

        $userIdCol = in_array('user_id', $cols) ? 'user_id' : (in_array('member_id', $cols) ? 'member_id' : null);
        $downlineCol = in_array('downline_id', $cols) ? 'downline_id' : null;

        if ($userIdCol) {
            $sql = "SELECT c.*" . ($downlineCol ? ", u.username as downline_username" : "") . " FROM {$conferredTableName} c";
            if ($downlineCol) {
                $sql .= " LEFT JOIN users u ON c.{$downlineCol} = u.id";
            }
            $sql .= " WHERE c.{$userIdCol} = ? ORDER BY c.id DESC";

            $stmtConf = $db->prepare($sql);
            $stmtConf->execute([$userId]);
            $conferredRecords = $stmtConf->fetchAll();
        }
    } catch (Exception $e) {}
}

$conferred_ranks = $conferredRecords;

// Fallback calculations for Slab-Matched Business box
$displayMatchedBusiness = (float)$legStats['matched_business'];
$isConferredFallback = false;
$maxConferredMatching = 0.00;

if (!empty($conferredRecords)) {
    foreach ($conferredRecords as $rec) {
        if (isset($rec['slab_amount'])) {
            $maxConferredMatching = max($maxConferredMatching, (float)$rec['slab_amount']);
        } elseif (isset($rec['matching_business'])) {
            $maxConferredMatching = max($maxConferredMatching, (float)$rec['matching_business']);
        } elseif (isset($rec['matching'])) {
            $maxConferredMatching = max($maxConferredMatching, (float)$rec['matching']);
        } elseif (isset($rec['rank_id']) && $rec['rank_id'] > 0 && isset($config['ranks'][$rec['rank_id']-1])) {
            $maxConferredMatching = max($maxConferredMatching, (float)$config['ranks'][$rec['rank_id']-1]['matching']);
        } elseif (isset($rec['rank_level']) && $rec['rank_level'] > 0 && isset($config['ranks'][$rec['rank_level']-1])) {
            $maxConferredMatching = max($maxConferredMatching, (float)$config['ranks'][$rec['rank_level']-1]['matching']);
        } else {
            $dailyInc = (float)($rec['daily_income'] ?? 0.00);
            foreach ($config['ranks'] as $rConf) {
                if (abs((float)$rConf['daily_income'] - $dailyInc) < 0.01) {
                    if ((float)$rConf['matching'] > $maxConferredMatching) {
                        $maxConferredMatching = (float)$rConf['matching'];
                    }
                }
            }
        }
    }
}

if ($displayMatchedBusiness <= 0.00 && $maxConferredMatching > 0.00) {
    $displayMatchedBusiness = $maxConferredMatching;
    $isConferredFallback = true;
}

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
            <?php if ($conferredRankMatching > 0 || $biggestSlab > 0): ?>
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
            <?php endif; ?>
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
                <div class="overview-box" style="background-color: #2d1840; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#slabMatchedModal" title="Click to view full slab matched details">
                    <h3 class="text-uppercase">SLAB-MATCHED BUSINESS <i class="fa-solid fa-circle-info ms-1 text-info" style="font-size: 14px;"></i></h3>
                    <p class="text-success mt-2 fw-bold"><?php echo number_format($displayMatchedBusiness, 2); ?></p>
                    <small class="<?php echo $isConferredFallback ? 'text-warning fw-bold' : 'text-muted'; ?> d-block mt-1" style="font-size: 11px;">
                        <?php echo $isConferredFallback ? '<i class="fa-solid fa-award text-warning me-1"></i> Conferred Fallback' : 'Click to view breakdown'; ?>
                    </small>
                </div>
                <div class="overview-box" style="background-color: #2d1840;">
                    <h3 class="text-uppercase">POWER CARRY FORWARD</h3>
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

    <!-- DataTables CSS & Native Theme Styles -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />
    <style>
        .dt-table-dark th {
            background-color: #2d1840 !important;
            color: #fff !important;
            border-color: rgba(255,255,255,0.1) !important;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .dt-table-dark td {
            background-color: #3f2259 !important;
            color: #fff !important;
            border-color: rgba(255,255,255,0.05) !important;
            font-size: 13px;
        }
        .dataTables_info, .dataTables_length, .dataTables_filter, .dataTables_paginate {
            color: #fff !important;
            margin-top: 10px;
            font-size: 13px;
        }
        .dataTables_filter input {
            background-color: #2d1840 !important;
            color: #fff !important;
            border: 1px solid #504793 !important;
            border-radius: 6px;
            padding: 4px 10px;
        }
        .dataTables_length select {
            background-color: #2d1840 !important;
            color: #fff !important;
            border: 1px solid #504793 !important;
            border-radius: 6px;
            padding: 4px 8px;
        }
        .page-link {
            background-color: #2d1840 !important;
            border-color: #504793 !important;
            color: #fff !important;
        }
        .page-item.active .page-link {
            background-color: #504793 !important;
            border-color: #cca354 !important;
            color: #fff !important;
        }
    </style>

    <!-- New MLM Activity and Historical Tables -->
    <div class="row mt-4 px-3">
        <div class="col-lg-12 px-0">
            <!-- Card 1: My Matching Slab Contracts -->
            <div class="card p-4 mb-4" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div class="card-header pb-3 border-bottom border-secondary d-flex align-items-center justify-content-between p-0" style="background: transparent;">
                    <h4 class="card-title text-white mb-0" style="font-weight: 700; text-transform: uppercase; font-size: 1.1rem;"><i class="fa-solid fa-cubes text-warning me-2"></i>My Matching Slab Contracts</h4>
                </div>
                <div class="card-body px-0 pt-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dt-table-dark" style="width:100%">
                            <thead>
                                <tr style="background-color: #2d1840; color: white;">
                                    <th>#</th>
                                    <th>Slab Match Tier</th>
                                    <th>Daily ROI (USD)</th>
                                    <th>Days Passed</th>
                                    <th>Max Days</th>
                                    <th>Status</th>
                                    <th>Initiated Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($matching_schedules)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No active slab matching contracts yet. Accumulate team volume on power and matching legs to trigger contracts!</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($matching_schedules as $index => $sched): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><span class="badge bg-primary" style="font-size: 13px;">$<?php echo number_format($sched['slab_amount'], 2); ?></span></td>
                                            <td class="text-success fw-bold">$<?php echo number_format($sched['daily_income'], 2); ?> / day</td>
                                            <td><?php echo $sched['days_passed']; ?></td>
                                            <td><?php echo $sched['max_days']; ?></td>
                                            <td>
                                                <?php if($sched['status'] == 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Completed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M, Y', strtotime($sched['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Card 2: My Conferred Ranks -->
            <div class="card p-4 mb-4" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div class="card-header pb-3 border-bottom border-secondary d-flex align-items-center justify-content-between p-0" style="background: transparent;">
                    <h4 class="card-title text-white mb-0" style="font-weight: 700; text-transform: uppercase; font-size: 1.1rem;"><i class="fa-solid fa-award text-warning me-2"></i>My Conferred Ranks</h4>
                </div>
                <div class="card-body px-0 pt-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dt-table-dark" style="width:100%">
                            <thead>
                                <tr style="background-color: #2d1840; color: white;">
                                    <th>#</th>
                                    <th>Conferred Rank</th>
                                    <th>Qualified By Downline</th>
                                    <th>Daily ROI (USD)</th>
                                    <th>Days Passed</th>
                                    <th>Max Days</th>
                                    <th>Status</th>
                                    <th>Conferred Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($conferred_ranks)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No conferred ranks achieved yet. Help your downlines qualify for ranks to earn conferred ranks!</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($conferred_ranks as $index => $cr):
                                        $rankName = isset($config['ranks'][$cr['rank_id']-1]) ? $config['ranks'][$cr['rank_id']-1]['name'] : "Rank " . $cr['rank_id'];
                                    ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><span class="badge bg-warning text-dark" style="font-size: 13px;"><?php echo htmlspecialchars($rankName); ?></span></td>
                                            <td><strong class="text-info"><?php echo htmlspecialchars($cr['downline_username'] ?? ('User ID ' . $cr['downline_id'])); ?></strong></td>
                                            <td class="text-success fw-bold">$<?php echo number_format($cr['daily_income'], 2); ?> / day</td>
                                            <td><?php echo $cr['days_passed']; ?></td>
                                            <td><?php echo $cr['max_days']; ?></td>
                                            <td>
                                                <?php if($cr['status'] == 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Completed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M, Y', strtotime($cr['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Card 3: Daily Rank / Matching Income Payout History (DataTables) -->
            <div class="card p-4 mb-4" style="background-color: #3f2259; border: 1px solid #504793 !important; border-radius: 12px !important; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div class="card-header pb-3 border-bottom border-secondary d-flex align-items-center justify-content-between p-0" style="background: transparent;">
                    <h4 class="card-title text-white mb-0" style="font-weight: 700; text-transform: uppercase; font-size: 1.1rem;"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Daily Rank / Matching Income Payout History</h4>
                </div>
                <div class="card-body px-0 pt-3">
                    <div class="table-responsive">
                        <table id="rankIncomeTable" class="table table-hover align-middle mb-0 dt-table-dark" style="width:100%">
                            <thead>
                                <tr style="background-color: #2d1840; color: white;">
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Received $</th>
                                    <th>View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($rank_transactions)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No daily rank income payout history available.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($rank_transactions as $index => $t): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo date('d M, Y h:i:s a', strtotime($t['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($t['description']); ?></td>
                                            <td class="text-success fw-bold">$<?php echo number_format($t['amount'], 2); ?></td>
                                            <td><button class="btn btn-primary btn-sm text-white" disabled style="background-color: #504793; border: none; border-radius: 4px;">View</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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

<!-- Slab Matched Business Modal -->
<div class="modal fade" id="slabMatchedModal" tabindex="-1" aria-labelledby="slabMatchedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content text-white" style="background-color: #2d1840; border: 2px solid #504793; border-radius: 12px;">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title text-white fw-bold" id="slabMatchedModalLabel">
                    <i class="fa-solid fa-chart-line me-2 text-warning"></i> Slab-Matched Business Breakdown
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Summary of Leg Volumes -->
                <div class="row g-2 text-center mb-4">
                    <div class="col-4">
                        <div class="p-2 rounded" style="background-color: #3f2259;">
                            <span class="text-uppercase text-muted d-block" style="font-size: 11px;">Power Leg</span>
                            <span class="text-warning fw-bold fs-6">$<?php echo number_format($legStats['power_leg'], 2); ?></span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 rounded" style="background-color: #3f2259;">
                            <span class="text-uppercase text-muted d-block" style="font-size: 11px;">Weaker Leg</span>
                            <span class="text-warning fw-bold fs-6">$<?php echo number_format($legStats['matching_leg'], 2); ?></span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 rounded" style="background-color: #3f2259; border: 1px solid #28a745;">
                            <span class="text-uppercase text-success d-block" style="font-size: 11px; font-weight: bold;">Slab Matched</span>
                            <span class="text-success fw-bold fs-6">$<?php echo number_format($legStats['matched_business'], 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Active Slab Matches Breakdown -->
                <h6 class="text-white fw-bold mb-3"><i class="fa-solid fa-cubes text-info me-2"></i> Unilevel Slab Breakdown</h6>
                <div class="p-3 rounded mb-4" style="background-color: #3f2259;">
                    <div class="table-responsive">
                        <table class="table table-dark table-borderless mb-0 align-middle">
                            <thead>
                                <tr class="text-muted" style="font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                    <th>Slab Tier</th>
                                    <th class="text-center">Matched Units</th>
                                    <th class="text-end">Total Volume</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $hasAnySlab = false;
                                if (!empty($legStats['slab_breakdown'])):
                                    foreach ($legStats['slab_breakdown'] as $slab => $units):
                                        if ($units > 0):
                                            $hasAnySlab = true;
                                ?>
                                            <tr>
                                                <td><span class="badge bg-primary">$<?php echo number_format($slab); ?> Slab</span></td>
                                                <td class="text-center fw-bold text-warning"><?php echo $units; ?> Unit(s)</td>
                                                <td class="text-end text-success fw-bold">$<?php echo number_format($units * $slab, 2); ?></td>
                                            </tr>
                                <?php
                                        endif;
                                    endforeach;
                                endif;

                                if (!$hasAnySlab):
                                ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3" style="font-size: 13px;">
                                            No active slab matches found in your placement tree yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Conferred Rank Fallback Details -->
                <h6 class="text-white fw-bold mb-3"><i class="fa-solid fa-medal text-danger me-2"></i> Conferred Rank & Propagation History</h6>
                <div class="p-3 rounded" style="background-color: #3f2259;">
                    <div class="table-responsive">
                        <table class="table table-dark table-borderless mb-0 align-middle">
                            <thead>
                                <tr class="text-muted" style="font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                    <th>Conferred ID</th>
                                    <th>Date</th>
                                    <th>Downline Origin</th>
                                    <th class="text-end">Daily Income</th>
                                    <th class="text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($conferredRecords)): ?>
                                    <?php foreach ($conferredRecords as $rec): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary">#<?php echo htmlspecialchars($rec['id']); ?></span></td>
                                            <td><small class="text-muted"><?php echo isset($rec['created_at']) ? date('d M, Y', strtotime($rec['created_at'])) : 'N/A'; ?></small></td>
                                            <td><strong class="text-info"><?php echo htmlspecialchars($rec['downline_username'] ?? ('User ID ' . ($rec['downline_id'] ?? 'N/A'))); ?></strong></td>
                                            <td class="text-end text-success fw-bold">$<?php echo number_format($rec['daily_income'] ?? 0.00, 2); ?></td>
                                            <td class="text-end">
                                                <span class="badge <?php echo (isset($rec['status']) && $rec['status'] == 'active') ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo htmlspecialchars(strtoupper($rec['status'] ?? 'completed')); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3" style="font-size: 13px;">
                                            No record exists in the conferred table.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 d-flex justify-content-center">
                <button type="button" class="btn btn-secondary px-4 text-white" data-bs-dismiss="modal" style="background-color: #504793; border: none; border-radius: 20px;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- DataTables JS & Initialization -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize DataTables for Payout History
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.DataTable !== 'undefined') {
        jQuery('#rankIncomeTable').DataTable({
            "order": [[1, "desc"]], // sort by date descending
            "language": {
                "emptyTable": "No daily rank income payout history available."
            }
        });
    }

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
