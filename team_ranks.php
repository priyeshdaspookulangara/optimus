<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Summary of team members by rank
$stmt = $db->prepare("
    SELECT r.name, COUNT(u.id) as count
    FROM genealogy g
    JOIN users u ON g.user_id = u.id
    JOIN ranks r ON u.rank_id = r.id
    WHERE g.parent_id = ?
    GROUP BY u.rank_id
");
$stmt->execute([$userId]);
$rankCounts = $stmt->fetchAll();

$config = require __DIR__ . '/includes/config.php';
$pageTitle = 'Team Ranks';
include __DIR__ . '/includes/header.php';
?>
<div class="container-fluid p-4">
    <div class="card bg-dark text-white p-4">
        <h2>Team Rank Summary</h2>
        <p>Total distribution of ranks across your 12-generation team.</p>
        <div class="row mt-4">
            <?php foreach($config['ranks'] as $id => $r):
                $count = 0;
                foreach($rankCounts as $rc) {
                    if($rc['name'] == $r['name']) $count = $rc['count'];
                }
            ?>
            <div class="col-md-3 mb-3">
                <div class="card bg-secondary p-3 text-center border-light">
                    <h6 class="text-uppercase"><?php echo $r['name']; ?></h6>
                    <h2 class="text-warning"><?php echo $count; ?></h2>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
