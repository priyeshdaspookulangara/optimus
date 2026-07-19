<?php
session_start();

// Strict admin session verification at the absolute top of the file
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../includes/db.php';
$db = Database::getInstance()->getConnection();

$pageTitle = 'Member Incomes';
include __DIR__ . '/includes/header.php';

// Fetch query filters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build database query
$query = "
    SELECT
        t.*,
        u_recv.username as receiver_username,
        u_recv.email as receiver_email,
        u_src.username as source_username
    FROM transactions t
    INNER JOIN users u_recv ON t.user_id = u_recv.id
    LEFT JOIN users u_src ON t.related_user_id = u_src.id
    WHERE t.type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')
";

$params = [];

if (!empty($search)) {
    $query .= " AND (u_recv.username LIKE ? OR u_src.username LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type_filter)) {
    $query .= " AND t.type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h3>Member Incomes Log</h3>
        <p class="text-muted mb-0">Track all income distributions, level payouts, daily ROI, and matching rank bonuses.</p>
    </div>
</div>

<!-- Filters Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-center">
            <div class="col-md-4">
                <label for="search" class="form-label font-bold">Search:</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Search receiver, source user..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <label for="type" class="form-label font-bold">Income Type:</label>
                <select name="type" id="type" class="form-select">
                    <option value="">-- All Incomes --</option>
                    <option value="ROI" <?php echo $type_filter == 'ROI' ? 'selected' : ''; ?>>ROI (Return on Investment)</option>
                    <option value="LEVEL_INCOME" <?php echo $type_filter == 'LEVEL_INCOME' ? 'selected' : ''; ?>>Level Income</option>
                    <option value="RANK_INCOME" <?php echo $type_filter == 'RANK_INCOME' ? 'selected' : ''; ?>>Rank / Matching Income</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2 w-50"><i class="fa fa-filter"></i> Filter</button>
                <a href="incomes.php" class="btn btn-outline-secondary w-50">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Incomes Listing -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Receiver Member</th>
                        <th>Income Type</th>
                        <th>Amount ($)</th>
                        <th>Source / Level</th>
                        <th>Detailed Description</th>
                        <th>Payout Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($incomes) > 0): ?>
                        <?php foreach ($incomes as $inc): ?>
                        <tr>
                            <td><?php echo $inc['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($inc['receiver_username']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($inc['receiver_email']); ?></small>
                            </td>
                            <td>
                                <?php if ($inc['type'] == 'ROI'): ?>
                                    <span class="badge bg-success">ROI Payout</span>
                                <?php elseif ($inc['type'] == 'LEVEL_INCOME'): ?>
                                    <span class="badge bg-primary">Level Commission</span>
                                <?php elseif ($inc['type'] == 'RANK_INCOME'): ?>
                                    <span class="badge bg-warning text-dark">Rank Bonus</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($inc['type']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold text-success">
                                $<?php echo number_format($inc['amount'], 2); ?>
                            </td>
                            <td>
                                <?php if ($inc['type'] == 'LEVEL_INCOME'): ?>
                                    <div class="fw-bold text-primary">Level <?php echo htmlspecialchars($inc['level']); ?></div>
                                    <small class="text-muted">From: <strong><?php echo htmlspecialchars($inc['source_username'] ?? 'N/A'); ?></strong></small>
                                <?php elseif ($inc['type'] == 'ROI'): ?>
                                    <span class="text-muted">Investment ID #<?php echo htmlspecialchars($inc['investment_id'] ?? 'N/A'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Global matching</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($inc['description']); ?>
                            </td>
                            <td>
                                <?php echo date('Y-m-d H:i:s', strtotime($inc['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No income records found matching your filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
