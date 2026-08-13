<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized admin session.']);
        exit();
    }
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();

// Rebuild Genealogy Helper Function
function rebuildGenealogyTable($db) {
    $isNested = $db->inTransaction();
    if (!$isNested) {
        $db->beginTransaction();
    }
    try {
        // 1. Clear genealogy table
        $db->exec("DELETE FROM genealogy");

        // 2. Insert Level 1 (direct sponsors)
        $db->exec("INSERT INTO genealogy (user_id, parent_id, level)
                   SELECT id, sponsor_id, 1 FROM users WHERE sponsor_id IS NOT NULL");

        // 3. Iteratively insert Levels 2 through 12
        for ($level = 2; $level <= 12; $level++) {
            $prevLevel = $level - 1;
            $stmt = $db->prepare("
                INSERT INTO genealogy (user_id, parent_id, level)
                SELECT g.user_id, u.sponsor_id, ?
                FROM genealogy g
                JOIN users u ON g.parent_id = u.id
                WHERE g.level = ? AND u.sponsor_id IS NOT NULL
            ");
            $stmt->execute([$level, $prevLevel]);
        }

        if (!$isNested) {
            $db->commit();
        }
        return true;
    } catch (Exception $e) {
        if (!$isNested && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Handle AJAX Drag & Drop Placement Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }

    // Check CSRF
    $headers = apache_request_headers();
    $csrfToken = isset($headers['X-CSRF-Token']) ? $headers['X-CSRF-Token'] : '';
    if (empty($csrfToken) && isset($data['csrf_token'])) {
        $csrfToken = $data['csrf_token'];
    }

    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    if (empty($csrfToken) || $csrfToken !== $_SESSION['admin_csrf']) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit();
    }

    $draggedId = isset($data['dragged_id']) ? (int)$data['dragged_id'] : 0;
    $targetId = isset($data['target_id']) ? (int)$data['target_id'] : 0;
    $position = isset($data['position']) ? trim($data['position']) : '';

    if ($draggedId <= 0 || $targetId <= 0 || !in_array($position, ['left', 'right'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters. Please drag and drop on a valid Left or Right zone.']);
        exit();
    }

    if ($draggedId === 1) {
        echo json_encode(['success' => false, 'message' => 'Root admin user cannot be moved.']);
        exit();
    }

    if ($draggedId === $targetId) {
        echo json_encode(['success' => false, 'message' => 'A user cannot be placed under themselves.']);
        exit();
    }

    $db->beginTransaction();
    try {
        // Fetch dragged user
        $stmtDragged = $db->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmtDragged->execute([$draggedId]);
        $draggedUser = $stmtDragged->fetch();
        if (!$draggedUser) {
            throw new Exception("Dragged member not found.");
        }

        // Fetch target user
        $stmtTarget = $db->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmtTarget->execute([$targetId]);
        $targetUser = $stmtTarget->fetch();
        if (!$targetUser) {
            throw new Exception("Target placement parent not found.");
        }

        // Circular check: is targetId a descendant of draggedId?
        $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE parent_id = ? AND user_id = ? LIMIT 1");
        $stmtCheck->execute([$draggedId, $targetId]);
        if ($stmtCheck->fetch()) {
            throw new Exception("Circular Reference: Cannot place a parent under their own descendant '" . htmlspecialchars($targetUser['username']) . "'.");
        }

        // Check if target position is already occupied
        $stmtCheckOcc = $db->prepare("SELECT id, username FROM users WHERE placement_id = ? AND position = ? LIMIT 1");
        $stmtCheckOcc->execute([$targetId, $position]);
        $occupied = $stmtCheckOcc->fetch();

        if ($occupied && (int)$occupied['id'] !== $draggedId) {
            throw new Exception("The position '{$position}' under " . htmlspecialchars($targetUser['username']) . " is already occupied by " . htmlspecialchars($occupied['username']) . ".");
        }

        // Update database
        $stmtUpdate = $db->prepare("UPDATE users SET placement_id = ?, position = ? WHERE id = ?");
        $stmtUpdate->execute([$targetId, $position, $draggedId]);

        // Rebuild Genealogy Table to keep everything fully synced
        rebuildGenealogyTable($db);

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Successfully placed ' . htmlspecialchars($draggedUser['username']) . ' as the ' . strtoupper($position) . ' child of ' . htmlspecialchars($targetUser['username']) . '!']);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

// Fetch general configuration & ranks
$config = require __DIR__ . '/../includes/config.php';
$ranksList = $config['ranks'] ?? [];

// Resolve Focused User ID
$focusedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1; // Default to Root admin
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$errorMsg = '';

if (!empty($searchQuery)) {
    $stmtSearch = $db->prepare("SELECT id, username FROM users WHERE username = ? OR mid = ?");
    $stmtSearch->execute([$searchQuery, $searchQuery]);
    $searchedUser = $stmtSearch->fetch();
    if ($searchedUser) {
        $focusedUserId = (int)$searchedUser['id'];
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

// Parent ID for the "Up" button
$upUserId = null;
if ($Node1 && $focusedUserId !== 1) {
    $upUserId = $Node1['placement_id'] ?: $Node1['sponsor_id'] ?: 1;
}

// Fetch All Users for the Draggable Sidebar
$stmtAllUsers = $db->prepare("SELECT id, username, mid, total_investment, status, position, placement_id FROM users ORDER BY username ASC");
$stmtAllUsers->execute();
$allUsers = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Drag & Drop Genealogy';
include __DIR__ . '/includes/header.php';
?>

<!-- Custom CSS styles for the drag-drop genealogy layout -->
<style>
    .drag-drop-container {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    .members-sidebar-card {
        flex: 1;
        min-width: 280px;
        max-width: 340px;
        background-color: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        max-height: 700px;
    }
    .members-list-wrapper {
        overflow-y: auto;
        padding: 12px;
        flex-grow: 1;
    }
    .draggable-member-item {
        background-color: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 8px;
        cursor: grab;
        transition: all 0.2s;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .draggable-member-item:hover {
        background-color: #f1f5f9;
        border-color: #94a3b8;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .draggable-member-item:active {
        cursor: grabbing;
    }
    .draggable-member-item.dragging-now {
        opacity: 0.4;
        border-style: dashed;
    }

    .tree-canvas-card {
        flex: 3;
        min-width: 600px;
        background-color: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        padding: 20px;
    }

    /* Fixed-width tree container coordinates matching perfectly */
    .binary-tree-canvas-wrapper {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding: 20px 0 60px 0;
    }
    .binary-tree-canvas {
        position: relative;
        width: 760px;
        height: 380px;
        margin: 0 auto;
        background-color: #fdfefe;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: inset 0 0 10px rgba(0,0,0,0.01);
    }
    .absolute-node-wrapper {
        position: absolute;
        width: 135px;
        height: 60px;
        z-index: 10;
    }

    /* Coordinate Positions */
    .node-pos-1 { left: 312px; top: 20px; }
    .node-pos-2 { left: 120px; top: 140px; }
    .node-pos-3 { left: 505px; top: 140px; }
    .node-pos-4 { left: 25px; top: 260px; }
    .node-pos-5 { left: 215px; top: 260px; }
    .node-pos-6 { left: 410px; top: 260px; }
    .node-pos-7 { left: 600px; top: 260px; }

    /* Compact Node Card Styling */
    .node-card-body {
        position: relative;
        width: 100%;
        height: 100%;
        border-radius: 10px;
        border: 2px solid #1e3a8a;
        background-color: #ffffff;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 6px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
        transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
        cursor: grab;
    }
    .node-card-body:hover {
        border-color: #d97706;
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 50;
    }
    .node-card-body:active {
        cursor: grabbing;
    }

    /* Drag overlay splitting node into Left & Right drop options */
    .drag-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: none;
        z-index: 1000;
        border-radius: 8px;
        overflow: hidden;
    }
    /* When a drag-over-node class is added, show the option split overlay */
    .node-card-body.drag-active-node .drag-overlay {
        display: flex;
    }
    .drop-option-zone {
        flex: 1;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        font-size: 9px;
        font-weight: 800;
        color: #ffffff;
        text-transform: uppercase;
        transition: background-color 0.15s;
        padding: 2px;
        text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    }
    .drop-option-zone.zone-left {
        background-color: rgba(37, 99, 235, 0.9); /* Translucent Blue */
        border-right: 1px solid rgba(255,255,255,0.4);
    }
    .drop-option-zone.zone-right {
        background-color: rgba(22, 163, 74, 0.9); /* Translucent Green */
        border-left: 1px solid rgba(255,255,255,0.4);
    }
    /* When user drags directly over Left or Right option, highlight it yellow/gold */
    .drop-option-zone.drag-hovering {
        background-color: rgba(217, 119, 6, 0.95) !important; /* Gold highlight */
    }

    /* Empty state node styling */
    .node-card-body.empty {
        border: 2px dashed #94a3b8;
        background-color: #f8fafc;
        cursor: default;
    }
    .node-card-body.empty.drag-hovering {
        border-color: #d97706;
        background-color: #fffbeb;
        transform: scale(1.02);
    }

    /* Hover Details dropdown tooltip */
    .node-details-dropdown {
        position: absolute;
        top: 105%;
        left: 50%;
        transform: translateX(-50%) scaleY(0);
        transform-origin: top;
        width: 220px;
        background-color: #ffffff;
        border: 2px solid #1e3a8a;
        border-radius: 12px;
        padding: 12px;
        z-index: 1000;
        opacity: 0;
        pointer-events: none;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.15s ease;
        text-align: left;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    }
    .node-card-body:hover .node-details-dropdown {
        transform: translateX(-50%) scaleY(1);
        opacity: 1;
        pointer-events: auto;
    }

    /* Expand level 3 details upwards to avoid clipping */
    .node-pos-4 .node-details-dropdown,
    .node-pos-5 .node-details-dropdown,
    .node-pos-6 .node-details-dropdown,
    .node-pos-7 .node-details-dropdown {
        top: auto;
        bottom: 105%;
        transform-origin: bottom;
    }

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

    /* Text styles inside compact node */
    .node-username {
        font-size: 11px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        text-align: center;
        max-width: 120px;
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

    /* Svg Connector Styles */
    .tree-connector-line {
        fill: none;
        stroke: #1e3a8a;
        stroke-width: 2.5;
        transition: stroke-width 0.2s;
    }
    .tree-connector-line.empty {
        stroke-dasharray: 4;
        stroke-width: 1.5;
        opacity: 0.5;
    }

    .pulse-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        position: absolute;
        top: 6px;
        right: 6px;
    }
    .pulse-dot.active {
        background-color: #10b981;
    }
    .pulse-dot.inactive {
        background-color: #ef4444;
    }

    /* Floating drag ghost style */
    .ghost-drag {
        opacity: 0.5;
        background-color: #e0f2fe;
    }
</style>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3><i class="fa fa-sitemap text-primary me-2"></i>Drag & Drop Genealogy Tree</h3>
            <p class="text-muted mb-0">Re-arrange placement structure interactively. Drag any node or sidebar member, and drop them on vacant slots or existing parent nodes with Left/Right options.</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($upUserId): ?>
                <a href="drag_drop_genealogy.php?user_id=<?php echo $upUserId; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fa fa-arrow-up me-1"></i>Up One Level
                </a>
            <?php endif; ?>
            <?php if ($focusedUserId !== 1): ?>
                <a href="drag_drop_genealogy.php" class="btn btn-primary btn-sm">
                    <i class="fa fa-home me-1"></i>Back to Root
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Drag-Drop Dashboard Container -->
<div class="drag-drop-container">

    <!-- Draggable Members Sidebar List -->
    <div class="members-sidebar-card">
        <div class="p-3 border-bottom bg-light">
            <h6 class="fw-bold text-dark mb-2"><i class="fa fa-users text-primary me-2"></i>Members Directory</h6>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fa fa-search text-muted"></i></span>
                <input type="text" id="memberSearch" class="form-control" placeholder="Search sidebar list..." onkeyup="filterSidebarList()">
            </div>
        </div>
        <div class="members-list-wrapper" id="sidebarMembersList">
            <?php foreach ($allUsers as $u): ?>
                <?php if ($u['id'] !== 1): ?>
                    <div class="draggable-member-item"
                         draggable="true"
                         ondragstart="handleSidebarDragStart(event)"
                         ondragend="handleSidebarDragEnd(event)"
                         data-id="<?php echo $u['id']; ?>"
                         data-username="<?php echo htmlspecialchars($u['username']); ?>"
                         data-mid="<?php echo htmlspecialchars($u['mid'] ?? ''); ?>">
                        <div>
                            <strong class="text-dark d-block" style="font-size: 12px;"><?php echo htmlspecialchars($u['username']); ?></strong>
                            <span class="text-muted small" style="font-size: 10px; font-family: monospace;"><?php echo htmlspecialchars($u['mid'] ?? 'ID: ' . $u['id']); ?></span>
                        </div>
                        <div>
                            <?php if ($u['placement_id']): ?>
                                <span class="badge bg-light text-dark border p-1" style="font-size: 8px;">Placed</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark p-1" style="font-size: 8px;">Unplaced</span>
                            <?php endif; ?>
                            <i class="fa fa-bars text-secondary ms-2 small"></i>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Interactive Tree Area -->
    <div class="tree-canvas-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 text-dark"><i class="fa fa-network-wired text-primary me-2"></i>Placement Organization Structure</h6>
            <form method="get" class="d-flex gap-2">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Focus on user username/MID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                <button type="submit" class="btn btn-sm btn-primary px-3">Focus</button>
            </form>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger py-2 px-3 small rounded mb-3">
                <i class="fa fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <div id="alertPlaceholder"></div>

        <div class="binary-tree-canvas-wrapper">
            <div class="binary-tree-canvas" id="binaryTreeCanvas">

                <!-- SVG Curved Connector Lines -->
                <svg class="tree-svg-container" width="760" height="380" style="position: absolute; top: 0; left: 0; pointer-events: none; z-index: 1;">
                    <!-- Path 1: Node 1 to Node 2 -->
                    <path d="M 380,80 C 380,110 187,110 187,140" class="tree-connector-line <?php echo $Node2 ? '' : 'empty'; ?>" />

                    <!-- Path 2: Node 1 to Node 3 -->
                    <path d="M 380,80 C 380,110 572,110 572,140" class="tree-connector-line <?php echo $Node3 ? '' : 'empty'; ?>" />

                    <?php if ($Node2): ?>
                        <!-- Path 3: Node 2 to Node 4 -->
                        <path d="M 187,200 C 187,230 92,230 92,260" class="tree-connector-line <?php echo $Node4 ? '' : 'empty'; ?>" />
                        <!-- Path 4: Node 2 to Node 5 -->
                        <path d="M 187,200 C 187,230 282,230 282,260" class="tree-connector-line <?php echo $Node5 ? '' : 'empty'; ?>" />
                    <?php endif; ?>

                    <?php if ($Node3): ?>
                        <!-- Path 5: Node 3 to Node 6 -->
                        <path d="M 572,200 C 572,230 477,230 477,260" class="tree-connector-line <?php echo $Node6 ? '' : 'empty'; ?>" />
                        <!-- Path 6: Node 3 to Node 7 -->
                        <path d="M 572,200 C 572,230 667,230 667,260" class="tree-connector-line <?php echo $Node7 ? '' : 'empty'; ?>" />
                    <?php endif; ?>
                </svg>

                <!-- LEVEL 1: Root Node -->
                <div class="absolute-node-wrapper node-pos-1">
                    <?php renderDragNode($Node1, $ranksList, 'Root Focus', 1, ''); ?>
                </div>

                <!-- LEVEL 2 -->
                <div class="absolute-node-wrapper node-pos-2">
                    <?php renderDragNode($Node2, $ranksList, 'LEFT TEAM', $focusedUserId, 'left'); ?>
                </div>
                <div class="absolute-node-wrapper node-pos-3">
                    <?php renderDragNode($Node3, $ranksList, 'RIGHT TEAM', $focusedUserId, 'right'); ?>
                </div>

                <!-- LEVEL 3 -->
                <div class="absolute-node-wrapper node-pos-4">
                    <?php renderDragNode($Node4, $ranksList, 'LEFT/LEFT', $Node2 ? $Node2['id'] : null, 'left'); ?>
                </div>
                <div class="absolute-node-wrapper node-pos-5">
                    <?php renderDragNode($Node5, $ranksList, 'LEFT/RIGHT', $Node2 ? $Node2['id'] : null, 'right'); ?>
                </div>
                <div class="absolute-node-wrapper node-pos-6">
                    <?php renderDragNode($Node6, $ranksList, 'RIGHT/LEFT', $Node3 ? $Node3['id'] : null, 'left'); ?>
                </div>
                <div class="absolute-node-wrapper node-pos-7">
                    <?php renderDragNode($Node7, $ranksList, 'RIGHT/RIGHT', $Node3 ? $Node3['id'] : null, 'right'); ?>
                </div>

            </div>
        </div>
    </div>

</div>

<?php
// Function to render an interactive node card supporting drag-and-drop
function renderDragNode($node, $ranksList, $positionLabel, $parentIdForEmpty, $positionForEmpty) {
    if ($node) {
        $isActive = ($node['status'] == 'active');
        $rankName = ($node['rank_id'] > 0 && isset($ranksList[$node['rank_id'] - 1])) ? $ranksList[$node['rank_id'] - 1]['name'] : 'None';
        $isDraggable = ($node['id'] !== 1); // Root admin node cannot be dragged

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
        <div class="node-card-body occupied-node"
             draggable="<?php echo $isDraggable ? 'true' : 'false'; ?>"
             ondragstart="handleNodeDragStart(event, <?php echo $node['id']; ?>)"
             ondragend="handleNodeDragEnd(event)"
             ondragover="handleNodeDragOver(event, this)"
             ondragleave="handleNodeDragLeave(event, this)"
             data-id="<?php echo $node['id']; ?>"
             data-username="<?php echo htmlspecialchars($node['username']); ?>">

            <div class="pulse-dot <?php echo $isActive ? 'active' : 'inactive'; ?>" title="<?php echo $isActive ? 'Active' : 'Inactive'; ?>"></div>
            <div class="node-username"><?php echo htmlspecialchars($node['username']); ?></div>
            <div class="node-mid"><?php echo htmlspecialchars($node['mid'] ?? 'ID: ' . $node['id']); ?></div>

            <!-- HOVER DETAILED TOOLTIP -->
            <div class="node-details-dropdown shadow border">
                <div class="dropdown-title">
                    <span><?php echo htmlspecialchars($positionLabel); ?></span>
                    <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-danger'; ?> text-white" style="font-size: 8px;">
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
                <div class="mt-2 text-center">
                    <a href="drag_drop_genealogy.php?user_id=<?php echo $node['id']; ?>" class="btn btn-xs btn-primary text-white w-100 py-1" style="font-size: 10px;">
                        <i class="fa fa-sitemap me-1"></i>Focus Node
                    </a>
                </div>
            </div>

            <!-- DRAG OVER OPTIONS: SPLIT OVERLAY WITH LEFT AND RIGHT DRAGGABLE SLOTS -->
            <div class="drag-overlay">
                <div class="drop-option-zone zone-left"
                     data-target-id="<?php echo $node['id']; ?>"
                     data-position="left"
                     ondragover="handleZoneDragOver(event, this)"
                     ondragleave="handleZoneDragLeave(event, this)"
                     ondrop="handleZoneDrop(event, this)">
                    <i class="fa fa-arrow-left d-block mb-1"></i>Left
                </div>
                <div class="drop-option-zone zone-right"
                     data-target-id="<?php echo $node['id']; ?>"
                     data-position="right"
                     ondragover="handleZoneDragOver(event, this)"
                     ondragleave="handleZoneDragLeave(event, this)"
                     ondrop="handleZoneDrop(event, this)">
                    <i class="fa fa-arrow-right d-block mb-1"></i>Right
                </div>
            </div>

        </div>
        <?php
    } else {
        // Display vacant slot as drop target
        if ($parentIdForEmpty) {
            ?>
            <div class="node-card-body empty"
                 data-target-id="<?php echo $parentIdForEmpty; ?>"
                 data-position="<?php echo $positionForEmpty; ?>"
                 ondragover="handleEmptyDragOver(event, this)"
                 ondragleave="handleEmptyDragLeave(event, this)"
                 ondrop="handleEmptyDrop(event, this)">
                <div class="node-username text-muted" style="font-weight: 500; font-size: 10px;"><?php echo htmlspecialchars($positionLabel); ?></div>
                <div class="node-mid text-secondary" style="font-size: 8px; font-weight: bold; letter-spacing: 0.5px;">VACANT SLOT</div>
                <small class="text-muted" style="font-size: 8px;">Drop to Place</small>
            </div>
            <?php
        } else {
            ?>
            <div class="node-card-body empty">
                <div class="node-username text-muted" style="font-weight: 500; font-size: 10px;"><?php echo htmlspecialchars($positionLabel); ?></div>
                <div class="node-mid text-secondary" style="font-size: 8px; font-weight: bold; letter-spacing: 0.5px;">LOCKED SLOT</div>
            </div>
            <?php
        }
    }
}
?>

<!-- Drag and drop interaction script -->
<script>
    // Keep track of the currently dragged item
    let draggedUserId = null;

    // Sidebar search filter function
    function filterSidebarList() {
        const query = document.getElementById('memberSearch').value.toLowerCase();
        const items = document.querySelectorAll('#sidebarMembersList .draggable-member-item');
        items.forEach(item => {
            const username = item.getAttribute('data-username').toLowerCase();
            const mid = item.getAttribute('data-mid').toLowerCase();
            if (username.includes(query) || mid.includes(query)) {
                item.style.setProperty('display', 'flex', 'important');
            } else {
                item.style.setProperty('display', 'none', 'important');
            }
        });
    }

    // Sidebar Node Drag Start
    function handleSidebarDragStart(event) {
        const id = event.target.getAttribute('data-id');
        draggedUserId = parseInt(id);
        event.target.classList.add('dragging-now');
        // Set data transfer
        event.dataTransfer.setData('text/plain', id);
        event.dataTransfer.effectAllowed = 'move';
    }

    // Sidebar Node Drag End
    function handleSidebarDragEnd(event) {
        event.target.classList.remove('dragging-now');
    }

    // Interactive Tree Node Drag Start
    function handleNodeDragStart(event, id) {
        // Prevent dragging root user
        if (id === 1) {
            event.preventDefault();
            return false;
        }
        draggedUserId = id;
        event.target.classList.add('dragging-now');
        event.dataTransfer.setData('text/plain', id);
        event.dataTransfer.effectAllowed = 'move';
    }

    function handleNodeDragEnd(event) {
        event.target.classList.remove('dragging-now');
        // Remove all drag-active-node classes from cards
        document.querySelectorAll('.node-card-body').forEach(card => {
            card.classList.remove('drag-active-node');
        });
    }

    // Dragover occupied node card - shows the Left/Right drop zone options overlay
    function handleNodeDragOver(event, element) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const targetId = parseInt(element.getAttribute('data-id'));
        // Don't show options if we drag a node over itself
        if (draggedUserId === targetId) {
            return;
        }

        element.classList.add('drag-active-node');
    }

    // Dragleave occupied node card - hides the Left/Right drop zone options overlay
    function handleNodeDragLeave(event, element) {
        // Check if we are actually leaving the node-card, not just entering children
        const rect = element.getBoundingClientRect();
        const x = event.clientX;
        const y = event.clientY;

        if (x < rect.left || x >= rect.right || y < rect.top || y >= rect.bottom) {
            element.classList.remove('drag-active-node');
        }
    }

    // Dragover specific overlay zone (Left/Right option)
    function handleZoneDragOver(event, element) {
        event.preventDefault();
        event.stopPropagation();
        element.classList.add('drag-hovering');
    }

    // Dragleave specific overlay zone
    function handleZoneDragLeave(event, element) {
        event.stopPropagation();
        element.classList.remove('drag-hovering');
    }

    // On Drop on specific Left/Right overlay zone
    function handleZoneDrop(event, element) {
        event.preventDefault();
        event.stopPropagation();
        element.classList.remove('drag-hovering');

        // Remove overlay display
        const parentCard = element.closest('.node-card-body');
        if (parentCard) {
            parentCard.classList.remove('drag-active-node');
        }

        const targetId = parseInt(element.getAttribute('data-target-id'));
        const position = element.getAttribute('data-position');

        if (!draggedUserId) {
            draggedUserId = parseInt(event.dataTransfer.getData('text/plain'));
        }

        executePlacementUpdate(draggedUserId, targetId, position);
    }

    // Dragover vacant empty slot
    function handleEmptyDragOver(event, element) {
        event.preventDefault();
        element.classList.add('drag-hovering');
    }

    // Dragleave vacant empty slot
    function handleEmptyDragLeave(event, element) {
        element.classList.remove('drag-hovering');
    }

    // Drop on vacant empty slot
    function handleEmptyDrop(event, element) {
        event.preventDefault();
        element.classList.remove('drag-hovering');

        const targetId = parseInt(element.getAttribute('data-target-id'));
        const position = element.getAttribute('data-position');

        if (!draggedUserId) {
            draggedUserId = parseInt(event.dataTransfer.getData('text/plain'));
        }

        executePlacementUpdate(draggedUserId, targetId, position);
    }

    // Execute Backend AJAX request to update the DB and rebuild genealogy paths
    function executePlacementUpdate(draggedId, targetId, position) {
        if (!draggedId || !targetId || !position) {
            showAlert('danger', 'Error resolving placement payload.');
            return;
        }

        showAlert('info', 'Updating placement & rebuilding genealogy structure...');

        fetch('drag_drop_genealogy.php?ajax=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo $_SESSION['admin_csrf']; ?>'
            },
            body: JSON.stringify({
                dragged_id: draggedId,
                target_id: targetId,
                position: position,
                csrf_token: '<?php echo $_SESSION['admin_csrf']; ?>'
            })
        })
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                showAlert('success', res.message);
                // Reload page after brief delay to re-render tree beautifully
                setTimeout(() => {
                    location.href = 'drag_drop_genealogy.php?user_id=' + targetId;
                }, 1500);
            } else {
                showAlert('danger', res.message);
            }
        })
        .catch(err => {
            console.error(err);
            showAlert('danger', 'AJAX request failed. Connection error or server exception.');
        });
    }

    // Display Toast Alert function
    function showAlert(type, message) {
        const placeholder = document.getElementById('alertPlaceholder');
        let iconClass = 'fa-info-circle';
        if (type === 'success') iconClass = 'fa-check-circle';
        if (type === 'danger') iconClass = 'fa-exclamation-triangle';

        placeholder.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show shadow-sm py-2 px-3 small d-flex align-items-center" role="alert">
                <i class="fa ${iconClass} me-2"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto py-2" data-bs-dismiss="alert" aria-label="Close" style="font-size: 8px;"></button>
            </div>
        `;
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
