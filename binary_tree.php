<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/includes/config.php';
$ranksList = $config['ranks'];
$loggedInUserId = $_SESSION['user_id'];

// Fetch logged-in user's mid
$stmtMe = $db->prepare("SELECT mid FROM users WHERE id = ?");
$stmtMe->execute([$loggedInUserId]);
$meData = $stmtMe->fetch();
$loggedInUserMid = $meData ? $meData['mid'] : '';

// Resolve focused user ID
$focusedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $loggedInUserId;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$errorMsg = '';

// Check security: is the focused user in the logged-in user's downline?
$isAllowed = false;
if ($focusedUserId === $loggedInUserId) {
    $isAllowed = true;
} else {
    // Verify focused user is a descendant of the logged-in user
    $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE parent_id = ? AND user_id = ? LIMIT 1");
    $stmtCheck->execute([$loggedInUserId, $focusedUserId]);
    if ($stmtCheck->fetch()) {
        $isAllowed = true;
    }
}

if (!$isAllowed) {
    $errorMsg = "Access denied. You can only view your own downline team.";
    $focusedUserId = $loggedInUserId;
}

// Handle search query
if (!empty($searchQuery)) {
    $stmtSearch = $db->prepare("SELECT id FROM users WHERE username = ? OR mid = ?");
    $stmtSearch->execute([$searchQuery, $searchQuery]);
    $searchedUser = $stmtSearch->fetch();

    if ($searchedUser) {
        $targetId = (int)$searchedUser['id'];
        // Check if searched user is allowed (descendant or self)
        $isSearchAllowed = false;
        if ($targetId === $loggedInUserId) {
            $isSearchAllowed = true;
        } else {
            $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE parent_id = ? AND user_id = ? LIMIT 1");
            $stmtCheck->execute([$loggedInUserId, $targetId]);
            if ($stmtCheck->fetch()) {
                $isSearchAllowed = true;
            }
        }

        if ($isSearchAllowed) {
            $focusedUserId = $targetId;
        } else {
            $errorMsg = "Search failed. User '{$searchQuery}' is not in your downline.";
        }
    } else {
        $errorMsg = "User '{$searchQuery}' not found.";
    }
}

// Helper functions to fetch nodes (supporting dynamic unilevel-to-binary fallback)
function getBinaryNode($db, $userId) {
    if (!$userId) return null;
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getChildrenNodes($db, $parentId) {
    if (!$parentId) return ['left' => null, 'right' => null];

    // Fetch all potential candidates who either:
    // a) have placement_id = $parentId (explicit placement)
    // b) have sponsor_id = $parentId AND placement_id IS NULL (unilevel fallback candidates)
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.placement_id = ?
           OR (u.sponsor_id = ? AND u.placement_id IS NULL)
        ORDER BY u.id ASC
    ");
    $stmt->execute([$parentId, $parentId]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $leftNode = null;
    $rightNode = null;

    // First pass: Assign explicit positions to their corresponding slots
    foreach ($candidates as $cand) {
        if ($cand['placement_id'] == $parentId && !empty($cand['position'])) {
            if ($cand['position'] === 'left' && !$leftNode) {
                $leftNode = $cand;
            } elseif ($cand['position'] === 'right' && !$rightNode) {
                $rightNode = $cand;
            }
        }
    }

    // Second pass: Assign fallback (unpositioned/unilevel) candidates to remaining empty slots
    foreach ($candidates as $cand) {
        // Skip candidates who have already been placed in the first pass
        if ($leftNode && $leftNode['id'] == $cand['id']) continue;
        if ($rightNode && $rightNode['id'] == $cand['id']) continue;

        // Also, if this candidate has an explicit placement elsewhere, skip them entirely to avoid duplicates!
        if (!empty($cand['placement_id']) && $cand['placement_id'] != $parentId) {
            continue;
        }

        // If a candidate has an explicit position, they should NOT be placed as fallback in the other slot!
        if (!empty($cand['position'])) {
            continue;
        }

        // Place them in the first vacant slot
        if (!$leftNode) {
            $leftNode = $cand;
        } elseif (!$rightNode) {
            $rightNode = $cand;
        }
    }

    return [
        'left' => $leftNode,
        'right' => $rightNode
    ];
}

// Fetch all 7 nodes of our 3-level binary tree
$Node1 = getBinaryNode($db, $focusedUserId);

$Level2 = getChildrenNodes($db, $focusedUserId);
$Node2 = $Level2['left'];
$Node3 = $Level2['right'];

$Level3_Left = getChildrenNodes($db, $Node2 ? $Node2['id'] : null);
$Node4 = $Level3_Left['left'];
$Node5 = $Level3_Left['right'];

$Level3_Right = getChildrenNodes($db, $Node3 ? $Node3['id'] : null);
$Node6 = $Level3_Right['left'];
$Node7 = $Level3_Right['right'];

// Determine parent ID of the current focused root user for the "Navigate Up" button
$upUserId = null;
if ($focusedUserId !== $loggedInUserId && $Node1) {
    // If we have explicit placement_id, use it, else use sponsor_id as unilevel parent fallback
    $upUserId = $Node1['placement_id'] ?: $Node1['sponsor_id'];
}

// Fetch all direct unilevel referrals of the focused user to allow navigating through they
$stmtDirects = $db->prepare("SELECT id, username, mid, total_investment, status FROM users WHERE sponsor_id = ? ORDER BY id ASC");
$stmtDirects->execute([$focusedUserId]);
$allDirects = $stmtDirects->fetchAll();

$pageTitle = 'Binary Placement Tree';
include __DIR__ . '/includes/header.php';
?>

<style>
    /* Styling for center-aligned cards & SVG connectors */
    .binary-tree-container {
        position: relative;
        overflow-x: auto;
    }
    .tree-row {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: nowrap;
    }
    .node-card-wrap {
        width: 220px;
        transition: all 0.25s ease;
    }
    .node-card-wrap:hover {
        transform: translateY(-4px);
    }
    .node-card-body {
        border-radius: 12px;
        border: 2px solid #504793;
        background-color: #fff;
        padding: 12px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    }
    .node-card-body.empty {
        border: 2px dashed #cbd5e1;
        background-color: #f8fafc;
        box-shadow: none;
    }
    .active-pulse-node {
        width: 8px;
        height: 8px;
        background-color: #10b981;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-node 1.6s infinite;
    }
    @keyframes pulse-node {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 4px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .inactive-dot-node {
        width: 8px;
        height: 8px;
        background-color: #ef4444;
        border-radius: 50%;
        display: inline-block;
    }
    .connector-svg {
        display: block;
        margin: 0 auto;
        pointer-events: none;
    }
    .btn-make-root {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 20px;
    }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2 mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 border-0 bg-transparent">
                    <div>
                        <h4 class="card-title mb-0">Binary Placement Tree</h4>
                        <p class="text-muted small mb-0">Visualize and explore your left & right organization structure.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($upUserId): ?>
                            <a href="binary_tree.php?user_id=<?php echo $upUserId; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fa fa-arrow-up me-1"></i>Up One Level
                            </a>
                        <?php endif; ?>
                        <?php if ($focusedUserId !== $loggedInUserId): ?>
                            <a href="binary_tree.php" class="btn btn-sm btn-primary">
                                <i class="fa fa-home me-1"></i>Back to My Root
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <!-- Search Input -->
                    <form method="get" class="row g-2 align-items-center mb-4 bg-light p-3 rounded">
                        <div class="col-md-6 col-lg-4">
                            <input type="text" name="search" class="form-control" placeholder="Search downline by Username or MID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm px-4"><i class="fa fa-search me-1"></i>Search</button>
                        </div>
                    </form>

                    <?php if (!empty($errorMsg)): ?>
                        <div class="alert alert-danger dismissible fade show" role="alert">
                            <i class="fa fa-exclamation-circle me-1"></i> <?php echo $errorMsg; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Direct Referrals Navigation (Extremely useful for unilevel trees) -->
                    <?php if (!empty($allDirects)): ?>
                        <div class="mb-4 bg-light p-3 rounded">
                            <h6 class="text-dark fw-bold mb-2"><i class="fa fa-users text-primary me-2"></i>Direct Downlines of <?php echo htmlspecialchars($Node1['username']); ?> (<?php echo count($allDirects); ?>):</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($allDirects as $index => $dir): ?>
                                    <a href="binary_tree.php?user_id=<?php echo $dir['id']; ?>" class="btn btn-xs <?php echo ($index < 2) ? 'btn-outline-primary' : 'btn-outline-secondary'; ?> py-1 px-2" style="font-size: 11px;">
                                        <i class="fa fa-user me-1"></i>
                                        <?php echo htmlspecialchars($dir['username']); ?> (<?php echo htmlspecialchars($dir['mid'] ?? $dir['id']); ?>)
                                        <?php if ($index < 2): ?>
                                            <span class="badge bg-primary text-white ms-1"><?php echo ($index === 0) ? 'Left Slot' : 'Right Slot'; ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($allDirects) > 2): ?>
                                <small class="text-muted d-block mt-2"><i class="fa fa-info-circle me-1"></i>Note: This tree displays the first two referrals as Left & Right. You can click on any referral above to focus and explore their subtree.</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Symmetric Binary Tree Render Area -->
                    <div class="binary-tree-container py-3">

                        <!-- LEVEL 1: Root Node -->
                        <div class="tree-row mb-2">
                            <div class="node-card-wrap">
                                <?php renderNodeCard($Node1, $ranksList, $loggedInUserId, 'Root (Top)', null, null, $loggedInUserMid); ?>
                            </div>
                        </div>

                        <!-- Connector SVG: Level 1 -> Level 2 -->
                        <div class="d-none d-md-block text-center" style="margin-top: -10px; margin-bottom: -10px;">
                            <svg class="connector-svg" width="600" height="40">
                                <path d="M300,0 L300,10 L150,10 L150,40 M300,10 L450,10 L450,40" fill="none" stroke="#504793" stroke-width="2" stroke-dasharray="3" />
                            </svg>
                        </div>

                        <!-- LEVEL 2: Left and Right -->
                        <div class="tree-row mb-2 gap-md-5">
                            <div class="node-card-wrap mx-2">
                                <?php renderNodeCard($Node2, $ranksList, $loggedInUserId, 'LEFT TEAM', $focusedUserId, 'left', $loggedInUserMid); ?>
                            </div>
                            <div class="node-card-wrap mx-2">
                                <?php renderNodeCard($Node3, $ranksList, $loggedInUserId, 'RIGHT TEAM', $focusedUserId, 'right', $loggedInUserMid); ?>
                            </div>
                        </div>

                        <!-- Connector SVG: Level 2 -> Level 3 -->
                        <div class="d-none d-md-block text-center" style="margin-top: -10px; margin-bottom: -10px;">
                            <svg class="connector-svg" width="600" height="40">
                                <path d="M150,0 L150,10 L75,10 L75,40 M150,10 L225,10 L225,40" fill="none" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="3" />
                                <path d="M450,0 L450,10 L375,10 L375,40 M450,10 L525,10 L525,40" fill="none" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="3" />
                            </svg>
                        </div>

                        <!-- LEVEL 3: 4 Leaf Nodes -->
                        <div class="tree-row gap-2 gap-md-4">
                            <div class="node-card-wrap mx-1">
                                <?php renderNodeCard($Node4, $ranksList, $loggedInUserId, 'LEFT/LEFT', $Node2 ? $Node2['id'] : null, 'left', $loggedInUserMid); ?>
                            </div>
                            <div class="node-card-wrap mx-1">
                                <?php renderNodeCard($Node5, $ranksList, $loggedInUserId, 'LEFT/RIGHT', $Node2 ? $Node2['id'] : null, 'right', $loggedInUserMid); ?>
                            </div>
                            <div class="node-card-wrap mx-1">
                                <?php renderNodeCard($Node6, $ranksList, $loggedInUserId, 'RIGHT/LEFT', $Node3 ? $Node3['id'] : null, 'left', $loggedInUserMid); ?>
                            </div>
                            <div class="node-card-wrap mx-1">
                                <?php renderNodeCard($Node7, $ranksList, $loggedInUserId, 'RIGHT/RIGHT', $Node3 ? $Node3['id'] : null, 'right', $loggedInUserMid); ?>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Function to render single node card inside the tree
function renderNodeCard($node, $ranksList, $loggedInUserId, $positionLabel, $parentId = null, $position = null, $loggedInUserMid = '') {
    if ($node) {
        $isActive = ($node['status'] == 'active');
        $rankName = ($node['rank_id'] > 0 && isset($ranksList[$node['rank_id'] - 1])) ? $ranksList[$node['rank_id'] - 1]['name'] : 'None';

        $placementMid = null;
        if (!empty($node['placement_id'])) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT mid, username FROM users WHERE id = ?");
            $stmt->execute([$node['placement_id']]);
            $placementUser = $stmt->fetch();
            if ($placementUser) {
                $placementMid = $placementUser['mid'] ?: 'ID: ' . $node['placement_id'];
            }
        }

        $sponsorMid = null;
        if (!empty($node['sponsor_id'])) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT mid, username FROM users WHERE id = ?");
            $stmt->execute([$node['sponsor_id']]);
            $sponsorUser = $stmt->fetch();
            if ($sponsorUser) {
                $sponsorMid = $sponsorUser['mid'] ?: 'ID: ' . $node['sponsor_id'];
            }
        }
        ?>
        <div class="node-card-body text-center">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="badge bg-secondary" style="font-size: 9px;"><?php echo htmlspecialchars($positionLabel); ?></span>
                <?php if ($isActive): ?>
                    <span class="active-pulse-node" title="Active"></span>
                <?php else: ?>
                    <span class="inactive-dot-node" title="Inactive/Suspended"></span>
                <?php endif; ?>
            </div>

            <h6 class="text-dark fw-bold mb-0 text-truncate" title="<?php echo htmlspecialchars($node['username']); ?>">
                <?php echo htmlspecialchars($node['username']); ?>
            </h6>
            <small class="text-muted font-monospace d-block" style="font-size: 11px;">(<?php echo htmlspecialchars($node['mid'] ?? 'MID: ' . $node['id']); ?>)</small>

            <div class="my-2 border-top border-bottom py-1" style="font-size: 11px; background-color: #fafafa;">
                <div class="text-dark">Invested: <strong>$<?php echo number_format($node['total_investment'], 0); ?></strong></div>
                <?php if ($node['rank_id'] > 0): ?>
                    <div class="text-warning fw-bold mb-1"><i class="fa fa-trophy me-1"></i><?php echo htmlspecialchars($rankName); ?></div>
                <?php else: ?>
                    <div class="text-secondary mb-1">Rank: None</div>
                <?php endif; ?>
                <?php if (!empty($node['position'])): ?>
                    <div class="text-info" style="font-size: 10px;">Position: <strong><?php echo strtoupper($node['position']); ?></strong></div>
                <?php endif; ?>
                <?php if ($sponsorMid): ?>
                    <div class="text-muted" style="font-size: 10px;">Sponsor: <strong><?php echo htmlspecialchars($sponsorMid); ?></strong></div>
                <?php endif; ?>
                <?php if ($placementMid): ?>
                    <div class="text-muted" style="font-size: 10px;">Placement: <strong><?php echo htmlspecialchars($placementMid); ?></strong></div>
                <?php endif; ?>
            </div>

            <!-- Make Root Button to navigate deeper -->
            <div class="mt-1">
                <a href="binary_tree.php?user_id=<?php echo $node['id']; ?>" class="btn btn-xs btn-primary btn-make-root text-white w-100">
                    <i class="fa fa-sitemap me-1"></i>Focus Node
                </a>
            </div>
        </div>
        <?php
    } else {
        ?>
        <div class="node-card-body empty text-center py-4">
            <i class="fa fa-user-plus text-muted fs-4 mb-2"></i>
            <h6 class="text-muted fw-bold mb-0" style="font-size: 12px;"><?php echo htmlspecialchars($positionLabel); ?></h6>
            <div class="text-secondary font-monospace mt-1" style="font-size: 10px;">EMPTY POSITION</div>
            <?php if ($parentId && $position): ?>
                <div class="mt-2">
                    <a href="members/register_member.php?id=<?php echo urlencode($loggedInUserId); ?>&placement_id=<?php echo $parentId; ?>&position=<?php echo $position; ?>" class="btn btn-xs btn-outline-success py-1 px-2" style="font-size: 10px;">
                        <i class="fa fa-plus me-1"></i>Add Member
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
