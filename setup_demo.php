<?php
require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance()->getConnection();

    // Reset test data
    $db->exec("DELETE FROM genealogy");
    $db->exec("DELETE FROM matching_schedules");
    $db->exec("DELETE FROM transactions");
    $db->exec("DELETE FROM users WHERE id > 1");

    // Re-insert Root Admin (ID 1) MLM metrics
    $db->exec("UPDATE users SET rank_id = 0, total_investment = 5000.00, status = 'active' WHERE id = 1");

    // Insert user 2: sponsor1 (sponsor_id = 1)
    $stmt = $db->prepare("INSERT INTO users (id, mid, username, email, password, sponsor_id, placement_id, status, total_investment) VALUES (2, 'MID002', 'sponsor1', 'sponsor1@example.com', 'pwd', 1, NULL, 'active', 2000.00)");
    $stmt->execute();

    // Insert user 3: achiever (sponsor_id = 2)
    $stmt = $db->prepare("INSERT INTO users (id, mid, username, email, password, sponsor_id, placement_id, status, total_investment) VALUES (3, 'MID003', 'achiever', 'achiever@example.com', 'pwd', 2, NULL, 'active', 1000.00)");
    $stmt->execute();

    // Populate genealogy tree
    $db->exec("INSERT INTO genealogy (user_id, parent_id, level) VALUES (2, 1, 1)");
    $db->exec("INSERT INTO genealogy (user_id, parent_id, level) VALUES (3, 2, 1)");
    $db->exec("INSERT INTO genealogy (user_id, parent_id, level) VALUES (3, 1, 2)");

    // Insert matching schedule for achiever (3)
    $stmt = $db->prepare("INSERT INTO matching_schedules (id, user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (101, 3, 1000.00, 2.50, 0, 100, 'active')");
    $stmt->execute();

    echo "Demo database setup complete!\n";
} catch (Exception $e) {
    echo "Setup failed: " . $e->getMessage() . "\n";
}
