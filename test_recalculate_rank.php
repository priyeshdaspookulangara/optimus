<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();
$config = require __DIR__ . '/includes/config.php';

echo "=== START RECALCULATE RANK TEST ===\n";

try {
    // 1. Clean up existing users (except root admin ID=1) and tables
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    $db->exec("TRUNCATE TABLE genealogy");
    $db->exec("TRUNCATE TABLE investments");
    $db->exec("TRUNCATE TABLE transactions");
    $db->exec("TRUNCATE TABLE matching_schedules");
    $db->exec("DELETE FROM users WHERE id > 1");

    // Reset root user
    $db->exec("UPDATE users SET rank_id = 0, total_investment = 0 WHERE id = 1");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "Tables cleaned and reset.\n";

    // 2. Create User B and User C under Root (ID 1)
    // User B: MID OPT00002
    $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id) VALUES ('OPT00002', 'user_b', 'b@test.com', 'pass', 1)")->execute();
    $userBId = $db->lastInsertId();
    $engine->addToGenealogy($userBId, 1);

    // User C: MID OPT00003
    $db->prepare("INSERT INTO users (mid, username, email, password, sponsor_id) VALUES ('OPT00003', 'user_c', 'c@test.com', 'pass', 1)")->execute();
    $userCId = $db->lastInsertId();
    $engine->addToGenealogy($userCId, 1);

    echo "Created User B (ID: $userBId) and User C (ID: $userCId) sponsored by Root Admin (ID: 1).\n";

    // 3. Create investments for B and C
    $engine->createInvestment($userBId, 1000);
    $engine->createInvestment($userCId, 1000);

    echo "Created $1000 investments for User B and User C.\n";

    // 4. Verify Root leg business and matched business
    $legStats = $engine->getLegsBusiness(1);
    echo "Root Legs Business:\n";
    echo " - Power Leg: " . $legStats['power_leg'] . "\n";
    echo " - Matching Leg: " . $legStats['matching_leg'] . "\n";
    echo " - Matched Business: " . $legStats['matched_business'] . "\n";

    if ($legStats['matched_business'] != 1000.00) {
        throw new Exception("Assertion Failed: Matched business should be 1000.00, got: " . $legStats['matched_business']);
    }

    // 5. Simulate incorrect database state for Root
    // Set Rank ID to 0 (should be 2) and delete any auto-created schedules
    $db->exec("UPDATE users SET rank_id = 0 WHERE id = 1");
    $db->exec("TRUNCATE TABLE matching_schedules");

    echo "Simulated incorrect state: Rank ID reset to 0, matching schedules truncated.\n";

    // 6. Run recalculation/correction logic programmatically
    echo "Running recalculation script simulation...\n";

    // Selected user is Root (OPT59655)
    $stmt = $db->prepare("SELECT * FROM users WHERE mid = 'OPT59655'");
    $stmt->execute();
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Root user not found.");
    }

    // Evaluate correct qualified rank
    $matchedBusiness = (float)$legStats['matched_business'];
    $slabBreakdown = $legStats['slab_breakdown'] ?? [];
    $qualifiedRankId = 0;
    foreach ($config['ranks'] as $idx => $rank) {
        if ($matchedBusiness >= $rank['matching']) {
            $qualifiedRankId = $idx + 1;
        } else {
            break;
        }
    }

    echo " - Organic Qualified Rank ID: " . $qualifiedRankId . " (Pioneer)\n";
    if ($qualifiedRankId !== 2) {
        throw new Exception("Assertion Failed: Qualified Rank ID should be 2, got: " . $qualifiedRankId);
    }

    // Simulate POST form submit with corrections
    // Option 1: Update Rank ID
    $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?")->execute([$qualifiedRankId, $user['id']]);
    echo " - Success: Corrected Rank ID to $qualifiedRankId.\n";

    // Option 2: Sync Matching Schedules
    $insertedCount = 0;
    foreach ($config['ranks'] as $rankConf) {
        $slab = (int)$rankConf['matching'];
        $required = $slabBreakdown[$slab] ?? 0;

        // Count existing schedules in DB
        $stmtCount = $db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
        $stmtCount->execute([$user['id'], $slab]);
        $existing = (int)$stmtCount->fetch()['count'];

        if ($required > $existing) {
            $diff = $required - $existing;
            $dailyIncome = $rankConf['daily_income'];
            $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
            for ($i = 0; $i < $diff; $i++) {
                $stmtInsert->execute([$user['id'], $slab, $dailyIncome]);
                $insertedCount++;
            }
        }
    }
    echo " - Success: Synced schedules. Generated $insertedCount missing schedules.\n";
    if ($insertedCount !== 1) {
        throw new Exception("Assertion Failed: Missing schedules should be 1 ($1000 slab), got: " . $insertedCount);
    }

    // 7. Test manual pay 1-day payout for $1000 slab schedule
    $stmtSched = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ? AND slab_amount = 1000 AND status = 'active' LIMIT 1");
    $stmtSched->execute([$user['id']]);
    $sched = $stmtSched->fetch();

    if (!$sched) {
        throw new Exception("Active matching schedule for $1000 slab not found.");
    }

    echo "Found active matching schedule ID: {$sched['id']} for slab \$1000.\n";

    // Trigger daily payout manual simulation
    $db->beginTransaction();

    // Simulate getAllowableAmount checking:
    // Under 300% cap, Root user has no other income, total investment is 0. Wait, root investment is 0!
    // Since root investment is 0, maximum cap = 0 * 3 = 0!
    // Let's update root user's total investment to $1000 so that they can receive payouts under the cap check!
    $db->exec("UPDATE users SET total_investment = 1000.00 WHERE id = 1");

    $dailyIncome = (float)$sched['daily_income'];

    // Calculate allowable
    $stmtCap = $db->prepare("SELECT total_investment, (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned FROM users WHERE id = ?");
    $stmtCap->execute([$user['id'], $user['id']]);
    $uData = $stmtCap->fetch();

    $maxCap = $uData['total_investment'] * $config['id_cap_multiplier'];
    $remainingCap = $maxCap - $uData['total_earned'];
    $allowable = min($dailyIncome, max(0.00, $remainingCap));

    echo "Allowable Daily Income payout: $allowable (Daily Rate: $dailyIncome, Remaining Cap: $remainingCap)\n";

    if ($allowable > 0) {
        $engine->logTransaction(
            $user['id'],
            'RANK_INCOME',
            $allowable,
            0,
            "Daily Matching Income for Slab \$" . number_format($sched['slab_amount'], 2) . " (Day " . ($sched['days_passed'] + 1) . "/100) [Manual Trigger]"
        );

        $newDaysPassed = $sched['days_passed'] + 1;
        $status = ($newDaysPassed >= $sched['max_days']) ? 'completed' : 'active';

        $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?")->execute([$newDaysPassed, $status, $sched['id']]);
        $db->commit();
        echo " - Success: Manually paid 1 day on schedule {$sched['id']}. Days passed: $newDaysPassed.\n";
    } else {
        $db->rollBack();
        throw new Exception("Assertion Failed: Allowable payout should be greater than 0.");
    }

    // 8. Reconcile direct transactions
    // Expected = 1 * $2.50 (from the paid $1000 slab day) = $2.50. Actual = $2.50.
    // Let's delete that transaction to simulate a missing transaction log!
    $db->exec("TRUNCATE TABLE transactions"); // Remove all transactions to make Actual = 0
    echo "Deleted transaction logs to simulate missing transactions.\n";

    // Expected direct rank income
    $freshExpected = 0.00;
    $stmtFreshSched = $db->prepare("SELECT * FROM matching_schedules WHERE user_id = ?");
    $stmtFreshSched->execute([$user['id']]);
    $freshScheds = $stmtFreshSched->fetchAll();
    foreach ($freshScheds as $fs) {
        $freshExpected += (float)$fs['days_passed'] * (float)$fs['daily_income'];
    }

    // Actual direct rank income
    $stmtFreshAct = $db->prepare("SELECT SUM(amount) as total_amount FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL");
    $stmtFreshAct->execute([$user['id']]);
    $freshActual = (float)($stmtFreshAct->fetch()['total_amount'] ?? 0);

    echo "Before Reconciliation:\n";
    echo " - Expected Income: $freshExpected\n";
    echo " - Actual Income: $freshActual\n";

    if ($freshExpected > $freshActual) {
        $diffAmount = $freshExpected - $freshActual;
        $engine->logTransaction(
            $user['id'],
            'RANK_INCOME',
            $diffAmount,
            0,
            "Rank Income Reconciliation: Added missing direct rank income of \$" . number_format($diffAmount, 2) . " to align with matching schedules"
        );
        echo " - Success: Reconciled transactions. Generated missing transaction of \$" . number_format($diffAmount, 2) . ".\n";
    }

    // Verify after reconciliation
    $stmtFreshAct->execute([$user['id']]);
    $freshActualAfter = (float)($stmtFreshAct->fetch()['total_amount'] ?? 0);
    echo "After Reconciliation:\n";
    echo " - Actual Income: $freshActualAfter\n";

    if ($freshActualAfter != $freshExpected) {
        throw new Exception("Assertion Failed: Reconciled actual income does not match expected.");
    }

    echo "=== ALL TESTS PASSED SUCCESSFULLY! ===\n";

} catch (Exception $e) {
    echo "=== TEST FAILED! ===\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
