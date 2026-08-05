<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$loggedInUserId = $_SESSION['user_id'];

// Check if user B is sponsor descendant of user A
function isSponsorDescendant($db, $targetUserId, $loggedInUserId) {
    if ($targetUserId == $loggedInUserId) {
        return true;
    }

    $currentId = $targetUserId;
    $maxDepth = 20;
    while ($currentId !== null && $maxDepth > 0) {
        $stmt = $db->prepare("SELECT sponsor_id FROM users WHERE id = ?");
        $stmt->execute([$currentId]);
        $sponsorId = $stmt->fetchColumn();

        if ($sponsorId == $loggedInUserId) {
            return true;
        }
        $currentId = $sponsorId ?: null;
        $maxDepth--;
    }
    return false;
}

// Check for AJAX request to load children nodes
if (isset($_GET['action']) && $_GET['action'] === 'get_children') {
    header('Content-Type: application/json');
    $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

    if ($parentId <= 0 || !isSponsorDescendant($db, $parentId, $loggedInUserId)) {
        echo json_encode(['html' => '']);
        exit();
    }

    $config = require __DIR__ . '/includes/config.php';
    $ranksList = $config['ranks'];

    // Fetch all direct children of the parent user
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.sponsor_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([$parentId]);
    $children = $stmt->fetchAll();

    $html = '';
    if (!empty($children)) {
        $html .= '<ul>';
        foreach ($children as $user) {
            $statusDot = ($user['status'] == 'active')
                ? '<div class="active-pulse d-inline-block align-middle me-1"></div>'
                : '<span class="d-inline-block align-middle rounded-circle bg-danger me-1" style="width: 10px; height: 10px;"></span>';

            $rankBadge = '';
            if ($user['rank_id'] > 0 && isset($ranksList[$user['rank_id'] - 1])) {
                $rankBadge = '<span class="badge bg-warning text-dark me-1"><i class="fa fa-trophy me-1"></i>' . htmlspecialchars($ranksList[$user['rank_id'] - 1]['name']) . '</span>';
            }

            $toggleBtn = '';
            if ($user['direct_count'] > 0) {
                $toggleBtn = '
                    <button class="btn btn-sm btn-link p-0 text-primary toggle-btn me-2" data-user-id="' . $user['id'] . '" data-loaded="false">
                        <i class="fa-solid fa-square-plus fs-5"></i>
                    </button>
                ';
            } else {
                $toggleBtn = '<span class="me-2" style="width: 20px; display: inline-block;"></span>';
            }

            $html .= '<li>';
            $html .= '
                <div class="d-flex align-items-center gap-2 p-2 border rounded bg-white shadow-sm node-card" style="width: max-content; min-width: 260px;">
                    ' . $toggleBtn . '
                    <div class="position-relative d-inline-block">
                        <i class="fa-solid fa-circle-user fs-3 text-secondary"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;">
                            ' . htmlspecialchars($user['username']) . '
                            <span class="text-muted fw-normal font-monospace" style="font-size: 0.8rem;">(' . htmlspecialchars($user['mid'] ?? 'MID: ' . $user['id']) . ')</span>
                        </div>
                        <div class="text-secondary small" style="font-size: 0.8rem; margin-top: -2px;">' . htmlspecialchars($user['full_name'] ?? '') . '</div>
                        <div class="d-flex gap-1 mt-1 align-items-center flex-wrap">
                            ' . $statusDot . '
                            <span class="badge bg-light text-dark border">Pkg: $' . number_format($user['total_investment'], 0) . '</span>
                            ' . $rankBadge . '
                            <span class="badge bg-info"><i class="fa fa-users me-1"></i>' . $user['direct_count'] . ' Directs</span>
                        </div>
                    </div>
                </div>
                <div id="children-' . $user['id'] . '" class="tree-children-list" style="display: none;"></div>
            ';
            $html .= '</li>';
        }
        $html .= '</ul>';
    }

    echo json_encode(['html' => $html]);
    exit();
}

$config = require __DIR__ . '/includes/config.php';
$ranksList = $config['ranks'];

$searchQuery = $_GET['search'] ?? '';
$errorMsg = '';
$rootUser = null;

if (!empty($searchQuery)) {
    // Search for user by MID or Username or ID
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.username = ? OR u.mid = ? OR u.id = ?
    ");
    $stmt->execute([$searchQuery, $searchQuery, $searchQuery]);
    $searchedUser = $stmt->fetch();

    if ($searchedUser && isSponsorDescendant($db, $searchedUser['id'], $loggedInUserId)) {
        $rootUser = $searchedUser;
    } else {
        $errorMsg = "Member '" . htmlspecialchars($searchQuery) . "' was not found or is not part of your downline tree.";
        // Fallback to logged in user
        $stmtFallback = $db->prepare("
            SELECT u.*,
                   (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
            FROM users u
            WHERE u.id = ?
        ");
        $stmtFallback->execute([$loggedInUserId]);
        $rootUser = $stmtFallback->fetch();
    }
} else {
    // Default: find logged in user
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_count
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->execute([$loggedInUserId]);
    $rootUser = $stmt->fetch();
}

$pageTitle = 'Sponsor Tree';
include __DIR__ . '/includes/header.php';
?>

<style>
    /* Modern Tree CSS */
    .tree-view ul {
        list-style-type: none;
        position: relative;
        padding-left: 30px;
        margin: 0;
    }
    .tree-view li {
        position: relative;
        margin: 10px 0;
        padding-left: 5px;
    }
    /* Left dashed line connecting vertical children */
    .tree-view ul::before {
        content: "";
        position: absolute;
        top: 0;
        left: 10px;
        bottom: 0;
        border-left: 2px dashed #cbd5e1;
        width: 1px;
    }
    /* Horizontal dashed line connecting child card to vertical parent line */
    .tree-view li::before {
        content: "";
        position: absolute;
        top: 24px;
        left: -20px;
        width: 20px;
        height: 1px;
        border-top: 2px dashed #cbd5e1;
    }
    /* Stop the vertical dashed line at the last child of the list */
    .tree-view li:last-child::after {
        content: "";
        position: absolute;
        top: 24px;
        left: -20px;
        bottom: 0;
        width: 1px;
        background: #f8f9fa; /* Matches the body background to block the line */
    }

    /* Ensure the main tree block container doesn't have a connector to nothing */
    .tree-view > ul::before {
        display: none;
    }
    .tree-view > ul > li::before {
        display: none;
    }
    .tree-view > ul > li::after {
        display: none;
    }

    .node-card {
        transition: all 0.2s ease-in-out;
        border: 1px solid #e2e8f0;
        z-index: 2;
    }
    .node-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
        border-color: #3b82f6;
    }

    /* Pulse animation for active status */
    .active-pulse {
        width: 10px;
        height: 10px;
        background-color: #10b981;
        border-radius: 50%;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse 1.6s infinite;
    }
    @keyframes pulse {
        0% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        }
        70% {
            transform: scale(1);
            box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
        }
        100% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
        }
    }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-4">
                <div class="card-header border-0 bg-transparent p-0 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h4 class="card-title mb-0">My Sponsor Tree</h4>
                        <p class="text-muted small mb-0">Explore and visualize your direct and indirect referral downline structure dynamically.</p>
                    </div>
                </div>

                <?php if (!empty($errorMsg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fa fa-exclamation-circle me-1"></i> <?php echo $errorMsg; ?>
                    </div>
                <?php endif; ?>

                <!-- Search Bar & Controls -->
                <div class="mb-4 bg-light p-3 rounded">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-6 col-lg-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa fa-search"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" placeholder="Search downline by Username or MID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4 btn-sm" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none;"><i class="fa fa-filter me-1"></i>Explore</button>
                            <?php if (!empty($searchQuery)): ?>
                                <a href="sponsor_tree.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-sync-alt me-1"></i>Reset to My Root</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Main Tree Visualizer -->
                <div class="border rounded bg-light p-4 overflow-auto" style="min-height: 400px; max-height: 750px;">
                    <?php if ($rootUser): ?>
                        <div class="tree-view">
                            <ul>
                                <li>
                                    <!-- Parent Root Node -->
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded bg-white shadow-sm node-card" style="width: max-content; min-width: 260px; border-left: 4px solid #3b82f6;">
                                        <?php if ($rootUser['direct_count'] > 0): ?>
                                            <button class="btn btn-sm btn-link p-0 text-primary toggle-btn me-2" data-user-id="<?php echo $rootUser['id']; ?>" data-loaded="false">
                                                <i class="fa-solid fa-square-plus fs-5"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="me-2" style="width: 20px; display: inline-block;"></span>
                                        <?php endif; ?>
                                        <div class="position-relative d-inline-block">
                                            <i class="fa-solid fa-crown fs-3 text-warning"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;">
                                                <?php echo htmlspecialchars($rootUser['username']); ?>
                                                <span class="text-muted fw-normal font-monospace" style="font-size: 0.8rem;">(<?php echo htmlspecialchars($rootUser['mid'] ?? 'MID: ' . $rootUser['id']); ?>)</span>
                                            </div>
                                            <div class="text-secondary small" style="font-size: 0.8rem; margin-top: -2px;"><?php echo htmlspecialchars($rootUser['full_name'] ?? ''); ?></div>
                                            <div class="d-flex gap-1 mt-1 align-items-center flex-wrap">
                                                <?php if ($rootUser['status'] == 'active'): ?>
                                                    <div class="active-pulse d-inline-block align-middle me-1"></div>
                                                <?php else: ?>
                                                    <span class="d-inline-block align-middle rounded-circle bg-danger me-1" style="width: 10px; height: 10px;"></span>
                                                <?php endif; ?>
                                                <span class="badge bg-light text-dark border">Pkg: $<?php echo number_format($rootUser['total_investment'], 0); ?></span>
                                                <?php if ($rootUser['rank_id'] > 0 && isset($ranksList[$rootUser['rank_id'] - 1])): ?>
                                                    <span class="badge bg-warning text-dark me-1"><i class="fa fa-trophy me-1"></i><?php echo htmlspecialchars($ranksList[$rootUser['rank_id'] - 1]['name']); ?></span>
                                                <?php endif; ?>
                                                <span class="badge bg-info"><i class="fa fa-users me-1"></i><?php echo $rootUser['direct_count']; ?> Directs</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Container to load children of Root user -->
                                    <div id="children-<?php echo $rootUser['id']; ?>" class="tree-children-list" style="display: none;"></div>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fa fa-users text-muted mb-3" style="font-size: 4rem;"></i>
                            <h5 class="text-secondary">No members found in the system.</h5>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.toggle-btn');
        if (!btn) return;

        e.preventDefault();
        var userId = btn.getAttribute('data-user-id');
        var loaded = btn.getAttribute('data-loaded') === 'true';
        var container = document.getElementById('children-' + userId);

        if (!loaded) {
            // Load children via AJAX
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fs-5 text-secondary"></i>';
            fetch('sponsor_tree.php?action=get_children&parent_id=' + userId)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    container.innerHTML = data.html;
                    btn.setAttribute('data-loaded', 'true');
                    btn.innerHTML = '<i class="fa-solid fa-square-minus fs-5 text-secondary"></i>';
                    container.style.display = 'block';
                })
                .catch(function(error) {
                    btn.innerHTML = '<i class="fa-solid fa-square-plus fs-5 text-danger"></i>';
                    alert('Error loading downline.');
                });
        } else {
            // Toggle visibility
            if (container.style.display !== 'none') {
                container.style.display = 'none';
                btn.innerHTML = '<i class="fa-solid fa-square-plus fs-5 text-primary"></i>';
            } else {
                container.style.display = 'block';
                btn.innerHTML = '<i class="fa-solid fa-square-minus fs-5 text-secondary"></i>';
            }
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
