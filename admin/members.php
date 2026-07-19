<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance()->getConnection();

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'update_status') {
        $userId = $_POST['user_id'];
        $newStatus = $_POST['status'];
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        header("Location: members.php?success=status_updated");
        exit();
    }

    if ($_POST['action'] == 'delete_member') {
        $userId = $_POST['user_id'];

        $db->beginTransaction();
        try {
            // 1. Update other users who have this user as sponsor or placement
            $stmt = $db->prepare("UPDATE users SET sponsor_id = NULL WHERE sponsor_id = ?");
            $stmt->execute([$userId]);

            $stmt = $db->prepare("UPDATE users SET placement_id = NULL, position = NULL WHERE placement_id = ?");
            $stmt->execute([$userId]);

            // 2. Delete from genealogy (both where user is parent or child)
            $stmt = $db->prepare("DELETE FROM genealogy WHERE user_id = ? OR parent_id = ?");
            $stmt->execute([$userId, $userId]);

            // 3. Delete from transactions (both where user is the receiver or related participant)
            $stmt = $db->prepare("DELETE FROM transactions WHERE user_id = ? OR related_user_id = ?");
            $stmt->execute([$userId, $userId]);

            // 4. Delete investments
            $stmt = $db->prepare("DELETE FROM investments WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 5. Delete pins (both assigned to or used by)
            $stmt = $db->prepare("DELETE FROM pins WHERE assigned_to = ? OR used_by = ?");
            $stmt->execute([$userId, $userId]);

            // 6. Delete user wallets
            $stmt = $db->prepare("DELETE FROM user_wallets WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 7. Finally, delete the user itself
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            $db->commit();
            header("Location: members.php?success=member_deleted");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: members.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

$pageTitle = 'Member Management';
include __DIR__ . '/includes/header.php';

$search = $_GET['search'] ?? '';
$query = "SELECT * FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute(["%$search%", "%$search%"]);
$members = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Members List</h3>
    <form class="d-flex" style="width: 300px;">
        <input type="text" name="search" class="form-control me-2" placeholder="Search username..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-outline-primary">Search</button>
    </form>
</div>

<?php if(isset($_GET['success'])): ?>
    <?php if($_GET['success'] == 'status_updated'): ?>
        <div class="alert alert-success">User status has been updated successfully.</div>
    <?php elseif($_GET['success'] == 'member_deleted'): ?>
        <div class="alert alert-success">Member and all associated join records deleted successfully.</div>
    <?php else: ?>
        <div class="alert alert-success">Action completed successfully.</div>
    <?php endif; ?>
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
                        <th>ID</th>
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
                        <td><?php echo $m['id']; ?></td>
                        <td><strong><?php echo $m['username']; ?></strong></td>
                        <td><?php echo $m['email']; ?></td>
                        <td><span class="badge bg-info">Rank <?php echo $m['rank_id']; ?></span></td>
                        <td>$<?php echo number_format($m['total_investment'], 2); ?></td>
                        <td>
                            <span class="badge <?php echo $m['status'] == 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo strtoupper($m['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($m['created_at'])); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <?php if($m['status'] == 'active'): ?>
                                    <button type="submit" name="status" value="suspended" class="btn btn-sm btn-outline-warning">Suspend</button>
                                <?php else: ?>
                                    <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
                                <?php endif; ?>
                            </form>

                            <form method="post" class="d-inline" onsubmit="return confirm('ARE YOU SURE? This will permanently delete this member and all associated records (genealogy, transactions, investments, wallets, PINs)! This action CANNOT be undone.');">
                                <input type="hidden" name="user_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="action" value="delete_member">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
