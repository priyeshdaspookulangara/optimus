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

// Fetch Wallet Balance
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as wallet_balance FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

$minWithdrawal = (float)($config['withdrawal']['min_amount'] ?? 25.00);
$fee = (float)($config['withdrawal']['fee'] ?? 10.00);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    try {
        $engine = new MLMEngine();
        $engine->requestWithdrawal($userId, $amount);
        header("Location: my_withdrawals.php?success=withdrawal_requested");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Withdraw Fund';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Withdraw Funds</h3>
                <a href="my_withdrawals.php" class="btn btn-secondary text-white fw-bold"><i class="fa fa-history me-2"></i>Withdrawals History</a>
            </div>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card p-4 bg-dark text-white mb-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card p-3" style="background: #01526f;">
                            <h5 class="card-title text-white mb-1">Available Wallet Balance</h5>
                            <h3 class="text-warning font-weight-bold">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card p-3 bg-secondary text-white">
                            <h5 class="card-title text-white mb-1">Withdrawal Rules</h5>
                            <p class="mb-1 text-white-50"><i class="fa fa-info-circle text-warning me-1"></i> Minimum withdrawal: <strong>$<?php echo number_format($minWithdrawal, 2); ?></strong></p>
                            <p class="mb-0 text-white-50"><i class="fa fa-tag text-info me-1"></i> Flat gas fee: <strong>$<?php echo number_format($fee, 2); ?></strong> (deducted from requested amount)</p>
                        </div>
                    </div>
                </div>

                <form method="post" class="mt-3" style="max-width: 600px;">
                    <div class="mb-3">
                        <label class="form-label text-white">Amount to Withdraw ($)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="<?php echo $minWithdrawal; ?>" placeholder="Enter amount (min $<?php echo number_format($minWithdrawal, 2); ?>)">
                    </div>

                    <div class="alert alert-info py-2 text-white" style="background-color: rgba(13, 202, 240, 0.2); border-color: #0dcaf0;">
                        <small><i class="fa fa-lightbulb me-1"></i> Note: A flat gas fee of <strong>$<?php echo number_format($fee, 2); ?></strong> will be deducted from your withdrawal amount upon processing.</small>
                    </div>

                    <button type="submit" class="btn btn-primary mt-2 px-4 py-2"><i class="fa fa-paper-plane me-2"></i>Request Withdrawal</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
