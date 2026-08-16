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

// AJAX Endpoint for fetching Genealogy
if (isset($_GET['action']) && $_GET['action'] == 'get_genealogy') {
    header('Content-Type: application/json');
    $userId = intval($_GET['user_id'] ?? 0);

    // Fetch user details with sponsor and placement
    $stmt = $db->prepare("
        SELECT u.*,
               sp.username as sponsor_username, sp.mid as sponsor_mid,
               pl.username as placement_username, pl.mid as placement_mid
        FROM users u
        LEFT JOIN users sp ON u.sponsor_id = sp.id
        LEFT JOIN users pl ON u.placement_id = pl.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => 'User not found']);
        exit();
    }

    // Fetch genealogy downlines
    $stmt = $db->prepare("
        SELECT
            g.level,
            u.id,
            u.mid,
            u.username,
            u.email,
            u.rank_id,
            u.status,
            u.total_investment,
            u.created_at
        FROM genealogy g
        JOIN users u ON g.user_id = u.id
        WHERE g.parent_id = ?
        ORDER BY g.level ASC, u.created_at DESC
    ");
    $stmt->execute([$userId]);
    $downlines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map rank names
    $config = require __DIR__ . '/../includes/config.php';
    $ranksList = $config['ranks'] ?? [];
    foreach ($downlines as &$d) {
        $rId = intval($d['rank_id']);
        $d['rank_name'] = ($rId > 0 && isset($ranksList[$rId - 1])) ? $ranksList[$rId - 1]['name'] : 'None';
    }

    echo json_encode([
        'user' => $user,
        'downlines' => $downlines
    ]);
    exit();
}

if (isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: members.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    if ($_POST['action'] == 'update_status') {
        $userId = $_POST['user_id'];
        $newStatus = $_POST['status'];
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        header("Location: members.php?success=status_updated");
        exit();
    } elseif ($_POST['action'] == 'reset_password') {
        $userId = intval($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $retypePassword = $_POST['retype_password'] ?? ($_POST['confirm_password'] ?? '');

        if (empty($userId) || empty($password)) {
            header("Location: members.php?error=" . urlencode("Password cannot be empty."));
            exit();
        }

        if ($password !== $retypePassword) {
            header("Location: members.php?error=" . urlencode("Password and Retype Password do not match."));
            exit();
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);

        header("Location: members.php?success=password_reset");
        exit();
    } elseif ($_POST['action'] == 'update_profile') {
        $userId = intval($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($userId) || empty($username) || empty($email)) {
            header("Location: members.php?error=" . urlencode("Username and Email are required."));
            exit();
        }

        // Check if username is already taken by another user
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $userId]);
        if ($stmt->fetch()) {
            header("Location: members.php?error=" . urlencode("Username '$username' is already taken by another user."));
            exit();
        }

        // Check if email is already taken by another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            header("Location: members.php?error=" . urlencode("Email '$email' is already taken by another user."));
            exit();
        }

        $stmt = $db->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, status = ? WHERE id = ?");
        $stmt->execute([$username, $fullName, $email, $phone, $status, $userId]);

        header("Location: members.php?success=profile_updated");
        exit();
    } elseif ($_POST['action'] == 'clear_system') {
        // Clear all members except root (ID 1)
        $db->beginTransaction();
        try {
            // Disable foreign keys temporarily
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Delete from dependent tables
            $db->exec("DELETE FROM genealogy WHERE user_id > 1 OR parent_id > 1");
            $db->exec("DELETE FROM investments WHERE user_id > 1");
            $db->exec("DELETE FROM transactions WHERE user_id > 1 OR related_user_id > 1");
            $db->exec("DELETE FROM user_wallets WHERE user_id > 1");
            $db->exec("DELETE FROM matching_schedules WHERE user_id > 1");

            // Delete unused/used PINs created for or by non-root users
            $db->exec("DELETE FROM pins WHERE used_by > 1 OR assigned_to > 1");

            // Delete users except ID 1 (Root admin)
            $db->exec("DELETE FROM users WHERE id > 1");

            // Reset Root user (ID 1) MLM metrics
            $db->exec("UPDATE users SET
                rank_id = 0,
                total_investment = 0.00,
                left_leg_business = 0.00,
                right_leg_business = 0.00,
                rank_income_days = 0,
                sponsor_id = NULL,
                placement_id = NULL,
                status = 'active'
                WHERE id = 1
            ");

            // Re-enable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");

            $db->commit();
            header("Location: members.php?success=system_cleared");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: members.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

$pageTitle = 'Member Management';
include __DIR__ . '/includes/header.php';

// Fetch lists for filters
$config = require __DIR__ . '/../includes/config.php';
$packagesList = $config['packages'];
$ranksList = $config['ranks'];

$search = $_GET['search'] ?? '';
$rankFilter = $_GET['rank_id'] ?? '';
$packageFilter = $_GET['package_amount'] ?? '';

// Build dynamic query
$query = "SELECT DISTINCT u.* FROM users u";
$params = [];
$joins = [];
$conditions = [];

if (!empty($packageFilter)) {
    $joins[] = "JOIN investments i ON u.id = i.user_id";
    $conditions[] = "i.amount = ?";
    $params[] = $packageFilter;
}

if ($search !== '') {
    $conditions[] = "(u.mid LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($rankFilter !== '') {
    $conditions[] = "u.rank_id = ?";
    $params[] = $rankFilter;
}

$joinStr = implode(" ", $joins);
$whereStr = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
$query .= " {$joinStr} {$whereStr} ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll();
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Members List</h3>
        <form method="post" onsubmit="return confirm('WARNING: This will permanently delete all members (except root user), their downlines, genealogy trees, and all historical transactions, investments, matching schedules, and incomes! This action is irreversible. Are you absolutely sure?');">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="clear_system">
            <button type="submit" class="btn btn-danger"><i class="fa fa-trash-alt me-1"></i>Reset System (Clear All Except Root)</button>
        </form>
    </div>

    <!-- Dynamic Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search MID / username / email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-3">
            <select name="rank_id" class="form-select">
                <option value="">-- All Ranks --</option>
                <option value="0" <?php echo $rankFilter === '0' ? 'selected' : ''; ?>>None / No Rank</option>
                <?php foreach($ranksList as $idx => $rConf): ?>
                    <option value="<?php echo $idx + 1; ?>" <?php echo $rankFilter == ($idx + 1) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rConf['name']); ?> (Slab $<?php echo number_format($rConf['matching']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="package_amount" class="form-select">
                <option value="">-- All Packages --</option>
                <?php foreach($packagesList as $pkgAmt): ?>
                    <option value="<?php echo $pkgAmt; ?>" <?php echo $packageFilter == $pkgAmt ? 'selected' : ''; ?>>
                        Package $<?php echo number_format($pkgAmt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="members.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if(isset($_GET['success']) && $_GET['success'] == 'system_cleared'): ?>
    <div class="alert alert-success"><strong>System Reset Complete!</strong> All members (except root user) and their associated genealogy tree, packages, investments, and transactional data have been securely deleted.</div>
<?php elseif(isset($_GET['success']) && $_GET['success'] == 'password_reset'): ?>
    <div class="alert alert-success">User password has been reset successfully.</div>
<?php elseif(isset($_GET['success']) && $_GET['success'] == 'profile_updated'): ?>
    <div class="alert alert-success">User profile updated successfully.</div>
<?php elseif(isset($_GET['success'])): ?>
    <div class="alert alert-success">Action completed successfully.</div>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
    <div class="alert alert-danger">Error: <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>MID (Member Code)</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Rank</th>
                        <th>Total Invested</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): ?>
                    <tr>
                        <td><strong class="text-primary"><?php echo htmlspecialchars($m['mid'] ?? 'None'); ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($m['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($m['email']); ?></td>
                        <td>
                            <span class="badge bg-info" style="font-size: 13px;">
                                <?php echo ($m['rank_id'] > 0 && isset($ranksList[$m['rank_id']-1])) ? htmlspecialchars($ranksList[$m['rank_id']-1]['name']) : 'None'; ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars($m['status']) == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo htmlspecialchars(strtoupper($m['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <div class="d-flex gap-2 align-items-center">
                                <button type="button" class="btn btn-sm text-primary p-1 border-0" onclick='openEditProfileModal(<?php echo json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Edit Profile">
                                    <i class="fa fa-edit fs-6"></i>
                                </button>
                                <button type="button" class="btn btn-sm text-warning p-1 border-0" onclick="openResetPasswordModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['username'], ENT_QUOTES); ?>')" title="Reset Password">
                                    <i class="fa fa-key fs-6"></i>
                                </button>
                                <button type="button" class="btn btn-sm text-info p-1 border-0" onclick="openGenealogyModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['username'], ENT_QUOTES); ?>')" title="View Genealogy">
                                    <i class="fa fa-sitemap fs-6"></i>
                                </button>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <?php if($m['status'] == 'active'): ?>
                                        <button type="submit" name="status" value="suspended" class="btn btn-sm text-danger p-1 border-0" title="Suspend User"><i class="fa fa-user-slash fs-6"></i></button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="active" class="btn btn-sm text-success p-1 border-0" title="Activate User"><i class="fa fa-user-check fs-6"></i></button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="user_id" id="edit_user_id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileModalLabel"><i class="fa fa-user-edit me-2"></i>Edit User Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="edit_mid" class="form-label">Member ID (MID)</label>
                        <input type="text" class="form-control bg-light" id="edit_mid" readonly disabled>
                    </div>
                    <div class="col-md-6">
                        <label for="edit_username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                    </div>
                    <div class="col-md-6">
                        <label for="edit_full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="edit_full_name" name="full_name">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="col-md-6">
                        <label for="edit_phone" class="form-label">Phone Number</label>
                        <input type="text" class="form-control" id="edit_phone" name="phone">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_status" class="form-label">Account Status</label>
                        <select class="form-select" id="edit_status" name="status" required>
                            <option value="active">ACTIVE</option>
                            <option value="suspended">SUSPENDED</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="resetPasswordModalLabel"><i class="fa fa-key me-2"></i>Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Resetting password for user: <strong id="reset_username_display"></strong></p>
                <div class="mb-3">
                    <label for="reset_password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="reset_password" name="password" required placeholder="Enter new password">
                </div>
                <div class="mb-3">
                    <label for="reset_retype_password" class="form-label">Retype Password</label>
                    <input type="password" class="form-control" id="reset_retype_password" name="retype_password" required placeholder="Retype new password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-warning"><i class="fa fa-key me-1"></i>Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- View Genealogy Modal -->
<div class="modal fade" id="genealogyModal" tabindex="-1" aria-labelledby="genealogyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="genealogyModalLabel"><i class="fa fa-sitemap me-2"></i>Genealogy Tree - <span id="genealogy_username_title"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="genealogy_loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading genealogy details...</p>
                </div>
                <div id="genealogy_content" style="display: none;">
                    <!-- User Header Info -->
                    <div class="card mb-3 bg-light border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Member Code (MID)</small>
                                    <strong class="text-primary" id="gen_info_mid"></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Username</small>
                                    <strong id="gen_info_username"></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Sponsor</small>
                                    <span id="gen_info_sponsor"></span>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Placement (Position)</small>
                                    <span id="gen_info_placement"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Downline Genealogy List -->
                    <h6 class="fw-bold mb-3"><i class="fa fa-users me-2"></i>Downline Team Members</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle" id="genealogy_table">
                            <thead class="table-dark">
                                <tr>
                                    <th>Level</th>
                                    <th>MID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Rank</th>
                                    <th>Total Invested</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody id="genealogy_table_body">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openEditProfileModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_mid').value = user.mid || 'N/A';
    document.getElementById('edit_username').value = user.username || '';
    document.getElementById('edit_full_name').value = user.full_name || '';
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_status').value = user.status || 'active';
    var editModal = new bootstrap.Modal(document.getElementById('editProfileModal'));
    editModal.show();
}

function openResetPasswordModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username_display').textContent = username;
    document.getElementById('reset_password').value = '';
    document.getElementById('reset_retype_password').value = '';
    var resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    resetModal.show();
}

function openGenealogyModal(userId, username) {
    document.getElementById('genealogy_username_title').textContent = username;
    document.getElementById('genealogy_loading').style.display = 'block';
    document.getElementById('genealogy_content').style.display = 'none';

    var genModal = new bootstrap.Modal(document.getElementById('genealogyModal'));
    genModal.show();

    fetch('members.php?action=get_genealogy&user_id=' + userId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            document.getElementById('genealogy_loading').style.display = 'none';
            document.getElementById('genealogy_content').style.display = 'block';

            if (data.error) {
                alert(data.error);
                return;
            }

            var user = data.user;
            var downlines = data.downlines;

            document.getElementById('gen_info_mid').textContent = user.mid || 'N/A';
            document.getElementById('gen_info_username').textContent = user.username || '';
            document.getElementById('gen_info_sponsor').textContent = user.sponsor_username ? (user.sponsor_username + ' (' + (user.sponsor_mid || '') + ')') : 'None';

            var posText = user.position ? (' [' + user.position.toUpperCase() + ']') : '';
            document.getElementById('gen_info_placement').textContent = user.placement_username ? (user.placement_username + ' (' + (user.placement_mid || '') + ')' + posText) : 'None';

            var tbody = document.getElementById('genealogy_table_body');
            tbody.innerHTML = '';

            if (!downlines || downlines.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No downline team members found.</td></tr>';
            } else {
                downlines.forEach(function(item) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><span class="badge bg-primary">Level ' + item.level + '</span></td>' +
                        '<td><strong class="text-primary">' + (item.mid || 'N/A') + '</strong></td>' +
                        '<td><strong>' + escapeHtml(item.username) + '</strong></td>' +
                        '<td>' + escapeHtml(item.email || '') + '</td>' +
                        '<td><span class="badge bg-info">' + escapeHtml(item.rank_name) + '</span></td>' +
                        '<td>$' + parseFloat(item.total_investment || 0).toFixed(2) + '</td>' +
                        '<td><span class="badge ' + (item.status === 'active' ? 'bg-success' : 'bg-danger') + '">' + item.status.toUpperCase() + '</span></td>' +
                        '<td>' + (item.created_at ? item.created_at.substring(0, 10) : '') + '</td>';
                    tbody.appendChild(tr);
                });
            }
        })
        .catch(function(err) {
            document.getElementById('genealogy_loading').style.display = 'none';
            alert('Failed to load genealogy details.');
        });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
