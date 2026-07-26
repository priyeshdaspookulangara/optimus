<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance()->getConnection();

if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $userId = $_POST['user_id'];
    $newStatus = $_POST['status'];
    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    header("Location: members.php?success=status_updated");
    exit();
}

$pageTitle = 'Member Management';
include __DIR__ . '/includes/header.php';

// Fetch lists for filters
$config = require __DIR__ . '/../includes/config.php';
$packagesList = $config['packages'];
$ranksList = $config['ranks'];

$search = $_GET['search'] ?? '';
$rankFilter = $_GET['rank_id'] ?? '';
$packageFilter = $_GET['package_amount'] ?? '';

// Build dynamic query
$query = "SELECT DISTINCT u.* FROM users u";
$params = [];
$joins = [];
$conditions = [];

if (!empty($packageFilter)) {
    $joins[] = "JOIN investments i ON u.id = i.user_id";
    $conditions[] = "i.amount = ?";
    $params[] = $packageFilter;
}

if ($search !== '') {
    $conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($rankFilter !== '') {
    $conditions[] = "u.rank_id = ?";
    $params[] = $rankFilter;
}

$joinStr = implode(" ", $joins);
$whereStr = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
$query .= " {$joinStr} {$whereStr} ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll();
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Members List</h3>
    </div>

    <!-- Dynamic Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search username / email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-3">
            <select name="rank_id" class="form-select">
                <option value="">-- All Ranks --</option>
                <option value="0" <?php echo $rankFilter === '0' ? 'selected' : ''; ?>>None / No Rank</option>
                <?php foreach($ranksList as $idx => $rConf): ?>
                    <option value="<?php echo $idx + 1; ?>" <?php echo $rankFilter == ($idx + 1) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rConf['name']); ?> (Slab $<?php echo number_format($rConf['matching']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="package_amount" class="form-select">
                <option value="">-- All Packages --</option>
                <?php foreach($packagesList as $pkgAmt): ?>
                    <option value="<?php echo $pkgAmt; ?>" <?php echo $packageFilter == $pkgAmt ? 'selected' : ''; ?>>
                        Package $<?php echo number_format($pkgAmt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="members.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success">Action completed successfully.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Rank</th>
                        <th>Total Invested</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): ?>
                    <tr>
                        <td><?php echo $m['id']; ?></td>
                        <td><strong><?php echo $m['username']; ?></strong></td>
                        <td><?php echo $m['email']; ?></td>
                        <td>
                            <span class="badge bg-info" style="font-size: 13px;">
                                <?php echo ($m['rank_id'] > 0 && isset($ranksList[$m['rank_id']-1])) ? htmlspecialchars($ranksList[$m['rank_id']-1]['name']) : 'None'; ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                        <td>
                            <span class="badge <?php echo $m['status'] == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo strtoupper($m['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <?php if($m['status'] == 'active'): ?>
                                    <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
                                <?php else: ?>
                                    <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
