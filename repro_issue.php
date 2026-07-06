<?php
require_once __DIR__ . '/includes/engine.php';

// Setup database connection (assuming a test db exists or using mlm_app)
// For this test, let's just use the real db but maybe clean up or use a test user.

try {
    $engine = new MLMEngine();
    $db = Database::getInstance()->getConnection();

    // Create a test user if not exists
    $username = "testuser_" . time();
    $email = $username . "@example.com";
    $password = password_hash("password123", PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $password]);
    $userId = $db->lastInsertId();
    echo "Test user created: $userId\n";

    // Add some money to e-wallet
    $engine->logTransaction($userId, 'DEPOSIT', 1000, 0, "Initial Deposit");
    echo "Deposited 1000\n";

    // Invest
    $packageAmount = 500;
    $engine->createInvestment($userId, $packageAmount);
    echo "Invested 500\n";

    // Check investment history
    $stmt = $db->prepare("SELECT * FROM investments WHERE user_id = ?");
    $stmt->execute([$userId]);
    $investments = $stmt->fetchAll();

    echo "Investment count: " . count($investments) . "\n";
    foreach ($investments as $inv) {
        echo " - ID: {$inv['id']}, Amount: {$inv['amount']}, Status: {$inv['status']}\n";
    }

    // Check transaction log
    $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll();
    echo "Transaction count: " . count($transactions) . "\n";
    foreach ($transactions as $tx) {
        echo " - Type: {$tx['type']}, Net: {$tx['net_amount']}, InvestmentID: {$tx['investment_id']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
