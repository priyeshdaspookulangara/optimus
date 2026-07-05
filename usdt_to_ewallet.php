<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = $_POST['amount'] ?? 0;
    $userId = $_SESSION['user_id'];

    if ($amount > 0) {
        $engine = new MLMEngine();
        // Log as DEPOSIT (net_amount will be positive)
        $engine->logTransaction($userId, 'DEPOSIT', $amount, 0, "USDT Deposit of \${$amount}");
        header("Location: e_wallet.php?success=1");
        exit();
    }
}
header("Location: e_wallet.php?error=1");
exit();
