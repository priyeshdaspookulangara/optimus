<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($username) || empty($email)) {
        $error = "Please fill in all fields.";
    } else {
        $db = Database::getInstance()->getConnection();

        // Find admin by username and email
        $stmt = $db->prepare("SELECT id, email, username FROM admins WHERE username = ? AND email = ?");
        $stmt->execute([$username, $email]);
        $admin = $stmt->fetch();

        if ($admin) {
            // Delete any existing tokens for this admin
            $stmt = $db->prepare("DELETE FROM password_resets WHERE admin_id = ?");
            $stmt->execute([$admin['id']]);

            // Generate a secure random token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Insert new token
            $stmt = $db->prepare("INSERT INTO password_resets (admin_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$admin['id'], $token, $expires_at]);

            // Construct password reset link pointing to admin reset page
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); // this already includes /admin
            $reset_link = "{$protocol}://{$host}{$dir}/reset_password.php?token={$token}";

            // Email details
            $to = $admin['email'];
            $subject = "Admin Password Recovery - MLM App";
            $message = "Hello " . htmlspecialchars($admin['username']) . ",\n\n" .
                       "We received a request to reset your admin password. Click the link below to set a new password:\n\n" .
                       $reset_link . "\n\n" .
                       "This link will expire in 1 hour.\n\n" .
                       "If you did not request this, please ignore this email.\n\n" .
                       "Best regards,\nMLM App Team";

            $headers = "From: no-reply@" . $host . "\r\n" .
                       "Reply-To: no-reply@" . $host . "\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            // Send actual mail using PHP mail()
            @mail($to, $subject, $message, $headers);

            // Log the email to emails.log for sandbox/local testing
            $log_entry = "========================================\n" .
                         "Date: " . date('Y-m-d H:i:s') . "\n" .
                         "To: " . $to . "\n" .
                         "Subject: " . $subject . "\n" .
                         "Body:\n" . $message . "\n" .
                         "========================================\n\n";
            file_put_contents(__DIR__ . '/../emails.log', $log_entry, FILE_APPEND);

            $success = "An admin password recovery email has been sent. Please check your inbox and follow the instructions.";
        } else {
            $error = "No admin found with the provided Username and Email combination.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #1a1a1a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: #2d2d2d; border-radius: 15px; padding: 40px; width: 100%; max-width: 400px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .btn-primary { background: linear-gradient(90deg, #504793, #4fc2da); border: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2 class="text-center mb-4">Forgot Password</h2>

        <?php if(!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-control" required>
            </div>
            <div class="mb-4">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 mb-3">Reset Password</button>
            <div class="text-center">
                <a href="login.php" class="text-decoration-none text-light">Back to Login</a>
            </div>
        </form>
    </div>
</body>
</html>
