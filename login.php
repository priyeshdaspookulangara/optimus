<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - MLM App</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #3f2259; color: #fff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .login-content { background: #fff; color: #333; padding: 40px; border-radius: 24px; width: 400px; }
    .btn-primary { background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; width: 100%; padding: 10px; border-radius: 25px; }
  </style>
</head>
<body>
  <div class="login-content">
    <form action="authenticate.php" method="post">
      <h2 class="text-center mb-4">Login</h2>
      <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger">Invalid credentials</div>
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label">User ID</label>
        <input type="text" name="user_id" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary mt-3">Login</button>
      <div class="text-center mt-3">
        <small>Demo account: Use your registered credentials</small>
      </div>
    </form>
  </div>
</body>
</html>
