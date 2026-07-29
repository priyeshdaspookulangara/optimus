<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$level = isset($_GET['level']) ? $_GET['level'] : 1;
if ($level !== 'all') {
    $level = (int)$level;
    if ($level < 1) $level = 1;
    if ($level > 12) $level = 12;
}

// Fetch User Data for header
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$config = require __DIR__ . '/includes/config.php';

// Fetch Team Members at specified level
// We use the genealogy table which stores all descendants and their level relative to the parent
if ($level === 'all') {
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.username,
            u.created_at as joining_date,
            u.rank_id,
            u.status as activation_status,
            u.total_investment as investment,
            g.level as member_level,
            (SELECT created_at FROM investments WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as invest_date,
            (SELECT COALESCE(SUM(u2.total_investment), 0) FROM users u2 JOIN genealogy g2 ON u2.id = g2.user_id WHERE g2.parent_id = u.id) as team_investment
        FROM genealogy g
        JOIN users u ON g.user_id = u.id
        WHERE g.parent_id = ?
        ORDER BY g.level ASC, u.created_at DESC
    ");
    $stmt->execute([$userId]);
} else {
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.username,
            u.created_at as joining_date,
            u.rank_id,
            u.status as activation_status,
            u.total_investment as investment,
            g.level as member_level,
            (SELECT created_at FROM investments WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as invest_date,
            (SELECT COALESCE(SUM(u2.total_investment), 0) FROM users u2 JOIN genealogy g2 ON u2.id = g2.user_id WHERE g2.parent_id = u.id) as team_investment
        FROM genealogy g
        JOIN users u ON g.user_id = u.id
        WHERE g.parent_id = ? AND g.level = ?
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$userId, $level]);
}
$members = $stmt->fetchAll();

$pageTitle = 'My Team';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<style>
    .active-level {
        background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%) !important;
        border-color: #cca354 !important;
    }
    .btn-primary:hover {
        background-color: #000 !important;
        border-color: #000 !important;
    }
    td { color: #000 !important; }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2">
                <div class="card-header">
                    <h4 class="card-title mb-0">My Team</h4>
                </div>
                <div class="card-body">
                    <div class="container text-center">
                        <div class="d-flex flex-nowrap justify-content-between overflow-auto py-2">
                            <a href="my_team.php?level=all"
                                class="text-white btn btn-sm me-2 btn-primary <?php echo ($level === 'all') ? 'active-level' : ''; ?>">
                                All Under
                            </a>
                            <?php for($i=1; $i<=12; $i++): ?>
                            <a href="my_team.php?level=<?php echo $i; ?>"
                                class="text-white btn btn-sm me-2 btn-primary <?php echo ($level == $i) ? 'active-level' : ''; ?>">
                                Level <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="table-responsive mt-4">
                        <table id="example" class="table table-striped" style="width:100%">
                            <thead>
                                <tr>
                                    <th>SL.no</th>
                                    <th>Joining date</th>
                                    <th>Invest date</th>
                                    <th>User</th>
                                    <th>Level</th>
                                    <th>Investment</th>
                                    <th>Team Investment</th>
                                    <th>Rank</th>
                                    <th>Activation Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($members as $index => $m): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($m['joining_date'])); ?></td>
                                    <td><?php echo $m['invest_date'] ? date('Y-m-d', strtotime($m['invest_date'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($m['username']); ?></td>
                                    <td><span class="badge bg-secondary">Level <?php echo $m['member_level']; ?></span></td>
                                    <td>$<?php echo number_format($m['investment'], 2); ?></td>
                                    <td>$<?php echo number_format($m['team_investment'], 2); ?></td>
                                    <td><?php echo ($m['rank_id'] > 0) ? $config['ranks'][$m['rank_id']-1]['name'] : 'None'; ?></td>
                                    <td>
                                        <span class="badge <?php echo ($m['activation_status'] == 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo ucfirst($m['activation_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function () {
        if ($('#example tbody tr').length > 0) {
            $('#example').DataTable();
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
