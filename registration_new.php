<?php
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance()->getConnection();

$sponsorId = $_GET['id'] ?? '';
$sponsorName = "Not Found";

if (!empty($sponsorId)) {
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$sponsorId]);
    $user = $stmt->fetch();
    if ($user) {
        $sponsorName = $user['username'];
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="title" content="Registration - Optimus Infinity">
  <meta property="og:title" content="Registration - Optimus Infinity">
  <title>Registration - Optimus Infinity</title>


  <!-- Favicon -->
  <link rel="shortcut icon" href="https://optimusinfinity.com/assets/fav.png" />
  <!-- Favicon -->

  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/core/libs.min.css">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/coinex.min.css?v=4.1.0">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/custom.min.css?v=4.1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <!-- Page CSS -->
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/authentication.css">

  <!-- Font Awesome -->
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700;1,800&display=swap"
    rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
    integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<style>
  /*New Loader*/
  .loader.simple-loader .loader-body {
    background: url('../assets/images/loader-new.png') no-repeat scroll center center;
    animation: rotate 2s infinite linear;
    background-size: auto 150px;

  }

  input {
    background-color: #3f2259 !important;
    color: #fff !important;
  }
  select {
    background-color: #3f2259 !important;
    color: #fff !important;
  }
  .form-label {
    color: #333 !important;
  }
</style>

<body style="background-color: #3f2259">
  <div class="container-fluid content-inner pb-0">

    <div class="wrapper">
      <div class="row reg">
        <div class="col-xl-9 col-lg-8 mx-auto">
          <div class="card p-3" style="background-color: #fff !important;">
            <div class="card-header d-flex justify-content-between">
              <div class="header-title">
                <h4 class="card-title text-uppercase text-dark">Registration</h4>
              </div>
            </div>
            <div class="card-body">
              <div class="new-user-info">
                <form action="create_user.php" method="post">
                  <div class="row">
                    <div class="form-group col-12 col-sm-12 col-md-6 col-lg-6 col-xl-6">
                      <label class="form-label" for="referral_id">Sponsor ID: </label>
                      <input type="text" class="form-control" id="referral_id" name="referral_id"
                        value="<?php echo htmlspecialchars($sponsorId); ?>" readonly>
                    </div>
                    <div class="form-group col-12 col-sm-12 col-md-6 col-lg-6 col-xl-6">
                      <label class="form-label" for="sponsor_name">Sponsor Name: </label>
                      <input type="text" class="form-control" id="sponsor_name" value="<?php echo htmlspecialchars($sponsorName); ?>" readonly>
                    </div>

                    <div class="form-group col-12">
                      <label class="form-label" for="name">Full Name: </label>
                      <input type="text" class="form-control" id="name" name="name" required
                        placeholder="Full Name">
                    </div>

                    <div class="form-group col-12">
                      <label class="form-label" for="username">Username: </label>
                      <input type="text" class="form-control" id="username" name="username" required
                        placeholder="Username">
                    </div>

                    <div class="form-group col-md-6">
                      <label class="form-label" for="phone">Phone: </label>
                      <input type="text" class="form-control" id="phone" name="phone" required
                        placeholder="Phone">
                    </div>

                    <div class="form-group col-md-6">
                      <label class="form-label" for="email">Email</label>
                      <input type="email" class="form-control" id="email" placeholder="Email"
                        name="email" required>
                    </div>

                    <div class="form-group col-md-6">
                      <label class="form-label" for="otp">OTP <span id="ajax_msg"></span></label>
                      <div class="input-group mb-3">
                        <input type="text" class="form-control" id="otp" placeholder="OTP" name="otp">
                        <button class="btn text-white btn-primary" type="button" id="otp_btn"
                          style="background-color:#cca254">SEND OTP</button>
                      </div>
                    </div>

                    <div class="form-group col-md-6">
                      <label class="form-label" for="country">Country</label>
                      <select class="form-select" id="country" name="country">
                        <option value="USA">United States</option>
                        <option value="UK">United Kingdom</option>
                        <option value="India">India</option>
                        <option value="UAE">United Arab Emirates</option>
                        <!-- Add other countries as needed -->
                      </select>
                    </div>

                    <div class="form-group col-md-6">
                        <label class="form-label">Position</label>
                        <select name="position" class="form-select">
                            <option value="left">Left</option>
                            <option value="right">Right</option>
                        </select>
                    </div>

                  </div>
                  <hr>
                  <h5 class="mb-3 text-dark">Security</h5>
                  <div class="row">
                    <div class="form-group col-md-6">
                      <label class="form-label" for="password">Password: </label>
                      <input type="password" class="form-control" id="password" name="password" required
                        placeholder="Password">
                    </div>
                    <div class="form-group col-md-6">
                      <label class="form-label" for="password_confirmation">Confirm Password: </label>
                      <input type="password" class="form-control" id="password_confirmation" required
                        name="password_confirmation" placeholder="Repeat Password">
                    </div>
                  </div>
                  <div class="text-center mt-4">
                    <button type="submit" id="submit-success" class="text-white btn btn-primary submit-btn px-4"><i
                        class="fa-regular fa-circle-check me-2"></i>Register Now</button>
                  </div>
                  <div class="text-center mt-3">
                      <a href="login.php" class="text-dark">Already have an account? Login</a>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
  <script type="text/javascript">
    $(document).ready(function () {
      $('#otp_btn').click(function () {
        $('#ajax_msg').text('OTP Sent (Simulator)').css('color', 'green');
      });
    });
  </script>
</body>
</html>
