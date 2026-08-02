<?php

require_once __DIR__ . '/db.php';

class MLMEngine {
    private $db;
    private $config;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->config = require __DIR__ . '/config.php';
    }

    /**
     * Create a new investment for a user
     */
    public function createInvestment($userId, $packageAmount) {
        if (!in_array($packageAmount, $this->config['packages'])) {
            throw new Exception("Invalid package amount");
        }

        $isNested = $this->db->inTransaction();
        if (!$isNested) {
            $this->db->beginTransaction();
        }

        try {
            // Find package ID (in this simplified schema, we can match by amount)
            $stmt = $this->db->prepare("SELECT id FROM packages WHERE amount = ?");
            $stmt->execute([$packageAmount]);
            $package = $stmt->fetch();

            if (!$package) {
                // Auto-create package if it doesn't exist for simplicity in this demo
                $stmt = $this->db->prepare("INSERT INTO packages (name, amount) VALUES (?, ?)");
                $stmt->execute(["Package \${$packageAmount}", $packageAmount]);
                $packageId = $this->db->lastInsertId();
            } else {
                $packageId = $package['id'];
            }

            // Record Investment
            $stmt = $this->db->prepare("INSERT INTO investments (user_id, package_id, amount, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$userId, $packageId, $packageAmount]);
            $investmentId = $this->db->lastInsertId();

            // Update user total investment
            $stmt = $this->db->prepare("UPDATE users SET total_investment = total_investment + ? WHERE id = ?");
            $stmt->execute([$packageAmount, $userId]);

            // Log Transaction
            $this->logTransaction($userId, 'INVESTMENT', $packageAmount, 0, "Purchased package \${$packageAmount}", null, $investmentId);

            // Distribute Level Income (Recursive up to 12 levels)
            $this->distributeLevelIncome($userId, $packageAmount);

            // Instantly evaluate leg business, ranks, and matching schedules for all ancestors
            $this->updateUplineRanks($userId);

            if (!$isNested) {
                $this->db->commit();
            }
            return true;
        } catch (Exception $e) {
            if (!$isNested && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ROI Engine: Process daily ROI for all active investments
     */
    public function processDailyROI() {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT i.*, u.id as user_id FROM investments i JOIN users u ON i.user_id = u.id WHERE i.status = 'active' AND (i.last_roi_at IS NULL OR i.last_roi_at < ?)");
        $stmt->execute([$today]);
        $investments = $stmt->fetchAll();

        foreach ($investments as $investment) {
            $this->db->beginTransaction();
            try {
                $dailyRate = $this->config['roi']['daily_rate'];
                $roiAmount = $investment['amount'] * $dailyRate;

                // Check Max Cap 200% over 400 days
                $maxROI = $investment['amount'] * $this->config['roi']['cap_multiplier'];
                $newROIEarned = $investment['roi_earned'] + $roiAmount;
                $newDaysPassed = $investment['days_passed'] + 1;

                if ($newROIEarned >= $maxROI || $newDaysPassed >= $this->config['roi']['max_days']) {
                    $roiAmount = max(0, $maxROI - $investment['roi_earned']);
                    $newROIEarned = $maxROI;
                    $status = 'completed';
                } else {
                    $status = 'active';
                }

                // Total ID Cap Check (300%)
                $allowableROI = $this->getAllowableAmount($investment['user_id'], $roiAmount);
                if ($allowableROI > 0) {
                    $this->logTransaction($investment['user_id'], 'ROI', $allowableROI, 0, "Daily ROI for investment ID: {$investment['id']}", null, $investment['id']);

                    $updateStmt = $this->db->prepare("UPDATE investments SET roi_earned = ?, total_earned = total_earned + ?, days_passed = ?, last_roi_at = ?, status = ? WHERE id = ?");
                    $updateStmt->execute([$newROIEarned, $allowableROI, $newDaysPassed, $today, $status, $investment['id']]);

                    if ($allowableROI < $roiAmount) {
                        $updateStmt = $this->db->prepare("UPDATE investments SET status = 'capped' WHERE id = ?");
                        $updateStmt->execute([$investment['id']]);
                    }
                } else {
                    $updateStmt = $this->db->prepare("UPDATE investments SET status = 'capped' WHERE id = ?");
                    $updateStmt->execute([$investment['id']]);
                }

                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                // Log error
            }
        }
    }

    /**
     * Level Income: Distribute commission up to 12 generations
     */
    public function distributeLevelIncome($userId, $investmentAmount) {
        $stmt = $this->db->prepare("SELECT parent_id, level FROM genealogy WHERE user_id = ? AND level <= 12 ORDER BY level ASC");
        $stmt->execute([$userId]);
        $parents = $stmt->fetchAll();

        foreach ($parents as $parent) {
            $level = $parent['level'];
            if (isset($this->config['level_percentages'][$level])) {
                $percentage = $this->config['level_percentages'][$level];
                $commission = ($investmentAmount * $percentage) / 100;

                $allowable = $this->getAllowableAmount($parent['parent_id'], $commission);
                if ($allowable > 0) {
                    $this->logTransaction($parent['parent_id'], 'LEVEL_INCOME', $allowable, 0, "Level {$level} income from user ID: {$userId}", $userId, null, $level);
                }
            }
        }
    }

    /**
     * Calculate unilevel leg business volumes and apply the
     * Sequential Slab-Matching Hierarchy (500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000)
     */
    public function getLegsBusiness($userId) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username,
                   (u.total_investment + COALESCE((
                       SELECT SUM(downline.total_investment)
                       FROM genealogy g
                       JOIN users downline ON g.user_id = downline.id
                       WHERE g.parent_id = u.id
                   ), 0)) as total_leg_business
            FROM users u
            WHERE u.sponsor_id = ?
        ");
        $stmt->execute([$userId]);
        $legs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($legs)) {
            return [
                'power_leg' => 0.00,
                'matching_leg' => 0.00,
                'matched_business' => 0.00,
                'power_carry_forward' => 0.00,
                'rest_carry_forward' => 0.00,
                'slab_breakdown' => []
            ];
        }

        // Raw Legs Calculation
        $volumes = array_column($legs, 'total_leg_business');
        $powerLegRaw = max($volumes);
        $totalVolume = array_sum($volumes);
        $restLegRaw = $totalVolume - $powerLegRaw;

        // Subtract already matched volume (all matching schedules created for this user) from raw leg volumes
        $stmtMatched = $this->db->prepare("SELECT COALESCE(SUM(slab_amount), 0) as total FROM matching_schedules WHERE user_id = ?");
        $stmtMatched->execute([$userId]);
        $matchedRes = $stmtMatched->fetch(PDO::FETCH_ASSOC);
        $matchedVolumeTotal = (float)($matchedRes['total'] ?? 0.00);

        $vPower = max(0.00, $powerLegRaw - $matchedVolumeTotal);
        $vRest = max(0.00, $restLegRaw - $matchedVolumeTotal);
        $totalMatched = 0.00;
        $slabBreakdown = [];

        // Apply Sequential Slab-Matching Hierarchy (Ascending order with immediate termination, strictly 1 unit max per slab)
        $slabs = [500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000];
        $terminated = false;
        foreach ($slabs as $slab) {
            if ($terminated) {
                $slabBreakdown[$slab] = 0;
                continue;
            }
            $m = min($vPower, $vRest);
            if ($m >= $slab) {
                $units = 1; // Strictly 1 unit max per slab
                $matchedVolume = $slab;

                $totalMatched += $matchedVolume;
                $vPower -= $matchedVolume;
                $vRest -= $matchedVolume;

                $slabBreakdown[$slab] = $units;
            } else {
                $slabBreakdown[$slab] = 0;
                $terminated = true; // Matchmaking is terminated immediately
            }
        }

        // Map slab breakdown to include historical matched units so that processRankIncome and updateUplineRanks can correctly sync/compare
        $stmtHist = $this->db->prepare("SELECT slab_amount, COUNT(*) as count FROM matching_schedules WHERE user_id = ? GROUP BY slab_amount");
        $stmtHist->execute([$userId]);
        $histSchedules = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
        $histCounts = [];
        foreach ($histSchedules as $hist) {
            $histCounts[(int)$hist['slab_amount']] = (int)$hist['count'];
        }

        $cumulativeSlabBreakdown = [];
        foreach ($slabs as $slab) {
            $existingUnits = isset($histCounts[$slab]) ? $histCounts[$slab] : 0;
            $cumulativeSlabBreakdown[$slab] = $existingUnits + (isset($slabBreakdown[$slab]) ? $slabBreakdown[$slab] : 0);
        }

        return [
            'power_leg' => (float)$powerLegRaw,
            'matching_leg' => (float)$restLegRaw,
            'matched_business' => (float)($matchedVolumeTotal + $totalMatched),
            'power_carry_forward' => (float)$vPower,
            'rest_carry_forward' => (float)$vRest,
            'slab_breakdown' => $cumulativeSlabBreakdown
        ];
    }

    /**
     * Matching Engine: Identify Power Leg and calculate Rank Income using Sequential Slab-Matching Hierarchy
     * and track active 100-day schedules per slab unit.
     */
    public function processRankIncome() {
        $stmt = $this->db->prepare("SELECT id, rank_id FROM users WHERE status = 'active'");
        $stmt->execute();
        $users = $stmt->fetchAll();

        foreach ($users as $user) {
            $legStats = $this->getLegsBusiness($user['id']);
            $matchedBusiness = $legStats['matched_business']; // Strictly uses exhausted slab-matched business
            $slabBreakdown = $legStats['slab_breakdown'] ?? [];

            $currentRankId = $this->checkRankQualification($matchedBusiness);

            // Handle Rank Upgrade
            if ($currentRankId !== null && $currentRankId > $user['rank_id']) {
                $updateRank = $this->db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                $updateRank->execute([$currentRankId, $user['id']]);
                $user['rank_id'] = $currentRankId;
            }

            // Sync/Create new matching schedules if currently qualified units > existing registered matching schedules
            foreach ($slabBreakdown as $slab => $requiredUnits) {
                if ($requiredUnits > 0) {
                    $stmtSched = $this->db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
                    $stmtSched->execute([$user['id'], $slab]);
                    $existing = $stmtSched->fetch();
                    $existingUnits = (int)$existing['count'];

                    if ($requiredUnits > $existingUnits) {
                        // Find daily income rate for this slab from config
                        $dailyIncome = 0.00;
                        foreach ($this->config['ranks'] as $rankConf) {
                            if ($rankConf['matching'] == $slab) {
                                $dailyIncome = $rankConf['daily_income'];
                                break;
                            }
                        }

                        $newUnits = $requiredUnits - $existingUnits;
                        $stmtInsert = $this->db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                        for ($i = 0; $i < $newUnits; $i++) {
                            $stmtInsert->execute([$user['id'], $slab, $dailyIncome]);
                        }
                    }
                }
            }
        }

        // Process daily payouts for all active matching schedules
        $stmtActiveScheds = $this->db->prepare("SELECT * FROM matching_schedules WHERE status = 'active'");
        $stmtActiveScheds->execute();
        $activeScheds = $stmtActiveScheds->fetchAll();

        foreach ($activeScheds as $sched) {
            $this->db->beginTransaction();
            try {
                $dailyIncome = (float)$sched['daily_income'];
                $userId = $sched['user_id'];

                // Verify remaining ID cap (300%)
                $allowable = $this->getAllowableAmount($userId, $dailyIncome);
                if ($allowable > 0) {
                    // Log the RANK_INCOME transaction
                    $this->logTransaction(
                        $userId,
                        'RANK_INCOME',
                        $allowable,
                        0,
                        "Daily Matching Income for Slab \$" . number_format($sched['slab_amount'], 2) . " (Day " . ($sched['days_passed'] + 1) . "/100)"
                    );

                    $newDaysPassed = $sched['days_passed'] + 1;
                    $status = ($newDaysPassed >= $sched['max_days']) ? 'completed' : 'active';

                    $stmtUpdateSched = $this->db->prepare("UPDATE matching_schedules SET days_passed = ?, status = ? WHERE id = ?");
                    $stmtUpdateSched->execute([$newDaysPassed, $status, $sched['id']]);

                    // Propagate rank income up to root (the same paid amount, subject to each upline's individual active status and 300% ID Cap)
                    $stmtUplines = $this->db->prepare("
                        SELECT g.parent_id, u.username, u.status
                        FROM genealogy g
                        JOIN users u ON g.parent_id = u.id
                        WHERE g.user_id = ?
                        ORDER BY g.level ASC
                    ");
                    $stmtUplines->execute([$userId]);
                    $uplines = $stmtUplines->fetchAll();

                    // Get username of the original matching Earner for transaction logging
                    $stmtUser = $this->db->prepare("SELECT username FROM users WHERE id = ?");
                    $stmtUser->execute([$userId]);
                    $origUserObj = $stmtUser->fetch();
                    $origUsername = $origUserObj ? $origUserObj['username'] : "user ID $userId";

                    $stmtReferrals = $this->db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
                    $consecutiveSingleCount = 0;

                    foreach ($uplines as $upline) {
                        // Check if this parent has only one direct referral (single direct referral node)
                        $stmtReferrals->execute([$upline['parent_id']]);
                        $refData = $stmtReferrals->fetch();
                        $refCount = (int)$refData['ref_count'];

                        if ($refCount === 1) {
                            $consecutiveSingleCount++;
                        } else {
                            $consecutiveSingleCount = 0;
                        }

                        // If we already went past 3 consecutive single nodes, break immediately
                        if ($consecutiveSingleCount > 3) {
                            break;
                        }

                        if ($upline['status'] === 'active') {
                            $uplineAllowable = $this->getAllowableAmount($upline['parent_id'], $allowable);
                            if ($uplineAllowable > 0) {
                                $this->logTransaction(
                                    $upline['parent_id'],
                                    'RANK_INCOME',
                                    $uplineAllowable,
                                    0,
                                    "Daily Propagated Match Income from " . $origUsername . " (Slab \$" . number_format($sched['slab_amount'], 2) . ")",
                                    $userId
                                );
                            }
                        }

                        // Stop propagating further if we just paid the 3rd consecutive single referral node
                        if ($consecutiveSingleCount === 3) {
                            break;
                        }
                    }
                } else {
                    // ID cap reached, do not pay today and do not increment days_passed. Payout can resume when cap is lifted.
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
            }
        }

        // Process daily payouts for all active conferred ranks
        $stmtActiveConferred = $this->db->prepare("SELECT * FROM conferred_ranks WHERE status = 'active'");
        $stmtActiveConferred->execute();
        $activeConferred = $stmtActiveConferred->fetchAll();

        foreach ($activeConferred as $conf) {
            $this->db->beginTransaction();
            try {
                $dailyIncome = (float)$conf['daily_income'];
                $userId = $conf['user_id'];

                // Verify remaining ID cap (300%)
                $allowable = $this->getAllowableAmount($userId, $dailyIncome);
                if ($allowable > 0) {
                    // Get rank name
                    $rankName = isset($this->config['ranks'][$conf['rank_id'] - 1]) ? $this->config['ranks'][$conf['rank_id'] - 1]['name'] : "Rank level " . $conf['rank_id'];

                    // Log the RANK_INCOME transaction
                    $this->logTransaction(
                        $userId,
                        'RANK_INCOME',
                        $allowable,
                        0,
                        "Daily Conferred Rank Income for Rank " . htmlspecialchars($rankName) . " (Day " . ($conf['days_passed'] + 1) . "/100)"
                    );

                    $newDaysPassed = $conf['days_passed'] + 1;
                    $status = ($newDaysPassed >= $conf['max_days']) ? 'completed' : 'active';

                    $stmtUpdateConf = $this->db->prepare("UPDATE conferred_ranks SET days_passed = ?, status = ? WHERE id = ?");
                    $stmtUpdateConf->execute([$newDaysPassed, $status, $conf['id']]);

                    // Propagate conferred rank income up to root (subject to each upline's active status and 300% ID Cap, with 3 consecutive orphan nodes threshold)
                    $stmtUplines = $this->db->prepare("
                        SELECT g.parent_id, u.username, u.status
                        FROM genealogy g
                        JOIN users u ON g.parent_id = u.id
                        WHERE g.user_id = ?
                        ORDER BY g.level ASC
                    ");
                    $stmtUplines->execute([$userId]);
                    $uplines = $stmtUplines->fetchAll();

                    // Get username of original matching Earner for transaction logging
                    $stmtUser = $this->db->prepare("SELECT username FROM users WHERE id = ?");
                    $stmtUser->execute([$userId]);
                    $origUserObj = $stmtUser->fetch();
                    $origUsername = $origUserObj ? $origUserObj['username'] : "user ID $userId";

                    $stmtReferrals = $this->db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
                    $consecutiveSingleCount = 0;

                    foreach ($uplines as $upline) {
                        // Check if this parent has only one direct referral (single direct referral node)
                        $stmtReferrals->execute([$upline['parent_id']]);
                        $refData = $stmtReferrals->fetch();
                        $refCount = (int)$refData['ref_count'];

                        if ($refCount === 1) {
                            $consecutiveSingleCount++;
                        } else {
                            $consecutiveSingleCount = 0;
                        }

                        // If we already went past 3 consecutive single nodes, break immediately
                        if ($consecutiveSingleCount > 3) {
                            break;
                        }

                        if ($upline['status'] === 'active') {
                            $uplineAllowable = $this->getAllowableAmount($upline['parent_id'], $allowable);
                            if ($uplineAllowable > 0) {
                                $this->logTransaction(
                                    $upline['parent_id'],
                                    'RANK_INCOME',
                                    $uplineAllowable,
                                    0,
                                    "Daily Propagated Conferred Rank Income from " . $origUsername . " (Rank: " . htmlspecialchars($rankName) . ")",
                                    $userId
                                );
                            }
                        }

                        // Stop propagating further if we just paid the 3rd consecutive single referral node
                        if ($consecutiveSingleCount === 3) {
                            break;
                        }
                    }
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
            }
        }
    }

    private function checkRankQualification($matchingBusiness) {
        $qualifiedRankId = null;
        foreach ($this->config['ranks'] as $id => $rank) {
            if ($matchingBusiness >= $rank['matching']) {
                $qualifiedRankId = $id + 1;
            } else {
                break;
            }
        }
        return $qualifiedRankId;
    }

    private function getAllowableAmount($userId, $amountToAdd) {
        // No 300% ID Cap limit enforced (unlimited payouts)
        return $amountToAdd;
    }

    public function logTransaction($userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null, $customNetAmount = null) {
        // Signage: Income types are positive, Expense/Debit types are negative
        $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);

        if ($customNetAmount !== null) {
            $netAmount = $customNetAmount;
        } elseif ($isDebit) {
            // For withdrawals: total deduction = amount + fee (both should be negative for balance)
            $netAmount = -($amount + $fee);
        } else {
            // For income: total gain = amount - fee (e.g. tax, though usually income is net here)
            $netAmount = $amount - $fee;
        }

        $stmt = $this->db->prepare("INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $relatedUserId, $investmentId, $level, $type, $amount, $fee, $netAmount, $description]);
    }

    /**
     * Add user to genealogy tree (Unilevel tree up to 12 generations)
     */
    public function addToGenealogy($userId, $sponsorId, $placementId = null, $position = null) {
        // Direct sponsor as Level 1 in genealogy (unilevel style for commission)
        $stmt = $this->db->prepare("INSERT INTO genealogy (user_id, parent_id, level) VALUES (?, ?, 1)");
        $stmt->execute([$userId, $sponsorId]);

        // Inherit parents from sponsor for unilevel commissions
        $stmt = $this->db->prepare("INSERT INTO genealogy (user_id, parent_id, level) SELECT ?, parent_id, level + 1 FROM genealogy WHERE user_id = ? AND level < 12");
        $stmt->execute([$userId, $sponsorId]);
    }

    /**
     * Withdrawal System
     */
    public function requestWithdrawal($userId, $amount) {
        $minWithdrawal = $this->config['withdrawal']['min_amount'];
        $fee = $this->config['withdrawal']['fee'];

        if ($amount < $minWithdrawal) {
            throw new Exception("Minimum withdrawal is \${$minWithdrawal}");
        }

        $stmt = $this->db->prepare("SELECT (SELECT SUM(net_amount) FROM transactions WHERE user_id = ?) as balance");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Check if balance covers both the requested amount and the flat gas fee
        if ($user['balance'] < ($amount + $fee)) {
            throw new Exception("Insufficient balance to cover withdrawal amount and the \$" . $fee . " flat gas fee.");
        }

        $this->logTransaction($userId, 'WITHDRAWAL', $amount, $fee, "Withdrawal request of \${$amount}");
        return true;
    }

    /**
     * PIN Activation System
     */
    public function activateWithPin($userId, $pinCode) {
        $stmt = $this->db->prepare("SELECT * FROM pins WHERE pin_code = ? AND status = 'unused'");
        $stmt->execute([$pinCode]);
        $pin = $stmt->fetch();

        if (!$pin) {
            throw new Exception("Invalid or already used PIN");
        }

        $stmt = $this->db->prepare("SELECT amount FROM packages WHERE id = ?");
        $stmt->execute([$pin['package_id']]);
        $package = $stmt->fetch();

        if (!$package) {
            throw new Exception("Package associated with PIN no longer exists");
        }

        // Detect if parent caller already started a transaction to prevent PDO duplicate transaction crash
        $isNested = $this->db->inTransaction();
        if (!$isNested) {
            $this->db->beginTransaction();
        }

        try {
            // Mark PIN as used
            $stmt = $this->db->prepare("UPDATE pins SET status = 'used', used_by = ? WHERE id = ?");
            $stmt->execute([$userId, $pin['id']]);

            // Create Investment (Package is already paid for by PIN)
            // We use the amount but don't deduct from e-wallet
            $stmt = $this->db->prepare("INSERT INTO investments (user_id, package_id, amount, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$userId, $pin['package_id'], $package['amount']]);
            $investmentId = $this->db->lastInsertId();

            $stmt = $this->db->prepare("UPDATE users SET total_investment = total_investment + ? WHERE id = ?");
            $stmt->execute([$package['amount'], $userId]);

            // PIN activation is a prepaid investment, so custom net_amount = 0.00 prevents debiting user's e-wallet balance
            $this->logTransaction($userId, 'INVESTMENT', $package['amount'], 0, "Package activated via PIN: {$pinCode}", null, $investmentId, null, 0.00);

            // Distribute commissions
            $this->distributeLevelIncome($userId, $package['amount']);

            // Instantly evaluate leg business, ranks, and matching schedules for all ancestors
            $this->updateUplineRanks($userId);

            if (!$isNested) {
                $this->db->commit();
            }
            return true;
        } catch (Exception $e) {
            if (!$isNested && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Instantly evaluate dynamic leg business, update rank qualifications, and
     * sync matching schedules (contracts) for a user and all their upline sponsors.
     */
    public function updateUplineRanks($userId) {
        // Fetch all direct unilevel ancestors (parents in the genealogy tree) ordered by level ascending
        $stmt = $this->db->prepare("SELECT parent_id FROM genealogy WHERE user_id = ? ORDER BY level ASC");
        $stmt->execute([$userId]);
        $ancestors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Include the user themselves as well (if they purchased a package, their own matching legs could change)
        $targets = array_merge([['parent_id' => $userId]], $ancestors);

        foreach ($targets as $target) {
            $ancestorId = $target['parent_id'];
            if (empty($ancestorId)) continue;

            // Fetch ancestor's current info
            $stmtUser = $this->db->prepare("SELECT id, rank_id, status FROM users WHERE id = ?");
            $stmtUser->execute([$ancestorId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if (!$user) continue;

            $legStats = $this->getLegsBusiness($ancestorId);
            $matchedBusiness = $legStats['matched_business'];
            $slabBreakdown = $legStats['slab_breakdown'] ?? [];

            $qualifiedRankId = $this->checkRankQualification($matchedBusiness);

            // Handle Rank Upgrade
            if ($qualifiedRankId !== null && $qualifiedRankId > $user['rank_id']) {
                $updateRank = $this->db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                $updateRank->execute([$qualifiedRankId, $ancestorId]);
                $user['rank_id'] = $qualifiedRankId;

                // Award Conferred Ranks to all ancestors above this updated user
                $this->awardConferredRanks($ancestorId, $qualifiedRankId);
            }

            // Sync/Create new matching schedules if qualified units > existing registered matching schedules
            foreach ($slabBreakdown as $slab => $requiredUnits) {
                if ($requiredUnits > 0) {
                    $stmtSched = $this->db->prepare("SELECT COUNT(*) as count FROM matching_schedules WHERE user_id = ? AND slab_amount = ?");
                    $stmtSched->execute([$ancestorId, $slab]);
                    $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);
                    $existingUnits = (int)$existing['count'];

                    if ($requiredUnits > $existingUnits) {
                        // Find daily income rate for this slab from config
                        $dailyIncome = 0.00;
                        foreach ($this->config['ranks'] as $rankConf) {
                            if ($rankConf['matching'] == $slab) {
                                $dailyIncome = $rankConf['daily_income'];
                                break;
                            }
                        }

                        $newUnits = $requiredUnits - $existingUnits;
                        $stmtInsert = $this->db->prepare("INSERT INTO matching_schedules (user_id, slab_amount, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, 0, 100, 'active')");
                        for ($i = 0; $i < $newUnits; $i++) {
                            $stmtInsert->execute([$ancestorId, $slab, $dailyIncome]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Award Conferred Ranks to all ancestors above a user who just achieved/qualified for a rank
     */
    public function awardConferredRanks($userId, $rankId) {
        // Fetch all direct unilevel ancestors (parents in genealogy tree) of the user
        $stmt = $this->db->prepare("SELECT parent_id FROM genealogy WHERE user_id = ? ORDER BY level ASC");
        $stmt->execute([$userId]);
        $ancestors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Find daily income for this rank
        $dailyIncome = 0.00;
        foreach ($this->config['ranks'] as $idx => $r) {
            if ($idx + 1 == $rankId) {
                $dailyIncome = (float)$r['daily_income'];
                break;
            }
        }

        foreach ($ancestors as $ancestor) {
            $ancestorId = $ancestor['parent_id'];
            if (empty($ancestorId)) continue;

            // Check if the ancestor already has this rank (or higher) as a conferred rank or organic rank
            $stmtCheck = $this->db->prepare("SELECT COUNT(*) as count FROM conferred_ranks WHERE user_id = ? AND rank_id = ?");
            $stmtCheck->execute([$ancestorId, $rankId]);
            $exists = $stmtCheck->fetch();

            if ($exists['count'] == 0) {
                // Insert into conferred_ranks
                $stmtInsert = $this->db->prepare("INSERT INTO conferred_ranks (user_id, downline_id, rank_id, daily_income, status) VALUES (?, ?, ?, ?, 'active')");
                $stmtInsert->execute([$ancestorId, $userId, $rankId, $dailyIncome]);

                // Update ancestor's rank_id in users table if current rank is lower
                $stmtUser = $this->db->prepare("SELECT rank_id FROM users WHERE id = ?");
                $stmtUser->execute([$ancestorId]);
                $u = $stmtUser->fetch();
                if ($u && $rankId > $u['rank_id']) {
                    $stmtUpd = $this->db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                    $stmtUpd->execute([$rankId, $ancestorId]);
                }
            }
        }
    }
}
