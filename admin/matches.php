<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check before running state-changing operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$config = require __DIR__ . '/../includes/config.php';

// --- AJAX ENDPOINT: GET ELIGIBLE REFERRERS ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_referrers') {
    $matchId = (int)($_GET['match_id'] ?? 0);

    // Fetch match details
    $stmt = $db->prepare("
        SELECT m.*, u.username as achiever_username, u.mid as achiever_mid, u.id as achiever_id
        FROM matching_schedules m
        JOIN users u ON m.user_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Matching schedule not found']);
        exit();
    }

    // Fetch all genealogy upline ancestors/sponsors ordered by level ASC
    $stmt = $db->prepare("
        SELECT g.parent_id, g.level, u.username, u.mid, u.status
        FROM genealogy g
        JOIN users u ON g.parent_id = u.id
        WHERE g.user_id = ?
        ORDER BY g.level ASC
    ");
    $stmt->execute([$match['achiever_id']]);
    $uplines = $stmt->fetchAll();

    $referrers = [];
    $consecutiveSingleCount = 0;
    $propagationStopped = false;

    $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");

    foreach ($uplines as $upline) {
        $parentId = $upline['parent_id'];

        // Check direct referrals count for consecutive check
        $stmtReferrals->execute([$parentId]);
        $refData = $stmtReferrals->fetch();
        $refCount = (int)$refData['ref_count'];

        if ($refCount === 1) {
            $consecutiveSingleCount++;
        } else {
            $consecutiveSingleCount = 0;
        }

        $eligible = true;
        $reason = "";

        if ($propagationStopped) {
            $eligible = false;
            $reason = "Propagation halted by previous consecutive single referral limit";
        } else {
            if ($consecutiveSingleCount > 3) {
                $eligible = false;
                $reason = "Exceeded limit of 3 consecutive single-referral nodes";
                $propagationStopped = true;
            } else {
                // Check active status
                if ($upline['status'] !== 'active') {
                    $eligible = false;
                    $reason = "Referrer status is inactive/suspended";
                } else {
                    // Check remaining ID Cap
                    $stmtCap = $db->prepare("
                        SELECT total_investment,
                               (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned
                        FROM users WHERE id = ?
                    ");
                    $stmtCap->execute([$parentId, $parentId]);
                    $userCap = $stmtCap->fetch();

                    $maxCap = $userCap['total_investment'] * $config['id_cap_multiplier'];
                    $remainingCap = $maxCap - $userCap['total_earned'];

                    if ($remainingCap <= 0) {
                        $eligible = false;
                        $reason = "300% ID Cap reached (Earned: \${$userCap['total_earned']}, Cap: \${$maxCap})";
                    } else {
                        $eligible = true;
                        $reason = "Eligible (remaining cap: \$" . number_format($remainingCap, 2) . ")";
                    }
                }

                // If we just hit the 3rd consecutive single referrer, propagation stops after this one!
                if ($consecutiveSingleCount === 3) {
                    $propagationStopped = true;
                }
            }
        }

        $referrers[] = [
            'level' => $upline['level'],
            'user_id' => $parentId,
            'username' => $upline['username'],
            'mid' => $upline['mid'],
            'status' => $upline['status'],
            'ref_count' => $refCount,
            'consecutive_single_count' => $consecutiveSingleCount,
            'eligible' => $eligible,
            'reason' => $reason
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'match' => [
            'id' => $match['id'],
            'achiever' => $match['achiever_username'],
            'mid' => $match['achiever_mid'],
            'slab_amount' => $match['slab_amount'],
            'daily_income' => $match['daily_income']
        ],
        'referrers' => $referrers
    ]);
    exit();
}

// --- POST HANDLING: EXECUTE PROPAGATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'propagate') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $_SESSION['flash_error'] = "CSRF token validation failed.";
        header("Location: matches.php");
        exit();
    }

    $matchId = (int)($_POST['match_id'] ?? 0);

    $stmt = $db->prepare("
        SELECT m.*, u.username as achiever_username, u.id as achiever_id
        FROM matching_schedules m
        JOIN users u ON m.user_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        $_SESSION['flash_error'] = "Matching schedule not found.";
        header("Location: matches.php");
        exit();
    }

    $db->beginTransaction();
    try {
        // Fetch all genealogy upline ancestors/sponsors ordered by level ASC
        $stmt = $db->prepare("
            SELECT g.parent_id, g.level, u.username, u.mid, u.status
            FROM genealogy g
            JOIN users u ON g.parent_id = u.id
            WHERE g.user_id = ?
            ORDER BY g.level ASC
        ");
        $stmt->execute([$match['achiever_id']]);
        $uplines = $stmt->fetchAll();

        $consecutiveSingleCount = 0;
        $propagationStopped = false;
        $propagatedTo = [];

        $stmtReferrals = $db->prepare("SELECT COUNT(*) as ref_count FROM users WHERE sponsor_id = ?");

        foreach ($uplines as $upline) {
            $parentId = $upline['parent_id'];

            $stmtReferrals->execute([$parentId]);
            $refData = $stmtReferrals->fetch();
            $refCount = (int)$refData['ref_count'];

            if ($refCount === 1) {
                $consecutiveSingleCount++;
            } else {
                $consecutiveSingleCount = 0;
            }

            if ($propagationStopped) {
                continue;
            }

            if ($consecutiveSingleCount > 3) {
                $propagationStopped = true;
                continue;
            }

            if ($upline['status'] === 'active') {
                // Check ID Cap
                $stmtCap = $db->prepare("
                    SELECT total_investment,
                           (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')) as total_earned
                    FROM users WHERE id = ?
                ");
                $stmtCap->execute([$parentId, $parentId]);
                $userCap = $stmtCap->fetch();

                $maxCap = $userCap['total_investment'] * $config['id_cap_multiplier'];
                $remainingCap = $maxCap - $userCap['total_earned'];

                if ($remainingCap > 0) {
                    $allowable = min($match['daily_income'], $remainingCap);

                    // Insert RANK_INCOME transaction
                    $stmtInsert = $db->prepare("
                        INSERT INTO transactions (user_id, related_user_id, investment_id, level, type, amount, fee, net_amount, description)
                        VALUES (?, ?, NULL, ?, 'RANK_INCOME', ?, 0, ?, ?)
                    ");
                    $description = "Daily Propagated Match Income from " . $match['achiever_username'] . " (Slab \$" . number_format($match['slab_amount'], 2) . ")";
                    $stmtInsert->execute([
                        $parentId,
                        $match['achiever_id'],
                        $upline['level'],
                        $allowable,
                        $allowable,
                        $description
                    ]);

                    $propagatedTo[] = "{$upline['username']} (MID: " . ($upline['mid'] ?? 'None') . ") received \$" . number_format($allowable, 2);
                }
            }

            if ($consecutiveSingleCount === 3) {
                $propagationStopped = true;
            }
        }

        $db->commit();

        if (empty($propagatedTo)) {
            $_SESSION['flash_success'] = "Match propagation processed. However, no referrers were eligible or all had reached their 300% ID Cap limit.";
        } else {
            $_SESSION['flash_success'] = "Successfully propagated rank income to referrers:<br><ul class='mb-0'><li>" . implode("</li><li>", $propagatedTo) . "</li></ul>";
        }

    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = "Failed to propagate rank income: " . $e->getMessage();
    }

    header("Location: matches.php");
    exit();
}

// --- MAIN LISTING PAGE ---
$pageTitle = 'Match Propagation Management';
include __DIR__ . '/includes/header.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(u.username LIKE ? OR u.mid LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter !== '') {
    $conditions[] = "m.status = ?";
    $params[] = $statusFilter;
}

$whereStr = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM matching_schedules m
    JOIN users u ON m.user_id = u.id
    {$whereStr}
");
$countStmt->execute($params);
$totalRows = (int)($countStmt->fetch()['count'] ?? 0);

// Pagination settings
$limit = 10;
$totalPages = ceil($totalRows / $limit);
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $limit;

if ($offset < 0) { $offset = 0; }

// Fetch paginated results
$query = "
    SELECT m.*, u.username, u.mid
    FROM matching_schedules m
    JOIN users u ON m.user_id = u.id
    {$whereStr}
    ORDER BY m.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$matches = $stmt->fetchAll();
?>

<style>
    td { color: #000 !important; }
    .table-success-light {
        background-color: rgba(25, 135, 84, 0.08) !important;
    }
</style>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Slab Matching Contracts (All Matches)</h3>
    </div>

    <!-- Filter Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-5">
            <input type="text" name="search" class="form-control" placeholder="Search achiever username / member code (MID)..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select">
                <option value="">-- All Statuses --</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="matches.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<!-- Success/Error Flash Alerts -->
<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <strong>Success!</strong> <?php echo $_SESSION['flash_success']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['flash_error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Matches Table -->
<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr class="table-secondary">
                        <th>Match ID</th>
                        <th>Achiever</th>
                        <th>Slab Amount</th>
                        <th>Daily Income</th>
                        <th>Days Passed</th>
                        <th>Max Days</th>
                        <th>Status</th>
                        <th>Date Achieved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matches)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No matching schedules found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($matches as $m): ?>
                            <tr>
                                <td>#<?php echo $m['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($m['username']); ?></strong><br>
                                    <small class="text-muted">MID: <?php echo htmlspecialchars($m['mid'] ?? 'N/A'); ?></small>
                                </td>
                                <td><span class="badge bg-primary" style="font-size: 13px;">$<?php echo number_format($m['slab_amount'], 2); ?></span></td>
                                <td class="text-success font-weight-bold">$<?php echo number_format($m['daily_income'], 2); ?>/day</td>
                                <td><?php echo $m['days_passed']; ?></td>
                                <td><?php echo $m['max_days']; ?></td>
                                <td>
                                    <span class="badge <?php echo $m['status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo htmlspecialchars(strtoupper($m['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($m['created_at'])); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#propagateModal"
                                            data-match-id="<?php echo $m['id']; ?>">
                                        <i class="fa fa-share-alt me-1"></i>Propagate
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Dialog -->
<div class="modal fade" id="propagateModal" tabindex="-1" aria-labelledby="propagateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="propagateModalLabel"><i class="fa fa-network-wired me-2 text-primary"></i>Propagate Rank Income</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalLoading" class="text-center my-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Analyzing upline referrers and calculating eligibility...</p>
                </div>
                <div id="modalContent" style="display: none;">
                    <div class="alert alert-info py-2 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Match Achiever:</strong> <span id="matchAchiever"></span> (MID: <span id="matchMid"></span>)
                            </div>
                            <div class="col-md-6">
                                <strong>Slab Amount:</strong> $<span id="matchSlab"></span><br>
                                <strong>Daily Income Propagation Amount:</strong> $<span id="matchDaily"></span>
                            </div>
                        </div>
                    </div>

                    <h6>Upline Referrers Hierarchy (Unilevel Tree)</h6>
                    <p class="text-muted small">Traversing upwards up to 12 generations. Eligibility is determined by Active Status, remaining ID Cap, and the limit of 3 consecutive single-referral nodes.</p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead>
                                <tr class="table-dark">
                                    <th>Level</th>
                                    <th>Referrer</th>
                                    <th>Status</th>
                                    <th>Direct Referrals</th>
                                    <th>Consecutive Single Count</th>
                                    <th>Eligible?</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="referrersTableBody">
                                <!-- Dynamically populated by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <form id="propagateForm" method="post" action="matches.php">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                    <input type="hidden" name="action" value="propagate">
                    <input type="hidden" name="match_id" id="formMatchId" value="">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnConfirmPropagate" class="btn btn-success" disabled>
                        <i class="fa fa-share-alt me-1"></i>Confirm Propagation
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const propagateModal = document.getElementById('propagateModal');
    if (propagateModal) {
        propagateModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const matchId = button.getAttribute('data-match-id');

            // Elements
            const modalLoading = document.getElementById('modalLoading');
            const modalContent = document.getElementById('modalContent');
            const matchAchiever = document.getElementById('matchAchiever');
            const matchMid = document.getElementById('matchMid');
            const matchSlab = document.getElementById('matchSlab');
            const matchDaily = document.getElementById('matchDaily');
            const referrersTableBody = document.getElementById('referrersTableBody');
            const formMatchId = document.getElementById('formMatchId');
            const btnConfirmPropagate = document.getElementById('btnConfirmPropagate');

            // Reset modal states
            modalLoading.style.display = 'block';
            modalContent.style.display = 'none';
            referrersTableBody.innerHTML = '';
            formMatchId.value = matchId;
            btnConfirmPropagate.disabled = true;

            // Fetch referrers info
            fetch(`matches.php?ajax=get_referrers&match_id=${matchId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        bootstrap.Modal.getInstance(propagateModal).hide();
                        return;
                    }

                    // Populate header info
                    matchAchiever.textContent = data.match.achiever;
                    matchMid.textContent = data.match.mid || 'N/A';
                    matchSlab.textContent = Number(data.match.slab_amount).toLocaleString(undefined, {minimumFractionDigits: 2});
                    matchDaily.textContent = Number(data.match.daily_income).toLocaleString(undefined, {minimumFractionDigits: 2});

                    let anyEligible = false;

                    // Populate referrers table
                    if (!data.referrers || data.referrers.length === 0) {
                        referrersTableBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">No upline referrers found in genealogy tree.</td></tr>`;
                    } else {
                        data.referrers.forEach(ref => {
                            const tr = document.createElement('tr');

                            // Style row and badges based on eligibility
                            let eligibilityBadge = '';
                            if (ref.eligible) {
                                eligibilityBadge = '<span class="badge bg-success"><i class="fa fa-check me-1"></i>Yes</span>';
                                tr.className = 'table-success-light';
                                anyEligible = true;
                            } else {
                                eligibilityBadge = '<span class="badge bg-danger"><i class="fa fa-times me-1"></i>No</span>';
                                tr.className = 'text-muted';
                            }

                            const statusBadge = ref.status === 'active'
                                ? '<span class="badge bg-success">Active</span>'
                                : `<span class="badge bg-danger">${ref.status.toUpperCase()}</span>`;

                            tr.innerHTML = `
                                <td>Level ${ref.level}</td>
                                <td><strong>${ref.username}</strong><br><small class="text-muted">MID: ${ref.mid || 'N/A'}</small></td>
                                <td>${statusBadge}</td>
                                <td class="text-center">${ref.ref_count}</td>
                                <td class="text-center">${ref.consecutive_single_count}</td>
                                <td>${eligibilityBadge}</td>
                                <td><small class="text-muted">${ref.reason}</small></td>
                            `;

                            referrersTableBody.appendChild(tr);
                        });
                    }

                    // Enable confirm button only if there is at least one eligible upline
                    if (anyEligible) {
                        btnConfirmPropagate.disabled = false;
                    }

                    modalLoading.style.display = 'none';
                    modalContent.style.display = 'block';
                })
                .catch(err => {
                    console.error(err);
                    alert("Failed to load referrer details.");
                    bootstrap.Modal.getInstance(propagateModal).hide();
                });
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
