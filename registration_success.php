<?php
session_start();
require_once __DIR__ . '/includes/db.php';

// Retrieve new user ID from session
$newUserId = $_SESSION['new_user_id'] ?? null;

if (!$newUserId) {
    // If accessed directly or refreshed, redirect to register
    header("Location: register.php");
    exit();
}

// Clear session variable so it can't be refreshed or accessed again
unset($_SESSION['new_user_id']);

$db = Database::getInstance()->getConnection();

// Fetch newly registered user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$newUserId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: register.php");
    exit();
}

// Fetch Sponsor username if any
$sponsorUsername = 'N/A';
if ($user['sponsor_id']) {
    $stmtSponsor = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmtSponsor->execute([$user['sponsor_id']]);
    $sponsor = $stmtSponsor->fetch();
    if ($sponsor) {
        $sponsorUsername = $sponsor['username'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Welcome to Optimus Infinity</title>

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
    .success-card {
      background-color: #ffffff !important;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      color: #333 !important;
    }
    .sticky-header.menu-area {
      background-color: #3f2259;
      padding: 15px 0;
      border-bottom: 1px solid #504793;
    }
    .logo img {
      width: 200px;
    }
    .welcome-banner {
      background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%);
      border-radius: 10px;
      color: #fff !important;
    }
    .details-table th {
      background-color: #f8f9fa !important;
      color: #3f2259 !important;
      font-weight: 600;
      width: 35%;
    }
    .details-table td {
      color: #333 !important;
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
        <div class="col-xl-8 col-lg-9">
          <div class="card success-card p-4">
            <div class="card-body">

              <!-- Professional Welcome Header -->
              <div class="welcome-banner p-4 text-center mb-4">
                <div class="mb-2">
                  <i class="fa-regular fa-circle-check fa-4x text-white"></i>
                </div>
                <h2 class="fw-bold text-uppercase mb-1">Welcome to Optimus Infinity</h2>
                <p class="mb-0 fs-5 text-light">Your account has been registered successfully!</p>
              </div>

              <!-- Registration Details Summary -->
              <h4 class="text-dark fw-bold mb-3 border-bottom pb-2"><i class="fa fa-user-circle me-2 text-primary"></i>Account Details</h4>
              <p class="text-muted small">Please save your credentials below. For security reasons, this page cannot be refreshed or reloaded.</p>

              <div class="table-responsive">
                <table class="table table-bordered details-table">
                  <tbody>
                    <tr>
                      <th>Account / Member ID</th>
                      <td><strong class="text-primary fs-5"><?php echo htmlspecialchars($user['id']); ?></strong></td>
                    </tr>
                    <tr>
                      <th>Username</th>
                      <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                    </tr>
                    <tr>
                      <th>Full Name</th>
                      <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    </tr>
                    <tr>
                      <th>Email Address</th>
                      <td><?php echo htmlspecialchars($user['email']); ?></td>
                    </tr>
                    <tr>
                      <th>Phone Number</th>
                      <td><?php echo htmlspecialchars($user['phone']); ?></td>
                    </tr>
                    <tr>
                      <th>Sponsor Name / ID</th>
                      <td><?php echo htmlspecialchars($sponsorUsername); ?> (ID: <?php echo htmlspecialchars($user['sponsor_id'] ?? 'None'); ?>)</td>
                    </tr>
                    <tr>
                      <th>Binary Tree Position</th>
                      <td><span class="badge bg-secondary text-uppercase"><?php echo htmlspecialchars($user['position'] ?? 'Left'); ?></span></td>
                    </tr>
                    <tr>
                      <th>Country</th>
                      <td><?php echo htmlspecialchars($user['country']); ?></td>
                    </tr>
                    <tr>
                      <th>State / Region</th>
                      <td><?php echo htmlspecialchars($user['state']); ?></td>
                    </tr>
                    <tr>
                      <th>Mailing Address</th>
                      <td><?php echo htmlspecialchars($user['address']); ?> (PO: <?php echo htmlspecialchars($user['post_office_number']); ?>)</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="alert alert-info mt-4">
                <i class="fa fa-info-circle me-2"></i><strong>Next Steps:</strong> You can now log into your personal backoffice using your unique **Username** and secure password to purchase investment packages, track ROI, and view commissions.
              </div>

              <div class="text-center mt-4">
                <a href="login.php" class="text-white btn btn-primary px-5 py-3 text-uppercase fw-bold w-100 fs-5" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none;">
                  <i class="fa fa-sign-in-alt me-2"></i>Proceed to Login
                </a>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

</body>
</html>
