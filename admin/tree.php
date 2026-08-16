<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';
$ranksList = $config['ranks'] ?? [];

$search = trim($_GET['mid'] ?? ($_GET['search'] ?? ''));
$targetUser = null;

if (!empty($search)) {
    $stmt = $db->prepare("SELECT * FROM users WHERE mid = ? OR username = ? OR id = ? LIMIT 1");
    $stmt->execute([$search, $search, intval($search)]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback to root member (ID 1 or lowest ID)
if (!$targetUser) {
    $stmt = $db->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper to get left and right placement children
function getNodeChildren($db, $parentId) {
    if (!$parentId) return ['left' => null, 'right' => null];
    $stmt = $db->prepare("SELECT * FROM users WHERE placement_id = ?");
    $stmt->execute([$parentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $children = ['left' => null, 'right' => null];
    foreach ($rows as $row) {
        if (strtolower($row['position'] ?? '') === 'left') {
            $children['left'] = $row;
        } elseif (strtolower($row['position'] ?? '') === 'right') {
            $children['right'] = $row;
        }
    }
    return $children;
}

// Build 3-level tree structure
$rootNode = $targetUser;
$l2 = getNodeChildren($db, $rootNode['id'] ?? 0);
$l2Left = $l2['left'];
$l2Right = $l2['right'];

$l3LeftChildren = getNodeChildren($db, $l2Left['id'] ?? 0);
$l3LeftLeft = $l3LeftChildren['left'];
$l3LeftRight = $l3LeftChildren['right'];

$l3RightChildren = getNodeChildren($db, $l2Right['id'] ?? 0);
$l3RightLeft = $l3RightChildren['left'];
$l3RightRight = $l3RightChildren['right'];

$pageTitle = 'Genealogy Binary Tree';
include __DIR__ . '/includes/header.php';
?>

<style>
    .tree-canvas-wrapper {
        position: relative;
        width: 760px;
        margin: 0 auto;
        padding: 20px 0 40px 0;
    }
    .tree-svg-container {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 1;
    }
    .tree-level {
        display: flex;
        justify-content: space-around;
        position: relative;
        z-index: 2;
        margin-bottom: 50px;
    }
    .node-slot {
        width: 140px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .node-card {
        width: 140px;
        background: #fff;
        border: 2px solid #0d6efd;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        padding: 8px;
        text-align: center;
        transition: transform 0.2s;
    }
    .node-card:hover {
        transform: translateY(-3px);
    }
    .node-card.empty {
        border: 2px dashed #adb5bd;
        background: #f8f9fa;
    }
    .node-mid {
        font-size: 11px;
        font-weight: bold;
        color: #0d6efd;
    }
    .node-username {
        font-size: 12px;
        font-weight: 600;
        color: #212529;
        margin: 2px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .node-rank {
        font-size: 10px;
    }
    .node-investment {
        font-size: 11px;
        color: #198754;
        font-weight: bold;
    }
</style>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Genealogy Binary Tree</h3>
        <a href="tree.php" class="btn btn-outline-primary"><i class="fa fa-home me-1"></i>Go to Root Member Tree</a>
    </div>

    <!-- MID Search Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-8">
            <input type="text" name="mid" class="form-control" placeholder="Search Member ID (MID) / Username / ID..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>View Tree</button>
            <a href="tree.php" class="btn btn-outline-secondary w-100">Reset</a>
        </div>
    </form>
</div>

<!-- Current Focused Member Banner -->
<?php if ($rootNode): ?>
<div class="card mb-4 bg-primary text-white">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1"><i class="fa fa-user-circle me-2"></i>Tree Root: <?php echo htmlspecialchars($rootNode['username']); ?> (MID: <?php echo htmlspecialchars($rootNode['mid'] ?? 'N/A'); ?>)</h5>
            <small>Email: <?php echo htmlspecialchars($rootNode['email']); ?> | Status: <?php echo strtoupper($rootNode['status']); ?></small>
        </div>
        <div>
            <span class="badge bg-light text-dark fs-6">$<?php echo number_format($rootNode['total_investment'], 2); ?> Invested</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Binary Tree Canvas -->
<div class="card">
    <div class="card-body overflow-auto text-center">
        <div class="tree-canvas-wrapper" id="treeWrapper">
            <!-- SVG Lines -->
            <svg class="tree-svg-container" id="treeSvg">
                <!-- Lines generated by JS script below -->
            </svg>

            <!-- Level 1 (Root Node) -->
            <div class="tree-level">
                <div class="node-slot" id="slot-l1">
                    <?php renderTreeNode($rootNode, $ranksList); ?>
                </div>
            </div>

            <!-- Level 2 (2 Nodes) -->
            <div class="tree-level">
                <div class="node-slot" id="slot-l2-left">
                    <?php renderTreeNode($l2Left, $ranksList, 'LEFT'); ?>
                </div>
                <div class="node-slot" id="slot-l2-right">
                    <?php renderTreeNode($l2Right, $ranksList, 'RIGHT'); ?>
                </div>
            </div>

            <!-- Level 3 (4 Nodes) -->
            <div class="tree-level">
                <div class="node-slot" id="slot-l3-ll">
                    <?php renderTreeNode($l3LeftLeft, $ranksList, 'LEFT'); ?>
                </div>
                <div class="node-slot" id="slot-l3-lr">
                    <?php renderTreeNode($l3LeftRight, $ranksList, 'RIGHT'); ?>
                </div>
                <div class="node-slot" id="slot-l3-rl">
                    <?php renderTreeNode($l3RightLeft, $ranksList, 'LEFT'); ?>
                </div>
                <div class="node-slot" id="slot-l3-rr">
                    <?php renderTreeNode($l3RightRight, $ranksList, 'RIGHT'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function renderTreeNode($node, $ranksList, $positionLabel = '') {
    if ($node) {
        $rId = intval($node['rank_id'] ?? 0);
        $rankName = ($rId > 0 && isset($ranksList[$rId - 1])) ? $ranksList[$rId - 1]['name'] : 'No Rank';
        ?>
        <div class="node-card">
            <div class="node-mid"><?php echo htmlspecialchars($node['mid'] ?? 'MID'); ?></div>
            <div class="node-username" title="<?php echo htmlspecialchars($node['username']); ?>"><?php echo htmlspecialchars($node['username']); ?></div>
            <div class="node-rank"><span class="badge bg-info text-dark" style="font-size: 9px;"><?php echo htmlspecialchars($rankName); ?></span></div>
            <div class="node-investment">$<?php echo number_format($node['total_investment'] ?? 0, 2); ?></div>
            <span class="badge <?php echo ($node['status'] ?? 'active') === 'active' ? 'bg-success' : 'bg-danger'; ?>" style="font-size: 8px;">
                <?php echo strtoupper($node['status'] ?? 'ACTIVE'); ?>
            </span>
            <a href="tree.php?mid=<?php echo urlencode($node['mid'] ?? $node['id']); ?>" class="btn btn-xs btn-outline-primary py-0 px-1 mt-1 w-100" style="font-size: 9px;">
                Focus Tree
            </a>
        </div>
        <?php
    } else {
        ?>
        <div class="node-card empty">
            <div class="text-muted fw-bold" style="font-size: 10px;"><?php echo htmlspecialchars($positionLabel); ?></div>
            <div class="text-secondary fw-bold" style="font-size: 9px;">VACANT</div>
        </div>
        <?php
    }
}
?>

<script>
    // Draw connecting lines between parent and child nodes
    function drawTreeLines() {
        const svg = document.getElementById('treeSvg');
        const wrapper = document.getElementById('treeWrapper');
        if (!svg || !wrapper) return;

        svg.innerHTML = '';
        const wrapperRect = wrapper.getBoundingClientRect();

        const connections = [
            ['slot-l1', 'slot-l2-left'],
            ['slot-l1', 'slot-l2-right'],
            ['slot-l2-left', 'slot-l3-ll'],
            ['slot-l2-left', 'slot-l3-lr'],
            ['slot-l2-right', 'slot-l3-rl'],
            ['slot-l2-right', 'slot-l3-rr']
        ];

        connections.forEach(([pId, cId]) => {
            const pElem = document.getElementById(pId);
            const cElem = document.getElementById(cId);
            if (!pElem || !cElem) return;

            const pCard = pElem.querySelector('.node-card');
            const cCard = cElem.querySelector('.node-card');
            if (!pCard || !cCard) return;

            const pRect = pCard.getBoundingClientRect();
            const cRect = cCard.getBoundingClientRect();

            const x1 = (pRect.left + pRect.width / 2) - wrapperRect.left;
            const y1 = pRect.bottom - wrapperRect.top;
            const x2 = (cRect.left + cRect.width / 2) - wrapperRect.left;
            const y2 = cRect.top - wrapperRect.top;

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const hy = y1 + (y2 - y1) / 2;
            const d = `M ${x1} ${y1} C ${x1} ${hy}, ${x2} ${hy}, ${x2} ${y2}`;

            path.setAttribute('d', d);
            path.setAttribute('stroke', '#0d6efd');
            path.setAttribute('stroke-width', '2');
            path.setAttribute('fill', 'none');
            if (cCard.classList.contains('empty')) {
                path.setAttribute('stroke-dasharray', '4');
            }
            svg.appendChild(path);
        });
    }

    window.addEventListener('load', drawTreeLines);
    window.addEventListener('resize', drawTreeLines);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
