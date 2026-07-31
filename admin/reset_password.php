<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = '';
$valid_token = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($token)) {
    $error = "No reset token provided.";
} else {
    $db = Database::getInstance()->getConnection();

    // Check if token exists, is for an admin, and has not expired
    $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND admin_id IS NOT NULL AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset_req = $stmt->fetch();

    if ($reset_req) {
        $valid_token = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($password) || empty($confirm_password)) {
                $error = "Please fill in all fields.";
            } elseif ($password !== $confirm_password) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 6) {
                $error = "Password must be at least 6 characters long.";
            } else {
                // Update password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $reset_req['admin_id']]);

                // Delete the used token
                $stmt = $db->prepare("DELETE FROM password_resets WHERE id = ?");
                $stmt->execute([$reset_req['id']]);

                $success = "Your password has been successfully reset. You can now log in.";
                $valid_token = false; // Form should no longer be shown
            }
        }
    } else {
        $error = "The password reset link is invalid or has expired. Please request a new link.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reset Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #1a1a1a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: #2d2d2d; border-radius: 15px; padding: 40px; width: 100%; max-width: 400px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .btn-primary { background: linear-gradient(90deg, #504793, #4fc2da); border: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2 class="text-center mb-4">Reset Password</h2>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-primary w-100">Go to Login</a>
            </div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <form method="post">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Update Password</button>
                </form>
            <?php else: ?>
                <div class="text-center mt-3">
                    <a href="forgot_password.php" class="text-decoration-none text-light">Request New Link</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
