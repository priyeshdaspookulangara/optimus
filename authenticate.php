<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'] ?? '';
    $password = $_POST['password'] ?? '';

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
        $_SESSION['user_id'] = $user['id'];
        header("Location: dashboard.php");
        exit();
    } else {
        header("Location: login.php?error=1");
        exit();
    }
}
