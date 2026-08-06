<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance()->getConnection();
$token = trim($_GET['token'] ?? '');
$email = trim($_GET['email'] ?? '');
$error = '';
$success = '';
$validToken = false;
$is_admin_val = 0;

if (empty($token) || empty($email)) {
    $error = "Invalid or missing password reset parameters.";
} else {
    // Validate token in database
    $stmt = $db->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$email, $token]);
    $resetRequest = $stmt->fetch();

    if ($resetRequest) {
        $validToken = true;
        $is_admin_val = (int)$resetRequest['is_admin'];
    } else {
        $error = "The password reset link is invalid or has expired. Please request a new link.";
    }
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "The passwords you entered do not match.";
    } else {
        // Hash password with bcrypt for secure password_verify login compatibility
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

        // Begin transaction to ensure consistency
        $db->beginTransaction();
        try {
            if ($is_admin_val) {
                $stmtUpdate = $db->prepare("UPDATE admins SET password = ? WHERE email = ?");
            } else {
                $stmtUpdate = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
            }
            $stmtUpdate->execute([$hashed_password, $email]);

            // Delete the consumed token
            $stmtDel = $db->prepare("DELETE FROM password_resets WHERE email = ? AND is_admin = ?");
            $stmtDel->execute([$email, $is_admin_val]);

            $db->commit();
            $success = "Your password has been successfully updated! You can now log in using your new credentials.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "An error occurred while updating your password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #3f2259; color: #fff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .reset-content { background: #fff; color: #333; padding: 40px; border-radius: 24px; width: 420px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn-primary { background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; width: 100%; padding: 10px; border-radius: 25px; }
        .text-custom-link { color: #cca354 !important; font-weight: 500; }
    </style>
</head>
<body>
    <div class="reset-content">
        <h2 class="text-center mb-2 fw-bold text-dark">Reset Password</h2>
        <p class="text-center text-muted mb-4" style="font-size: 0.9rem;">
            Set your new login credentials below.
        </p>

        <?php if (!empty($error) && !$success): ?>
            <div class="alert alert-danger py-2" style="font-size: 0.85rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success py-2" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($success); ?></div>
            <div class="text-center mt-3">
                <a href="<?php echo $is_admin_val ? 'admin/login.php' : 'login.php'; ?>" class="btn btn-primary text-white" style="border-radius: 25px;">Proceed to Login</a>
            </div>
        <?php elseif ($validToken): ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label" style="font-size: 0.9rem; font-weight: 500;">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="new_password" class="form-control border-start-0" placeholder="Minimum 6 characters" style="border-radius: 0 8px 8px 0;" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size: 0.9rem; font-weight: 500;">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-circle-check"></i></span>
                        <input type="password" name="confirm_password" class="form-control border-start-0" placeholder="Repeat new password" style="border-radius: 0 8px 8px 0;" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Reset Password</button>
            </form>
        <?php else: ?>
            <div class="text-center mt-3">
                <a href="login.php" class="text-custom-link text-decoration-none" style="font-size: 0.85rem;"><i class="fa-solid fa-arrow-left me-1"></i> Return to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
