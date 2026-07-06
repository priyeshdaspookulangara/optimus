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

    try {
        $engine = new MLMEngine();
        $engine->activateWithPin($userId, $pinCode);
        header("Location: invest_history.php?success=package_activated");
        exit();
    } catch (Exception $e) {
        header("Location: invest.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: invest.php");
exit();
