<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Rebuild Genealogy Helper Function
function rebuildGenealogyTable($db) {
    // Start transaction if not already in one
    $isNested = $db->inTransaction();
    if (!$isNested) {
        $db->beginTransaction();
    }
    try {
        // Clean up any orphaned sponsor_id or placement_id references
        $db->exec("UPDATE users SET sponsor_id = NULL WHERE sponsor_id IS NOT NULL AND sponsor_id NOT IN (SELECT id FROM (SELECT id FROM users) AS tmp_u1)");
        $db->exec("UPDATE users SET placement_id = NULL WHERE placement_id IS NOT NULL AND placement_id NOT IN (SELECT id FROM (SELECT id FROM users) AS tmp_u2)");

        // 1. Clear genealogy table
        $db->exec("DELETE FROM genealogy");

        // 2. Insert Level 1 (direct sponsors) - joining with users to guarantee valid foreign keys
        $db->exec("INSERT INTO genealogy (user_id, parent_id, level)
                   SELECT u.id, u.sponsor_id, 1
                   FROM users u
                   JOIN users p ON u.sponsor_id = p.id
                   WHERE u.sponsor_id IS NOT NULL");

        // 3. Iteratively insert Levels 2 through 12
        for ($level = 2; $level <= 12; $level++) {
            $prevLevel = $level - 1;
            $stmt = $db->prepare("
                INSERT INTO genealogy (user_id, parent_id, level)
                SELECT g.user_id, u.sponsor_id, ?
                FROM genealogy g
                JOIN users u ON g.parent_id = u.id
                JOIN users p ON u.sponsor_id = p.id
                WHERE g.level = ? AND u.sponsor_id IS NOT NULL
            ");
            $stmt->execute([$level, $prevLevel]);
        }

        if (!$isNested) {
            $db->commit();
        }
        return true;
    } catch (Exception $e) {
        if (!$isNested && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_genealogy') {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error = "CSRF token validation failed.";
    } else {
        $userId = (int)$_POST['user_id'];
        $newPosition = ($_POST['position'] === '' || $_POST['position'] === null) ? null : $_POST['position'];
        $sponsorInput = trim($_POST['sponsor'] ?? '');
        $placementInput = trim($_POST['placement'] ?? '');

        $db->beginTransaction();
        try {
            // Fetch current user details
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currentUserObj = $stmt->fetch();

            if (!$currentUserObj) {
                throw new Exception("User not found.");
            }

            // Root user (ID 1) cannot have a sponsor or placement parent
            if ($userId === 1) {
                // Only allow position change for root if needed
                $stmtUpdate = $db->prepare("UPDATE users SET position = ? WHERE id = ?");
                $stmtUpdate->execute([$newPosition, $userId]);
            } else {
                // Resolve Sponsor
                $sponsorId = null;
                if (!empty($sponsorInput)) {
                    $stmtSponsor = $db->prepare("SELECT id FROM users WHERE username = ? OR mid = ?");
                    $stmtSponsor->execute([$sponsorInput, $sponsorInput]);
                    $sponsorRow = $stmtSponsor->fetch();
                    if (!$sponsorRow) {
                        throw new Exception("Sponsor '$sponsorInput' not found in the database.");
                    }
                    $sponsorId = $sponsorRow['id'];
                }

                // Resolve Placement Parent
                $placementId = null;
                if (!empty($placementInput)) {
                    $stmtPlacement = $db->prepare("SELECT id FROM users WHERE username = ? OR mid = ?");
                    $stmtPlacement->execute([$placementInput, $placementInput]);
                    $placementRow = $stmtPlacement->fetch();
                    if (!$placementRow) {
                        throw new Exception("Placement Parent '$placementInput' not found in the database.");
                    }
                    $placementId = $placementRow['id'];
                }

                // Validations
                if ($sponsorId === $userId) {
                    throw new Exception("A user cannot be their own sponsor.");
                }
                if ($placementId === $userId) {
                    throw new Exception("A user cannot be their own placement parent.");
                }

                // Circular reference checks
                if ($sponsorId !== null) {
                    $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE user_id = ? AND parent_id = ?");
                    $stmtCheck->execute([$sponsorId, $userId]);
                    if ($stmtCheck->fetch()) {
                        throw new Exception("Circular reference: Proposed Sponsor is a descendant of this user.");
                    }
                }
                if ($placementId !== null) {
                    $stmtCheck = $db->prepare("SELECT id FROM genealogy WHERE user_id = ? AND parent_id = ?");
                    $stmtCheck->execute([$placementId, $userId]);
                    if ($stmtCheck->fetch()) {
                        throw new Exception("Circular reference: Proposed Placement Parent is a descendant of this user.");
                    }
                }

                // Update users table
                $stmtUpdate = $db->prepare("UPDATE users SET position = ?, sponsor_id = ?, placement_id = ? WHERE id = ?");
                $stmtUpdate->execute([$newPosition, $sponsorId, $placementId, $userId]);
            }

            // Rebuild Genealogy Table to reflect changes safely
            rebuildGenealogyTable($db);

            $db->commit();
            $success = "User genealogy and position updated successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch lists for filters / rendering
$search = $_GET['search'] ?? '';
$positionFilter = $_GET['position'] ?? '';

// Build dynamic query
$query = "SELECT u.*,
                 sp.username AS sponsor_username, sp.mid AS sponsor_mid,
                 pl.username AS placement_username, pl.mid AS placement_mid
          FROM users u
          LEFT JOIN users sp ON u.sponsor_id = sp.id
          LEFT JOIN users pl ON u.placement_id = pl.id";

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(u.username LIKE ? OR u.email LIKE ? OR u.mid LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($positionFilter !== '') {
    if ($positionFilter === 'not_specified') {
        $conditions[] = "u.position IS NULL";
    } else {
        $conditions[] = "u.position = ?";
        $params[] = $positionFilter;
    }
}

if (!empty($conditions)) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Genealogy Management';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Genealogy & Position Management</h3>
        <span class="badge bg-secondary fs-6">Total Members: <?php echo count($users); ?></span>
    </div>

    <!-- Search & Filter Form -->
    <form method="get" class="row g-2 align-items-center bg-white p-3 rounded shadow-sm border">
        <div class="col-md-5">
            <input type="text" name="search" class="form-control" placeholder="Search username, email, or MID..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-4">
            <select name="position" class="form-select">
                <option value="">-- All Positions --</option>
                <option value="left" <?php echo $positionFilter === 'left' ? 'selected' : ''; ?>>Left Position</option>
                <option value="right" <?php echo $positionFilter === 'right' ? 'selected' : ''; ?>>Right Position</option>
                <option value="not_specified" <?php echo $positionFilter === 'not_specified' ? 'selected' : ''; ?>>Not Specified</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="genealogy.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if(!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa fa-check-circle me-2"></i><strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if(!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa fa-exclamation-triangle me-2"></i><strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">MID (Member Code)</th>
                        <th>Username</th>
                        <th>Sponsor</th>
                        <th>Placement Parent</th>
                        <th>Current Position</th>
                        <th>Joined</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No members found matching the criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td class="ps-3"><strong class="text-primary"><?php echo htmlspecialchars($u['mid'] ?? 'None'); ?></strong></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm me-2 bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                        <i class="fa fa-user text-secondary"></i>
                                    </div>
                                    <div>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($u['username']); ?></span>
                                        <div class="small text-muted"><?php echo htmlspecialchars($u['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($u['sponsor_username']): ?>
                                    <span class="fw-bold"><?php echo htmlspecialchars($u['sponsor_username']); ?></span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($u['sponsor_mid']); ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">None (Root)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['placement_username']): ?>
                                    <span class="fw-bold"><?php echo htmlspecialchars($u['placement_username']); ?></span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($u['placement_mid']); ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">None (Root)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['position'] === 'left'): ?>
                                    <span class="badge bg-primary px-3 py-2"><i class="fa fa-arrow-left me-1"></i>LEFT</span>
                                <?php elseif ($u['position'] === 'right'): ?>
                                    <span class="badge bg-success px-3 py-2"><i class="fa fa-arrow-right me-1"></i>RIGHT</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-question-circle me-1"></i>NOT SPECIFIED</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($u['created_at'])); ?></td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-primary px-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editGenealogyModal"
                                        data-id="<?php echo $u['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                        data-mid="<?php echo htmlspecialchars($u['mid'] ?? ''); ?>"
                                        data-position="<?php echo htmlspecialchars($u['position'] ?? ''); ?>"
                                        data-sponsor="<?php echo htmlspecialchars($u['sponsor_username'] ?? ''); ?>"
                                        data-placement="<?php echo htmlspecialchars($u['placement_username'] ?? ''); ?>">
                                    <i class="fa fa-edit me-1"></i>Change
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Genealogy Modal -->
<div class="modal fade" id="editGenealogyModal" tabindex="-1" aria-labelledby="editGenealogyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="update_genealogy">
            <input type="hidden" name="user_id" id="modal_user_id">

            <div class="modal-header">
                <h5 class="modal-title" id="editGenealogyModalLabel"><i class="fa fa-sitemap me-2 text-primary"></i>Modify Position & Parents</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-info py-2 mb-3">
                    <small><i class="fa fa-info-circle me-1"></i>Changing a member's position or parent will instantly update their position status and rebuild the structural genealogy hierarchy tree accordingly.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Editing Member</label>
                    <div class="p-2 bg-light border rounded">
                        <strong id="modal_username_display" class="text-dark"></strong>
                        <span id="modal_mid_display" class="badge bg-secondary ms-2"></span>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="modal_position" class="form-label fw-bold">Current Position</label>
                    <select class="form-select" id="modal_position" name="position">
                        <option value="">Not Specified</option>
                        <option value="left">Left</option>
                        <option value="right">Right</option>
                    </select>
                </div>

                <div id="parent_fields_container">
                    <div class="mb-3">
                        <label for="modal_sponsor" class="form-label fw-bold">Sponsor (Username or MID)</label>
                        <input type="text" class="form-control" id="modal_sponsor" name="sponsor" placeholder="Enter Sponsor Username or MID">
                    </div>

                    <div class="mb-3">
                        <label for="modal_placement" class="form-label fw-bold">Placement Parent (Username or MID)</label>
                        <input type="text" class="form-control" id="modal_placement" name="placement" placeholder="Enter Placement Parent Username or MID">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var editModal = document.getElementById('editGenealogyModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;

            var id = button.getAttribute('data-id');
            var username = button.getAttribute('data-username');
            var mid = button.getAttribute('data-mid');
            var position = button.getAttribute('data-position');
            var sponsor = button.getAttribute('data-sponsor');
            var placement = button.getAttribute('data-placement');

            // Populate fields
            document.getElementById('modal_user_id').value = id;
            document.getElementById('modal_username_display').textContent = username;
            document.getElementById('modal_mid_display').textContent = mid;
            document.getElementById('modal_position').value = position;
            document.getElementById('modal_sponsor').value = sponsor;
            document.getElementById('modal_placement').value = placement;

            // Root user ID 1 safety (disable parent fields)
            var parentFields = document.getElementById('parent_fields_container');
            if (id === '1') {
                parentFields.style.display = 'none';
            } else {
                parentFields.style.display = 'block';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
