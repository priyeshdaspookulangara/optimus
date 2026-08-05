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
     * Helper to recursively compute the total investment volume of a placement subtree.
     */
    private function getPlacementDescendantsVolume($parentId) {
        $total = 0.00;
        $queue = [$parentId];

        while (!empty($queue)) {
            $currentId = array_shift($queue);

            // Fetch direct placement children of the current node
            $stmt = $this->db->prepare("SELECT id, total_investment FROM users WHERE placement_id = ?");
            $stmt->execute([$currentId]);
            $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($children as $child) {
                $total += (float)$child['total_investment'];
                $queue[] = $child['id'];
            }
        }
        return $total;
    }

    /**
     * Calculate binary placement leg business volumes (Left placement subtree vs. Right placement subtree)
     * and apply the Sequential Slab-Matching Hierarchy (Single-Pass).
     */
    public function getLegsBusiness($userId) {
        // Find direct placement child for Left position
        $stmtLeft = $this->db->prepare("SELECT id, total_investment FROM users WHERE placement_id = ? AND position = 'left'");
        $stmtLeft->execute([$userId]);
        $leftUser = $stmtLeft->fetch(PDO::FETCH_ASSOC);

        // Find direct placement child for Right position
        $stmtRight = $this->db->prepare("SELECT id, total_investment FROM users WHERE placement_id = ? AND position = 'right'");
        $stmtRight->execute([$userId]);
        $rightUser = $stmtRight->fetch(PDO::FETCH_ASSOC);

        $leftVolume = 0.00;
        if ($leftUser) {
            $leftVolume = (float)$leftUser['total_investment'] + $this->getPlacementDescendantsVolume($leftUser['id']);
        }

        $rightVolume = 0.00;
        if ($rightUser) {
            $rightVolume = (float)$rightUser['total_investment'] + $this->getPlacementDescendantsVolume($rightUser['id']);
        }

        // Left leg volume and Right leg volume matching
        $powerLegRaw = max($leftVolume, $rightVolume);
        $restLegRaw = min($leftVolume, $rightVolume);

        /**
         * Apply Sequential Slab-Matching Hierarchy in Ascending Order from Config (Single-Pass).
         *
         * 1. Slabs are evaluated sequentially from smallest to largest (e.g. Mentor $500, Pioneer $1000, Elite $2500, etc.).
         * 2. If the user has sufficient volume to match the current slab, it matches exactly 1 unit of that slab,
         *    deducts the matched volume, and proceeds to the next larger slab.
         * 3. **Strict Sequential Restriction**: If any slab fails to match (e.g. volume is less than the slab),
         *    the matchmaking is immediately terminated ("break"). This guarantees that higher-tier units cannot be matched
         *    unless the user has already qualified for all lower-tier units sequentially.
         */
        $vPower = $powerLegRaw;
        $vRest = $restLegRaw;
        $totalMatched = 0.00;
        $slabBreakdown = [];

        // Dynamically fetch and sort matching slabs from config ranks in ascending order
        $slabs = [];
        foreach ($this->config['ranks'] as $rankConf) {
            $slabs[] = (int)$rankConf['matching'];
        }
        sort($slabs); // Ensure sorted in ascending order

        // Pre-populate slab breakdown with 0 to prevent notice issues downstream
        foreach ($slabs as $slab) {
            $slabBreakdown[$slab] = 0;
        }

        foreach ($slabs as $slab) {
            $m = min($vPower, $vRest);
            if ($m >= $slab) {
                $units = 1; // Match exactly 1 unit of this slab in the ascending sequence
                $matchedVolume = $slab;

                $totalMatched += $matchedVolume;
                $vPower -= $matchedVolume;
                $vRest -= $matchedVolume;

                $slabBreakdown[$slab] = $units;
            } else {
                // If any slab fails to match sequentially, matchmaking is immediately terminated
                break;
            }
        }

        return [
            'power_leg' => (float)$powerLegRaw,
            'matching_leg' => (float)$restLegRaw,
            'matched_business' => (float)$totalMatched,
            'power_carry_forward' => (float)$vPower,
            'rest_carry_forward' => (float)$vRest,
            'slab_breakdown' => $slabBreakdown
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

                            // Propagate rank upward to conferred_ranks table
                            $this->propagateConferredRank($user['id'], $slab);
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
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
            }
        }

        // Process daily payouts for all active conferred ranks contracts
        $stmtActiveConferred = $this->db->prepare("
            SELECT cr.*, u.username as sponsor_username, d.username as downline_username
            FROM conferred_ranks cr
            JOIN users u ON cr.user_id = u.id
            JOIN users d ON cr.downline_id = d.id
            WHERE cr.status = 'active'
        ");
        $stmtActiveConferred->execute();
        $activeConferred = $stmtActiveConferred->fetchAll();

        foreach ($activeConferred as $cr) {
            $this->db->beginTransaction();
            try {
                $dailyIncome = (float)$cr['daily_income'];
                $userId = $cr['user_id'];

                $allowable = $this->getAllowableAmount($userId, $dailyIncome);
                if ($allowable > 0) {
                    // Log the RANK_INCOME transaction for the propagated sponsor
                    $this->logTransaction(
                        $userId,
                        'RANK_INCOME',
                        $allowable,
                        0,
                        "Daily Propagated Match Income from " . $cr['downline_username'] . " (Rank ID " . $cr['rank_id'] . ") (Day " . ($cr['days_passed'] + 1) . "/100)",
                        $cr['downline_id']
                    );

                    $newDaysPassed = $cr['days_passed'] + 1;
                    $status = ($newDaysPassed >= $cr['max_days']) ? 'completed' : 'active';

                    $stmtUpdateConferred = $this->db->prepare("UPDATE conferred_ranks SET days_passed = ?, status = ? WHERE id = ?");
                    $stmtUpdateConferred->execute([$newDaysPassed, $status, $cr['id']]);
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

    /**
     * Earning Limiter Check (ID Cap):
     *
     * Previously, this method checked and limited daily payouts based on a 300% ID Cap
     * relative to the user's total investment. To ensure there is completely no limitation
     * in total earnings and the 300% ID Cap is removed, this function has been updated to
     * always return the full amount to add directly without any caps or limits.
     *
     * @param int $userId The ID of the user.
     * @param float $amountToAdd The pending commission/ROI payout amount.
     * @return float The allowable amount to pay (unlimited/uncapped).
     */
    private function getAllowableAmount($userId, $amountToAdd) {
        return (float)$amountToAdd;
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

                            // Propagate rank upward to conferred_ranks table
                            $this->propagateConferredRank($ancestorId, $slab);
                        }
                    }
                }
            }
        }
    }

    /**
     * Fetch all sponsor uplines recursively all the way to root (with no level limit).
     */
    public function getAllSponsorUplines($userId) {
        $uplines = [];
        $currentUserId = $userId;

        while (true) {
            $stmt = $this->db->prepare("
                SELECT u.sponsor_id, parent.id as parent_id, parent.username, parent.status, parent.rank_id
                FROM users u
                LEFT JOIN users parent ON u.sponsor_id = parent.id
                WHERE u.id = ?
            ");
            $stmt->execute([$currentUserId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$res || empty($res['sponsor_id']) || empty($res['parent_id'])) {
                break;
            }

            $uplines[] = [
                'parent_id' => (int)$res['parent_id'],
                'username' => $res['username'],
                'status' => $res['status'],
                'rank_id' => (int)$res['rank_id']
            ];

            $currentUserId = (int)$res['sponsor_id'];
        }

        return $uplines;
    }

    /**
     * Propagate a matched slab achievement upwards to all active qualified uplines
     * by creating active contracts in the 'conferred_ranks' table.
     */
    public function propagateConferredRank($downlineId, $slabAmount) {
        // Find matching rank level corresponding to this slab
        $requiredRankId = 0;
        $dailyIncome = 0.00;
        foreach ($this->config['ranks'] as $idx => $rankConf) {
            if ($rankConf['matching'] == $slabAmount) {
                $requiredRankId = $idx + 1;
                $dailyIncome = $rankConf['daily_income'];
                break;
            }
        }

        if ($requiredRankId === 0) return;

        // Fetch sponsor uplines all the way up to root (no level limit)
        $uplines = $this->getAllSponsorUplines($downlineId);

        $stmtReferrals = $this->db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");
        $stmtCheck = $this->db->prepare("SELECT COUNT(*) as count FROM conferred_ranks WHERE user_id = ? AND downline_id = ? AND rank_id = ?");
        $stmtInsert = $this->db->prepare("INSERT INTO conferred_ranks (user_id, downline_id, rank_id, daily_income, days_passed, max_days, status) VALUES (?, ?, ?, ?, 0, 100, 'active')");

        $consecutiveSingleCount = 0;

        foreach ($uplines as $upline) {
            // Check if this parent has fewer than two direct referral branches
            $stmtReferrals->execute([$upline['parent_id']]);
            $refData = $stmtReferrals->fetch();
            $refCount = (int)$refData['ref_count'];

            if ($refCount <= 1) {
                $consecutiveSingleCount++;
            } else {
                $consecutiveSingleCount = 0;
            }

            // Stop propagation immediately on the consecutive 3rd parent with no two branches
            if ($consecutiveSingleCount >= 3) {
                break;
            }

            if ($upline['status'] === 'active') {
                // Conferred Rank Logic: The rank itself is dynamically conferred (assigned) to the upline sponsor
                if ($requiredRankId > (int)$upline['rank_id']) {
                    $stmtUpdateUserRank = $this->db->prepare("UPDATE users SET rank_id = ? WHERE id = ?");
                    $stmtUpdateUserRank->execute([$requiredRankId, $upline['parent_id']]);
                }

                // Ensure no duplicate contract for this specific downline rank achievement
                $stmtCheck->execute([$upline['parent_id'], $downlineId, $requiredRankId]);
                $exists = $stmtCheck->fetch();
                if ((int)$exists['count'] === 0) {
                    $stmtInsert->execute([$upline['parent_id'], $downlineId, $requiredRankId, $dailyIncome]);
                }
            }
        }
    }
}
