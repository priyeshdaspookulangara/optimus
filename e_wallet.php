<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch Wallet Balance
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as wallet_balance FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

// [Provided Wallet HTML Code]
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<!-- Content from provided Wallet HTML with PHP values injected -->
<head>
    <!-- ... head content ... -->
    <title>Wallet - MLM App</title>
    <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/core/libs.min.css">
    <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/coinex.min.css?v=4.1.0">
</head>
<body class="bg-dark">
    <div class="container-fluid content-inner pb-0 bg-dark">
        <div class="row">
            <div class="col-lg-12">
                <div class="card bg-dark text-white">
                    <div class="card-header border-secondary">
                        <h4 class="card-title mb-0 text-white">E-Wallet Balance</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card p-3 mb-3 bg-secondary">
                                    <h5>Current Balance</h5>
                                    <h2 class="text-warning">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h2>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card p-4 bg-secondary">
                                    <h5 class="text-center mb-3 text-white">Deposit Funds</h5>
                                    <form action="usdt_to_ewallet.php" method="post">
                                        <div class="mb-3">
                                            <label class="form-label">Enter Amount ($)</label>
                                            <input type="number" step="any" name="amount" class="form-control" placeholder="0.0" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100">DEPOSIT</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
