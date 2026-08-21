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

    $db = Database::getInstance()->getConnection();

    // Check wallet balance
    $stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as balance FROM transactions WHERE user_id = ? AND type != 'INVESTMENT'");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();

    if ($wallet['balance'] < $amount) {
        header("Location: invest.php?error=insufficient_balance");
        exit();
    }

    try {
        $engine = new MLMEngine();
        $engine->createInvestment($userId, $amount);
        header("Location: invest_history.php?success=investment_created");
        exit();
    } catch (Exception $e) {
        header("Location: invest.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: invest.php");
exit();
