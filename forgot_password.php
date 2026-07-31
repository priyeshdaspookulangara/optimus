<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($username) || empty($email)) {
        $error = "Please fill in all fields.";
    } else {
        $db = Database::getInstance()->getConnection();

        // Find user by username and email
        $stmt = $db->prepare("SELECT id, email, username FROM users WHERE username = ? AND email = ?");
        $stmt->execute([$username, $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Delete any existing tokens for this user
            $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->execute([$user['id']]);

            // Generate a secure random token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Insert new token
            $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expires_at]);

            // Construct password reset link
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $reset_link = "{$protocol}://{$host}{$dir}/reset_password.php?token={$token}";

            // Email details
            $to = $user['email'];
            $subject = "Password Recovery - MLM App";
            $message = "Hello " . htmlspecialchars($user['username']) . ",\n\n" .
                       "We received a request to reset your password. Click the link below to set a new password:\n\n" .
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
            file_put_contents(__DIR__ . '/emails.log', $log_entry, FILE_APPEND);

            $success = "A password recovery email has been sent. Please check your inbox and follow the instructions.";
        } else {
            $error = "No user found with the provided Username and Email combination.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password - MLM App</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #3f2259; color: #fff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .forgot-content { background: #fff; color: #333; padding: 40px; border-radius: 24px; width: 400px; }
    .btn-primary { background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; width: 100%; padding: 10px; border-radius: 25px; }
  </style>
</head>
<body>
  <div class="forgot-content">
    <form method="post">
      <h2 class="text-center mb-4">Forgot Password</h2>

      <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label">User ID (Username)</label>
        <input type="text" name="username" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary mt-3">Reset Password</button>
      <div class="text-center mt-3">
        <a href="login.php" class="text-decoration-none">Back to Login</a>
      </div>
    </form>
  </div>
</body>
</html>
