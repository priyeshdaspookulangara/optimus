<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication Check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$engine = new MLMEngine();
$config = require __DIR__ . '/../includes/config.php';
$levelPercentages = $config['level_percentages'];

$successMessage = '';
$errorMessage = '';

// Handle manual fix action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMessage = "CSRF token validation failed.";
    } elseif ($_POST['action'] === 'fix_level_income') {
        $investmentId = (int)$_POST['investment_id'];
        $parentId = (int)$_POST['parent_id'];
        $level = (int)$_POST['level'];
        $expectedAmount = (float)$_POST['expected_amount'];
        $relatedUserId = (int)$_POST['related_user_id'];

        try {
            $db->beginTransaction();

            // Double check existing level income transaction to prevent duplicate fixes
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) as count
                FROM transactions
                WHERE user_id = ? AND related_user_id = ? AND investment_id = ? AND level = ? AND type = 'LEVEL_INCOME'
            ");
            $stmtCheck->execute([$parentId, $relatedUserId, $investmentId, $level]);
            if ($stmtCheck->fetch()['count'] > 0) {
                throw new Exception("Level income has already been credited or fixed.");
            }

            // Get original related user username
            $stmtUser = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->execute([$relatedUserId]);
            $relUser = $stmtUser->fetch();
            $relUsername = $relUser ? $relUser['username'] : "User ID $relatedUserId";

            // Recalculate remaining cap
            $stmtCap = $db->prepare("SELECT total_investment, (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned FROM users WHERE id = ?");
            $stmtCap->execute([$parentId, $parentId]);
            $userCap = $stmtCap->fetch();

            $maxCap = $userCap['total_investment'] * $config['id_cap_multiplier'];
            $remainingCap = $maxCap - $userCap['total_earned'];

            $allowableAmount = min($expectedAmount, $remainingCap);

            if ($allowableAmount <= 0) {
                throw new Exception("Upline user (ID $parentId) has no remaining Cap balance. Payout of \$$expectedAmount cannot be processed.");
            }

            // Credit the transaction
            $engine->logTransaction(
                $parentId,
                'LEVEL_INCOME',
                $allowableAmount,
                0,
                "Manual Level $level income fix from $relUsername (Investment ID: $investmentId)",
                $relatedUserId,
                $investmentId,
                $level
            );

            $db->commit();
            $successMessage = "Successfully credited Level $level income of \$" . number_format($allowableAmount, 2) . " to parent ID $parentId.";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    }
}

// Search / Filter inputs
$searchQuery = $_GET['search'] ?? '';

// Fetch all investments with their purchasers and packages info
$investQuery = "
    SELECT i.*, u.username, u.mid as user_mid
    FROM investments i
    JOIN users u ON i.user_id = u.id
";
$params = [];
if (!empty($searchQuery)) {
    $investQuery .= " WHERE u.username LIKE ? OR u.mid LIKE ? ";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}
$investQuery .= " ORDER BY i.created_at DESC";

$stmtInvests = $db->prepare($investQuery);
$stmtInvests->execute($params);
$investments = $stmtInvests->fetchAll();

$pageTitle = 'Level Income Audit & Fixer';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Level Income Distribution Audit & Verification</h3>
    </div>

    <!-- Search/Filter -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-9">
            <input type="text" name="search" class="form-control" placeholder="Search by purchaser username or MID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>Search</button>
            <a href="check_level_income.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if (!empty($successMessage)): ?>
    <div class="alert alert-success"><strong>Success:</strong> <?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fa fa-info-circle me-1"></i> How Audit Verification Works</h5>
    </div>
    <div class="card-body">
        <p class="mb-1">This tool dynamically inspects Level Income distributions down the 12 generations unilevel tree.</p>
        <p class="mb-1">For any chosen investment, it traces all qualified upline ancestors, calculates the expected Level percentage, checks active status, cap balances, and queries whether a valid transaction was recorded.</p>
        <p class="mb-0 text-muted"><strong>Inactive users</strong> or users with <strong>reached/exhausted caps</strong> will display details on why a transaction is absent, with full correction capabilities if an anomaly is detected.</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Inv ID</th>
                        <th>Purchased By (MID)</th>
                        <th>Package Amount</th>
                        <th>Date</th>
                        <th>Income Verification Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($investments)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No investments found matching the criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($investments as $inv):
                            // Quick verification status count
                            $stmtGenealogy = $db->prepare("SELECT parent_id, level FROM genealogy WHERE user_id = ? AND level <= 12 ORDER BY level ASC");
                            $stmtGenealogy->execute([$inv['user_id']]);
                            $parents = $stmtGenealogy->fetchAll();

                            $mismatchCount = 0;
                            foreach ($parents as $parent) {
                                $level = $parent['level'];
                                $pct = $levelPercentages[$level] ?? 0;
                                $expectedCommission = ($inv['amount'] * $pct) / 100;

                                // Query existing transactions (using both related_user_id and investment_id for maximum precision, or fallback to related_user_id + level + type)
                                $stmtTx = $db->prepare("
                                    SELECT amount
                                    FROM transactions
                                    WHERE user_id = ?
                                      AND type = 'LEVEL_INCOME'
                                      AND related_user_id = ?
                                      AND (investment_id = ? OR level = ?)
                                ");
                                $stmtTx->execute([$parent['parent_id'], $inv['user_id'], $inv['id'], $level]);
                                $tx = $stmtTx->fetch();

                                if ($expectedCommission > 0) {
                                    // Verify parent active status
                                    $stmtPStatus = $db->prepare("SELECT status FROM users WHERE id = ?");
                                    $stmtPStatus->execute([$parent['parent_id']]);
                                    $pStatus = $stmtPStatus->fetch()['status'] ?? 'inactive';

                                    if ($pStatus === 'active') {
                                        if (!$tx || abs($tx['amount'] - $expectedCommission) > 0.01) {
                                            $mismatchCount++;
                                        }
                                    }
                                }
                            }
                        ?>
                        <tr>
                            <td><strong>#<?php echo $inv['id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($inv['username']); ?></strong>
                                (<span class="text-primary font-monospace"><?php echo htmlspecialchars($inv['user_mid']); ?></span>)
                            </td>
                            <td><span class="text-success fw-bold">$<?php echo number_format($inv['amount'], 2); ?></span></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($inv['created_at'])); ?></td>
                            <td>
                                <?php if ($mismatchCount === 0): ?>
                                    <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> Fully Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fa fa-exclamation-triangle me-1"></i> <?php echo $mismatchCount; ?> Mismatch(es)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#auditModal<?php echo $inv['id']; ?>">
                                    <i class="fa fa-search me-1"></i> Audit Details
                                </button>
                            </td>
                        </tr>

                        <!-- Audit Modal for this specific investment -->
                        <div class="modal fade" id="auditModal<?php echo $inv['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title"><i class="fa fa-stethoscope me-1"></i> Level Income Audit - Investment #<?php echo $inv['id']; ?></h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3 bg-light p-3 rounded mx-1">
                                            <div class="col-md-3"><strong>Investment ID:</strong> #<?php echo $inv['id']; ?></div>
                                            <div class="col-md-3"><strong>Purchased By:</strong> <?php echo htmlspecialchars($inv['username']); ?></div>
                                            <div class="col-md-3"><strong>Package Amount:</strong> $<?php echo number_format($inv['amount'], 2); ?></div>
                                            <div class="col-md-3"><strong>Purchase Date:</strong> <?php echo date('Y-m-d H:i:s', strtotime($inv['created_at'])); ?></div>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped align-middle text-center">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>Level</th>
                                                        <th>Upline Member</th>
                                                        <th>Expected %</th>
                                                        <th>Expected Amt</th>
                                                        <th>Actual Credited</th>
                                                        <th>Parent Status</th>
                                                        <th>Cap Status / Verification</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($parents)): ?>
                                                        <tr>
                                                            <td colspan="8" class="text-center text-muted">No upline ancestors found in genealogy tree.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($parents as $parent):
                                                            $level = $parent['level'];
                                                            $pct = $levelPercentages[$level] ?? 0;
                                                            $expectedCommission = ($inv['amount'] * $pct) / 100;

                                                            // Fetch actual parent details
                                                            $stmtParentUser = $db->prepare("SELECT id, username, mid, status, total_investment FROM users WHERE id = ?");
                                                            $stmtParentUser->execute([$parent['parent_id']]);
                                                            $parentData = $stmtParentUser->fetch();

                                                            // Fetch actual credited amount
                                                            $stmtTxDetail = $db->prepare("
                                                                SELECT amount, created_at
                                                                FROM transactions
                                                                WHERE user_id = ?
                                                                  AND type = 'LEVEL_INCOME'
                                                                  AND related_user_id = ?
                                                                  AND (investment_id = ? OR level = ?)
                                                            ");
                                                            $stmtTxDetail->execute([$parent['parent_id'], $inv['user_id'], $inv['id'], $level]);
                                                            $txDetail = $stmtTxDetail->fetch();
                                                            $actualCredited = $txDetail ? (float)$txDetail['amount'] : 0.00;

                                                            // Remaining Cap Calculation for verification explanation
                                                            $stmtCapDetail = $db->prepare("SELECT (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned");
                                                            $stmtCapDetail->execute([$parent['parent_id']]);
                                                            $capData = $stmtCapDetail->fetch();
                                                            $totalEarned = (float)($capData['total_earned'] ?? 0.00);
                                                            $maxCap = (float)($parentData['total_investment'] ?? 0.00) * $config['id_cap_multiplier'];
                                                            $remainingCap = $maxCap - $totalEarned;
                                                        ?>
                                                        <tr>
                                                            <td><span class="badge bg-secondary">Level <?php echo $level; ?></span></td>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($parentData['username'] ?? 'Unknown'); ?></strong><br>
                                                                <small class="text-primary font-monospace"><?php echo htmlspecialchars($parentData['mid'] ?? 'None'); ?></small>
                                                            </td>
                                                            <td><?php echo $pct; ?>%</td>
                                                            <td><span class="fw-bold">$<?php echo number_format($expectedCommission, 2); ?></span></td>
                                                            <td class="<?php echo $actualCredited > 0 ? 'text-success fw-bold' : 'text-danger'; ?>">
                                                                $<?php echo number_format($actualCredited, 2); ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge <?php echo ($parentData['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-warning'; ?>">
                                                                    <?php echo strtoupper($parentData['status'] ?? 'inactive'); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($actualCredited > 0): ?>
                                                                    <span class="text-success"><i class="fa fa-check-circle"></i> Paid correctly</span>
                                                                <?php else: ?>
                                                                    <?php if (($parentData['status'] ?? 'inactive') !== 'active'): ?>
                                                                        <span class="text-warning"><i class="fa fa-exclamation-triangle"></i> Skip: Parent inactive</span>
                                                                    <?php elseif ($remainingCap <= 0): ?>
                                                                        <span class="text-danger"><i class="fa fa-ban"></i> Skip: Cap Limit reached ($<?php echo number_format($totalEarned, 2); ?> / $<?php echo number_format($maxCap, 2); ?>)</span>
                                                                    <?php else: ?>
                                                                        <span class="text-danger fw-bold"><i class="fa fa-times-circle"></i> Unpaid Anomaly</span>
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($actualCredited <= 0 && ($parentData['status'] ?? 'inactive') === 'active' && $remainingCap > 0): ?>
                                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to manually credit this missing level income?');">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                                        <input type="hidden" name="action" value="fix_level_income">
                                                                        <input type="hidden" name="investment_id" value="<?php echo $inv['id']; ?>">
                                                                        <input type="hidden" name="parent_id" value="<?php echo $parent['parent_id']; ?>">
                                                                        <input type="hidden" name="level" value="<?php echo $level; ?>">
                                                                        <input type="hidden" name="expected_amount" value="<?php echo $expectedCommission; ?>">
                                                                        <input type="hidden" name="related_user_id" value="<?php echo $inv['user_id']; ?>">
                                                                        <button type="submit" class="btn btn-success btn-xs">
                                                                            <i class="fa fa-wrench"></i> Credit / Fix
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <button class="btn btn-secondary btn-sm" disabled><i class="fa fa-ban"></i> No fix required</button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
