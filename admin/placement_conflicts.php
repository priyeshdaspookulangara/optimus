<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Ensure CSRF token is set
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle Administrative Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error = "CSRF token validation failed.";
    } else {
        $action = $_POST['action'];

        if ($action === 'move_other_position') {
            $userId = (int)$_POST['user_id'];
            $placementId = (int)$_POST['placement_id'];
            $currentPos = $_POST['current_position'];
            $newPos = ($currentPos === 'left') ? 'right' : 'left';

            try {
                $db->beginTransaction();

                // Double check if the other position is vacant under this parent
                $stmtCheck = $db->prepare("SELECT COUNT(*) as count FROM users WHERE placement_id = ? AND position = ?");
                $stmtCheck->execute([$placementId, $newPos]);
                $occupied = $stmtCheck->fetch()['count'];

                if ($occupied > 0) {
                    throw new Exception("The destination position ('{$newPos}') is already occupied under this parent.");
                }

                $stmtUpdate = $db->prepare("UPDATE users SET position = ? WHERE id = ? AND placement_id = ?");
                $stmtUpdate->execute([$newPos, $userId, $placementId]);

                $db->commit();
                $success = "Successfully moved user to the '{$newPos}' position under the same parent.";
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Failed to move user: " . $e->getMessage();
            }

        } elseif ($action === 'set_placement_null') {
            $userId = (int)$_POST['user_id'];

            try {
                $db->beginTransaction();

                $stmtUpdate = $db->prepare("UPDATE users SET placement_id = NULL, position = NULL WHERE id = ?");
                $stmtUpdate->execute([$userId]);

                $db->commit();
                $success = "Successfully removed placement parent. The user is now detached from the physical binary placement tree.";
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Failed to detach user: " . $e->getMessage();
            }

        } elseif ($action === 'change_placement') {
            $userId = (int)$_POST['user_id'];
            $newParentSearch = trim($_POST['new_parent_search']);
            $newPosition = $_POST['new_position']; // 'left', 'right', or '' (NULL)

            try {
                if (empty($newParentSearch)) {
                    throw new Exception("New Parent ID / MID / Username is required.");
                }
                if ($newPosition !== 'left' && $newPosition !== 'right' && $newPosition !== '') {
                    throw new Exception("Invalid position selected.");
                }

                $db->beginTransaction();

                // Resolve new parent user
                $stmtParent = $db->prepare("SELECT id, username, mid FROM users WHERE id = ? OR mid = ? OR username = ?");
                $stmtParent->execute([$newParentSearch, $newParentSearch, $newParentSearch]);
                $parent = $stmtParent->fetch();

                if (!$parent) {
                    throw new Exception("Target parent user not found. Please verify the ID, MID, or Username.");
                }

                $newParentId = $parent['id'];

                if ($newParentId == $userId) {
                    throw new Exception("A user cannot be placed under themselves.");
                }

                // If position is left/right, check if occupied
                $posValue = ($newPosition === '') ? null : $newPosition;
                if ($posValue !== null) {
                    $stmtCheckOccupied = $db->prepare("SELECT COUNT(*) as count FROM users WHERE placement_id = ? AND position = ? AND id != ?");
                    $stmtCheckOccupied->execute([$newParentId, $posValue, $userId]);
                    $occupiedCount = $stmtCheckOccupied->fetch()['count'];

                    if ($occupiedCount > 0) {
                        // We will allow the admin to assign, but raise a notice or we can block it. Blocking is cleaner to prevent new conflicts!
                        throw new Exception("The target parent '{$parent['username']}' already has another user placed on their '{$posValue}' position.");
                    }
                }

                $stmtUpdate = $db->prepare("UPDATE users SET placement_id = ?, position = ? WHERE id = ?");
                $stmtUpdate->execute([$newParentId, $posValue, $userId]);

                $db->commit();
                $success = "Successfully updated user placement to parent '{$parent['username']}' ({$parent['mid']}) on position '{$newPosition}'.";
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Failed to change placement: " . $e->getMessage();
            }
        }
    }
}

// Fetch conflict groups
$qConflicts = "
    SELECT placement_id, position, COUNT(*) as child_count
    FROM users
    WHERE placement_id IS NOT NULL AND position IS NOT NULL
    GROUP BY placement_id, position
    HAVING COUNT(*) > 1
";
$stmtConflicts = $db->query($qConflicts);
$conflictGroups = $stmtConflicts->fetchAll();

$resolvedConflicts = [];
$totalAffectedUsers = 0;

foreach ($conflictGroups as $group) {
    $parent_id = $group['placement_id'];
    $position = $group['position'];
    $child_count = $group['child_count'];
    $totalAffectedUsers += $child_count;

    // Fetch parent details
    $stmtParent = $db->prepare("SELECT id, username, mid, email, full_name FROM users WHERE id = ?");
    $stmtParent->execute([$parent_id]);
    $parent = $stmtParent->fetch();

    // Fetch children details
    $stmtChildren = $db->prepare("
        SELECT id, mid, username, email, full_name, total_investment, status, created_at
        FROM users
        WHERE placement_id = ? AND position = ?
        ORDER BY created_at ASC
    ");
    $stmtChildren->execute([$parent_id, $position]);
    $children = $stmtChildren->fetchAll();

    // Check if other position is vacant
    $otherPos = ($position === 'left') ? 'right' : 'left';
    $stmtOtherCheck = $db->prepare("SELECT id, username, mid FROM users WHERE placement_id = ? AND position = ?");
    $stmtOtherCheck->execute([$parent_id, $otherPos]);
    $otherOccupant = $stmtOtherCheck->fetch();
    $isOtherPosVacant = !$otherOccupant;

    $resolvedConflicts[] = [
        'parent_id' => $parent_id,
        'parent_username' => $parent ? $parent['username'] : 'Unknown',
        'parent_mid' => $parent ? $parent['mid'] : 'Unknown',
        'parent_email' => $parent ? $parent['email'] : 'N/A',
        'parent_name' => $parent ? $parent['full_name'] : 'N/A',
        'position' => $position,
        'other_position' => $otherPos,
        'is_other_pos_vacant' => $isOtherPosVacant,
        'other_occupant' => $otherOccupant,
        'children' => $children,
        'child_count' => $child_count
    ];
}

$pageTitle = 'Duplicate Placement Conflicts';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h3>Duplicate Placement Conflicts</h3>
    <p class="text-muted">
        In a binary tree structure, a parent node can have at most one left child and one right child.
        This page identifies nodes (users) placed under the same parent node (`placement_id`) and on the same leg/position, which violates the binary tree constraint. Use the utilities below to reconcile them.
    </p>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa fa-check-circle me-2"></i><strong>Success!</strong> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa fa-exclamation-circle me-2"></i><strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card card-stat bg-danger text-white">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5>Conflict Areas</h5>
                    <h2><?php echo count($resolvedConflicts); ?></h2>
                </div>
                <i class="fa fa-exclamation-triangle opacity-50" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card card-stat bg-warning text-dark">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5>Affected Nodes</h5>
                    <h2><?php echo $totalAffectedUsers; ?></h2>
                </div>
                <i class="fa fa-users opacity-50" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-12 col-lg-4 mb-3">
        <div class="card card-stat bg-success text-white">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5>System Status</h5>
                    <h2><?php echo count($resolvedConflicts) === 0 ? 'Healthy' : 'Action Needed'; ?></h2>
                </div>
                <i class="fa <?php echo count($resolvedConflicts) === 0 ? 'fa-check-circle' : 'fa-wrench'; ?> opacity-50" style="font-size: 3rem;"></i>
            </div>
        </div>
    </div>
</div>

<?php if (count($resolvedConflicts) === 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <div class="text-success mb-3">
                <i class="fa fa-circle-check" style="font-size: 4rem;"></i>
            </div>
            <h4 class="fw-bold">No Placement Conflicts Found!</h4>
            <p class="text-muted mb-0">Every parent node in the binary placement tree has at most one left and one right child. The tree structure is perfectly organic and integrated.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Conflicts List -->
    <?php foreach ($resolvedConflicts as $index => $conflict): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap">
                <div class="d-flex align-items-center">
                    <span class="badge bg-danger me-2" style="font-size: 0.9rem;">Conflict Area #<?php echo $index + 1; ?></span>
                    <h5 class="mb-0 text-white">
                        Parent: <strong><?php echo htmlspecialchars($conflict['parent_username']); ?></strong>
                        (<span class="text-warning"><?php echo htmlspecialchars($conflict['parent_mid']); ?></span>)
                        on the <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($conflict['position']); ?></span> Position
                    </h5>
                </div>
                <div class="mt-2 mt-md-0">
                    <small class="text-muted">Parent ID: <?php echo $conflict['parent_id']; ?> | Email: <?php echo htmlspecialchars($conflict['parent_email']); ?></small>
                </div>
            </div>
            <div class="card-body bg-white">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="p-3 bg-light rounded border border-warning d-flex align-items-center justify-content-between flex-wrap">
                            <div>
                                <i class="fa fa-info-circle text-warning me-2"></i>
                                There are <strong><?php echo $conflict['child_count']; ?> users</strong> assigned to the <strong><?php echo htmlspecialchars($conflict['position']); ?></strong> leg of this parent.
                            </div>
                            <div class="mt-2 mt-md-0">
                                <strong>Status of other (<?php echo htmlspecialchars($conflict['other_position']); ?>) leg:</strong>
                                <?php if ($conflict['is_other_pos_vacant']): ?>
                                    <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> Vacant (Free)</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">
                                        Occupied by <strong><?php echo htmlspecialchars($conflict['other_occupant']['username']); ?></strong> (<?php echo htmlspecialchars($conflict['other_occupant']['mid']); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>MID (Member ID)</th>
                                <th>Username / Name</th>
                                <th>Email</th>
                                <th>Investment</th>
                                <th>Joined Date</th>
                                <th>Status</th>
                                <th class="text-center" style="width: 400px;">Resolution Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conflict['children'] as $child): ?>
                                <tr>
                                    <td>
                                        <strong class="text-primary"><?php echo htmlspecialchars($child['mid']); ?></strong>
                                        <br><small class="text-muted">ID: <?php echo $child['id']; ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($child['username']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($child['full_name'] ?: 'No Full Name'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($child['email']); ?></td>
                                    <td>$<?php echo number_format($child['total_investment'], 2); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($child['created_at'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo $child['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo strtoupper($child['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            <!-- Move to vacant position, if applicable -->
                                            <?php if ($conflict['is_other_pos_vacant']): ?>
                                                <form method="post" onsubmit="return confirm('Are you sure you want to shift this user to the other leg?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="action" value="move_other_position">
                                                    <input type="hidden" name="user_id" value="<?php echo $child['id']; ?>">
                                                    <input type="hidden" name="placement_id" value="<?php echo $conflict['parent_id']; ?>">
                                                    <input type="hidden" name="current_position" value="<?php echo htmlspecialchars($conflict['position']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success w-100 text-start">
                                                        <i class="fa fa-exchange-alt me-2"></i> Move to vacant <strong><?php echo htmlspecialchars($conflict['other_position']); ?></strong> position
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Detach from placement -->
                                            <form method="post" onsubmit="return confirm('Are you sure you want to detach this user from the binary placement tree? You will need to re-assign them later.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                <input type="hidden" name="action" value="set_placement_null">
                                                <input type="hidden" name="user_id" value="<?php echo $child['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100 text-start">
                                                    <i class="fa fa-unlink me-2"></i> Detach from placement tree (Set to NULL)
                                                </button>
                                            </form>

                                            <!-- Reassign parent/position dropdown form -->
                                            <div class="border rounded p-2 bg-light">
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="action" value="change_placement">
                                                    <input type="hidden" name="user_id" value="<?php echo $child['id']; ?>">

                                                    <div class="input-group input-group-sm mb-2">
                                                        <span class="input-group-text"><i class="fa fa-user-plus"></i></span>
                                                        <input type="text" name="new_parent_search" class="form-control" placeholder="New Parent ID, MID or Username" required>
                                                    </div>

                                                    <div class="row g-1 mb-2">
                                                        <div class="col-8">
                                                            <select name="new_position" class="form-select form-select-sm" required>
                                                                <option value="">-- Choose Leg --</option>
                                                                <option value="left">Left Leg</option>
                                                                <option value="right">Right Leg</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-4">
                                                            <button type="submit" class="btn btn-sm btn-primary w-100">Reassign</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
