<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

// Fetch default root member if no search term provided
$stmtRoot = $db->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
$defaultUser = $stmtRoot->fetch(PDO::FETCH_ASSOC);

$searchMid = isset($_GET['mid']) ? trim($_GET['mid']) : (isset($_POST['mid']) ? trim($_POST['mid']) : '');
if (empty($searchMid) && $defaultUser) {
    $searchMid = $defaultUser['mid'] ?? $defaultUser['username'];
}

$targetUser = null;
$errorMessage = null;

if (!empty($searchMid)) {
    // Find user by MID or Username
    $stmtTarget = $db->prepare("SELECT * FROM users WHERE mid = ? OR username = ? LIMIT 1");
    $stmtTarget->execute([$searchMid, $searchMid]);
    $targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $errorMessage = "Member with MID or Username '" . htmlspecialchars($searchMid) . "' was not found.";
    }
}

/**
 * Recursively traverse down position = 'left' to find bottomest left node
 */
function getBottomLeftNode($db, $userId, &$path = [], &$visited = []) {
    if (in_array($userId, $visited)) {
        return end($path) ?: null;
    }
    $visited[] = $userId;

    $stmt = $db->prepare("SELECT id, mid, username, full_name, email, rank_id, total_investment, status, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $path[] = $user;

    // Find left placement child
    $stmtLeft = $db->prepare("SELECT id FROM users WHERE placement_id = ? AND LOWER(position) = 'left' LIMIT 1");
    $stmtLeft->execute([$userId]);
    $leftChild = $stmtLeft->fetch(PDO::FETCH_ASSOC);

    if ($leftChild && !in_array($leftChild['id'], $visited)) {
        return getBottomLeftNode($db, $leftChild['id'], $path, $visited);
    }

    return $user;
}

/**
 * Recursively traverse down position = 'right' to find bottomest right node
 */
function getBottomRightNode($db, $userId, &$path = [], &$visited = []) {
    if (in_array($userId, $visited)) {
        return end($path) ?: null;
    }
    $visited[] = $userId;

    $stmt = $db->prepare("SELECT id, mid, username, full_name, email, rank_id, total_investment, status, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $path[] = $user;

    // Find right placement child
    $stmtRight = $db->prepare("SELECT id FROM users WHERE placement_id = ? AND LOWER(position) = 'right' LIMIT 1");
    $stmtRight->execute([$userId]);
    $rightChild = $stmtRight->fetch(PDO::FETCH_ASSOC);

    if ($rightChild && !in_array($rightChild['id'], $visited)) {
        return getBottomRightNode($db, $rightChild['id'], $path, $visited);
    }

    return $user;
}

$leftPath = [];
$rightPath = [];
$bottomLeftNode = null;
$bottomRightNode = null;

if ($targetUser) {
    $visitedLeft = [];
    $visitedRight = [];
    $bottomLeftNode = getBottomLeftNode($db, $targetUser['id'], $leftPath, $visitedLeft);
    $bottomRightNode = getBottomRightNode($db, $targetUser['id'], $rightPath, $visitedRight);
}

function getRankNameAdmin($rankId, $ranksConfig) {
    if ($rankId > 0 && isset($ranksConfig[$rankId - 1])) {
        return $ranksConfig[$rankId - 1]['name'];
    }
    return 'None';
}

$pageTitle = 'Find Bottom Nodes - Admin';
include __DIR__ . '/includes/header.php';
?>

<style>
    .card-accent-left {
        border-left: 5px solid #0d6efd !important;
    }
    .card-accent-right {
        border-left: 5px solid #ffc107 !important;
    }
    .path-step {
        display: inline-flex;
        align-items: center;
        background: #e9ecef;
        color: #212529;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        margin: 3px;
    }
    .path-step i {
        color: #0d6efd;
        margin-left: 6px;
    }
    .path-step.active-end {
        background: #0d6efd;
        color: #fff;
    }
</style>

<div class="row">
    <div class="col-md-12 mb-3">
        <h3><i class="fa fa-sitemap me-2 text-primary"></i>Bottom Nodes Finder</h3>
        <p class="text-muted">Recursively traverse down binary placement trees to identify the bottommost left and right nodes for any member.</p>
    </div>
</div>

<!-- Search Form Card -->
<div class="row">
    <div class="col-md-12">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="fa fa-search me-2"></i>Search Member
            </div>
            <div class="card-body">
                <form method="GET" action="bottom_nodes.php" class="row g-3 align-items-center">
                    <div class="col-md-8 col-sm-12">
                        <label for="mid_input" class="form-label fw-bold">Member ID (MID) or Username:</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fa fa-id-card text-muted"></i></span>
                            <input type="text" class="form-control" id="mid_input" name="mid"
                                   placeholder="Enter Member ID (e.g. OPT59655)"
                                   value="<?php echo htmlspecialchars($searchMid); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-12 d-flex align-items-end gap-2 mt-md-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa fa-search me-1"></i> Find Bottom Nodes
                        </button>
                        <?php if ($defaultUser && isset($defaultUser['mid'])): ?>
                        <a href="bottom_nodes.php?mid=<?php echo urlencode($defaultUser['mid']); ?>" class="btn btn-outline-secondary">
                            <i class="fa fa-home me-1"></i> Root Member
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($errorMessage): ?>
<div class="row">
    <div class="col-12">
        <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
            <i class="fa fa-exclamation-triangle me-2"></i>
            <div><?php echo $errorMessage; ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($targetUser): ?>
<!-- Target Member Summary Card -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card bg-light border p-3 shadow-sm">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <span class="badge bg-primary mb-2">Target Starting Node</span>
                    <h4 class="mb-1 text-dark fw-bold"><?php echo htmlspecialchars($targetUser['username']); ?>
                        <small class="text-muted">(<?php echo htmlspecialchars($targetUser['mid'] ?? 'MID N/A'); ?>)</small>
                    </h4>
                    <p class="mb-0 text-secondary">
                        <strong>Full Name:</strong> <?php echo htmlspecialchars($targetUser['full_name'] ?: 'N/A'); ?> &nbsp;|&nbsp;
                        <strong>Email:</strong> <?php echo htmlspecialchars($targetUser['email']); ?> &nbsp;|&nbsp;
                        <strong>Rank:</strong> <?php echo getRankNameAdmin($targetUser['rank_id'], $config['ranks']); ?> &nbsp;|&nbsp;
                        <strong>Total Investment:</strong> $<?php echo number_format($targetUser['total_investment'], 2); ?>
                    </p>
                </div>
                <div class="mt-2 mt-md-0 text-end">
                    <span class="badge <?php echo ($targetUser['status'] == 'active') ? 'bg-success' : 'bg-danger'; ?> p-2 fs-6">
                        <?php echo ucfirst($targetUser['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bottomest Left & Right Nodes Cards -->
<div class="row">
    <!-- Bottomest Left Node -->
    <div class="col-md-6 mb-4">
        <div class="card h-100 shadow-sm card-accent-left">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="card-title mb-0 text-dark fw-bold">
                    <i class="fa fa-arrow-down-left-long text-primary me-2"></i>Bottomest Left Node
                </h5>
                <span class="badge bg-primary">
                    Depth: <?php echo max(0, count($leftPath) - 1); ?> level(s) down
                </span>
            </div>
            <div class="card-body">
                <?php if ($bottomLeftNode): ?>
                <div class="table-responsive">
                    <table class="table table-bordered mb-3">
                        <tbody>
                            <tr>
                                <th width="35%" class="bg-light">Member ID (MID)</th>
                                <td>
                                    <strong class="text-dark"><?php echo htmlspecialchars($bottomLeftNode['mid'] ?? 'N/A'); ?></strong>
                                    <?php if (!empty($bottomLeftNode['mid'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2 ms-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($bottomLeftNode['mid']); ?>'); alert('Copied MID!');">
                                        <i class="fa fa-copy"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-light">Username</th>
                                <td><?php echo htmlspecialchars($bottomLeftNode['username']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Full Name</th>
                                <td><?php echo htmlspecialchars($bottomLeftNode['full_name'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Email</th>
                                <td><?php echo htmlspecialchars($bottomLeftNode['email']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Rank</th>
                                <td><?php echo getRankNameAdmin($bottomLeftNode['rank_id'], $config['ranks']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Total Investment</th>
                                <td>$<?php echo number_format($bottomLeftNode['total_investment'], 2); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Joined Date</th>
                                <td><?php echo date('Y-m-d H:i', strtotime($bottomLeftNode['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Status</th>
                                <td>
                                    <span class="badge <?php echo ($bottomLeftNode['status'] == 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo ucfirst($bottomLeftNode['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Path Sequence -->
                <div class="mt-3">
                    <h6 class="fw-bold text-secondary mb-2"><i class="fa fa-route me-1"></i>Recursive Path Traversal:</h6>
                    <div class="d-flex flex-wrap align-items-center">
                        <?php foreach ($leftPath as $idx => $step): ?>
                        <span class="path-step <?php echo ($idx === count($leftPath) - 1) ? 'active-end' : ''; ?>">
                            <?php echo htmlspecialchars($step['mid'] ?: $step['username']); ?>
                            <?php if ($idx < count($leftPath) - 1): ?>
                            <i class="fa fa-chevron-right"></i>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (count($leftPath) <= 1): ?>
                <div class="alert alert-info mt-3 mb-0 py-2">
                    <small><i class="fa fa-info-circle me-1"></i> No downline members exist on the Left placement leg yet. The target starting node is the bottommost left node.</small>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottomest Right Node -->
    <div class="col-md-6 mb-4">
        <div class="card h-100 shadow-sm card-accent-right">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="card-title mb-0 text-dark fw-bold">
                    <i class="fa fa-arrow-down-right-long text-warning me-2"></i>Bottomest Right Node
                </h5>
                <span class="badge bg-warning text-dark">
                    Depth: <?php echo max(0, count($rightPath) - 1); ?> level(s) down
                </span>
            </div>
            <div class="card-body">
                <?php if ($bottomRightNode): ?>
                <div class="table-responsive">
                    <table class="table table-bordered mb-3">
                        <tbody>
                            <tr>
                                <th width="35%" class="bg-light">Member ID (MID)</th>
                                <td>
                                    <strong class="text-dark"><?php echo htmlspecialchars($bottomRightNode['mid'] ?? 'N/A'); ?></strong>
                                    <?php if (!empty($bottomRightNode['mid'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2 ms-2" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($bottomRightNode['mid']); ?>'); alert('Copied MID!');">
                                        <i class="fa fa-copy"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-light">Username</th>
                                <td><?php echo htmlspecialchars($bottomRightNode['username']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Full Name</th>
                                <td><?php echo htmlspecialchars($bottomRightNode['full_name'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Email</th>
                                <td><?php echo htmlspecialchars($bottomRightNode['email']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Rank</th>
                                <td><?php echo getRankNameAdmin($bottomRightNode['rank_id'], $config['ranks']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Total Investment</th>
                                <td>$<?php echo number_format($bottomRightNode['total_investment'], 2); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Joined Date</th>
                                <td><?php echo date('Y-m-d H:i', strtotime($bottomRightNode['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Status</th>
                                <td>
                                    <span class="badge <?php echo ($bottomRightNode['status'] == 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo ucfirst($bottomRightNode['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Path Sequence -->
                <div class="mt-3">
                    <h6 class="fw-bold text-secondary mb-2"><i class="fa fa-route me-1"></i>Recursive Path Traversal:</h6>
                    <div class="d-flex flex-wrap align-items-center">
                        <?php foreach ($rightPath as $idx => $step): ?>
                        <span class="path-step <?php echo ($idx === count($rightPath) - 1) ? 'active-end' : ''; ?>">
                            <?php echo htmlspecialchars($step['mid'] ?: $step['username']); ?>
                            <?php if ($idx < count($rightPath) - 1): ?>
                            <i class="fa fa-chevron-right"></i>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (count($rightPath) <= 1): ?>
                <div class="alert alert-info mt-3 mb-0 py-2">
                    <small><i class="fa fa-info-circle me-1"></i> No downline members exist on the Right placement leg yet. The target starting node is the bottommost right node.</small>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
