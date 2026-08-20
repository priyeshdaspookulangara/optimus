<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/includes/config.php';

$treeType = isset($_GET['type']) && $_GET['type'] === 'placement' ? 'placement' : 'sponsor';
$searchMid = isset($_GET['mid']) ? trim($_GET['mid']) : (isset($_GET['search']) ? trim($_GET['search']) : '');

// Locate starting root node
$rootUser = null;

if (!empty($searchMid)) {
    $stmt = $db->prepare("SELECT id, mid, username FROM users WHERE mid = ? OR username = ? OR id = ? LIMIT 1");
    $stmt->execute([$searchMid, $searchMid, $searchMid]);
    $rootUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$rootUser) {
    // Default to top root user (sponsor_id IS NULL or lowest ID)
    $stmt = $db->query("SELECT id, mid, username FROM users WHERE sponsor_id IS NULL ORDER BY id ASC LIMIT 1");
    $rootUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rootUser) {
        $stmt = $db->query("SELECT id, mid, username FROM users ORDER BY id ASC LIMIT 1");
        $rootUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function fetchUserNodeData($db, $userId, $config) {
    $stmt = $db->prepare("
        SELECT u.id, u.mid, u.username, u.rank_id, u.total_investment, u.status, u.created_at, u.position,
               (
                   SELECT p.name
                   FROM investments i
                   JOIN packages p ON i.package_id = p.id
                   WHERE i.user_id = u.id
                   ORDER BY i.amount DESC, i.id ASC LIMIT 1
               ) as package_name,
               (
                   SELECT i.amount
                   FROM investments i
                   WHERE i.user_id = u.id
                   ORDER BY i.amount DESC, i.id ASC LIMIT 1
               ) as package_amount
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;

    // Resolve Rank
    $rankName = 'None';
    if (!empty($user['rank_id']) && $user['rank_id'] > 0 && isset($config['ranks'][$user['rank_id'] - 1])) {
        $rankName = $config['ranks'][$user['rank_id'] - 1]['name'];
    }

    // Resolve Joining Package
    if (!empty($user['package_name'])) {
        $joiningPackage = $user['package_name'] . ' ($' . number_format($user['package_amount'], 2) . ')';
    } elseif ($user['total_investment'] > 0) {
        $joiningPackage = 'Package $' . number_format($user['total_investment'], 2);
    } else {
        $joiningPackage = 'No Package ($0.00)';
    }

    $user['rank_name'] = $rankName;
    $user['joining_package'] = $joiningPackage;
    return $user;
}

function buildRecursiveTreeData($db, $userId, $treeType, $config, &$visited = []) {
    if (isset($visited[$userId])) {
        return null; // Prevents infinite recursion in case of cyclic data
    }
    $visited[$userId] = true;

    $node = fetchUserNodeData($db, $userId, $config);
    if (!$node) return null;

    if ($treeType === 'placement') {
        $stmt = $db->prepare("SELECT id FROM users WHERE placement_id = ? ORDER BY CASE WHEN position = 'left' THEN 1 WHEN position = 'right' THEN 2 ELSE 3 END, id ASC");
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE sponsor_id = ? ORDER BY id ASC");
    }
    $stmt->execute([$userId]);
    $childIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $node['children'] = [];
    foreach ($childIds as $childId) {
        $childNode = buildRecursiveTreeData($db, $childId, $treeType, $config, $visited);
        if ($childNode) {
            $node['children'][] = $childNode;
        }
    }

    return $node;
}

$treeData = $rootUser ? buildRecursiveTreeData($db, $rootUser['id'], $treeType, $config) : null;

function countSubtreeNodes($node) {
    if (!$node) return 0;
    $count = 1;
    if (!empty($node['children'])) {
        foreach ($node['children'] as $child) {
            $count += countSubtreeNodes($child);
        }
    }
    return $count;
}

$totalNodes = countSubtreeNodes($treeData);

function renderTreeHtml($node, $treeType, $isRoot = false) {
    if (!$node) return '';

    $midRaw = !empty($node['mid']) ? $node['mid'] : $node['id'];
    $midDisplay = !empty($node['mid']) ? htmlspecialchars($node['mid']) : 'ID: ' . $node['id'];
    $usernameDisplay = htmlspecialchars($node['username']);
    $packageDisplay = htmlspecialchars($node['joining_package']);
    $rankDisplay = htmlspecialchars($node['rank_name']);
    $positionDisplay = !empty($node['position']) ? ' <span class="badge bg-secondary position-badge">' . ucfirst(htmlspecialchars($node['position'])) . '</span>' : '';
    $idAttr = $isRoot ? ' id="rootNodeCard"' : '';

    $html = '<li>';
    $html .= '<div class="node-card"' . $idAttr . ' onclick="focusNode(\'' . htmlspecialchars($midRaw, ENT_QUOTES) . '\')" title="Click to make this user root">';
    $html .= '  <div class="card-top-bar">';
    $html .= '    <span class="mid-badge"><i class="fa-solid fa-id-badge me-1"></i>' . $midDisplay . '</span>';
    $html .=     $positionDisplay;
    $html .= '  </div>';
    $html .= '  <div class="user-name"><i class="fa-solid fa-user me-1 text-info"></i>' . $usernameDisplay . '</div>';
    $html .= '  <div class="info-line"><span class="info-label"><i class="fa-solid fa-box me-1 text-warning"></i>Package:</span> <span class="info-value package-text">' . $packageDisplay . '</span></div>';
    $html .= '  <div class="info-line"><span class="info-label"><i class="fa-solid fa-award me-1 text-success"></i>Rank:</span> <span class="info-value rank-text">' . $rankDisplay . '</span></div>';
    $html .= '</div>';

    if (!empty($node['children'])) {
        $html .= '<ul>';
        foreach ($node['children'] as $child) {
            $html .= renderTreeHtml($child, $treeType, false);
        }
        $html .= '</ul>';
    }

    $html .= '</li>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recursive Downline Tree Diagram</title>
    <!-- Bootstrap 5 & FontAwesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Global Canvas Styling: Non-responsive, infinite expansion in horizontal & vertical dimensions */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background-color: #0d1117;
            color: #c9d1d9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            overflow: hidden;
        }

        /* Top Toolbar */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 65px;
            background-color: #161b22;
            border-bottom: 1px solid #30363d;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        }

        .toolbar-title {
            font-size: 18px;
            font-weight: 700;
            color: #58a6ff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Scrollable Canvas Container */
        .canvas-container {
            position: absolute;
            top: 65px;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: auto;
            background-color: #0d1117;
            cursor: grab;
        }

        .canvas-container:active {
            cursor: grabbing;
        }

        /* Viewport that holds the tree structure - strictly non-responsive, expanding as large as needed */
        .tree-viewport {
            display: inline-block;
            width: max-content;
            min-width: 100%;
            height: max-content;
            min-height: 100%;
            padding: 60px 100px 120px 100px;
            box-sizing: border-box;
            text-align: center;
            transform-origin: top center;
            transition: transform 0.15s ease-out;
        }

        /* Pure CSS Tree Architecture */
        .tree {
            display: inline-block;
            width: max-content;
            margin: 0 auto;
            white-space: nowrap;
            text-align: center;
        }

        .tree ul {
            padding-top: 25px;
            position: relative;
            transition: all 0.3s;
            display: inline-flex;
            justify-content: center;
            margin: 0;
            padding-left: 0;
        }

        .tree li {
            text-align: center;
            list-style-type: none;
            position: relative;
            padding: 25px 12px 0 12px;
            transition: all 0.3s;
            display: inline-block;
            vertical-align: top;
        }

        /* Connecting Lines */
        .tree li::before, .tree li::after {
            content: '';
            position: absolute;
            top: 0;
            right: 50%;
            border-top: 2px solid #30363d;
            width: 50%;
            height: 25px;
        }

        .tree li::after {
            right: auto;
            left: 50%;
            border-left: 2px solid #30363d;
        }

        .tree li:only-child::after, .tree li:only-child::before {
            display: none;
        }

        .tree li:only-child {
            padding-top: 0;
        }

        .tree li:first-child::before, .tree li:last-child::after {
            border: 0 none;
        }

        .tree li:last-child::before {
            border-right: 2px solid #30363d;
            border-radius: 0 6px 0 0;
        }

        .tree li:first-child::after {
            border-radius: 6px 0 0 0;
        }

        .tree ul ul::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            border-left: 2px solid #30363d;
            width: 0;
            height: 25px;
        }

        /* Node Box Styling */
        .node-card {
            display: inline-block;
            border: 2px solid #30363d;
            padding: 12px 16px;
            text-decoration: none;
            color: #c9d1d9;
            border-radius: 10px;
            background: #161b22;
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
            transition: all 0.25s ease;
            width: 210px;
            text-align: left;
            white-space: normal;
            cursor: pointer;
            position: relative;
            z-index: 2;
        }

        .node-card:hover {
            background: #21262d;
            border-color: #58a6ff;
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(88, 166, 255, 0.25);
        }

        .card-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .mid-badge {
            background: #1f6feb;
            color: #ffffff;
            padding: 2px 8px;
            border-radius: 5px;
            font-weight: 700;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .position-badge {
            font-size: 10px;
            padding: 2px 6px;
        }

        .user-name {
            font-size: 15px;
            font-weight: 700;
            color: #f0f6fc;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .info-line {
            font-size: 12px;
            margin-top: 4px;
            line-height: 1.4;
        }

        .info-label {
            color: #8b949e;
            font-weight: 600;
        }

        .info-value {
            font-weight: 600;
        }

        .package-text {
            color: #f2cc60;
        }

        .rank-text {
            color: #3fb950;
        }

        /* Search & Control Forms */
        .control-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .zoom-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            background: #21262d;
            padding: 4px;
            border-radius: 6px;
            border: 1px solid #30363d;
        }

        .btn-zoom {
            background: #30363d;
            color: #c9d1d9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .btn-zoom:hover {
            background: #58a6ff;
            color: #ffffff;
        }

        /* Custom Scrollbar for large trees */
        .canvas-container::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }
        .canvas-container::-webkit-scrollbar-track {
            background: #0d1117;
        }
        .canvas-container::-webkit-scrollbar-thumb {
            background: #30363d;
            border-radius: 6px;
            border: 3px solid #0d1117;
        }
        .canvas-container::-webkit-scrollbar-thumb:hover {
            background: #58a6ff;
        }
    </style>
</head>
<body>

    <!-- Sticky Header Control Bar -->
    <div class="toolbar">
        <div class="toolbar-title">
            <i class="fa-solid fa-sitemap"></i>
            <span>Recursive Downline Tree</span>
            <span class="badge bg-primary rounded-pill px-3 py-2 ms-2" style="font-size: 12px;">
                <i class="fa-solid fa-users me-1"></i> Subtree Members: <?php echo $totalNodes; ?>
            </span>
        </div>

        <form method="GET" action="printree.php" class="control-group">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($treeType); ?>">
            <div class="input-group input-group-sm" style="width: 260px;">
                <span class="input-group-text bg-dark text-secondary border-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" name="mid" class="form-control bg-dark text-white border-secondary" placeholder="Enter MID or Username..." value="<?php echo htmlspecialchars($searchMid); ?>">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-arrow-right"></i></button>
            </div>

            <!-- Tree Type Toggle -->
            <div class="btn-group btn-group-sm me-2" role="group">
                <a href="printree.php?mid=<?php echo urlencode($searchMid); ?>&type=sponsor" class="btn <?php echo $treeType === 'sponsor' ? 'btn-success' : 'btn-outline-secondary text-white'; ?>">
                    <i class="fa-solid fa-diagram-next me-1"></i> Sponsor
                </a>
                <a href="printree.php?mid=<?php echo urlencode($searchMid); ?>&type=placement" class="btn <?php echo $treeType === 'placement' ? 'btn-success' : 'btn-outline-secondary text-white'; ?>">
                    <i class="fa-solid fa-network-wired me-1"></i> Placement
                </a>
            </div>
        </form>

        <div class="control-group">
            <div class="zoom-controls">
                <button class="btn-zoom" onclick="centerOnRoot()" title="Center Root Node"><i class="fa-solid fa-crosshairs"></i></button>
                <button class="btn-zoom" onclick="zoomIn()" title="Zoom In"><i class="fa-solid fa-plus"></i></button>
                <button class="btn-zoom" onclick="zoomReset()" title="Reset Zoom"><i class="fa-solid fa-rotate-left"></i></button>
                <button class="btn-zoom" onclick="zoomOut()" title="Zoom Out"><i class="fa-solid fa-minus"></i></button>
            </div>
            <?php if (isset($_SESSION['admin_id'])): ?>
                <a href="admin/dashboard.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-shield-halved me-1"></i> Admin Panel</a>
            <?php elseif (isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scrollable Horizontal & Vertical Tree Canvas -->
    <div class="canvas-container" id="canvasContainer">
        <div class="tree-viewport" id="treeViewport">
            <?php if ($treeData): ?>
                <div class="tree">
                    <ul>
                        <?php echo renderTreeHtml($treeData, $treeType, true); ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="text-center text-muted mt-5">
                    <i class="fa-solid fa-circle-exclamation fa-3x mb-3 text-warning"></i>
                    <h4>No member found for the specified MID or search term.</h4>
                    <a href="printree.php" class="btn btn-primary mt-2"><i class="fa-solid fa-house me-1"></i> Reset to Root Tree</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let currentScale = 1.0;
        const viewport = document.getElementById('treeViewport');
        const container = document.getElementById('canvasContainer');

        function updateZoom() {
            viewport.style.transform = `scale(${currentScale})`;
        }

        function zoomIn() {
            currentScale = Math.min(currentScale + 0.15, 2.5);
            updateZoom();
        }

        function zoomOut() {
            currentScale = Math.max(currentScale - 0.15, 0.2);
            updateZoom();
        }

        function zoomReset() {
            currentScale = 1.0;
            updateZoom();
            centerOnRoot();
        }

        function centerOnRoot() {
            const rootCard = document.getElementById('rootNodeCard');
            if (container && rootCard) {
                const containerRect = container.getBoundingClientRect();
                const rootRect = rootCard.getBoundingClientRect();

                const scrollOffset = (rootRect.left + container.scrollLeft + rootCard.offsetWidth / 2) - (containerRect.width / 2);
                container.scrollLeft = Math.max(0, scrollOffset);
                container.scrollTop = 0;
            }
        }

        window.addEventListener('load', () => {
            setTimeout(centerOnRoot, 100);
        });

        function focusNode(mid) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('mid', mid);
            window.location.search = urlParams.toString();
        }

        // Click and drag panning on canvas
        let isMouseDown = false;
        let startX, startY, scrollLeft, scrollTop;

        container.addEventListener('mousedown', (e) => {
            if (e.target.closest('.node-card') || e.target.closest('.toolbar')) return;
            isMouseDown = true;
            startX = e.pageX - container.offsetLeft;
            startY = e.pageY - container.offsetTop;
            scrollLeft = container.scrollLeft;
            scrollTop = container.scrollTop;
        });

        container.addEventListener('mouseleave', () => {
            isMouseDown = false;
        });

        container.addEventListener('mouseup', () => {
            isMouseDown = false;
        });

        container.addEventListener('mousemove', (e) => {
            if (!isMouseDown) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const y = e.pageY - container.offsetTop;
            const walkX = (x - startX) * 1.5;
            const walkY = (y - startY) * 1.5;
            container.scrollLeft = scrollLeft - walkX;
            container.scrollTop = scrollTop - walkY;
        });
    </script>
</body>
</html>
