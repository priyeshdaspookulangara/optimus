<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit();
}

$loggedInUserId = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

$targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$position = isset($_POST['position']) ? trim($_POST['position']) : '';

if ($targetUserId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user ID.']);
    exit();
}

// Security check: is targetUserId the logged-in user or a downline?
$isAllowed = false;
if ($targetUserId === $loggedInUserId) {
    $isAllowed = true;
} else {
    $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE parent_id = ? AND user_id = ? LIMIT 1");
    $stmtCheck->execute([$loggedInUserId, $targetUserId]);
    if ($stmtCheck->fetch()) {
        $isAllowed = true;
    }
}

if (!$isAllowed) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. You can only update yourself or your downlines.']);
    exit();
}

// Validate position input and decide DB value
if ($position === 'left') {
    $dbPosition = 'left';
} elseif ($position === 'right') {
    $dbPosition = 'right';
} elseif ($position === 'none' || $position === 'not_decided') {
    $dbPosition = null;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid position value.']);
    exit();
}

try {
    $stmtUpdate = $db->prepare("UPDATE users SET position = ? WHERE id = ?");
    $stmtUpdate->execute([$dbPosition, $targetUserId]);

    echo json_encode(['status' => 'success', 'message' => 'Position updated successfully.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database update failed: ' . $e->getMessage()]);
}
