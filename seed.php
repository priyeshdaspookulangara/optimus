<?php
require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/includes/config.php';

try {
    echo "Seeding database...\n";

    // 1. Seed Packages
    $db->exec("DELETE FROM packages");
    $stmt = $db->prepare("INSERT INTO packages (name, amount) VALUES (?, ?)");
    foreach ($config['packages'] as $amount) {
        $stmt->execute(["Package \${$amount}", $amount]);
    }
    echo "Packages seeded.\n";

    // 2. Seed Ranks
    $db->exec("DELETE FROM ranks");
    $stmt = $db->prepare("INSERT INTO ranks (name, matching_business, daily_income, duration_days, total_cap_multiplier) VALUES (?, ?, ?, ?, ?)");
    foreach ($config['ranks'] as $rank) {
        $stmt->execute([$rank['name'], $rank['matching'], $rank['daily_income'], $rank['days'], 3.0]);
    }
    echo "Ranks seeded.\n";

    // 3. Create Default User
    $username = 'admin';
    $password = 'password123';
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if (!$stmt->fetch()) {
        // Root user: no sponsor_id, no placement_id
        $stmt = $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id, placement_id) VALUES (?, ?, ?, ?, NULL, NULL)");
        $stmt->execute(['OPT59655', $username, 'admin@example.com', $hashedPassword]);
        echo "Root user 'admin' created with mid 'OPT59655' and password 'password123'.\n";
    } else {
        echo "Default user already exists.\n";
    }

    echo "Seeding complete!\n";

} catch (Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
}
