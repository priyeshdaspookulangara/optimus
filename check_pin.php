<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$pinCode = trim($_GET['pin'] ?? '');

if (empty($pinCode)) {
    echo json_encode(['status' => 'invalid', 'message' => 'Please enter a PIN code']);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT p.*, pkg.name as package_name, pkg.amount
                          FROM pins p
                          JOIN packages pkg ON p.package_id = pkg.id
                          WHERE p.pin_code = ?");
    $stmt->execute([$pinCode]);
    $pin = $stmt->fetch();

    if (!$pin) {
        echo json_encode(['status' => 'invalid', 'message' => 'Invalid PIN code']);
    } else if ($pin['status'] == 'used') {
        echo json_encode(['status' => 'used', 'message' => 'This PIN code has already been used']);
    } else {
        echo json_encode([
            'status' => 'success',
            'message' => 'Valid PIN',
            'amount' => (float)$pin['amount'],
            'package' => $pin['package_name']
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System error occurred']);
}
