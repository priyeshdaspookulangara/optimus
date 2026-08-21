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

$totalAvailable = (float)($wallet['wallet_balance'] ?? 0);
$deduction5 = $totalAvailable * 0.05;
$netAfterDeduction = $totalAvailable * 0.95;

$minWithdrawal = 5.00;

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
            <div class="card p-4 bg-dark text-white mb-4">
                <h2>Withdraw Funds</h2>

                <?php if(isset($error)): ?>
                    <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="row mt-3">
                    <div class="col-md-6 col-lg-5">
                        <div class="card p-3 border border-secondary" style="background-color: #0d121d; border-radius: 12px;">
                            <h5 class="card-title text-white mb-3">Available Balance</h5>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-info">Total Available:</span>
                                <span class="fw-bold text-danger" style="color: #ff6b35 !important; font-size: 1.25rem;">$<?php echo number_format($totalAvailable, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2" style="border-bottom: 1px dashed #2c3e50; padding-bottom: 8px;">
                                <span class="text-danger">5% Deduction:</span>
                                <span class="fw-bold text-danger">-$<?php echo number_format($deduction5, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-1">
                                <span class="text-warning">Net After Deduction:</span>
                                <span class="fw-bold text-warning" style="font-size: 1.25rem;">$<?php echo number_format($netAfterDeduction, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="post" class="mt-4" style="max-width: 500px;">
                    <div class="mb-3">
                        <label class="form-label text-white">Amount to Withdraw ($)</label>
                        <input type="number" step="0.01" name="amount" id="withdrawAmount" class="form-control" required min="5" placeholder="Enter amount">
                        <small class="text-info d-block mt-1">Minimum withdrawal: $5.00</small>
                    </div>

                    <div id="liveCalculationBox" class="p-3 mb-3 rounded" style="background-color: #1a2332; border: 1px solid #2d3d50; display: none;">
                        <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                            <span class="text-white-50">Requested Amount:</span>
                            <span class="fw-bold text-white" id="calcRequested">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                            <span class="text-white-50">5% Withdrawal Fee:</span>
                            <span class="fw-bold text-danger" id="calcFee">+$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between pt-2 border-top border-secondary" style="font-size: 14px;">
                            <span class="text-warning fw-bold">Total Wallet Deduction:</span>
                            <span class="fw-bold text-warning" id="calcTotal">$0.00</span>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 my-3 text-dark" style="background-color: #cff4fc; border-color: #b6effb;">
                        <strong>Note:</strong> 5% will be deducted from your withdrawal amount.
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Request Withdrawal</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var amountInput = document.getElementById('withdrawAmount');
    var calcBox = document.getElementById('liveCalculationBox');
    var calcReq = document.getElementById('calcRequested');
    var calcFee = document.getElementById('calcFee');
    var calcTot = document.getElementById('calcTotal');

    if (amountInput) {
        amountInput.addEventListener('input', function() {
            var val = parseFloat(amountInput.value);
            if (!isNaN(val) && val > 0) {
                var fee = val * 0.05;
                var total = val + fee;
                calcReq.textContent = '$' + val.toFixed(2);
                calcFee.textContent = '+$' + fee.toFixed(2);
                calcTot.textContent = '$' + total.toFixed(2);
                calcBox.style.display = 'block';
            } else {
                calcBox.style.display = 'none';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
