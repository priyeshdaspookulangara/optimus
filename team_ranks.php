<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

$config = require __DIR__ . '/includes/config.php';

// Summary of team members by rank
$stmt = $db->prepare("
    SELECT u.rank_id, COUNT(u.id) as count
    FROM genealogy g
    JOIN users u ON g.user_id = u.id
    WHERE g.parent_id = ? AND u.rank_id > 0
    GROUP BY u.rank_id
");
$stmt->execute([$userId]);
$rankCountsRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Detailed team rank list
$stmt = $db->prepare("
    SELECT u.username, u.email, u.rank_id, g.level, u.total_investment, u.created_at
    FROM genealogy g
    JOIN users u ON g.user_id = u.id
    WHERE g.parent_id = ?
    ORDER BY g.level ASC, u.rank_id DESC
");
$stmt->execute([$userId]);
$teamDetails = $stmt->fetchAll();

$pageTitle = 'Team Ranks';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-4 bg-dark text-white mb-4">
                <h2 class="mb-2">Team Rank Summary</h2>
                <p class="text-muted">Rank distribution across your 12-generation network.</p>
                <div class="row mt-4">
                    <?php foreach($config['ranks'] as $index => $r):
                        $rankId = $index + 1;
                        $count = $rankCountsRaw[$rankId] ?? 0;
                    ?>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card p-3 text-center border-secondary" style="background: #01526f;">
                            <h6 class="text-uppercase mb-1" style="font-size: 12px;"><?php echo $r['name']; ?></h6>
                            <h3 class="text-warning mb-0"><?php echo $count; ?></h3>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-12 DT-col">
            <div class="card p-2">
                <div class="card-header">
                    <h4 class="card-title mb-0">Detailed Team Rankings</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="teamRanksTable" class="table table-striped" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Level</th>
                                    <th>Current Rank</th>
                                    <th>Investment</th>
                                    <th>Join Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($teamDetails as $member):
                                    $rankName = ($member['rank_id'] > 0) ? $config['ranks'][$member['rank_id']-1]['name'] : 'None';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($member['username']); ?></strong></td>
                                    <td>Level <?php echo $member['level']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $member['rank_id'] > 0 ? 'bg-primary' : 'bg-secondary'; ?>">
                                            <?php echo $rankName; ?>
                                        </span>
                                    </td>
                                    <td>$<?php echo number_format($member['total_investment'], 2); ?></td>
                                    <td><?php echo date('d M, Y', strtotime($member['created_at'])); ?></td>
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
      $('#teamRanksTable').DataTable({
          "order": [[ 1, "asc" ], [ 2, "desc" ]]
      });
    });
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
