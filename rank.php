<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch User Data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$config = require __DIR__ . '/includes/config.php';
$pageTitle = 'Rank';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2">
                <div class="card-header"><h4 class="card-title mb-0">Rank Status</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card bg-dark text-white p-4">
                                <h5>Current Rank</h5>
                                <h2 class="text-primary"><?php echo ($user['rank_id'] > 0) ? $config['ranks'][$user['rank_id']-1]['name'] : 'None'; ?></h2>
                                <hr>
                                <p>Matching Leg Business: $<?php echo number_format(min($user['left_leg_business'], $user['right_leg_business']), 2); ?></p>
                                <p>Power Leg Business: $<?php echo number_format(max($user['left_leg_business'], $user['right_leg_business']), 2); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light p-4">
                                <h5>Rank Income Status</h5>
                                <p>Days Paid: <?php echo $user['rank_income_days']; ?> / 100</p>
                                <div class="progress">
                                    <div class="progress-bar bg-success" style="width: <?php echo $user['rank_income_days']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h4 class="mt-5 mb-3">Rank Qualifications</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Matching Required</th>
                                    <th>Daily Income</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($config['ranks'] as $id => $r): ?>
                                <tr class="<?php echo ($user['rank_id'] > $id) ? 'table-success' : ''; ?>">
                                    <td><?php echo $r['name']; ?></td>
                                    <td>$<?php echo number_format($r['matching']); ?></td>
                                    <td>$<?php echo number_format($r['daily_income'], 2); ?></td>
                                    <td>
                                        <?php if ($user['rank_id'] > $id): ?>
                                            <span class="badge bg-success">Achieved</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Locked</span>
                                        <?php endif; ?>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
