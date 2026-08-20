<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

// Date selection (defaults to today's date)
$selectedDate = isset($_GET['date']) && !empty($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

// --- 1. Today's New Joins ---
$stmtJoinsCount = $db->prepare("SELECT COUNT(*) as total FROM users WHERE DATE(created_at) = ?");
$stmtJoinsCount->execute([$selectedDate]);
$newJoinsCount = $stmtJoinsCount->fetch()['total'] ?? 0;

$stmtJoins = $db->prepare("
    SELECT u.*, s.username as sponsor_username, s.mid as sponsor_mid
    FROM users u
    LEFT JOIN users s ON u.sponsor_id = s.id
    WHERE DATE(u.created_at) = ?
    ORDER BY u.created_at DESC
");
$stmtJoins->execute([$selectedDate]);
$newJoins = $stmtJoins->fetchAll();

// --- 2. Today's Rank Achieved / Rank Income ---
$stmtRankIncomeSum = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE type = 'RANK_INCOME' AND DATE(created_at) = ?");
$stmtRankIncomeSum->execute([$selectedDate]);
$rankIncomeTotal = $stmtRankIncomeSum->fetch()['total'] ?? 0;

$stmtRankEarnersCount = $db->prepare("SELECT COUNT(DISTINCT user_id) as total FROM transactions WHERE type = 'RANK_INCOME' AND DATE(created_at) = ?");
$stmtRankEarnersCount->execute([$selectedDate]);
$rankEarnersCount = $stmtRankEarnersCount->fetch()['total'] ?? 0;

$stmtRankTrans = $db->prepare("
    SELECT t.*, u.username, u.mid, u.rank_id
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'RANK_INCOME' AND DATE(t.created_at) = ?
    ORDER BY t.created_at DESC
");
$stmtRankTrans->execute([$selectedDate]);
$rankTrans = $stmtRankTrans->fetchAll();

// Fetch users with ranks
$stmtRankUsers = $db->prepare("
    SELECT id, mid, username, full_name, rank_id, created_at
    FROM users
    WHERE rank_id > 0 AND DATE(created_at) = ?
    ORDER BY created_at DESC
");
$stmtRankUsers->execute([$selectedDate]);
$todayRankAchievers = $stmtRankUsers->fetchAll();

// --- 3. Today's Level Income ---
$stmtLevelIncomeSum = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE type = 'LEVEL_INCOME' AND DATE(created_at) = ?");
$stmtLevelIncomeSum->execute([$selectedDate]);
$levelIncomeTotal = $stmtLevelIncomeSum->fetch()['total'] ?? 0;

$stmtLevelTrans = $db->prepare("
    SELECT t.*, u.username, u.mid, ru.username as source_username, ru.mid as source_mid
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN users ru ON t.related_user_id = ru.id
    WHERE t.type = 'LEVEL_INCOME' AND DATE(t.created_at) = ?
    ORDER BY t.created_at DESC
");
$stmtLevelTrans->execute([$selectedDate]);
$levelTrans = $stmtLevelTrans->fetchAll();

// --- 4. Today's ROI Gained ---
$stmtRoiSum = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM transactions
    WHERE type = 'ROI' AND (roi_date = ? OR (roi_date IS NULL AND DATE(created_at) = ?))
");
$stmtRoiSum->execute([$selectedDate, $selectedDate]);
$roiTotal = $stmtRoiSum->fetch()['total'] ?? 0;

$stmtRoiTrans = $db->prepare("
    SELECT t.*, u.username, u.mid
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'ROI' AND (t.roi_date = ? OR (t.roi_date IS NULL AND DATE(t.created_at) = ?))
    ORDER BY t.created_at DESC
");
$stmtRoiTrans->execute([$selectedDate, $selectedDate]);
$roiTrans = $stmtRoiTrans->fetchAll();

$pageTitle = "Daily Summary Report - " . date('d M, Y', strtotime($selectedDate));
include __DIR__ . '/includes/header.php';
?>

<style>
    @media print {
        .sidebar, .no-print { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .card { border: 1px solid #ccc !important; box-shadow: none !important; }
        .nav-tabs { display: none !important; }
        .tab-pane { display: block !important; opacity: 1 !important; margin-bottom: 30px; }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
    <div>
        <h3 class="mb-1"><i class="fa fa-calendar-day text-primary me-2"></i>Daily Summary & Today's Activity</h3>
        <p class="text-muted mb-0">Overview of new registrations, rank income/achievements, level commissions, and daily ROI gained.</p>
    </div>
    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selectedDate); ?>" required>
            <button type="submit" class="btn btn-primary text-nowrap"><i class="fa fa-filter me-1"></i> Filter Date</button>
        </form>
        <button onclick="window.print();" class="btn btn-outline-secondary"><i class="fa fa-print me-1"></i> Print</button>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card card-stat bg-primary text-white h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 text-uppercase fw-bold">New Joins</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($newJoinsCount); ?></h2>
                    </div>
                    <i class="fa fa-user-plus fa-2x opacity-50"></i>
                </div>
                <small class="text-white-50 mt-2 d-block">Members registered on <?php echo date('M d, Y', strtotime($selectedDate)); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card card-stat bg-success text-white h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 text-uppercase fw-bold">Rank Income</h6>
                        <h2 class="mb-0 fw-bold">$<?php echo number_format($rankIncomeTotal, 2); ?></h2>
                    </div>
                    <i class="fa fa-trophy fa-2x opacity-50"></i>
                </div>
                <small class="text-white-50 mt-2 d-block"><?php echo number_format($rankEarnersCount); ?> earners paid today</small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card card-stat bg-info text-white h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 text-uppercase fw-bold">Level Income</h6>
                        <h2 class="mb-0 fw-bold">$<?php echo number_format($levelIncomeTotal, 2); ?></h2>
                    </div>
                    <i class="fa fa-sitemap fa-2x opacity-50"></i>
                </div>
                <small class="text-white-50 mt-2 d-block"><?php echo count($levelTrans); ?> commission transactions</small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card card-stat bg-warning text-dark h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-dark-50 text-uppercase fw-bold">ROI Gained</h6>
                        <h2 class="mb-0 fw-bold">$<?php echo number_format($roiTotal, 2); ?></h2>
                    </div>
                    <i class="fa fa-chart-line fa-2x opacity-50"></i>
                </div>
                <small class="text-dark-50 mt-2 d-block"><?php echo count($roiTrans); ?> daily payouts logged</small>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white border-bottom-0 pb-0 no-print">
        <ul class="nav nav-tabs card-header-tabs" id="summaryTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold" id="joins-tab" data-bs-toggle="tab" data-bs-target="#joins" type="button" role="tab" aria-controls="joins" aria-selected="true">
                    <i class="fa fa-user-plus me-1 text-primary"></i> New Joins (<?php echo count($newJoins); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="rank-tab" data-bs-toggle="tab" data-bs-target="#rank" type="button" role="tab" aria-controls="rank" aria-selected="false">
                    <i class="fa fa-trophy me-1 text-success"></i> Rank Income & Achievers (<?php echo count($rankTrans); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="level-tab" data-bs-toggle="tab" data-bs-target="#level" type="button" role="tab" aria-controls="level" aria-selected="false">
                    <i class="fa fa-sitemap me-1 text-info"></i> Level Income (<?php echo count($levelTrans); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="roi-tab" data-bs-toggle="tab" data-bs-target="#roi" type="button" role="tab" aria-controls="roi" aria-selected="false">
                    <i class="fa fa-chart-line me-1 text-warning"></i> ROI Gained Today (<?php echo count($roiTrans); ?>)
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body">
        <div class="tab-content" id="summaryTabsContent">

            <!-- Tab 1: New Joins Today -->
            <div class="tab-pane fade show active" id="joins" role="tabpanel" aria-labelledby="joins-tab">
                <h5 class="mb-3"><i class="fa fa-user-plus me-2 text-primary"></i>Members Registered on <?php echo date('d M Y', strtotime($selectedDate)); ?></h5>
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Member ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Sponsor</th>
                                <th>Joined Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($newJoins)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fa fa-info-circle me-1"></i> No new member registrations recorded on this date.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($newJoins as $index => $u): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($u['mid'] ?? 'N/A'); ?></span></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($u['username']); ?></td>
                                        <td><?php echo htmlspecialchars($u['full_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td>
                                            <?php if ($u['sponsor_username']): ?>
                                                <small class="d-block fw-bold"><?php echo htmlspecialchars($u['sponsor_username']); ?></small>
                                                <small class="text-muted">(<?php echo htmlspecialchars($u['sponsor_mid'] ?? 'N/A'); ?>)</small>
                                            <?php else: ?>
                                                <span class="text-muted">None / Direct</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('H:i:s', strtotime($u['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Rank Income & Achievers -->
            <div class="tab-pane fade" id="rank" role="tabpanel" aria-labelledby="rank-tab">
                <h5 class="mb-3"><i class="fa fa-trophy me-2 text-success"></i>Rank Income Payouts & Achievers</h5>

                <?php if (!empty($todayRankAchievers)): ?>
                    <div class="alert alert-success d-flex align-items-center mb-3">
                        <i class="fa fa-award fa-2x me-3"></i>
                        <div>
                            <h6 class="mb-1 fw-bold">New Rank Achievers on <?php echo date('d M Y', strtotime($selectedDate)); ?></h6>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($todayRankAchievers as $achiever):
                                    $rankName = ($achiever['rank_id'] > 0 && isset($config['ranks'][$achiever['rank_id']-1])) ? $config['ranks'][$achiever['rank_id']-1]['name'] : 'Rank ' . $achiever['rank_id'];
                                ?>
                                    <li><strong><?php echo htmlspecialchars($achiever['username']); ?></strong> (<?php echo htmlspecialchars($achiever['mid']); ?>) achieved rank <strong><?php echo htmlspecialchars($rankName); ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Member ID</th>
                                <th>Username</th>
                                <th>Current Rank</th>
                                <th>Rank Income ($)</th>
                                <th>Description</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rankTrans)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fa fa-info-circle me-1"></i> No rank income payouts processed on this date.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rankTrans as $index => $rt):
                                    $rankName = ($rt['rank_id'] > 0 && isset($config['ranks'][$rt['rank_id']-1])) ? $config['ranks'][$rt['rank_id']-1]['name'] : 'None';
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($rt['mid'] ?? 'N/A'); ?></span></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($rt['username']); ?></td>
                                        <td><span class="badge bg-success"><?php echo htmlspecialchars($rankName); ?></span></td>
                                        <td class="fw-bold text-success">$<?php echo number_format($rt['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($rt['description'] ?? 'Rank Income'); ?></td>
                                        <td><?php echo date('H:i:s', strtotime($rt['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 3: Level Income -->
            <div class="tab-pane fade" id="level" role="tabpanel" aria-labelledby="level-tab">
                <h5 class="mb-3"><i class="fa fa-sitemap me-2 text-info"></i>Level Commissions Distributed Today</h5>
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Recipient Member ID</th>
                                <th>Recipient Username</th>
                                <th>Generation Level</th>
                                <th>Commission ($)</th>
                                <th>Source Downline</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($levelTrans)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fa fa-info-circle me-1"></i> No level income commissions generated on this date.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($levelTrans as $index => $lt): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($lt['mid'] ?? 'N/A'); ?></span></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($lt['username']); ?></td>
                                        <td><span class="badge bg-info text-dark">Level <?php echo htmlspecialchars($lt['level'] ?? 'N/A'); ?></span></td>
                                        <td class="fw-bold text-info">$<?php echo number_format($lt['amount'], 2); ?></td>
                                        <td>
                                            <?php if ($lt['source_username']): ?>
                                                <span class="fw-bold"><?php echo htmlspecialchars($lt['source_username']); ?></span>
                                                <small class="text-muted">(<?php echo htmlspecialchars($lt['source_mid'] ?? 'N/A'); ?>)</small>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('H:i:s', strtotime($lt['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 4: ROI Gained Today -->
            <div class="tab-pane fade" id="roi" role="tabpanel" aria-labelledby="roi-tab">
                <h5 class="mb-3"><i class="fa fa-chart-line me-2 text-warning"></i>Daily ROI Returns Distributed</h5>
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Member ID</th>
                                <th>Username</th>
                                <th>ROI Amount ($)</th>
                                <th>Description</th>
                                <th>Recorded Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roiTrans)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="fa fa-info-circle me-1"></i> No ROI daily income distributed on this date.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($roiTrans as $index => $rt): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($rt['mid'] ?? 'N/A'); ?></span></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($rt['username']); ?></td>
                                        <td class="fw-bold text-warning">$<?php echo number_format($rt['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($rt['description'] ?? 'Daily ROI Payout'); ?></td>
                                        <td><?php echo !empty($rt['roi_date']) ? htmlspecialchars($rt['roi_date']) : date('Y-m-d H:i', strtotime($rt['created_at'])); ?></td>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
