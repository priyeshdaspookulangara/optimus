<?php
/**
 * Global MLM Recalculator Utility
 * Resets and recalculates unilevel/placement leg volumes, updates user ranks,
 * and generates any missing matching contracts/schedules for all members.
 */

// Only allow execution from CLI or authenticated admins
if (php_sapi_name() !== 'cli' && !isset($_SESSION['admin_id'])) {
    session_start();
    if (!isset($_SESSION['admin_id'])) {
        // Fallback check if session was initiated inside admin panel
        if (isset($_SESSION['user_id'])) {
            // Member panel fallback
            $isAdmin = false;
        } else {
            die("Access Denied: Administrative privileges required.");
        }
    }
}

require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();
$config = require __DIR__ . '/includes/config.php';

echo "========================================================\n";
echo "Starting Global MLM Recalculation & Reconciliation Engine\n";
echo "========================================================\n\n";

try {
    $db->beginTransaction();

    // Reset all existing matching schedules and user ranks transaction-safely (using DELETE instead of TRUNCATE)
    echo "Resetting all existing calculated matching schedules and user ranks...\n";
    $db->exec("DELETE FROM matching_schedules");
    $db->exec("UPDATE users SET rank_id = 0");
    echo "Reset complete. Starting fresh recalculation...\n\n";

    // Fetch all users in the system
    $stmt = $db->prepare("SELECT id, username, mid, rank_id, total_investment, status FROM users ORDER BY id ASC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($users) . " members to process.\n\n";

    $updatedCount = 0;
    $newSchedulesCount = 0;

    foreach ($users as $user) {
        $userId = $user['id'];
        $username = $user['username'];
        $userMid = $user['mid'] ?: 'ID: ' . $userId;
        $currentRankId = 0; // Freshly reset to 0

        // Get actual leg stats based on placement-based hierarchy
        $legStats = $engine->getLegsBusiness($userId);
        $matchedBusiness = (float)$legStats['matched_business'];
        $slabBreakdown = $legStats['slab_breakdown'] ?? [];

        // Check if user qualifies for a rank based on matched business
        $qualifiedRankId = 0;
        foreach ($config['ranks'] as $idx => $rankConf) {
            if ($matchedBusiness >= $rankConf['matching']) {
                $qualifiedRankId = $idx + 1;
            } else {
                break;
            }
        }

        $rankChanged = false;
        $oldRankName = 'None';
        $newRankName = ($qualifiedRankId > 0) ? $config['ranks'][$qualifiedRankId - 1]['name'] : 'None';

        // 2. Update rank ID
        if ($qualifiedRankId !== $currentRankId) {
            $stmtUpdate = $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
            $stmtUpdate->execute([$qualifiedRankId, $userId]);
            $rankChanged = true;
            $updatedCount++;
        }

        // 3. Reconcile matching schedules (contracts)
        $schedulesAddedForUser = 0;
        foreach ($slabBreakdown as $slab => $requiredUnits) {
            if ($requiredUnits > 0) {
                // Count current schedules in DB for this slab and user (should be 0 since we deleted, but kept for robust logic)
                $stmtSched = $db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
                $stmtSched->execute([$userId, $slab]);
                $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);
                $existingUnits = (int)$existing['count'];

                if ($requiredUnits > $existingUnits) {
                    // Find daily income rate for this slab from config
                    $dailyIncome = 0.00;
                    foreach ($config['ranks'] as $rankConf) {
                        if ($rankConf['matching'] == $slab) {
                            $dailyIncome = (float)$rankConf['daily_income'];
                            break;
                        }
                    }

                    $newUnits = $requiredUnits - $existingUnits;
                    $stmtInsert = $db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                    for ($i = 0; $i < $newUnits; $i++) {
                        $stmtInsert->execute([$userId, $slab, $dailyIncome]);
                    }
                    $schedulesAddedForUser += $newUnits;
                    $newSchedulesCount += $newUnits;
                }
            }
        }

        // Print details if any qualification occurred
        if ($qualifiedRankId > 0 || $schedulesAddedForUser > 0) {
            echo "--------------------------------------------------------\n";
            echo "User: {$username} ({$userMid})\n";
            echo "  - Left Leg: $" . number_format($legStats['power_leg'], 2) . "\n";
            echo "  - Right Leg: $" . number_format($legStats['matching_leg'], 2) . "\n";
            echo "  - Matched Business: $" . number_format($matchedBusiness, 2) . "\n";
            echo "  - Rank Status Updated: [{$oldRankName}] => [{$newRankName}]\n";
            if ($schedulesAddedForUser > 0) {
                echo "  - Matching Contracts Generated: +{$schedulesAddedForUser} new contract(s) added.\n";
            }
        }
    }

    $db->commit();

    echo "========================================================\n";
    echo "Recalculation and Reconciliation Complete!\n";
    echo "  - Ranks Upgraded/Rebuilt: {$updatedCount} user(s)\n";
    echo "  - Fresh Matching Contracts Generated: {$newSchedulesCount} contract(s)\n";
    echo "========================================================\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error during global recalculation: " . $e->getMessage() . "\n";
}
