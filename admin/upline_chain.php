<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check before running operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';
$ranksList = $config['ranks'] ?? [];

$pageTitle = 'Recursive Upline Chain Search';
include __DIR__ . '/includes/header.php';

// Read query parameter: check if 'id' or 'mid' query string parameter is passed
$searchMid = trim($_GET['id'] ?? $_GET['mid'] ?? '');
$targetUser = null;
$sponsorChain = [];
$placementChain = [];
$errorMsg = '';

if ($searchMid !== '') {
    // Find target user by MID, Username, or ID
    $stmt = $db->prepare("
        SELECT u.*,
               s.mid AS sponsor_mid, s.username AS sponsor_username,
               p.mid AS placement_mid, p.username AS placement_username
        FROM users u
        LEFT JOIN users s ON u.sponsor_id = s.id
        LEFT JOIN users p ON u.placement_id = p.id
        WHERE u.mid = ? OR u.username = ? OR u.id = ?
    ");
    $stmt->execute([$searchMid, $searchMid, $searchMid]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        $errorMsg = "No member found matching MID or Username: " . htmlspecialchars($searchMid);
    } else {
        // Build Sponsor Upline Chain (Recursive up to Root)
        $currentId = $targetUser['id'];
        $visitedSponsor = [$currentId];
        $level = 1;

        while ($currentId) {
            $stmtCurr = $db->prepare("SELECT sponsor_id FROM users WHERE id = ?");
            $stmtCurr->execute([$currentId]);
            $currRow = $stmtCurr->fetch();

            if (!$currRow || empty($currRow['sponsor_id'])) {
                break;
            }

            $sponsorId = $currRow['sponsor_id'];
            if (in_array($sponsorId, $visitedSponsor)) {
                // Prevent infinite loop if circular dependency exists
                break;
            }
            $visitedSponsor[] = $sponsorId;

            $stmtSponsor = $db->prepare("
                SELECT u.*,
                       s.mid AS sponsor_mid, s.username AS sponsor_username,
                       p.mid AS placement_mid, p.username AS placement_username
                FROM users u
                LEFT JOIN users s ON u.sponsor_id = s.id
                LEFT JOIN users p ON u.placement_id = p.id
                WHERE u.id = ?
            ");
            $stmtSponsor->execute([$sponsorId]);
            $sponsorUser = $stmtSponsor->fetch();

            if ($sponsorUser) {
                $sponsorUser['chain_level'] = $level;
                $sponsorChain[] = $sponsorUser;
                $currentId = $sponsorUser['id'];
                $level++;
            } else {
                break;
            }
        }

        // Build Placement Upline Chain (Recursive up to Root)
        $currentId = $targetUser['id'];
        $visitedPlacement = [$currentId];
        $level = 1;

        while ($currentId) {
            $stmtCurr = $db->prepare("SELECT placement_id FROM users WHERE id = ?");
            $stmtCurr->execute([$currentId]);
            $currRow = $stmtCurr->fetch();

            if (!$currRow || empty($currRow['placement_id'])) {
                break;
            }

            $placementId = $currRow['placement_id'];
            if (in_array($placementId, $visitedPlacement)) {
                // Prevent infinite loop
                break;
            }
            $visitedPlacement[] = $placementId;

            $stmtPlacement = $db->prepare("
                SELECT u.*,
                       s.mid AS sponsor_mid, s.username AS sponsor_username,
                       p.mid AS placement_mid, p.username AS placement_username
                FROM users u
                LEFT JOIN users s ON u.sponsor_id = s.id
                LEFT JOIN users p ON u.placement_id = p.id
                WHERE u.id = ?
            ");
            $stmtPlacement->execute([$placementId]);
            $placementUser = $stmtPlacement->fetch();

            if ($placementUser) {
                $placementUser['chain_level'] = $level;
                $placementChain[] = $placementUser;
                $currentId = $placementUser['id'];
                $level++;
            } else {
                break;
            }
        }
    }
}
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0"><i class="fa fa-sitemap text-primary me-2"></i>Recursive Upline Chain</h3>
            <small class="text-muted">Search any Member Code (MID) to trace their complete upline hierarchy recursively all the way up to Root.</small>
        </div>
        <?php if ($targetUser): ?>
            <a href="upline_chain.php" class="btn btn-outline-primary"><i class="fa fa-search me-1"></i> Search Another Member</a>
        <?php endif; ?>
    </div>

    <!-- Search Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border shadow-sm">
        <div class="col-md-9">
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-search"></i></span>
                <input type="text" name="id" class="form-control form-control-lg" placeholder="Enter Member Code (e.g. OPT12345) or Username..." value="<?php echo htmlspecialchars($searchMid); ?>" required>
            </div>
        </div>
        <div class="col-md-3 gap-2 d-flex">
            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fa fa-arrow-up me-1"></i> Trace Upline</button>
            <?php if (!empty($searchMid)): ?>
                <a href="upline_chain.php" class="btn btn-outline-secondary btn-lg">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger shadow-sm"><i class="fa fa-exclamation-circle me-2"></i><?php echo $errorMsg; ?></div>
<?php endif; ?>

<?php if ($targetUser): ?>
    <!-- Target Member Info Card -->
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fa fa-user me-2"></i> Target Member: <?php echo htmlspecialchars($targetUser['username']); ?> (MID: <?php echo htmlspecialchars($targetUser['mid'] ?? 'N/A'); ?>)</h5>
            <span class="badge bg-light text-dark">ID: #<?php echo $targetUser['id']; ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Full Name</small>
                    <strong><?php echo htmlspecialchars($targetUser['full_name'] ?? 'N/A'); ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Email</small>
                    <span><?php echo htmlspecialchars($targetUser['email']); ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Direct Sponsor</small>
                    <strong><?php echo htmlspecialchars($targetUser['sponsor_username'] ?? 'None (Root)'); ?></strong>
                    <?php if (!empty($targetUser['sponsor_mid'])): ?>
                        <small class="text-muted">(<?php echo htmlspecialchars($targetUser['sponsor_mid']); ?>)</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Placement Parent</small>
                    <strong><?php echo htmlspecialchars($targetUser['placement_username'] ?? 'None (Root)'); ?></strong>
                    <?php if (!empty($targetUser['placement_mid'])): ?>
                        <small class="text-muted">(<?php echo htmlspecialchars($targetUser['placement_mid']); ?>)</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Rank</small>
                    <span class="badge bg-info">
                        <?php echo ($targetUser['rank_id'] > 0 && isset($ranksList[$targetUser['rank_id'] - 1])) ? htmlspecialchars($ranksList[$targetUser['rank_id'] - 1]['name']) : 'None'; ?>
                    </span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Total Investment</small>
                    <strong class="text-success">$<?php echo number_format($targetUser['total_investment'], 2); ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Binary Position</small>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars(strtoupper($targetUser['position'] ?? 'Not Marked')); ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Joined Date</small>
                    <span><?php echo date('Y-m-d H:i', strtotime($targetUser['created_at'])); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs for Sponsor vs Placement Chain -->
    <ul class="nav nav-tabs mb-3" id="uplineTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="sponsor-tab" data-bs-toggle="tab" data-bs-target="#sponsor-chain" type="button" role="tab">
                <i class="fa fa-users me-2"></i>Sponsor Upline Chain (Unilevel) <span class="badge bg-primary rounded-pill ms-1"><?php echo count($sponsorChain); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="placement-tab" data-bs-toggle="tab" data-bs-target="#placement-chain" type="button" role="tab">
                <i class="fa fa-network-wired me-2"></i>Placement Upline Chain (Binary) <span class="badge bg-secondary rounded-pill ms-1"><?php echo count($placementChain); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="uplineTabsContent">
        <!-- Sponsor Upline Chain Tab -->
        <div class="tab-pane fade show active" id="sponsor-chain" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fa fa-long-arrow-alt-up me-2 text-primary"></i>Recursive Sponsor Hierarchy (Upline Referrers up to Root)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 100px;">Upline Level</th>
                                    <th>MID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Rank</th>
                                    <th>Total Invested</th>
                                    <th>Status</th>
                                    <th>Joined Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sponsorChain)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="fa fa-info-circle me-1"></i> No sponsor uplines found. This member is at the top of the sponsor hierarchy (Root).
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sponsorChain as $index => $u): ?>
                                        <tr class="<?php echo ($index == count($sponsorChain) - 1) ? 'table-warning' : ''; ?>">
                                            <td>
                                                <span class="badge bg-primary px-3 py-2">
                                                    Level +<?php echo $u['chain_level']; ?>
                                                    <?php if ($index == count($sponsorChain) - 1): ?>
                                                        (ROOT)
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($u['mid'] ?? 'N/A'); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($u['full_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ($u['rank_id'] > 0 && isset($ranksList[$u['rank_id'] - 1])) ? htmlspecialchars($ranksList[$u['rank_id'] - 1]['name']) : 'None'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                $<?php echo number_format($u['total_investment'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $u['status'] == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars(strtoupper($u['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('Y-m-d H:i', strtotime($u['created_at'])); ?>
                                            </td>
                                            <td>
                                                <a href="upline_chain.php?id=<?php echo urlencode($u['mid'] ?? $u['username']); ?>" class="btn btn-sm btn-outline-primary" title="Trace from this member">
                                                    <i class="fa fa-sitemap me-1"></i> Trace Up
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Placement Upline Chain Tab -->
        <div class="tab-pane fade" id="placement-chain" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fa fa-long-arrow-alt-up me-2 text-secondary"></i>Recursive Placement Hierarchy (Binary Parent Upline up to Root)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 100px;">Upline Level</th>
                                    <th>MID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Binary Position</th>
                                    <th>Rank</th>
                                    <th>Total Invested</th>
                                    <th>Status</th>
                                    <th>Joined Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($placementChain)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="fa fa-info-circle me-1"></i> No placement uplines found. This member is at the top of the placement tree (Root).
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($placementChain as $index => $u): ?>
                                        <tr class="<?php echo ($index == count($placementChain) - 1) ? 'table-warning' : ''; ?>">
                                            <td>
                                                <span class="badge bg-secondary px-3 py-2">
                                                    Level +<?php echo $u['chain_level']; ?>
                                                    <?php if ($index == count($placementChain) - 1): ?>
                                                        (ROOT)
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($u['mid'] ?? 'N/A'); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($u['full_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars(strtoupper($u['position'] ?? 'Not Marked')); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ($u['rank_id'] > 0 && isset($ranksList[$u['rank_id'] - 1])) ? htmlspecialchars($ranksList[$u['rank_id'] - 1]['name']) : 'None'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                $<?php echo number_format($u['total_investment'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $u['status'] == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars(strtoupper($u['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('Y-m-d H:i', strtotime($u['created_at'])); ?>
                                            </td>
                                            <td>
                                                <a href="upline_chain.php?id=<?php echo urlencode($u['mid'] ?? $u['username']); ?>" class="btn btn-sm btn-outline-primary" title="Trace from this member">
                                                    <i class="fa fa-sitemap me-1"></i> Trace Up
                                                </a>
                                            </td>
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
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
