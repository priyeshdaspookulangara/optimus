<?php
require_once __DIR__ . '/includes/engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $postOfficeNumber = trim($_POST['post_office_number'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';
    $sponsorId = $_POST['referral_id'] ?? null;
    $pinCode = trim($_POST['pin_code'] ?? '');

    if ($password !== $confirmPassword) {
        die("Passwords do not match. <a href='javascript:history.back()'>Go back</a>");
    }

    $db = Database::getInstance()->getConnection();

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        die("Username or Email already exists. <a href='javascript:history.back()'>Go back</a>");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO users (username, full_name, phone, address, post_office_number, state, country, email, password, sponsor_id, placement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $fullName, $phone, $address, $postOfficeNumber, $state, $country, $email, $hashedPassword, $sponsorId, $sponsorId]);
        $newUserId = $db->lastInsertId();

        if ($sponsorId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorId);
        }

        // If a PIN code was provided during registration, activate the package instantly!
        if (!empty($pinCode)) {
            $engine = new MLMEngine();
            $engine->activateWithPin($newUserId, $pinCode);
        }

        $db->commit();

        // Retrieve Sponsor Name
        $sponsorName = 'N/A';
        if (!empty($sponsorId)) {
            $stmtSponsor = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmtSponsor->execute([$sponsorId]);
            $sponsor = $stmtSponsor->fetch();
            if ($sponsor) {
                $sponsorName = $sponsor['username'];
            }
        }

        // Retrieve Package Name
        $packageName = 'N/A';
        if (!empty($pinCode)) {
            $stmtPin = $db->prepare("SELECT pkg.name as package_name FROM pins p JOIN packages pkg ON p.package_id = pkg.id WHERE p.pin_code = ?");
            $stmtPin->execute([$pinCode]);
            $pinInfo = $stmtPin->fetch();
            if ($pinInfo) {
                $packageName = $pinInfo['package_name'];
            }
        }
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Registration Success - Optimus Infinity</title>

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
    .sticky-header.menu-area {
      background-color: #3f2259;
      padding: 15px 0;
      border-bottom: 1px solid #504793;
    }
    .logo img {
      width: 200px;
    }
    .detail-table th {
      background-color: #f8f9fa;
      color: #333;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.9rem;
      width: 40%;
    }
    .detail-table td {
      color: #495057;
      font-weight: 500;
      font-size: 0.95rem;
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
          <div class="card reg-card p-4">
            <div class="card-header border-0 bg-transparent text-center">
              <div class="text-success mb-3">
                <i class="fa-regular fa-circle-check fa-4x animate__animated animate__bounceIn"></i>
              </div>
              <h2 class="text-uppercase fw-bold text-dark mb-2">Registration Successful!</h2>
              <p class="text-muted">Please find your account and sponsorship details below. Make sure to keep them safe.</p>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-bordered detail-table">
                  <tbody>
                    <tr>
                      <th>SPONSOR NAME</th>
                      <td><?php echo htmlspecialchars($sponsorName); ?></td>
                    </tr>
                    <tr>
                      <th>PACKAGE</th>
                      <td><?php echo htmlspecialchars($packageName); ?></td>
                    </tr>
                    <tr>
                      <th>FULL NAME</th>
                      <td><?php echo htmlspecialchars($fullName); ?></td>
                    </tr>
                    <tr>
                      <th>USERNAME</th>
                      <td><?php echo htmlspecialchars($username); ?></td>
                    </tr>
                    <tr>
                      <th>USER ID</th>
                      <td><strong><?php echo htmlspecialchars($newUserId); ?></strong></td>
                    </tr>
                    <tr>
                      <th>PASSWORD</th>
                      <td><code><?php echo htmlspecialchars($password); ?></code></td>
                    </tr>
                    <tr>
                      <th>MOBILE NUMBER</th>
                      <td><?php echo htmlspecialchars($phone); ?></td>
                    </tr>
                    <tr>
                      <th>EMAIL ID</th>
                      <td><?php echo htmlspecialchars($email); ?></td>
                    </tr>
                    <tr>
                      <th>COUNTRY</th>
                      <td><?php echo htmlspecialchars($country); ?></td>
                    </tr>
                    <tr>
                      <th>STATE / REGION</th>
                      <td><?php echo htmlspecialchars($state); ?></td>
                    </tr>
                    <tr>
                      <th>ADDRESS</th>
                      <td><?php echo htmlspecialchars($address); ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="text-center mt-4">
                <a href="login.php" class="text-white btn btn-primary px-5 py-3 text-uppercase fw-bold" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none;">
                  <i class="fa-solid fa-right-to-bracket me-2"></i>Login Now
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
<?php
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "Registration failed: " . $e->getMessage() . " <a href='javascript:history.back()'>Go back</a>";
    }
} else {
    header("Location: register.php");
}
