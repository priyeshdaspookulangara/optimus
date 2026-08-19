<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check before running operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();

$pageTitle = 'Members with Unmarked Positions';
include __DIR__ . '/includes/header.php';

$search = trim($_GET['search'] ?? '');

// Build SQL query for members with position NOT marked
$query = "
    SELECT
        u.id,
        u.mid,
        u.username,
        u.created_at,
        u.total_investment,
        u.position,
        p_parent.mid AS placement_mid,
        p_parent.username AS placement_username,
        s_parent.mid AS sponsor_mid,
        s_parent.username AS sponsor_username,
        GROUP_CONCAT(DISTINCT pkg.name ORDER BY pkg.amount ASC SEPARATOR ', ') AS package_names,
        GROUP_CONCAT(DISTINCT pkg.amount ORDER BY pkg.amount ASC SEPARATOR ', ') AS package_amounts
    FROM users u
    LEFT JOIN users p_parent ON u.placement_id = p_parent.id
    LEFT JOIN users s_parent ON u.sponsor_id = s_parent.id
    LEFT JOIN investments inv ON u.id = inv.user_id
    LEFT JOIN packages pkg ON inv.package_id = pkg.id
    WHERE (u.position IS NULL OR u.position = '' OR u.position NOT IN ('left', 'right'))
";

$params = [];

if ($search !== '') {
    $query .= " AND (u.username LIKE ? OR u.mid LIKE ? OR p_parent.username LIKE ? OR p_parent.mid LIKE ? OR s_parent.username LIKE ? OR s_parent.mid LIKE ?)";
    $searchParam = "%{$search}%";
    $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
}

$query .= " GROUP BY u.id ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll();
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Unmarked Position Members</h3>
            <small class="text-muted">List of all registered members whose binary position ('left' / 'right') is not yet assigned.</small>
        </div>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2">
            <i class="fa fa-exclamation-triangle me-1"></i> Total Unmarked: <?php echo count($members); ?>
        </span>
    </div>

    <!-- Filter Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-9">
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search by MID, Username, or Parent MID / Username..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="unmarked_positions.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>MID</th>
                        <th>Username</th>
                        <th>Parent MID</th>
                        <th>Parent Username</th>
                        <th>Date of Join</th>
                        <th>Package</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="fa fa-info-circle me-1"></i> No members found with unmarked positions.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($members as $m): ?>
                            <?php
                            // Resolve Parent MID and Username (prefer placement parent, fallback to sponsor parent)
                            $parentMid = !empty($m['placement_mid']) ? $m['placement_mid'] : (!empty($m['sponsor_mid']) ? $m['sponsor_mid'] : 'N/A');
                            $parentUsername = !empty($m['placement_username']) ? $m['placement_username'] : (!empty($m['sponsor_username']) ? $m['sponsor_username'] : 'None (Root)');

                            // Resolve Package string
                            if (!empty($m['package_names'])) {
                                $pkgDisplay = htmlspecialchars($m['package_names']);
                            } elseif ($m['total_investment'] > 0) {
                                $pkgDisplay = '$' . number_format($m['total_investment'], 2);
                            } else {
                                $pkgDisplay = 'No Package';
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'N/A'); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($m['username']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($parentMid); ?></span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($parentUsername); ?>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d H:i', strtotime($m['created_at'])); ?>
                                </td>
                                <td>
                                    <?php if ($pkgDisplay === 'No Package'): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $pkgDisplay; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><?php echo $pkgDisplay; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
