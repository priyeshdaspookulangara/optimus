<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();

// Handle AJAX request for fetching sponsored members list
if (isset($_GET['action']) && $_GET['action'] === 'get_sponsored') {
    header('Content-Type: application/json');
    $sponsorId = (int)($_GET['sponsor_id'] ?? 0);
    $stmt = $db->prepare("
        SELECT id, mid, username, full_name, total_investment, created_at
        FROM users
        WHERE sponsor_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$sponsorId]);
    $sponsoredList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $sponsoredList]);
    exit();
}

$pageTitle = 'Members by Sponsored Count';
include __DIR__ . '/includes/header.php';

// Fetch lists for configs
$config = require __DIR__ . '/../includes/config.php';
$ranksList = $config['ranks'];

$search = trim($_GET['search'] ?? '');
$sortDir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

// Build dynamic query
$whereClauses = [];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.mid LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Query members ordered by number of persons sponsored by him (default DESC)
$query = "
    SELECT
        u.id,
        u.mid,
        u.username,
        u.full_name,
        u.rank_id,
        u.total_investment,
        u.created_at,
        COUNT(s.id) AS sponsored_count
    FROM users u
    LEFT JOIN users s ON s.sponsor_id = u.id
    {$whereSql}
    GROUP BY u.id, u.mid, u.username, u.full_name, u.rank_id, u.total_investment, u.created_at
    ORDER BY sponsored_count {$sortDir}, u.id ASC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll();

// Statistics calculation
$totalMembersCount = count($members);
$totalSponsoredCount = array_sum(array_column($members, 'sponsored_count'));
$avgSponsored = $totalMembersCount > 0 ? round($totalSponsoredCount / $totalMembersCount, 2) : 0;
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-1"><i class="fa fa-user-plus text-primary me-2"></i>Members Listed by Sponsored Count</h3>
            <p class="text-muted mb-0">List of all members in descending order based on the number of persons sponsored by them.</p>
        </div>
        <div>
            <a href="members.php" class="btn btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>All Members Management</a>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat bg-primary text-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-white-50 mb-1">Total Members</h6>
                        <h3 class="mb-0 text-white"><?php echo number_format($totalMembersCount); ?></h3>
                    </div>
                    <i class="fa fa-users fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-success text-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-white-50 mb-1">Total Sponsorships</h6>
                        <h3 class="mb-0 text-white"><?php echo number_format($totalSponsoredCount); ?></h3>
                    </div>
                    <i class="fa fa-user-check fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-info text-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-white-50 mb-1">Avg Sponsored per Member</h6>
                        <h3 class="mb-0 text-white"><?php echo $avgSponsored; ?></h3>
                    </div>
                    <i class="fa fa-chart-line fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-7">
            <input type="text" name="search" class="form-control" placeholder="Search username, full name, MID..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-3">
            <select name="dir" class="form-select">
                <option value="desc" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Descending Order</option>
                <option value="asc" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Ascending Order</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="sponsored_members.php" class="btn btn-outline-secondary w-100">Reset</a>
        </div>
    </form>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>MID (Member Code)</th>
                        <th>Username / Full Name</th>
                        <th class="text-center">Persons Sponsored</th>
                        <th>Rank</th>
                        <th>Total Invested</th>
                        <th>Joined Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No members found matching your search criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($members as $index => $m): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'N/A'); ?></strong></td>
                            <td>
                                <div><strong><?php echo htmlspecialchars($m['username']); ?></strong></div>
                                <?php if (!empty($m['full_name'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($m['full_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill <?php echo $m['sponsored_count'] > 0 ? 'bg-success' : 'bg-secondary'; ?>" style="font-size: 14px; padding: 6px 12px;">
                                    <i class="fa fa-user-friends me-1"></i><?php echo $m['sponsored_count']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info text-dark" style="font-size: 12px;">
                                    <?php echo ($m['rank_id'] > 0 && isset($ranksList[$m['rank_id']-1])) ? htmlspecialchars($ranksList[$m['rank_id']-1]['name']) : 'None'; ?>
                                </span>
                            </td>
                            <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                            <td class="text-center">
                                <?php if ($m['sponsored_count'] > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary view-sponsored-btn"
                                            data-sponsor-id="<?php echo $m['id']; ?>"
                                            data-sponsor-name="<?php echo htmlspecialchars($m['username']); ?>"
                                            data-sponsored-count="<?php echo $m['sponsored_count']; ?>">
                                        <i class="fa fa-list me-1"></i>View Sponsored (<?php echo $m['sponsored_count']; ?>)
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">No Sponsored</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal to display list of sponsored members -->
<div class="modal fade" id="sponsoredListModal" tabindex="-1" aria-labelledby="sponsoredListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="sponsoredListModalLabel">
                    <i class="fa fa-users me-2"></i>Sponsored Members for <span id="modalSponsorName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="sponsoredModalLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Fetching sponsored members...</p>
                </div>
                <div id="sponsoredModalContent" class="table-responsive" style="display: none;">
                    <table class="table table-bordered table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>MID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Total Invested</th>
                                <th>Joined Date</th>
                            </tr>
                        </thead>
                        <tbody id="sponsoredModalTableBody">
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

<script class="code-script">
document.addEventListener('DOMContentLoaded', function() {
    const sponsoredModal = new bootstrap.Modal(document.getElementById('sponsoredListModal'));
    const modalSponsorName = document.getElementById('modalSponsorName');
    const loadingEl = document.getElementById('sponsoredModalLoading');
    const contentEl = document.getElementById('sponsoredModalContent');
    const tableBody = document.getElementById('sponsoredModalTableBody');

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.querySelectorAll('.view-sponsored-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const sponsorId = this.getAttribute('data-sponsor-id');
            const sponsorName = this.getAttribute('data-sponsor-name');

            modalSponsorName.textContent = sponsorName;
            loadingEl.style.display = 'block';
            contentEl.style.display = 'none';
            tableBody.innerHTML = '';

            sponsoredModal.show();

            fetch(`sponsored_members.php?action=get_sponsored&sponsor_id=${sponsorId}`)
                .then(response => response.json())
                .then(data => {
                    loadingEl.style.display = 'none';
                    if (data.success && data.data.length > 0) {
                        data.data.forEach((member, idx) => {
                            const tr = document.createElement('tr');
                            const midEsc = escapeHtml(member.mid || 'N/A');
                            const usernameEsc = escapeHtml(member.username || '');
                            const fullNameEsc = escapeHtml(member.full_name || '-');
                            const dateEsc = escapeHtml(member.created_at ? member.created_at.substring(0, 10) : '-');

                            tr.innerHTML = `
                                <td>${idx + 1}</td>
                                <td><strong class="text-primary">${midEsc}</strong></td>
                                <td><strong>${usernameEsc}</strong></td>
                                <td>${fullNameEsc}</td>
                                <td>$${parseFloat(member.total_investment || 0).toFixed(2)}</td>
                                <td>${dateEsc}</td>
                            `;
                            tableBody.appendChild(tr);
                        });
                        contentEl.style.display = 'block';
                    } else {
                        tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">No sponsored members found.</td></tr>`;
                        contentEl.style.display = 'block';
                    }
                })
                .catch(err => {
                    loadingEl.style.display = 'none';
                    tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error loading data.</td></tr>`;
                    contentEl.style.display = 'block';
                });
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
