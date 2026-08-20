<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/engine.php';

// Authentication check before running state-changing operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();

// Helper function to rebuild genealogy table for all generations
function rebuildGenealogyTable($db) {
    $db->exec("TRUNCATE TABLE genealogy");

    // Level 1: direct sponsors
    $db->exec("INSERT INTO genealogy (user_id, parent_id, level)
               SELECT id, sponsor_id, 1 FROM users WHERE sponsor_id IS NOT NULL AND sponsor_id > 0");

    // Levels 2 to 12
    for ($level = 2; $level <= 12; $level++) {
        $prevLevel = $level - 1;
        $db->exec("INSERT INTO genealogy (user_id, parent_id, level)
                   SELECT g.user_id, u.sponsor_id, {$level}
                   FROM genealogy g
                   JOIN users u ON g.parent_id = u.id
                   WHERE g.level = {$prevLevel} AND u.sponsor_id IS NOT NULL AND u.sponsor_id > 0");
    }
}

// AJAX / JSON lookup helper endpoint
if (isset($_GET['action']) && $_GET['action'] == 'lookup_user') {
    header('Content-Type: application/json');
    $ref = trim($_GET['ref'] ?? '');
    if (empty($ref)) {
        echo json_encode(['success' => false, 'message' => 'No reference provided']);
        exit();
    }

    $stmt = $db->prepare("
        SELECT u.id, u.mid, u.username, u.full_name, u.email, u.sponsor_id, u.created_at,
               s.mid as sponsor_mid, s.username as sponsor_username, s.full_name as sponsor_name
        FROM users u
        LEFT JOIN users s ON u.sponsor_id = s.id
        WHERE u.mid = ? OR u.username = ?
    ");
    $stmt->execute([$ref, $ref]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit();
}

$successMsg = '';
$errorMsg = '';

// Handle Sponsor Update form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_sponsor') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $errorMsg = "CSRF token validation failed.";
    } else {
        $memberRef = trim($_POST['member_ref'] ?? '');
        $sponsorRef = trim($_POST['sponsor_ref'] ?? '');

        if (empty($memberRef)) {
            $errorMsg = "Please specify the Target Member (Member ID or Username).";
        } elseif (empty($sponsorRef)) {
            $errorMsg = "Please specify the New Sponsor (Member ID or Username).";
        } else {
            // Fetch target member
            $stmt = $db->prepare("SELECT * FROM users WHERE mid = ? OR username = ?");
            $stmt->execute([$memberRef, $memberRef]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            // Fetch new sponsor
            $stmt = $db->prepare("SELECT * FROM users WHERE mid = ? OR username = ?");
            $stmt->execute([$sponsorRef, $sponsorRef]);
            $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $errorMsg = "Target Member '{$memberRef}' not found.";
            } elseif (!$sponsor) {
                $errorMsg = "New Sponsor '{$sponsorRef}' not found.";
            } elseif ($member['id'] == 1) {
                $errorMsg = "Cannot change sponsor for Root User (ID 1).";
            } elseif ($member['id'] == $sponsor['id']) {
                $errorMsg = "Target Member cannot be set as their own sponsor.";
            } else {
                // Check if current sponsor is already the requested new sponsor
                if ($member['sponsor_id'] == $sponsor['id']) {
                    $errorMsg = "Member '{$member['username']}' is already sponsored by '{$sponsor['username']}'.";
                } else {
                    // Check circular reference: verify that new sponsor is NOT a descendant/downline of target member
                    $stmtCheck = $db->prepare("SELECT 1 FROM genealogy WHERE user_id = ? AND parent_id = ?");
                    $stmtCheck->execute([$sponsor['id'], $member['id']]);
                    if ($stmtCheck->fetch()) {
                        $errorMsg = "Circular Reference Error: New Sponsor '{$sponsor['username']}' is a downline descendant of Member '{$member['username']}'.";
                    } else {
                        // Execute update inside a database transaction
                        $db->beginTransaction();
                        try {
                            $oldSponsorId = $member['sponsor_id'];

                            // 1. Update sponsor_id in users table
                            $stmtUpd = $db->prepare("UPDATE users SET sponsor_id = ? WHERE id = ?");
                            $stmtUpd->execute([$sponsor['id'], $member['id']]);

                            // 2. Rebuild genealogy table structure
                            rebuildGenealogyTable($db);

                            // 3. Re-evaluate upline ranks and leg business
                            $engine = new MLMEngine();
                            $engine->updateUplineRanks($member['id']);
                            if ($oldSponsorId) {
                                $engine->updateUplineRanks($oldSponsorId);
                            }
                            $engine->updateUplineRanks($sponsor['id']);

                            $db->commit();
                            $successMsg = "Successfully changed sponsor for <strong>" . htmlspecialchars($member['username']) . "</strong> (MID: " . htmlspecialchars($member['mid']) . ") to <strong>" . htmlspecialchars($sponsor['username']) . "</strong> (MID: " . htmlspecialchars($sponsor['mid']) . ").";
                        } catch (Exception $e) {
                            $db->rollBack();
                            $errorMsg = "Database error updating sponsor: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Search and filter list parameters
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query for member search facility
$query = "
    SELECT u.id, u.mid, u.username, u.full_name, u.email, u.sponsor_id, u.created_at,
           s.mid as sponsor_mid, s.username as sponsor_username, s.full_name as sponsor_full_name
    FROM users u
    LEFT JOIN users s ON u.sponsor_id = s.id
";

$whereClauses = ["u.id > 1"];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(u.mid LIKE ? OR u.username LIKE ? OR u.full_name LIKE ? OR s.mid LIKE ? OR s.username LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN users s ON u.sponsor_id = s.id {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Fetch page results
$query .= " {$whereSql} ORDER BY u.created_at DESC LIMIT {$limit} OFFSET {$offset}";
$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Change Sponsor ID';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-2">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa fa-user-edit text-primary me-2"></i>Change Sponsor ID</h3>
            <p class="text-muted mb-0">Specify target member and new sponsor using Member Code (MID) or Username.</p>
        </div>
    </div>

    <?php if(!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-2"></i><?php echo $successMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Change Sponsor Action Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="card-title fw-bold mb-0"><i class="fa fa-exchange-alt me-2 text-primary"></i>Sponsor Change Form</h5>
        </div>
        <div class="card-body p-4">
            <form method="post" id="changeSponsorForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                <input type="hidden" name="action" value="change_sponsor">

                <div class="row g-4">
                    <!-- Target Member Selection -->
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border">
                            <label for="member_ref" class="form-label fw-bold text-dark">
                                Target Member <span class="text-danger">*</span>
                            </label>
                            <div class="input-group mb-2">
                                <span class="input-group-text bg-white"><i class="fa fa-user"></i></span>
                                <input type="text" class="form-control" id="member_ref" name="member_ref" placeholder="Enter MID (e.g. OPT12345) or Username" required value="<?php echo htmlspecialchars($_POST['member_ref'] ?? $_GET['select_member'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="btnVerifyMember">
                                    <i class="fa fa-search me-1"></i>Verify
                                </button>
                            </div>
                            <small class="text-muted d-block mb-2">The member whose sponsor will be changed.</small>

                            <!-- Member Preview Container -->
                            <div id="memberPreview" class="mt-2 p-2 rounded bg-white border d-none">
                                <div class="d-flex align-items-center">
                                    <i class="fa fa-user-circle fa-2x text-primary me-2"></i>
                                    <div>
                                        <strong id="mPreviewName" class="text-dark"></strong>
                                        <div class="small text-muted">MID: <span id="mPreviewMid" class="fw-bold"></span> | Current Sponsor: <span id="mPreviewSponsor" class="fw-bold text-info"></span></div>
                                    </div>
                                </div>
                            </div>
                            <div id="memberError" class="mt-2 small text-danger d-none"></div>
                        </div>
                    </div>

                    <!-- New Sponsor Selection -->
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border">
                            <label for="sponsor_ref" class="form-label fw-bold text-dark">
                                New Sponsor <span class="text-danger">*</span>
                            </label>
                            <div class="input-group mb-2">
                                <span class="input-group-text bg-white"><i class="fa fa-user-check"></i></span>
                                <input type="text" class="form-control" id="sponsor_ref" name="sponsor_ref" placeholder="Enter MID (e.g. OPT12345) or Username" required value="<?php echo htmlspecialchars($_POST['sponsor_ref'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="btnVerifySponsor">
                                    <i class="fa fa-search me-1"></i>Verify
                                </button>
                            </div>
                            <small class="text-muted d-block mb-2">The new sponsor to assign to the member.</small>

                            <!-- Sponsor Preview Container -->
                            <div id="sponsorPreview" class="mt-2 p-2 rounded bg-white border d-none">
                                <div class="d-flex align-items-center">
                                    <i class="fa fa-user-shield fa-2x text-success me-2"></i>
                                    <div>
                                        <strong id="sPreviewName" class="text-dark"></strong>
                                        <div class="small text-muted">MID: <span id="sPreviewMid" class="fw-bold"></span> | Email: <span id="sPreviewEmail"></span></div>
                                    </div>
                                </div>
                            </div>
                            <div id="sponsorError" class="mt-2 small text-danger d-none"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary btn-lg px-4" onclick="return confirm('Are you sure you want to change the sponsor for this member? This will rebuild the unilevel genealogy hierarchy.');">
                        <i class="fa fa-save me-2"></i>Update Sponsor ID
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Members & Sponsor Search Directory -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between border-bottom gap-2">
            <h5 class="card-title fw-bold mb-0"><i class="fa fa-list-alt me-2 text-primary"></i>Members & Current Sponsors Search Directory</h5>
            <span class="badge bg-secondary"><?php echo number_format($totalRecords); ?> Total Members</span>
        </div>
        <div class="card-body p-3">
            <!-- Search Facility Form -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-9 col-sm-8">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by Member ID (MID), Username, Full Name, or Sponsor MID/Username..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3 col-sm-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i>Search</button>
                    <?php if(!empty($search)): ?>
                        <a href="change_sponsor.php" class="btn btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Member ID (MID)</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Current Sponsor ID (MID)</th>
                            <th>Current Sponsor Name</th>
                            <th>Joined Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($members)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fa fa-info-circle me-1"></i>No members found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($members as $m): ?>
                                <tr>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'None'); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($m['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($m['full_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if(!empty($m['sponsor_mid'])): ?>
                                            <span class="badge bg-info text-dark" style="font-size: 12px;"><?php echo htmlspecialchars($m['sponsor_mid']); ?></span>
                                            <small class="text-muted">(<?php echo htmlspecialchars($m['sponsor_username']); ?>)</small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Root / None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['sponsor_full_name'] ?? ($m['sponsor_username'] ?? 'N/A')); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary btnSelectMember"
                                                data-mid="<?php echo htmlspecialchars($m['mid'] ?? $m['username']); ?>"
                                                data-username="<?php echo htmlspecialchars($m['username']); ?>"
                                                data-fullname="<?php echo htmlspecialchars($m['full_name'] ?? $m['username']); ?>"
                                                data-sponsormid="<?php echo htmlspecialchars($m['sponsor_mid'] ?? 'None'); ?>"
                                                data-sponsorusername="<?php echo htmlspecialchars($m['sponsor_username'] ?? 'None'); ?>">
                                            <i class="fa fa-arrow-up me-1"></i>Select Member
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success btnSelectSponsor ms-1"
                                                data-mid="<?php echo htmlspecialchars($m['mid'] ?? $m['username']); ?>"
                                                data-username="<?php echo htmlspecialchars($m['username']); ?>"
                                                data-fullname="<?php echo htmlspecialchars($m['full_name'] ?? $m['username']); ?>">
                                            <i class="fa fa-user-check me-1"></i>Select as New Sponsor
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">Previous</a>
                        </li>
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function lookupUser(ref, callback) {
        if (!ref.trim()) {
            callback({ success: false, message: 'Please enter a Member ID or Username' });
            return;
        }
        fetch('change_sponsor.php?action=lookup_user&ref=' + encodeURIComponent(ref))
            .then(res => res.json())
            .then(data => callback(data))
            .catch(err => callback({ success: false, message: 'Server communication error' }));
    }

    const memberInput = document.getElementById('member_ref');
    const btnVerifyMember = document.getElementById('btnVerifyMember');
    const memberPreview = document.getElementById('memberPreview');
    const memberError = document.getElementById('memberError');

    function checkMember() {
        lookupUser(memberInput.value, function(res) {
            if (res.success) {
                document.getElementById('mPreviewName').textContent = (res.user.full_name || res.user.username) + ' (@' + res.user.username + ')';
                document.getElementById('mPreviewMid').textContent = res.user.mid || res.user.id;
                document.getElementById('mPreviewSponsor').textContent = res.user.sponsor_username ? (res.user.sponsor_username + ' [' + (res.user.sponsor_mid || '') + ']') : 'None / Root';
                memberPreview.classList.remove('d-none');
                memberError.classList.add('d-none');
            } else {
                memberPreview.classList.add('d-none');
                memberError.textContent = res.message;
                memberError.classList.remove('d-none');
            }
        });
    }

    btnVerifyMember.addEventListener('click', checkMember);
    memberInput.addEventListener('blur', function() {
        if (memberInput.value.trim() !== '') checkMember();
    });

    const sponsorInput = document.getElementById('sponsor_ref');
    const btnVerifySponsor = document.getElementById('btnVerifySponsor');
    const sponsorPreview = document.getElementById('sponsorPreview');
    const sponsorError = document.getElementById('sponsorError');

    function checkSponsor() {
        lookupUser(sponsorInput.value, function(res) {
            if (res.success) {
                document.getElementById('sPreviewName').textContent = (res.user.full_name || res.user.username) + ' (@' + res.user.username + ')';
                document.getElementById('sPreviewMid').textContent = res.user.mid || res.user.id;
                document.getElementById('sPreviewEmail').textContent = res.user.email;
                sponsorPreview.classList.remove('d-none');
                sponsorError.classList.add('d-none');
            } else {
                sponsorPreview.classList.add('d-none');
                sponsorError.textContent = res.message;
                sponsorError.classList.remove('d-none');
            }
        });
    }

    btnVerifySponsor.addEventListener('click', checkSponsor);
    sponsorInput.addEventListener('blur', function() {
        if (sponsorInput.value.trim() !== '') checkSponsor();
    });

    // Auto verify if inputs have initial values
    if (memberInput.value.trim() !== '') checkMember();
    if (sponsorInput.value.trim() !== '') checkSponsor();

    // Directory selection buttons
    document.querySelectorAll('.btnSelectMember').forEach(btn => {
        btn.addEventListener('click', function() {
            const mid = this.getAttribute('data-mid');
            memberInput.value = mid;
            checkMember();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    document.querySelectorAll('.btnSelectSponsor').forEach(btn => {
        btn.addEventListener('click', function() {
            const mid = this.getAttribute('data-mid');
            sponsorInput.value = mid;
            checkSponsor();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
