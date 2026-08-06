<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - MLM App</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    body { background-color: #3f2259; color: #fff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .login-content { background: #fff; color: #333; padding: 40px; border-radius: 24px; width: 400px; }
    .btn-primary { background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; width: 100%; padding: 10px; border-radius: 25px; }
    .password-toggle-btn {
      border: 1px solid #ced4da;
      border-left: none;
      background-color: #f8f9fa;
      color: #6c757d;
      border-top-left-radius: 0;
      border-bottom-left-radius: 0;
    }
    .password-toggle-btn:hover {
      background-color: #e2e6ea;
      color: #495057;
    }
  </style>
</head>
<body>
  <div class="login-content">
    <div class="text-center mb-4">
      <img src="https://optimusinfinity.com/assets/logo.png" alt="Logo" style="width: 200px; max-width: 100%;">
    </div>
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
        <div class="input-group">
          <input type="password" name="password" id="password" class="form-control" required style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none;">
          <button class="btn password-toggle-btn" type="button" id="togglePassword">
            <i class="fa-solid fa-eye-slash"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-3">Login</button>
      <div class="text-center mt-3">
        <small>Demo account: Use your registered credentials</small>
      </div>
    </form>
  </div>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script type="text/javascript">
    $(document).ready(function () {
      $('#togglePassword').click(function() {
        var pwdField = $('#password');
        var type = pwdField.attr('type') === 'password' ? 'text' : 'password';
        pwdField.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
      });
    });
  </script>
</body>
</html>
