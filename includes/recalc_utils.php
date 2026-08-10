<?php
// Core recalculation utilities for Rank daily income and matching schedules

/**
 * Helper to calculate expected rank daily payout dates based on 5:00 AM calculation thresholds
 */
function getExpectedRankPayoutDates($createdAt, $nowStr = null) {
    if ($nowStr === null) {
        $nowStr = date('Y-m-d H:i:s');
    }
    $creationDateStr = date('Y-m-d', strtotime($createdAt));
    $creationTimeStr = date('H:i:s', strtotime($createdAt));

    // If matching schedule is created before 05:00:00, first payout is on creation date itself
    if ($creationTimeStr < '05:00:00') {
        $firstPayoutDate = $creationDateStr;
    } else {
        // Otherwise, first payout is the next day morning
        $firstPayoutDate = date('Y-m-d', strtotime($creationDateStr . ' +1 day'));
    }

    $nowDateStr = date('Y-m-d', strtotime($nowStr));
    $nowTimeStr = date('H:i:s', strtotime($nowStr));

    // If current time is before 05:00:00, last possible payout date is yesterday
    if ($nowTimeStr < '05:00:00') {
        $lastPayoutDate = date('Y-m-d', strtotime($nowDateStr . ' -1 day'));
    } else {
        // Otherwise, last payout date is today
        $lastPayoutDate = $nowDateStr;
    }

    $payoutDates = [];
    $currentPtr = $firstPayoutDate;
    while ($currentPtr <= $lastPayoutDate) {
        $payoutDates[] = $currentPtr;
        $currentPtr = date('Y-m-d', strtotime($currentPtr . ' +1 day'));
    }
    return $payoutDates;
}

/**
 * Perform correction for a single matching schedule
 */
function correctMatchingScheduleRankIncome($db, $engine, $schedId, $maxDays = 100) {
    // 1. Fetch matching schedule
    $stmt = $db->prepare("SELECT * FROM matching_schedules WHERE id = ?");
    $stmt->execute([$schedId]);
    $sched = $stmt->fetch();
    if (!$sched) return false;

    $userId = $sched['user_id'];
    $slabAmount = $sched['slab_amount'];
    $dailyIncome = $sched['daily_income'];

    // 2. Fetch user's details to get username
    $stmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $userObj = $stmtUser->fetch();
    $origUsername = $userObj ? $userObj['username'] : "user ID $userId";

    // 3. Determine expected daily payout dates from schedule creation date to today
    $payoutDates = getExpectedRankPayoutDates($sched['created_at']);

    // 4. Delete existing direct rank income transactions for this schedule for this user
    $slabDesc = "Slab \$" . number_format($slabAmount, 2);
    $stmtDelDirect = $db->prepare("DELETE FROM transactions WHERE user_id = ? AND type = 'RANK_INCOME' AND related_user_id IS NULL AND description LIKE ?");
    $stmtDelDirect->execute([$userId, "%" . $slabDesc . "%"]);

    // 5. Delete existing propagated rank income transactions originating from this user with this slab
    $stmtDelProp = $db->prepare("DELETE FROM transactions WHERE related_user_id = ? AND type = 'RANK_INCOME' AND description LIKE ?");
    $stmtDelProp->execute([$userId, "%" . $slabDesc . "%"]);

    // 6. Recalculate day-by-day
    $daysPassed = 0;
    $status = 'active';

    $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
    $stmtUplines = $db->prepare("
        SELECT g.parent_id, u.username, u.status
        FROM genealogy g
        JOIN users u ON g.parent_id = u.id
        WHERE g.user_id = ?
        ORDER BY g.level ASC
    ");

    foreach ($payoutDates as $payoutDate) {
        if ($daysPassed >= $maxDays) {
            $status = 'completed';
            break;
        }

        // Check user's remaining ID cap allowance
        $allowable = $engine->getAllowableAmount($userId, $dailyIncome);
        if ($allowable > 0) {
            $daysPassed++;
            $status = ($daysPassed >= $maxDays) ? 'completed' : 'active';

            // Insert direct RANK_INCOME transaction
            $description = "Daily Matching Income for Slab \$" . number_format($slabAmount, 2) . " (Day " . $daysPassed . "/100) (Corrected)";
            $createdAtFormatted = $payoutDate . ' 05:00:00';

            $stmtIns = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at) VALUES (?, NULL, NULL, NULL, 'RANK_INCOME', ?, 0.00, ?, ?, ?)");
            $stmtIns->execute([
                $userId,
                $allowable,
                $allowable,
                $description,
                $createdAtFormatted
            ]);

            // Propagate to uplines at this historical moment
            $stmtUplines->execute([$userId]);
            $uplines = $stmtUplines->fetchAll();
            $consecutiveSingleCount = 0;

            foreach ($uplines as $upline) {
                // Check single referral orphan node limit
                $stmtReferrals->execute([$upline['parent_id']]);
                $refData = $stmtReferrals->fetch();
                $refCount = (int)$refData['ref_count'];

                if ($refCount === 1) {
                    $consecutiveSingleCount++;
                } else {
                    $consecutiveSingleCount = 0;
                }

                if ($consecutiveSingleCount > 3) {
                    break;
                }

                if ($upline['status'] === 'active') {
                    $uplineAllowable = $engine->getAllowableAmount($upline['parent_id'], $allowable);
                    if ($uplineAllowable > 0) {
                        $propDesc = "Daily Propagated Match Income from " . $origUsername . " (Slab \$" . number_format($slabAmount, 2) . ") (Corrected)";
                        $stmtInsProp = $db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description, created_at) VALUES (?, ?, NULL, NULL, 'RANK_INCOME', ?, 0.00, ?, ?, ?)");
                        $stmtInsProp->execute([
                            $upline['parent_id'],
                            $userId,
                            $uplineAllowable,
                            $uplineAllowable,
                            $propDesc,
                            $createdAtFormatted
                        ]);
                    }
                }

                if ($consecutiveSingleCount === 3) {
                    break;
                }
            }
        }
    }

    // 7. Update matching schedule
    $stmtUpd = $db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
    $stmtUpd->execute([$daysPassed, $status, $schedId]);

    return true;
}
