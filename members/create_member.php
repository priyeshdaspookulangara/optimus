<?php
session_start();
require_once __DIR__ . '/../includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$db = Database::getInstance()->getConnection();

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

    $placementDbId = $_POST['placement_id'] ?? null;
    $position = $_POST['position'] ?? null;

    if ($password !== $confirmPassword) {
        die("Passwords do not match. <a href='javascript:history.back()'>Go back</a>");
    }

    // Check if activation pin is provided and is valid/unused
    if (empty($pinCode)) {
        die("Activation PIN is required for registration. <a href='javascript:history.back()'>Go back</a>");
    }

    $stmtPin = $db->prepare("SELECT id, status FROM pins WHERE pin_code = ?");
    $stmtPin->execute([$pinCode]);
    $pinData = $stmtPin->fetch();
    if (!$pinData) {
        die("Invalid Activation PIN. <a href='javascript:history.back()'>Go back</a>");
    }
    if ($pinData['status'] !== 'unused') {
        die("Activation PIN has already been used. <a href='javascript:history.back()'>Go back</a>");
    }

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
        $stmtSponsor = $db->prepare("SELECT id, username, mid FROM users WHERE id = ? OR mid = ?");
        $stmtSponsor->execute([$sponsorRef, $sponsorRef]);
        $sData = $stmtSponsor->fetch();
        if ($sData) {
            $sponsorDbId = $sData['id'];
            $sponsorName = $sData['username'];
            $sponsorMidDisplay = !empty($sData['mid']) ? $sData['mid'] : $sData['id'];
        }
    }

    // Resolve placement parent ID
    if (!empty($placementDbId)) {
        $placementDbId = (int)$placementDbId;
    } else {
        $placementDbId = $sponsorDbId; // Fallback to sponsor_id as placement parent
    }

    if (empty($position) || !in_array($position, ['left', 'right'])) {
        $position = null; // Default to NULL if no explicit position
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

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO users (mid, username, full_name, phone, address, post_office_number, state, country, email, password, sponsor_id, placement_id, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$newMid, $username, $fullName, $phone, $address, $postOfficeNumber, $state, $country, $email, $hashedPassword, $sponsorDbId, $placementDbId, $position]);
        $newUserId = $db->lastInsertId();

        if ($sponsorDbId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorDbId);
        }

        // Activate the package using PIN code!
        if (!empty($pinCode)) {
            $engine = new MLMEngine();
            $engine->activateWithPin($newUserId, $pinCode);
        }

        $db->commit();

        // Fetch Activated Package Name
        $packageName = "None";
        if (!empty($pinCode)) {
            $stmtPinPkg = $db->prepare("SELECT p.name FROM pins pin JOIN packages p ON pin.package_id = p.id WHERE pin.pin_code = ?");
            $stmtPinPkg->execute([$pinCode]);
            $pData = $stmtPinPkg->fetch();
            if ($pData) {
                $packageName = $pData['name'];
            }
        }

        $pageTitle = 'Registration Success';
        $baseHref = '../'; // Set base href to parent folder so assets and side links resolve perfectly!
        include __DIR__ . '/../includes/header.php';
        ?>

        <div class="container-fluid content-inner pb-0">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card p-4 shadow-sm border-0 mb-4 bg-white text-dark">
                        <div class="card-header border-0 bg-transparent text-center pb-0">
                            <div class="mb-3 text-success">
                                <i class="fa-solid fa-circle-check" style="font-size: 4rem;"></i>
                            </div>
                            <h2 class="text-uppercase fw-bold text-dark mb-1">Registration Success</h2>
                            <p class="text-muted">The new downline member has been registered and placed successfully!</p>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0 text-dark">
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">SPONSOR NAME</td>
                                            <td class="text-dark font-weight-500"><?php echo htmlspecialchars($sponsorName); ?> (<?php echo htmlspecialchars($sponsorMidDisplay); ?>)</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">ACTIVATED PACKAGE</td>
                                            <td class="text-dark font-weight-500">
                                                <span class="badge bg-success" style="font-size: 0.9rem;"><?php echo htmlspecialchars($packageName); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">FULL NAME</td>
                                            <td class="text-dark font-weight-500"><?php echo htmlspecialchars($fullName); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">USERNAME</td>
                                            <td class="text-dark font-weight-500"><strong><?php echo htmlspecialchars($username); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">MEMBER ID (MID)</td>
                                            <td class="text-dark font-weight-500"><strong style="color: #504793; font-size: 1.1rem;"><?php echo htmlspecialchars($newMid); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">PASSWORD</td>
                                            <td class="text-dark font-weight-500"><code style="font-size: 1.1rem; color: #d63384; font-weight: bold;"><?php echo htmlspecialchars($password); ?></code></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">MOBILE NUMBER</td>
                                            <td class="text-dark font-weight-500"><?php echo htmlspecialchars($phone); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold text-primary w-35 uppercase" style="font-size: 0.9rem;">EMAIL ID</td>
                                            <td class="text-dark font-weight-500"><?php echo htmlspecialchars($email); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="text-center mt-4 d-flex gap-3 justify-content-center">
                                <a href="binary_tree.php" class="btn btn-primary px-4 py-2 text-uppercase fw-bold text-white border-0" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border-radius: 5px;">
                                    <i class="fa-solid fa-network-wired me-2"></i>View Binary Tree
                                </a>
                                <a href="dashboard.php" class="btn btn-outline-secondary px-4 py-2 text-uppercase fw-bold">
                                    <i class="fa-solid fa-home me-2"></i>Go to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        include __DIR__ . '/../includes/footer.php';

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        die("Registration failed: " . $e->getMessage() . " <a href='javascript:history.back()'>Go back</a>");
    }
} else {
    header("Location: register_member.php");
}
