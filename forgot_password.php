<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance()->getConnection();
$isAdmin = isset($_GET['admin']) || (isset($_POST['is_admin']) && $_POST['is_admin'] == '1');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $is_admin_val = (isset($_POST['is_admin']) && $_POST['is_admin'] == '1') ? 1 : 0;

    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        // Check if email exists
        if ($is_admin_val) {
            $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        }
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        // Standard message for security against harvesting
        $success = "If your email is registered in our system, you will receive a password reset link shortly.";

        if ($record) {
            // Generate secure token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Delete existing tokens for this email
            $stmtDel = $db->prepare("DELETE FROM password_resets WHERE email = ? AND is_admin = ?");
            $stmtDel->execute([$email, $is_admin_val]);

            // Save token
            $stmtIns = $db->prepare("INSERT INTO password_resets (email, token, is_admin, expires_at) VALUES (?, ?, ?, ?)");
            $stmtIns->execute([$email, $token, $is_admin_val, $expiresAt]);

            // Construct Reset Link
            $resetLink = "http://" . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000') . "/reset_password.php?token=" . $token . "&email=" . urlencode($email);

            // Logging Email locally for sandbox testing (using PHP's secure error_log system)
            $logMsg = date('[Y-m-d H:i:s]') . " " . ($is_admin_val ? 'ADMIN' : 'USER') . " Password Reset Request for: {$email}\n";
            $logMsg .= "Reset Link: {$resetLink}\n";
            $logMsg .= "---------------------------------------------------------\n";
            error_log($logMsg);
            // Also write to a standard location in /tmp for verification scripts if needed
            @file_put_contents('/tmp/emails.log', $logMsg, FILE_APPEND);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #3f2259; color: #fff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .recovery-content { background: #fff; color: #333; padding: 40px; border-radius: 24px; width: 420px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn-primary { background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; width: 100%; padding: 10px; border-radius: 25px; }
        .text-custom-link { color: #cca354 !important; font-weight: 500; }
    </style>
</head>
<body>
    <div class="recovery-content">
        <h2 class="text-center mb-2 fw-bold text-dark">Recover Password</h2>
        <p class="text-center text-muted mb-4" style="font-size: 0.9rem;">
            <?php echo $isAdmin ? 'Admin Portal Recovery Mode' : 'Enter your registered email to reset your password.'; ?>
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2" style="font-size: 0.85rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success py-2" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($success); ?></div>
            <div class="text-center mt-3">
                <a href="<?php echo $isAdmin ? 'admin/login.php' : 'login.php'; ?>" class="btn btn-outline-secondary w-100" style="border-radius: 25px;">Back to Login</a>
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="is_admin" value="<?php echo $isAdmin ? '1' : '0'; ?>">
                <div class="mb-3">
                    <label class="form-label" style="font-size: 0.9rem; font-weight: 500;">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control border-start-0" placeholder="name@example.com" style="border-radius: 0 8px 8px 0;" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Send Reset Link</button>
                <div class="text-center mt-3">
                    <a href="<?php echo $isAdmin ? 'admin/login.php' : 'login.php'; ?>" class="text-custom-link text-decoration-none" style="font-size: 0.85rem;"><i class="fa-solid fa-arrow-left me-1"></i> Back to Login</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
