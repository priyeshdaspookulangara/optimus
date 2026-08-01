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
    $sponsorRef = trim($_POST['referral_id'] ?? '');
    $pinCode = trim($_POST['pin_code'] ?? '');

    if ($password !== $confirmPassword) {
        die("Passwords do not match. <a href='javascript:history.back()'>Go back</a>");
    }

    $db = Database::getInstance()->getConnection();

    // Check if user exists (only username must be unique, email can be used by multiple users)
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        die("Username already exists. <a href='javascript:history.back()'>Go back</a>");
    }

    // Resolve sponsor ID from Sponsor mid/code
    $sponsorDbId = null;
    $sponsorName = "None";
    $sponsorMidDisplay = "None";
    if (!empty($sponsorRef)) {
        $stmtSponsor = $db->prepare("SELECT id, username, mid FROM users WHERE mid = ? OR id = ?");
        $stmtSponsor->execute([$sponsorRef, $sponsorRef]);
        $sData = $stmtSponsor->fetch();
        if ($sData) {
            $sponsorDbId = $sData['id'];
            $sponsorName = $sData['username'];
            $sponsorMidDisplay = !empty($sData['mid']) ? $sData['mid'] : $sData['id'];
        }
    }

    // Generate unique alphanumeric mid code (OPTxxxxx)
    $newMid = '';
    $midExists = true;
    while ($midExists) {
        $newMid = 'OPT' . rand(10000, 99999);
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE mid = ?");
        $stmtCheck->execute([$newMid]);
        if (!$stmtCheck->fetch()) {
            $midExists = false;
        }
    }

    $hashedPassword = $password;

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO users (mid, username, full_name, phone, address, post_office_number, state, country, email, password, sponsor_id, placement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$newMid, $username, $fullName, $phone, $address, $postOfficeNumber, $state, $country, $email, $hashedPassword, $sponsorDbId, $sponsorDbId]);
        $newUserId = $db->lastInsertId();

        if ($sponsorDbId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorDbId);
        }

        // If a PIN code was provided during registration, activate the package instantly!
        if (!empty($pinCode)) {
            $engine = new MLMEngine();
            $engine->activateWithPin($newUserId, $pinCode);
        }

        $db->commit();

        // Details are already fetched beforehand as $sponsorName and $sponsorMidDisplay

        // Fetch Activated Package Name
        $packageName = "None (Pending Activation)";
        if (!empty($pinCode)) {
            $stmtPinPkg = $db->prepare("SELECT p.name FROM pins pin JOIN packages p ON pin.package_id = p.id WHERE pin.pin_code = ?");
            $stmtPinPkg->execute([$pinCode]);
            $pData = $stmtPinPkg->fetch();
            if ($pData) {
                $packageName = $pData['name'];
            }
        }
        ?>
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
          <title>Registration Successful - Optimus Infinity</title>
          <link rel="shortcut icon" href="https://optimusinfinity.com/assets/fav.png" />
          <link rel="stylesheet" href="assets/web/css/bootstrap.min.css">
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
            .table-details td {
              padding: 10px 15px;
              font-size: 1rem;
              border-bottom: 1px solid #eee;
            }
            .table-details tr:last-child td {
              border-bottom: none;
            }
            .label-column {
              font-weight: 600;
              color: #504793;
              width: 35%;
              text-transform: uppercase;
              font-size: 0.9rem;
            }
            .value-column {
              color: #333;
              font-weight: 500;
            }
          </style>
        </head>
        <body class="py-5">
          <div class="container">
            <div class="row justify-content-center">
              <div class="col-lg-8 col-md-10">
                <div class="card success-card p-4">
                  <div class="card-header border-0 bg-transparent text-center pb-0">
                    <div class="mb-3 text-success">
                      <i class="fa-solid fa-circle-check" style="font-size: 4rem;"></i>
                    </div>
                    <h2 class="text-uppercase fw-bold text-dark mb-1">Registration Success</h2>
                    <p class="text-muted">Your account has been successfully created. Please save these details!</p>
                  </div>
                  <div class="card-body">
                    <div class="table-responsive">
                      <table class="table table-details mb-0">
                        <tbody>
                          <tr>
                            <td class="label-column">SPONSOR NAME</td>
                            <td class="value-column"><?php echo htmlspecialchars($sponsorName); ?> (<?php echo htmlspecialchars($sponsorMidDisplay); ?>)</td>
                          </tr>
                          <tr>
                            <td class="label-column">PACKAGE</td>
                            <td class="value-column">
                              <span class="badge bg-success" style="font-size: 0.9rem;"><?php echo htmlspecialchars($packageName); ?></span>
                            </td>
                          </tr>
                          <tr>
                            <td class="label-column">FULL NAME</td>
                            <td class="value-column"><?php echo htmlspecialchars($fullName); ?></td>
                          </tr>
                          <tr>
                            <td class="label-column">USERNAME</td>
                            <td class="value-column"><strong><?php echo htmlspecialchars($username); ?></strong></td>
                          </tr>
                          <tr>
                            <td class="label-column">USER ID (MEMBER CODE / MID)</td>
                            <td class="value-column"><strong style="color: #504793; font-size: 1.1rem;"><?php echo htmlspecialchars($newMid); ?></strong></td>
                          </tr>
                          <tr>
                            <td class="label-column">PASSWORD</td>
                            <td class="value-column"><code style="font-size: 1.1rem; color: #d63384; font-weight: bold;"><?php echo htmlspecialchars($password); ?></code></td>
                          </tr>
                          <tr>
                            <td class="label-column">MOBILE NUMBER</td>
                            <td class="value-column"><?php echo htmlspecialchars($phone); ?></td>
                          </tr>
                          <tr>
                            <td class="label-column">EMAIL ID</td>
                            <td class="value-column"><?php echo htmlspecialchars($email); ?></td>
                          </tr>
                          <tr>
                            <td class="label-column">COUNTRY</td>
                            <td class="value-column"><?php echo htmlspecialchars($country); ?></td>
                          </tr>
                          <tr>
                            <td class="label-column">STATE / REGION</td>
                            <td class="value-column"><?php echo htmlspecialchars($state); ?></td>
                          </tr>
                          <tr>
                            <td class="label-column">ADDRESS</td>
                            <td class="value-column"><?php echo htmlspecialchars($address); ?></td>
                          </tr>
                        </tbody>
                      </table>
                    </div>

                    <div class="text-center mt-4">
                      <a href="login.php" class="text-white btn btn-primary px-5 py-3 text-uppercase fw-bold" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; border-radius: 5px;">
                        <i class="fa-solid fa-sign-in-alt me-2"></i>Proceed to Login
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
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
