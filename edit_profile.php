<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

$successMsg = '';
$errorMsg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $postOfficeNumber = trim($_POST['post_office_number'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirmation'] ?? '';

    if (empty($username) || empty($email)) {
        $errorMsg = 'Username and Email are required.';
    } else {
        // Validate Username uniqueness (excluding current user)
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmtCheck->execute([$username, $userId]);
        if ($stmtCheck->fetch()) {
            $errorMsg = 'Username is already taken by another user.';
        } else {
            // Check if password is provided
            $passwordUpdate = false;
            if (!empty($password)) {
                if ($password !== $passwordConfirm) {
                    $errorMsg = 'Passwords do not match.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $passwordUpdate = true;
                }
            }

            if (empty($errorMsg)) {
                try {
                    if ($passwordUpdate) {
                        $stmtUpdate = $db->prepare("UPDATE users SET username = ?, full_name = ?, phone = ?, email = ?, address = ?, post_office_number = ?, state = ?, country = ?, password = ? WHERE id = ?");
                        $stmtUpdate->execute([$username, $fullName, $phone, $email, $address, $postOfficeNumber, $state, $country, $hashedPassword, $userId]);
                    } else {
                        $stmtUpdate = $db->prepare("UPDATE users SET username = ?, full_name = ?, phone = ?, email = ?, address = ?, post_office_number = ?, state = ?, country = ? WHERE id = ?");
                        $stmtUpdate->execute([$username, $fullName, $phone, $email, $address, $postOfficeNumber, $state, $country, $userId]);
                    }
                    $successMsg = 'Profile updated successfully!';
                } catch (Exception $e) {
                    $errorMsg = 'Failed to update profile: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch current user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$pageTitle = 'Edit Profile';
include __DIR__ . '/includes/header.php';
?>

<style>
    /* Styling to match consistent dark text layout and padded card layout */
    .edit-profile-card {
        border-radius: 12px;
        background-color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        border: 1px solid #dedede !important;
    }
    .edit-profile-card label {
        color: #000 !important;
        font-weight: 600;
        margin-bottom: 5px;
    }
    .edit-profile-card input, .edit-profile-card textarea {
        color: #ffffff !important;
        background-color: #3f2259 !important;
        border: 1px solid #504793;
    }
    .edit-profile-card input:focus, .edit-profile-card textarea:focus {
        color: #ffffff !important;
        background-color: #3f2259 !important;
        border-color: #fabe20;
        box-shadow: 0 0 5px rgba(80, 71, 147, 0.5);
    }
</style>

<div class="container-fluid content-inner pb-5 p-3">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-8">
            <div class="card edit-profile-card p-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pb-2">
                    <h4 class="card-title text-dark fw-bold mb-0">
                        <i class="fa-solid fa-user-pen me-2 text-primary"></i>Edit Profile
                    </h4>
                    <span class="badge bg-primary text-white">MID: <?php echo htmlspecialchars($user['mid'] ?? 'N/A'); ?></span>
                </div>
                <hr class="mt-1 mb-4">
                <div class="card-body p-0">
                    <?php if (!empty($successMsg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($successMsg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($errorMsg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form action="edit_profile.php" method="POST" id="editProfileForm">
                        <div class="row">
                            <!-- Non-editable Read-Only Fields -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="member_id">Database ID</label>
                                <input type="text" class="form-control" id="member_id" value="<?php echo htmlspecialchars($user['id']); ?>" disabled style="background-color: #e9ecef !important; color: #495057 !important;">
                                <small class="text-muted">Unique Database Identifier (Not editable)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="member_mid">Member ID (MID)</label>
                                <input type="text" class="form-control" id="member_mid" value="<?php echo htmlspecialchars($user['mid']); ?>" disabled style="background-color: #e9ecef !important; color: #495057 !important;">
                                <small class="text-muted">Public Alphanumeric Referral ID (Not editable)</small>
                            </div>

                            <hr class="my-3">

                            <!-- Editable Fields -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="full_name">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label" for="address">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address']); ?></textarea>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="post_office_number">Post Office Number</label>
                                <input type="text" class="form-control" id="post_office_number" name="post_office_number" value="<?php echo htmlspecialchars($user['post_office_number']); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="state">State / Province</label>
                                <input type="text" class="form-control" id="state" name="state" value="<?php echo htmlspecialchars($user['state']); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="country">Country</label>
                                <input type="text" class="form-control" id="country" name="country" value="<?php echo htmlspecialchars($user['country']); ?>">
                            </div>

                            <hr class="my-3">
                            <h5 class="text-dark fw-bold mb-3">Security Updates</h5>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="password">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Leave blank to keep current">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword" style="border: 1px solid #ced4da; background-color: #f8f9fa; color: #333;">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="password_confirmation">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" placeholder="Leave blank to keep current">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePasswordConfirm" style="border: 1px solid #ced4da; background-color: #f8f9fa; color: #333;">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="text-end mt-4">
                            <a href="dashboard.php" class="btn btn-outline-secondary me-2 px-4" style="border-radius: 6px;">Cancel</a>
                            <button type="submit" class="btn btn-primary px-5 text-white fw-bold" style="background-color: #3f2259; border-color: #3f2259; border-radius: 6px;">
                                <i class="fa-regular fa-floppy-disk me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {
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

<?php include __DIR__ . '/includes/footer.php'; ?>
