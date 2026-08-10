<?php
// Complete test suite for correct_rank_income.php core engine
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/engine.php';
require_once __DIR__ . '/includes/recalc_utils.php';

echo "=== STARTING CORE RANK INCOME RECALCULATION TESTS ===\n";

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();

$db->beginTransaction();

try {
    // 1. Create a mock sponsor/placement structure to test direct and propagated rank payouts
    // Clean old test transactions/users
    $db->exec("DELETE FROM transactions");
    $db->exec("DELETE FROM genealogy");
    $db->exec("DELETE FROM matching_schedules");
    $db->exec("DELETE FROM investments");
    $db->exec("DELETE FROM users WHERE username IN ('test_upline', 'test_earner', 'ref2', 'ref3')");

    // Insert upline user
    $hashedPassword = password_hash('password123', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (mid, username, email, password, total_investment, status) VALUES ('OPT00001', 'test_upline', 'upline@example.com', ?, 1000.00, 'active')");
    $stmt->execute([$hashedPassword]);
    $uplineId = $db->lastInsertId();

    // Insert earner user sponsored by upline
    $stmt = $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id, total_investment, status) VALUES ('OPT00002', 'test_earner', 'earner@example.com', ?, $uplineId, 500.00, 'active')");
    $stmt->execute([$hashedPassword]);
    $earnerId = $db->lastInsertId();

    // Set genealogy level 1
    $stmt = $db->prepare("INSERT INTO genealogy (user_id, parent_id, level) VALUES (?, ?, 1)");
    $stmt->execute([$earnerId, $uplineId]);

    // Let's seed 2 more direct referrals under test_upline to ensure it's NOT an orphan and qualifies for propagation
    $stmt = $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id, status) VALUES ('OPT00003', 'ref2', 'ref2@example.com', ?, $uplineId, 'active')");
    $stmt->execute([$hashedPassword]);
    $stmt = $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id, status) VALUES ('OPT00004', 'ref3', 'ref3@example.com', ?, $uplineId, 'active')");
    $stmt->execute([$hashedPassword]);

    // 2. Create a matching schedule for the earner created 5 days ago at 04:00:00 (before 5 AM threshold)
    $createdAt = date('Y-m-d H:i:s', strtotime('-5 days 04:00:00'));
    $stmt = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status, created_at) VALUES (?, 500.00, 2.50, 0, 100, 'active', ?)");
    $stmt->execute([$earnerId, $createdAt]);
    $schedId = $db->lastInsertId();

    echo "Mock matching schedule created with ID #$schedId, Commence Date: $createdAt\n";

    // 3. Perform correction/recalculation
    echo "Running correction logic for schedule #$schedId...\n";
    $success = correctMatchingScheduleRankIncome($db, $engine, $schedId);

    if (!$success) {
        throw new Exception("Core correction function returned false.");
    }

    // 4. Verification
    // Retrieve updated schedule details
    $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE id = ?");
    $stmt->execute([$schedId]);
    $updatedSched = $stmt->fetch();

    echo "Updated schedule: days_passed = {$updatedSched['days_passed']}, status = {$updatedSched['status']}\n";

    // Since it was created 5 days ago before 5 AM, the payout dates should be calculated using getExpectedRankPayoutDates
    $expectedDates = getExpectedRankPayoutDates($createdAt);
    $expectedDaysCount = count($expectedDates);

    if ((int)$updatedSched['days_passed'] !== $expectedDaysCount) {
        throw new Exception("Mismatch in days_passed! Expected: $expectedDaysCount, Got: " . $updatedSched['days_passed']);
    }
    echo "✔ days_passed updated correctly to $expectedDaysCount.\n";

    // Verify direct RANK_INCOME transactions
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL");
    $stmt->execute([$earnerId]);
    $directCount = $stmt->fetchColumn();

    if ((int)$directCount !== $expectedDaysCount) {
        throw new Exception("Expected $expectedDaysCount direct RANK_INCOME transactions, got $directCount.");
    }
    echo "✔ Created $directCount direct RANK_INCOME transactions.\n";

    // Verify propagated RANK_INCOME transactions
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id = ?");
    $stmt->execute([$uplineId, $earnerId]);
    $propCount = $stmt->fetchColumn();

    if ((int)$propCount !== $expectedDaysCount) {
        throw new Exception("Expected $expectedDaysCount propagated RANK_INCOME transactions for upline, got $propCount.");
    }
    echo "✔ Created $propCount propagated RANK_INCOME transactions for upline.\n";

    echo "\n=== ALL CORE RECALCULATION TESTS PASSED SUCCESSFULLY! ===\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
} finally {
    // Rollback test transaction so database remains completely clean
    $db->rollBack();
    echo "Transaction rolled back. Sandbox remains pristine.\n";
}
