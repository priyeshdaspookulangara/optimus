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
$minWithdrawal = $config['withdrawal']['min_amount'] ?? 25.00;
$fee = $config['withdrawal']['fee'] ?? 10.00;

// Fetch Wallet Balance
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as wallet_balance FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = $_POST['amount'] ?? 0;
    try {
        $engine = new MLMEngine();
        $engine->requestWithdrawal($userId, $amount);
        header("Location: e_wallet_history.php?success=withdrawal_requested");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Withdraw Fund';
include __DIR__ . '/includes/header.php';
?>
<div class="container-fluid p-4">
    <div class="card bg-dark text-white p-4">
        <h2>Withdraw Funds</h2>
        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="card bg-secondary p-3">
                    <h5>Available Balance</h5>
                    <h3 class="text-warning">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h3>
                </div>
            </div>
        </div>
        <form method="post" class="mt-4" style="max-width: 500px;">
            <div class="mb-3">
                <label class="form-label">Amount to Withdraw ($)</label>
                <input type="number" step="any" name="amount" class="form-control" required min="<?php echo htmlspecialchars($minWithdrawal); ?>">
                <small class="text-info">Minimum withdrawal: $<?php echo number_format($minWithdrawal, 2); ?>. A flat fee of $<?php echo number_format($fee, 2); ?> will be deducted.</small>
            </div>
            <button type="submit" class="btn btn-primary">Request Withdrawal</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
