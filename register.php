<?php
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance()->getConnection();

$sponsorQuery = $_GET['id'] ?? '';
$sponsorName = "Not Found";
$sponsorMid = "";
$sponsorDbId = "";

if (!empty($sponsorQuery)) {
    // Search by mid first, then by auto-increment id as fallback
    $stmt = $db->prepare("SELECT id, username, mid FROM users WHERE mid = ? OR id = ?");
    $stmt->execute([$sponsorQuery, $sponsorQuery]);
    $user = $stmt->fetch();
    if ($user) {
        $sponsorName = $user['username'];
        $sponsorMid = !empty($user['mid']) ? $user['mid'] : $user['id'];
        $sponsorDbId = $user['id'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Registration - Optimus Infinity</title>

  <!-- Favicon -->
  <link rel="shortcut icon" href="https://optimusinfinity.com/assets/fav.png" />

  <link rel="stylesheet" href="assets/web/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/web/css/fontawesome-all.min.css">
  <link rel="stylesheet" href="assets/web/css/style.css">
  <link rel="stylesheet" href="assets/web/css/responsive.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    body {
      background-color: #3f2259;
      color: #fff;
      font-family: 'Poppins', sans-serif;
    }
    .reg-card {
      background-color: #ffffff !important;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      color: #333 !important;
    }
    .form-label, label {
      color: #333 !important;
      font-weight: 500;
    }
    .form-control, .form-select {
      background-color: #3f2259 !important;
      color: #fff !important;
      border: 1px solid #504793;
    }
    .form-control:focus, .form-select:focus {
      background-color: #3f2259 !important;
      color: #fff !important;
      box-shadow: 0 0 5px rgba(80, 71, 147, 0.5);
    }
    .pin-status-badge {
      font-size: 0.85rem;
      padding: 5px 10px;
      border-radius: 5px;
      display: inline-block;
      margin-top: 5px;
    }
    .sticky-header.menu-area {
      background-color: #3f2259;
      padding: 15px 0;
      border-bottom: 1px solid #504793;
    }
    .logo img {
      width: 200px;
    }
  </style>
</head>

<body>

  <!-- header-area -->
  <header id="header">
    <div id="sticky-header" class="menu-area">
      <div class="container custom-container">
        <div class="row">
          <div class="col-12">
            <div class="menu-wrap d-flex justify-content-between align-items-center">
              <div class="logo">
                <a href="index.php"><img src="https://optimusinfinity.com/assets/logo.png" alt="Logo"></a>
              </div>
              <div class="header-action">
                <a href="login.php" class="btn btn-outline-light px-4">Sign In</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
  <!-- header-area-end -->

  <main class="py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
          <div class="card reg-card p-4">
            <div class="card-header border-0 bg-transparent text-center">
              <h2 class="text-uppercase fw-bold text-dark mb-0">Registration</h2>
            </div>
            <div class="card-body">
              <form action="create_user.php" method="post" id="regForm">
                <div class="row">

                  <!-- Sponsor Info -->
                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="mid">Sponsor Code (Sponsor ID): </label>
                    <input type="text" class="form-control" id="mid" name="mid"
                      value="<?php echo htmlspecialchars($sponsorMid); ?>" required placeholder="Sponsor Code">
                    <input type="hidden" id="referral_id" name="referral_id"
                      value="<?php echo htmlspecialchars($sponsorDbId); ?>">
                  </div>
                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="sponsor_name">Sponsor Name: </label>
                    <input type="text" class="form-control" id="sponsor_name" value="<?php echo htmlspecialchars($sponsorName); ?>" readonly style="background-color: #e9ecef !important; color: #495057 !important;">
                  </div>

                  <!-- Activation PIN -->
                  <div class="form-group col-12 mb-3">
                    <label class="form-label" for="pin_code">Activation PIN (Required): </label>
                    <input type="text" class="form-control" id="pin_code" name="pin_code" required placeholder="OPTXXXXXX">
                    <div id="pin_feedback"></div>
                  </div>

                  <!-- Personal Info -->
                  <div class="form-group col-12 mb-3">
                    <label class="form-label" for="name">Full Name: </label>
                    <input type="text" class="form-control" id="name" name="name" required placeholder="Full Name">
                  </div>

                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="username">Username: </label>
                    <input type="text" class="form-control" id="username" name="username" required placeholder="Username">
                  </div>

                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="phone">Phone: </label>
                    <input type="text" class="form-control" id="phone" name="phone" required placeholder="Phone">
                  </div>

                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" placeholder="Email" name="email" required>
                  </div>

                  <!-- Address Details -->
                  <div class="form-group col-12 mb-3">
                    <label class="form-label" for="address">Address: </label>
                    <input type="text" class="form-control" id="address" name="address" required placeholder="Street address">
                  </div>

                  <div class="form-group col-md-4 mb-3">
                    <label class="form-label" for="post_office_number">Post Office Number: </label>
                    <input type="text" class="form-control" id="post_office_number" name="post_office_number" required placeholder="ZIP / PO Box">
                  </div>

                  <div class="form-group col-md-4 mb-3">
                    <label class="form-label" for="state">State: </label>
                    <input type="text" class="form-control" id="state" name="state" required placeholder="State / Province">
                  </div>

                  <div class="form-group col-md-4 mb-3">
                    <label class="form-label" for="country">Country</label>
                    <input type="text" class="form-control" id="country" name="country" required placeholder="Country">
                  </div>

                </div>

                <hr class="my-4">
                <h5 class="mb-3 text-dark fw-bold">Security</h5>
                <div class="row">
                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="password">Password: </label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="password" name="password" required placeholder="Password" style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none;">
                      <button class="btn btn-outline-secondary" type="button" id="togglePassword" style="border: 1px solid #504793; border-left: none; background-color: #3f2259; color: #ccc; border-top-left-radius: 0; border-bottom-left-radius: 0; padding-left: 15px; padding-right: 15px;">
                        <i class="fa-solid fa-eye-slash"></i>
                      </button>
                    </div>
                  </div>
                  <div class="form-group col-md-6 mb-3">
                    <label class="form-label" for="password_confirmation">Confirm Password: </label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="password_confirmation" required name="password_confirmation" placeholder="Repeat Password" style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none;">
                      <button class="btn btn-outline-secondary" type="button" id="togglePasswordConfirm" style="border: 1px solid #504793; border-left: none; background-color: #3f2259; color: #ccc; border-top-left-radius: 0; border-bottom-left-radius: 0; padding-left: 15px; padding-right: 15px;">
                        <i class="fa-solid fa-eye-slash"></i>
                      </button>
                    </div>
                  </div>
                </div>

                <div class="text-center mt-4">
                  <button type="submit" class="text-white btn btn-primary px-5 py-3 text-uppercase fw-bold" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none;">
                    <i class="fa-regular fa-circle-check me-2"></i>Register Now
                  </button>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="text-dark fw-bold">Already have an account? Login</a>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script type="text/javascript">
    $(document).ready(function () {

      // Dynamic Sponsor lookup on keyup
      $('#mid').on('input', function() {
        var id = $(this).val().trim();
        if(id) {
          $.getJSON('get_sponsor.php', { id: id }, function(data) {
            if (data.status === 'success') {
              $('#sponsor_name').val(data.username);
              $('#referral_id').val(data.id);
            } else {
              $('#sponsor_name').val('Not Found');
              $('#referral_id').val('');
            }
          });
        } else {
          $('#sponsor_name').val('Not Found');
          $('#referral_id').val('');
        }
      });

      var isPinValid = false;

      // Real-time PIN validation
      $('#pin_code').on('input', function() {
        var pin = $(this).val().trim();
        if(pin.length >= 6) {
          $.getJSON('check_pin.php', { pin: pin }, function(data) {
            if(data.status === 'success') {
              isPinValid = true;
              $('#pin_feedback').html('<span class="pin-status-badge bg-success text-white"><i class="fa fa-check-circle me-1"></i> Valid PIN: ' + data.package + ' ($' + data.amount + ')</span>');
            } else if(data.status === 'used') {
              isPinValid = false;
              $('#pin_feedback').html('<span class="pin-status-badge bg-warning text-white"><i class="fa fa-exclamation-triangle me-1"></i> Used PIN</span>');
            } else {
              isPinValid = false;
              $('#pin_feedback').html('<span class="pin-status-badge bg-danger text-white"><i class="fa fa-times-circle me-1"></i> Invalid PIN</span>');
            }
          });
        } else {
          isPinValid = false;
          $('#pin_feedback').empty();
        }
      });

      // Validate PIN before form submission
      $('#regForm').on('submit', function(e) {
        if (!isPinValid) {
          e.preventDefault();
          alert('Please enter a valid, unused Activation PIN.');
          $('#pin_code').focus();
          return false;
        }
      });

      // Password visibility toggles
      $('#togglePassword').click(function() {
        var pwdField = $('#password');
        var type = pwdField.attr('type') === 'password' ? 'text' : 'password';
        pwdField.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
      });

      $('#togglePasswordConfirm').click(function() {
        var pwdField = $('#password_confirmation');
        var type = pwdField.attr('type') === 'password' ? 'text' : 'password';
        pwdField.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
      });

    });
  </script>
</body>
</html>
