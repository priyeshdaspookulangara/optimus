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

function getChildNode($db, $parentId, $position) {
    if (!$parentId) return null;

    // 1. Try to find a child with explicit binary placement column
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.placement_id = ? AND u.position = ?
        LIMIT 1
    ");
    $stmt->execute([$parentId, $position]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($node) {
        return $node;
    }

    // 2. Fallback to unilevel direct sponsor downline:
    // 'left' -> 1st child (index 0), 'right' -> 2nd child (index 1)
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.sponsor_id = ?
          AND u.id NOT IN (
              SELECT id FROM users WHERE placement_id = ? AND position IS NOT NULL AND position != ''
          )
        ORDER BY u.id ASC
    ");
    $stmt->execute([$parentId, $parentId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($position === 'left' && isset($children[0])) {
        return $children[0];
    }
    if ($position === 'right' && isset($children[1])) {
        return $children[1];
    }

    return null;
}

// Fetch all 7 nodes of our 3-level binary tree
$Node1 = getBinaryNode($db, $focusedUserId);

$Node2 = getChildNode($db, $focusedUserId, 'left');
$Node3 = getChildNode($db, $focusedUserId, 'right');

$Node4 = getChildNode($db, $Node2 ? $Node2['id'] : null, 'left');
$Node5 = getChildNode($db, $Node2 ? $Node2['id'] : null, 'right');

$Node6 = getChildNode($db, $Node3 ? $Node3['id'] : null, 'left');
$Node7 = getChildNode($db, $Node3 ? $Node3['id'] : null, 'right');

// Determine parent ID of the current focused root user for the "Navigate Up" button
$upUserId = null;
if ($focusedUserId !== $loggedInUserId && $Node1) {
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
    /* Fixed-width tree container layout to ensure coordinates match perfectly */
    .binary-tree-canvas-wrapper {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding: 20px 0 60px 0;
    }
    .binary-tree-canvas {
        position: relative;
        width: 760px;
        height: 340px;
        margin: 0 auto;
        background-color: #fafbfe;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: inset 0 0 10px rgba(0,0,0,0.01);
    }
    .absolute-node-wrapper {
        position: absolute;
        width: 130px;
        height: 54px;
        z-index: 10;
    }

    /* Mathematical placement of nodes inside 760x340 space */
    .node-pos-1 { left: 315px; top: 20px; }
    .node-pos-2 { left: 125px; top: 130px; }
    .node-pos-3 { left: 505px; top: 130px; }
    .node-pos-4 { left: 30px; top: 240px; }
    .node-pos-5 { left: 220px; top: 240px; }
    .node-pos-6 { left: 410px; top: 240px; }
    .node-pos-7 { left: 600px; top: 240px; }

    /* Compact Node Card Styling */
    .node-card-body {
        position: relative;
        width: 100%;
        height: 100%;
        border-radius: 8px;
        border: 2px solid navy;
        background-color: #ffffff;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 4px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
    }
    .node-card-body:hover {
        border-color: #cca354;
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        z-index: 100;
    }
    .node-card-body.empty {
        border: 2px dashed #cbd5e1;
        background-color: #f8fafc;
        cursor: default;
    }
    .node-card-body.empty:hover {
        border-color: #cbd5e1;
        transform: none;
        box-shadow: none;
    }

    /* Text styles inside compact node */
    .node-username {
        font-size: 11px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        text-align: center;
        max-width: 115px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .node-mid {
        font-size: 9px;
        color: #64748b;
        font-family: monospace;
        margin-top: 1px;
    }

    /* Curved Navy Blue Lines */
    .tree-connector-line {
        fill: none;
        stroke: navy;
        stroke-width: 2.5;
        transition: stroke-width 0.2s;
    }
    .tree-connector-line.empty {
        stroke-dasharray: 4;
        stroke-width: 1.5;
        opacity: 0.5;
    }

    /* Hover Dropdown details card */
    .node-details-dropdown {
        position: absolute;
        top: 110%;
        left: 50%;
        transform: translateX(-50%) scaleY(0);
        transform-origin: top;
        width: 220px;
        background-color: #ffffff;
        border: 2px solid navy;
        border-radius: 12px;
        padding: 12px;
        z-index: 1000;
        opacity: 0;
        pointer-events: none;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.15s ease;
        text-align: left;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    .node-card-body:hover .node-details-dropdown {
        transform: translateX(-50%) scaleY(1);
        opacity: 1;
        pointer-events: auto;
    }

    /* Adjust Level 3 to expand upwards to avoid clipping inside canvas */
    .node-pos-4 .node-details-dropdown,
    .node-pos-5 .node-details-dropdown,
    .node-pos-6 .node-details-dropdown,
    .node-pos-7 .node-details-dropdown {
        top: auto;
        bottom: 110%;
        transform-origin: bottom;
    }

    /* Details Typography */
    .dropdown-title {
        font-size: 11px;
        font-weight: 700;
        color: #1e293b;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 6px;
        margin-bottom: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .dropdown-row {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        margin-bottom: 4px;
        color: #334155;
    }
    .dropdown-row strong {
        color: #0f172a;
    }
    .dropdown-badge {
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 600;
    }

    /* Pulse Dot in Compact Card */
    .pulse-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        position: absolute;
        top: 4px;
        right: 4px;
    }
    .pulse-dot.active {
        background-color: #10b981;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-node 1.6s infinite;
    }
    .pulse-dot.inactive {
        background-color: #ef4444;
    }
    @keyframes pulse-node {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 4px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
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

                    <!-- Direct Referrals Navigation -->
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
                    <div class="binary-tree-canvas-wrapper">
                        <div class="binary-tree-canvas">

                            <!-- SVG Curved Connector Lines -->
                            <svg class="tree-svg-container" width="760" height="340" style="position: absolute; top: 0; left: 0; pointer-events: none; z-index: 1;">
                                <!-- Path 1: Node 1 to Node 2 -->
                                <path d="M 380,74 C 380,102 190,102 190,130" class="tree-connector-line <?php echo $Node2 ? '' : 'empty'; ?>" />

                                <!-- Path 2: Node 1 to Node 3 -->
                                <path d="M 380,74 C 380,102 570,102 570,130" class="tree-connector-line <?php echo $Node3 ? '' : 'empty'; ?>" />

                                <?php if ($Node2): ?>
                                    <!-- Path 3: Node 2 to Node 4 -->
                                    <path d="M 190,184 C 190,212 95,212 95,240" class="tree-connector-line <?php echo $Node4 ? '' : 'empty'; ?>" />
                                    <!-- Path 4: Node 2 to Node 5 -->
                                    <path d="M 190,184 C 190,212 285,212 285,240" class="tree-connector-line <?php echo $Node5 ? '' : 'empty'; ?>" />
                                <?php endif; ?>

                                <?php if ($Node3): ?>
                                    <!-- Path 5: Node 3 to Node 6 -->
                                    <path d="M 570,184 C 570,212 475,212 475,240" class="tree-connector-line <?php echo $Node6 ? '' : 'empty'; ?>" />
                                    <!-- Path 6: Node 3 to Node 7 -->
                                    <path d="M 570,184 C 570,212 665,212 665,240" class="tree-connector-line <?php echo $Node7 ? '' : 'empty'; ?>" />
                                <?php endif; ?>
                            </svg>

                            <!-- LEVEL 1: Root Node -->
                            <div class="absolute-node-wrapper node-pos-1">
                                <?php renderNodeCard($Node1, $ranksList, $loggedInUserId, 'Root (Top)'); ?>
                            </div>

                            <!-- LEVEL 2: Left and Right -->
                            <div class="absolute-node-wrapper node-pos-2">
                                <?php renderNodeCard($Node2, $ranksList, $loggedInUserId, 'LEFT TEAM'); ?>
                            </div>
                            <div class="absolute-node-wrapper node-pos-3">
                                <?php renderNodeCard($Node3, $ranksList, $loggedInUserId, 'RIGHT TEAM'); ?>
                            </div>

                            <!-- LEVEL 3: 4 Leaf Nodes -->
                            <div class="absolute-node-wrapper node-pos-4">
                                <?php renderNodeCard($Node4, $ranksList, $loggedInUserId, 'LEFT/LEFT'); ?>
                            </div>
                            <div class="absolute-node-wrapper node-pos-5">
                                <?php renderNodeCard($Node5, $ranksList, $loggedInUserId, 'LEFT/RIGHT'); ?>
                            </div>
                            <div class="absolute-node-wrapper node-pos-6">
                                <?php renderNodeCard($Node6, $ranksList, $loggedInUserId, 'RIGHT/LEFT'); ?>
                            </div>
                            <div class="absolute-node-wrapper node-pos-7">
                                <?php renderNodeCard($Node7, $ranksList, $loggedInUserId, 'RIGHT/RIGHT'); ?>
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
function renderNodeCard($node, $ranksList, $loggedInUserId, $positionLabel) {
    if ($node) {
        $isActive = ($node['status'] == 'active');
        $rankName = ($node['rank_id'] > 0 && isset($ranksList[$node['rank_id'] - 1])) ? $ranksList[$node['rank_id'] - 1]['name'] : 'None';

        $placementMid = null;
        $placementName = null;
        if (!empty($node['placement_id'])) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT mid, username FROM users WHERE id = ?");
            $stmt->execute([$node['placement_id']]);
            $placementUser = $stmt->fetch();
            if ($placementUser) {
                $placementMid = $placementUser['mid'] ?: 'ID: ' . $node['placement_id'];
                $placementName = $placementUser['username'];
            }
        }

        $sponsorMid = null;
        $sponsorName = null;
        if (!empty($node['sponsor_id'])) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT mid, username FROM users WHERE id = ?");
            $stmt->execute([$node['sponsor_id']]);
            $sponsorUser = $stmt->fetch();
            if ($sponsorUser) {
                $sponsorMid = $sponsorUser['mid'] ?: 'ID: ' . $node['sponsor_id'];
                $sponsorName = $sponsorUser['username'];
            }
        }
        ?>
        <div class="node-card-body">
            <!-- Compact Mode (Always Visible) -->
            <div class="pulse-dot <?php echo $isActive ? 'active' : 'inactive'; ?>" title="<?php echo $isActive ? 'Active' : 'Inactive/Suspended'; ?>"></div>
            <div class="node-username"><?php echo htmlspecialchars($node['username']); ?></div>
            <div class="node-mid"><?php echo htmlspecialchars($node['mid'] ?? 'MID: ' . $node['id']); ?></div>

            <!-- Hover Details Dropdown (Saves Space) -->
            <div class="node-details-dropdown shadow">
                <div class="dropdown-title">
                    <span><?php echo htmlspecialchars($positionLabel); ?></span>
                    <span class="dropdown-badge <?php echo $isActive ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>

                <div class="dropdown-row">
                    <span>Username:</span>
                    <strong><?php echo htmlspecialchars($node['username']); ?></strong>
                </div>
                <div class="dropdown-row">
                    <span>Member ID:</span>
                    <strong><?php echo htmlspecialchars($node['mid'] ?? $node['id']); ?></strong>
                </div>
                <div class="dropdown-row">
                    <span>Investment:</span>
                    <strong>$<?php echo number_format($node['total_investment'], 2); ?></strong>
                </div>
                <div class="dropdown-row">
                    <span>Rank:</span>
                    <strong class="text-warning"><?php echo htmlspecialchars($rankName); ?></strong>
                </div>
                <?php if (!empty($node['position'])): ?>
                    <div class="dropdown-row">
                        <span>Leg Position:</span>
                        <strong class="text-uppercase text-info"><?php echo htmlspecialchars($node['position']); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($sponsorMid): ?>
                    <div class="dropdown-row">
                        <span>Sponsor:</span>
                        <strong title="<?php echo htmlspecialchars($sponsorName); ?>"><?php echo htmlspecialchars($sponsorMid); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($placementMid): ?>
                    <div class="dropdown-row">
                        <span>Placement:</span>
                        <strong title="<?php echo htmlspecialchars($placementName); ?>"><?php echo htmlspecialchars($placementMid); ?></strong>
                    </div>
                <?php endif; ?>

                <div class="mt-3">
                    <a href="binary_tree.php?user_id=<?php echo $node['id']; ?>" class="btn btn-xs btn-primary text-white w-100 py-1" style="font-size: 10px;">
                        <i class="fa fa-sitemap me-1"></i>Focus Node
                    </a>
                </div>
            </div>
        </div>
        <?php
    } else {
        ?>
        <div class="node-card-body empty text-center">
            <div class="node-username text-muted" style="font-weight: 500; font-size: 10px;"><?php echo htmlspecialchars($positionLabel); ?></div>
            <div class="node-mid text-secondary" style="font-size: 8px; letter-spacing: 0.5px;">EMPTY</div>
        </div>
        <?php
    }
}
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
