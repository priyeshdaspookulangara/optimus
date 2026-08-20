<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/includes/config.php';

$treeType = isset($_GET['type']) && $_GET['type'] === 'placement' ? 'placement' : 'sponsor';
$searchMid = isset($_GET['mid']) ? trim($_GET['mid']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
$maxLevels = isset($_GET['levels']) ? max(1, min(10, (int)$_GET['levels'])) : 5;

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

function buildRecursiveTreeData($db, $userId, $treeType, $config, &$visited = [], $currentLevel = 1, $maxLevels = 5) {
    if (isset($visited[$userId])) {
        return null; // Prevents infinite recursion in case of cyclic data
    }
    $visited[$userId] = true;

    $node = fetchUserNodeData($db, $userId, $config);
    if (!$node) return null;

    $node['level'] = $currentLevel;
    $node['children'] = [];

    // Strict limit to maxLevels depth
    if ($currentLevel < $maxLevels) {
        if ($treeType === 'placement') {
            $stmt = $db->prepare("SELECT id FROM users WHERE placement_id = ? ORDER BY CASE WHEN position = 'left' THEN 1 WHEN position = 'right' THEN 2 ELSE 3 END, id ASC");
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE sponsor_id = ? ORDER BY id ASC");
        }
        $stmt->execute([$userId]);
        $childIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($childIds as $childId) {
            $childNode = buildRecursiveTreeData($db, $childId, $treeType, $config, $visited, $currentLevel + 1, $maxLevels);
            if ($childNode) {
                $node['children'][] = $childNode;
            }
        }
    }

    return $node;
}

$visitedArr = [];
$treeData = $rootUser ? buildRecursiveTreeData($db, $rootUser['id'], $treeType, $config, $visitedArr, 1, $maxLevels) : null;

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
    $levelDisplay = isset($node['level']) ? 'L' . $node['level'] : '';
    $positionDisplay = !empty($node['position']) ? ' <span class="badge position-badge">' . ucfirst(htmlspecialchars($node['position'])) . '</span>' : '';
    $idAttr = $isRoot ? ' id="rootNodeCard"' : '';

    $hasChildren = !empty($node['children']);
    $liClass = $hasChildren ? 'has-children' : 'leaf-node';

    $html = '<li class="' . $liClass . '">';
    $html .= '<div class="node-card-wrapper">';
    $html .= '  <div class="node-card"' . $idAttr . ' onclick="focusNode(\'' . htmlspecialchars($midRaw, ENT_QUOTES) . '\')" title="Click to make this user root">';
    $html .= '    <div class="card-top-bar">';
    $html .= '      <div><span class="level-badge me-1">' . $levelDisplay . '</span><span class="mid-badge"><i class="fa-solid fa-id-badge me-1"></i>' . $midDisplay . '</span></div>';
    $html .=       $positionDisplay;
    $html .= '    </div>';
    $html .= '    <div class="user-name"><i class="fa-solid fa-user me-1 text-primary"></i>' . $usernameDisplay . '</div>';
    $html .= '    <div class="info-line"><span class="info-label"><i class="fa-solid fa-box me-1 text-warning"></i>Package:</span> <span class="info-value package-text">' . $packageDisplay . '</span></div>';
    $html .= '    <div class="info-line"><span class="info-label"><i class="fa-solid fa-award me-1 text-success"></i>Rank:</span> <span class="info-value rank-text">' . $rankDisplay . '</span></div>';
    $html .= '  </div>';
    $html .= '</div>';

    if ($hasChildren) {
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
    <!-- Google Fonts Poppins, Bootstrap 5 & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Global Canvas Styling matching Dashboard Theme */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background-color: #f4f5f8;
            color: #2b2b2b;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            overflow: hidden;
        }

        /* Top Toolbar matching App Brand (#3f2259 & #cca354) */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 65px;
            background-color: #3f2259;
            border-bottom: 3px solid #cca354;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .toolbar-title {
            font-size: 18px;
            font-weight: 700;
            color: #ffffff;
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
            background-color: #f4f5f8;
            background-image: radial-gradient(#d1d5db 1px, transparent 1px);
            background-size: 20px 20px;
            cursor: grab;
        }

        .canvas-container:active {
            cursor: grabbing;
        }

        /* Viewport that holds the tree structure */
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

        /* Pure CSS Tree Architecture - Strict Top-to-Bottom Levels */
        .tree {
            display: inline-block;
            width: max-content;
            margin: 0 auto;
            white-space: nowrap;
            text-align: center;
        }

        .tree ul {
            padding-top: 20px;
            position: relative;
            transition: all 0.3s;
            display: flex;
            justify-content: center;
            margin: 0;
            padding-left: 0;
        }

        .tree li {
            text-align: center;
            list-style-type: none;
            position: relative;
            padding: 20px 12px 0 12px;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Connecting Lines */
        .tree li::before, .tree li::after {
            content: '';
            position: absolute;
            top: 0;
            right: 50%;
            border-top: 2px solid #3f2259;
            width: 50%;
            height: 20px;
        }

        .tree li::after {
            right: auto;
            left: 50%;
            border-left: 2px solid #3f2259;
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
            border-right: 2px solid #3f2259;
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
            border-left: 2px solid #3f2259;
            width: 0;
            height: 20px;
        }

        /* Downward connector line from parent card to children ul */
        .node-card-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .has-children > .node-card-wrapper::after {
            content: '';
            width: 0;
            height: 20px;
            border-left: 2px solid #3f2259;
            display: block;
        }

        /* Node Card Styling - Dashboard Theme */
        .node-card {
            display: inline-block;
            border: 1px solid #dedede;
            border-left: 4px solid #3f2259;
            border-top: 3px solid #cca354;
            padding: 12px 16px;
            text-decoration: none;
            color: #2b2b2b;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 6px 16px rgba(63,34,89,0.08);
            transition: all 0.25s ease;
            width: 220px;
            text-align: left;
            white-space: normal;
            cursor: pointer;
            position: relative;
            z-index: 2;
        }

        .node-card:hover {
            background: #ffffff;
            border-color: #cca354;
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(63,34,89,0.18);
        }

        .card-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .mid-badge {
            background: #3f2259;
            color: #ffffff;
            padding: 2px 8px;
            border-radius: 5px;
            font-weight: 700;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .level-badge {
            background: #cca354;
            color: #ffffff;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 10px;
        }

        .position-badge {
            font-size: 10px;
            padding: 2px 6px;
            background-color: #e9ecef;
            color: #495057;
        }

        .user-name {
            font-size: 15px;
            font-weight: 700;
            color: #3f2259;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .info-line {
            font-size: 12px;
            margin-top: 4px;
            line-height: 1.4;
            color: #495057;
        }

        .info-label {
            color: #6c757d;
            font-weight: 600;
        }

        .info-value {
            font-weight: 600;
        }

        .package-text {
            color: #3f2259;
            font-weight: 700;
        }

        .rank-text {
            color: #198754;
            font-weight: 700;
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
            background: rgba(255,255,255,0.15);
            padding: 4px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .btn-zoom {
            background: #ffffff;
            color: #3f2259;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .btn-zoom:hover {
            background: #cca354;
            color: #ffffff;
        }

        .btn-dashboard-link {
            background: #cca354;
            color: #ffffff;
            border: none;
            font-weight: 600;
        }

        .btn-dashboard-link:hover {
            background: #b58d3f;
            color: #ffffff;
        }

        /* Custom Scrollbar */
        .canvas-container::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }
        .canvas-container::-webkit-scrollbar-track {
            background: #e9ecef;
        }
        .canvas-container::-webkit-scrollbar-thumb {
            background: #3f2259;
            border-radius: 6px;
            border: 3px solid #e9ecef;
        }
        .canvas-container::-webkit-scrollbar-thumb:hover {
            background: #cca354;
        }
    </style>
</head>
<body>

    <!-- Sticky Header Control Bar -->
    <div class="toolbar">
        <div class="toolbar-title">
            <i class="fa-solid fa-sitemap text-warning"></i>
            <span>Downline Tree</span>
            <span class="badge rounded-pill px-3 py-2 ms-2" style="font-size: 12px; background: #cca354; color: #fff;">
                <i class="fa-solid fa-users me-1"></i> Visible Members: <?php echo $totalNodes; ?>
            </span>
        </div>

        <form method="GET" action="printree.php" class="control-group">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($treeType); ?>">
            <div class="input-group input-group-sm" style="width: 240px;">
                <span class="input-group-text bg-white text-dark border-0"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" name="mid" class="form-control bg-white text-dark border-0" placeholder="Enter MID or Username..." value="<?php echo htmlspecialchars($searchMid); ?>">
                <button type="submit" class="btn text-white" style="background: #cca354;"><i class="fa-solid fa-arrow-right"></i></button>
            </div>

            <!-- Depth / Levels Selector -->
            <select name="levels" class="form-select form-select-sm bg-white text-dark border-0" style="width: 105px; font-weight: 600;" onchange="this.form.submit()" title="Select Tree Depth">
                <option value="1" <?php echo $maxLevels == 1 ? 'selected' : ''; ?>>1 Level</option>
                <option value="2" <?php echo $maxLevels == 2 ? 'selected' : ''; ?>>2 Levels</option>
                <option value="3" <?php echo $maxLevels == 3 ? 'selected' : ''; ?>>3 Levels</option>
                <option value="4" <?php echo $maxLevels == 4 ? 'selected' : ''; ?>>4 Levels</option>
                <option value="5" <?php echo $maxLevels == 5 ? 'selected' : ''; ?>>5 Levels</option>
                <option value="6" <?php echo $maxLevels == 6 ? 'selected' : ''; ?>>6 Levels</option>
                <option value="7" <?php echo $maxLevels == 7 ? 'selected' : ''; ?>>7 Levels</option>
                <option value="8" <?php echo $maxLevels == 8 ? 'selected' : ''; ?>>8 Levels</option>
                <option value="9" <?php echo $maxLevels == 9 ? 'selected' : ''; ?>>9 Levels</option>
                <option value="10" <?php echo $maxLevels == 10 ? 'selected' : ''; ?>>10 Levels</option>
            </select>

            <!-- Tree Type Toggle -->
            <div class="btn-group btn-group-sm me-2" role="group">
                <a href="printree.php?mid=<?php echo urlencode($searchMid); ?>&type=sponsor&levels=<?php echo $maxLevels; ?>" class="btn <?php echo $treeType === 'sponsor' ? 'text-white' : 'btn-outline-light'; ?>" style="<?php echo $treeType === 'sponsor' ? 'background: #cca354;' : ''; ?>">
                    <i class="fa-solid fa-diagram-next me-1"></i> Sponsor
                </a>
                <a href="printree.php?mid=<?php echo urlencode($searchMid); ?>&type=placement&levels=<?php echo $maxLevels; ?>" class="btn <?php echo $treeType === 'placement' ? 'text-white' : 'btn-outline-light'; ?>" style="<?php echo $treeType === 'placement' ? 'background: #cca354;' : ''; ?>">
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
                <a href="admin/dashboard.php" class="btn btn-sm btn-dashboard-link"><i class="fa-solid fa-shield-halved me-1"></i> Admin Panel</a>
            <?php elseif (isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="btn btn-sm btn-dashboard-link"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a>
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
                    <a href="printree.php" class="btn text-white mt-2" style="background: #3f2259;"><i class="fa-solid fa-house me-1"></i> Reset to Root Tree</a>
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
