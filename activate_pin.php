<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pinCode = trim($_POST['pin_code'] ?? '');
    $userId = $_SESSION['user_id'];

    $referer = $_SERVER['HTTP_REFERER'] ?? 'invest.php';
    $isEWallet = (strpos($referer, 'e_wallet.php') !== false);

    try {
        $engine = new MLMEngine();
        $engine->activateWithPin($userId, $pinCode);
        if ($isEWallet) {
            header("Location: e_wallet.php?success=package_activated");
        } else {
            header("Location: invest_history.php?success=package_activated");
        }
        exit();
    } catch (Exception $e) {
        if ($isEWallet) {
            header("Location: e_wallet.php?error=" . urlencode($e->getMessage()));
        } else {
            header("Location: invest.php?error=" . urlencode($e->getMessage()));
        }
        exit();
    }
}

header("Location: invest.php");
exit();
