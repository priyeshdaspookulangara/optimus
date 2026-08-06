<?php
session_start();
require_once __DIR__ . '/../includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$db = Database::getInstance()->getConnection();

$sponsorQuery = $_GET['id'] ?? '';
$sponsorName = "Not Found";
$sponsorMid = "";
$sponsorDbId = "";

if (!empty($sponsorQuery)) {
    // Search by numeric id first, then by mid as fallback
    $stmt = $db->prepare("SELECT id, username, mid FROM users WHERE id = ? OR mid = ?");
    $stmt->execute([$sponsorQuery, $sponsorQuery]);
    $userRow = $stmt->fetch();
    if ($userRow) {
        $sponsorName = $userRow['username'];
        $sponsorMid = $userRow['mid'];
        $sponsorDbId = $userRow['id'];
    }
}

$placementIdQuery = $_GET['placement_id'] ?? '';
$positionQuery = $_GET['position'] ?? ''; // 'left' or 'right'

$placementName = "";
$placementMid = "";
$placementDbId = "";

if (!empty($placementIdQuery)) {
    $stmtPlacement = $db->prepare("SELECT id, username, mid FROM users WHERE id = ? OR mid = ?");
    $stmtPlacement->execute([$placementIdQuery, $placementIdQuery]);
    $pUser = $stmtPlacement->fetch();
    if ($pUser) {
        $placementName = $pUser['username'];
        $placementMid = $pUser['mid'];
        $placementDbId = $pUser['id'];
    }
}

$pageTitle = 'Register New Member';
$baseHref = '../'; // Set base href to parent folder so assets and side links resolve perfectly!
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid content-inner pb-0">
    <div class="row">
        <div class="col-lg-12">
            <div class="card p-2 mb-4">
                <div class="card-header border-0 bg-transparent">
                    <h4 class="card-title mb-0">Register New Member</h4>
                    <p class="text-muted small mb-0">Add a new downline member directly into your binary organization tree.</p>
                </div>

                <div class="card-body">
                    <form action="members/create_member.php" method="post" id="regFormInner">
                        <?php if (!empty($placementDbId)): ?>
                            <input type="hidden" name="placement_id" value="<?php echo htmlspecialchars($placementDbId); ?>">
                            <input type="hidden" name="position" value="<?php echo htmlspecialchars($positionQuery); ?>">
                        <?php endif; ?>

                        <div class="row">
                            <?php if (!empty($placementDbId)): ?>
                                <div class="form-group col-12 mb-4">
                                    <div class="alert alert-info bg-light text-dark border-info mb-0 d-flex align-items-center">
                                        <i class="fa fa-sitemap me-3 text-info fs-4"></i>
                                        <div>
                                            <strong>Binary Placement Position Reserved:</strong> You are placing this new joining under
                                            <strong><?php echo htmlspecialchars($placementName); ?> (<?php echo htmlspecialchars($placementMid); ?>)</strong>
                                            on the <strong><?php echo strtoupper($positionQuery); ?></strong> side.
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Sponsor Info -->
                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="mid">Sponsor Code (Sponsor ID): </label>
                                <input type="text" class="form-control text-dark border-secondary" id="mid" name="mid"
                                    value="<?php echo htmlspecialchars($sponsorMid); ?>" required placeholder="Sponsor Code">
                                <input type="hidden" id="referral_id" name="referral_id"
                                    value="<?php echo htmlspecialchars($sponsorDbId); ?>">
                            </div>
                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="sponsor_name">Sponsor Name: </label>
                                <input type="text" class="form-control" id="sponsor_name" value="<?php echo htmlspecialchars($sponsorName); ?>" readonly style="background-color: #f1f3f5 !important; color: #495057 !important; border: 1px solid #ced4da;">
                            </div>

                            <!-- Activation PIN -->
                            <div class="form-group col-12 mb-3">
                                <label class="form-label text-dark fw-bold" for="pin_code">Activation PIN (Mandatory): </label>
                                <input type="text" class="form-control text-dark border-secondary" id="pin_code" name="pin_code" required placeholder="OPTXXXXXX">
                                <div id="pin_feedback"></div>
                            </div>

                            <hr class="my-4">
                            <h5 class="mb-3 text-dark fw-bold">Personal Information</h5>

                            <!-- Personal Info -->
                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="name">Full Name: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="name" name="name" required placeholder="Full Name">
                            </div>

                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="username">Username: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="username" name="username" required placeholder="Username">
                            </div>

                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="phone">Phone Number: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="phone" name="phone" required placeholder="Phone">
                            </div>

                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="email">Email Address: </label>
                                <input type="email" class="form-control text-dark border-secondary" id="email" placeholder="Email" name="email" required>
                            </div>

                            <!-- Address Details -->
                            <div class="form-group col-12 mb-3">
                                <label class="form-label text-dark fw-bold" for="address">Address: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="address" name="address" required placeholder="Street address">
                            </div>

                            <div class="form-group col-md-4 mb-3">
                                <label class="form-label text-dark fw-bold" for="post_office_number">Post Office Number: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="post_office_number" name="post_office_number" required placeholder="ZIP / PO Box">
                            </div>

                            <div class="form-group col-md-4 mb-3">
                                <label class="form-label text-dark fw-bold" for="state">State / Region: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="state" name="state" required placeholder="State / Province">
                            </div>

                            <div class="form-group col-md-4 mb-3">
                                <label class="form-label text-dark fw-bold" for="country">Country: </label>
                                <input type="text" class="form-control text-dark border-secondary" id="country" name="country" required placeholder="Country">
                            </div>

                            <hr class="my-4">
                            <h5 class="mb-3 text-dark fw-bold">Security</h5>

                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="password">Password: </label>
                                <div class="input-group">
                                    <input type="password" class="form-control text-dark border-secondary" id="password" name="password" required placeholder="Password">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword" style="border: 1px solid #ced4da;">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group col-md-6 mb-3">
                                <label class="form-label text-dark fw-bold" for="password_confirmation">Confirm Password: </label>
                                <div class="input-group">
                                    <input type="password" class="form-control text-dark border-secondary" id="password_confirmation" required name="password_confirmation" placeholder="Repeat Password">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePasswordConfirm" style="border: 1px solid #ced4da;">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" class="text-white btn btn-primary px-5 py-3 text-uppercase fw-bold" style="background: linear-gradient(90deg, rgb(80 71 147) 17%, rgb(79 194 218) 98%); border: none; border-radius: 5px;">
                                <i class="fa-regular fa-circle-check me-2"></i>Register Now
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

    // Real-time PIN validation
    $('#pin_code').on('input', function() {
        var pin = $(this).val().trim();
        if(pin.length >= 6) {
            $.getJSON('check_pin.php', { pin: pin }, function(data) {
                if(data.status === 'success') {
                    $('#pin_feedback').html('<span class="badge bg-success text-white mt-1"><i class="fa fa-check-circle me-1"></i> Valid PIN: ' + data.package + ' ($' + data.amount + ')</span>');
                } else if(data.status === 'used') {
                    $('#pin_feedback').html('<span class="badge bg-warning text-white mt-1"><i class="fa fa-exclamation-triangle me-1"></i> Used PIN</span>');
                } else {
                    $('#pin_feedback').html('<span class="badge bg-danger text-white mt-1"><i class="fa fa-times-circle me-1"></i> Invalid PIN</span>');
                }
            });
        } else {
            $('#pin_feedback').empty();
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
