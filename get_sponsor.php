<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$sponsorId = trim($_GET['id'] ?? '');

if (empty($sponsorId)) {
    echo json_encode(['status' => 'not_found', 'username' => 'Not Found']);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT username FROM users WHERE mid = ? OR id = ?");
    $stmt->execute([$sponsorId, $sponsorId]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'username' => $user['username']]);
    } else {
        echo json_encode(['status' => 'not_found', 'username' => 'Not Found']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'username' => 'System Error']);
}
