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

        $this->db->beginTransaction();
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

            // Update Binary Business volume for ancestors
            $stmt = $this->db->prepare("SELECT placement_id, position FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user && $user['placement_id']) {
                $this->updateBinaryBusiness($user['placement_id'], $user['position'], $packageAmount);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
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
     * Matching Engine: Identify Power Leg and calculate Rank Income
     */
    public function processRankIncome() {
        $stmt = $this->db->prepare("SELECT id, left_leg_business, right_leg_business, rank_id, rank_income_days FROM users WHERE status = 'active'");
        $stmt->execute();
        $users = $stmt->fetchAll();

        foreach ($users as $user) {
            $matchingLeg = min($user['left_leg_business'], $user['right_leg_business']);

            $currentRankId = $this->checkRankQualification($matchingLeg);

            // Handle Rank Upgrade
            if ($currentRankId !== null && $currentRankId > $user['rank_id']) {
                $updateRank = $this->db->prepare("UPDATE users SET rank_id = ?, rank_income_days = 0 WHERE id = ?");
                $updateRank->execute([$currentRankId, $user['id']]);
                $user['rank_id'] = $currentRankId;
                $user['rank_income_days'] = 0;
            }

            // Distribute Daily Rank Income (for 100 days)
            if ($user['rank_id'] > 0 && $user['rank_income_days'] < 100) {
                $rankData = $this->config['ranks'][$user['rank_id'] - 1];
                $dailyRankIncome = $rankData['daily_income'];

                $allowable = $this->getAllowableAmount($user['id'], $dailyRankIncome);
                if ($allowable > 0) {
                    $this->logTransaction($user['id'], 'RANK_INCOME', $allowable, 0, "Daily Rank Income for rank: {$rankData['name']}");
                    $updateDays = $this->db->prepare("UPDATE users SET rank_income_days = rank_income_days + 1 WHERE id = ?");
                    $updateDays->execute([$user['id']]);
                }
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
        // Only sum income-generating types for the cap
        $stmt = $this->db->prepare("SELECT total_investment, (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned FROM users WHERE id = ?");
        $stmt->execute([$userId, $userId]);
        $user = $stmt->fetch();

        $maxCap = $user['total_investment'] * $this->config['id_cap_multiplier'];
        $remainingCap = $maxCap - $user['total_earned'];

        if ($remainingCap <= 0) return 0;
        return min($amountToAdd, $remainingCap);
    }

    public function logTransaction($userId, $type, $amount, $fee, $description, $relatedUserId = null, $investmentId = null, $level = null) {
        // Signage: Income types are positive, Expense/Debit types are negative
        $isDebit = in_array($type, ['WITHDRAWAL', 'INVESTMENT']);

        if ($isDebit) {
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
     * Add user to genealogy tree
     */
    public function addToGenealogy($userId, $sponsorId, $placementId, $position) {
        // Direct sponsor as Level 1 in genealogy (unilevel style for commission)
        $stmt = $this->db->prepare("INSERT INTO genealogy (user_id, parent_id, level) VALUES (?, ?, 1)");
        $stmt->execute([$userId, $sponsorId]);

        // Inherit parents from sponsor for unilevel commissions
        $stmt = $this->db->prepare("INSERT INTO genealogy (user_id, parent_id, level) SELECT ?, parent_id, level + 1 FROM genealogy WHERE user_id = ? AND level < 12");
        $stmt->execute([$userId, $sponsorId]);

        // Update binary business volume
        $this->updateBinaryBusiness($placementId, $position, 0); // Initial business is 0
    }

    public function updateBinaryBusiness($placementId, $position, $amount) {
        $currentId = $placementId;
        $currentPosition = $position;

        while ($currentId !== null) {
            if ($currentPosition == 'left') {
                $stmt = $this->db->prepare("UPDATE users SET left_leg_business = left_leg_business + ? WHERE id = ?");
            } else {
                $stmt = $this->db->prepare("UPDATE users SET right_leg_business = right_leg_business + ? WHERE id = ?");
            }
            $stmt->execute([$amount, $currentId]);

            // Move up the binary tree
            $stmt = $this->db->prepare("SELECT placement_id, position FROM users WHERE id = ?");
            $stmt->execute([$currentId]);
            $parent = $stmt->fetch();

            if (!$parent || $parent['placement_id'] === null) break;

            $currentPosition = $parent['position'];
            $currentId = $parent['placement_id'];
        }
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

        if ($user['balance'] < $amount) {
            throw new Exception("Insufficient balance");
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

        $this->db->beginTransaction();
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

            $this->logTransaction($userId, 'INVESTMENT', $package['amount'], 0, "Package activated via PIN: {$pinCode}", null, $investmentId);

            // Distribute commissions
            $this->distributeLevelIncome($userId, $package['amount']);

            // Update Binary Business
            $stmt = $this->db->prepare("SELECT placement_id, position FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user && $user['placement_id']) {
                $this->updateBinaryBusiness($user['placement_id'], $user['position'], $package['amount']);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
