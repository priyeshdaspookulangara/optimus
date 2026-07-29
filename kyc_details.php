<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch User Data for Header
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Fetch Current KYC Details
$stmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id = ?");
$stmt->execute([$userId]);
$kyc = $stmt->fetch();

$success = "";
$error = "";

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If approved or pending, editing is locked
    if ($kyc && ($kyc['status'] === 'approved' || $kyc['status'] === 'pending')) {
        $error = "KYC is currently locked and cannot be updated.";
    } else {
        $pan_number = strtoupper(trim($_POST['pan_number'] ?? ''));
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $ifsc_code = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $account_holder_name = trim($_POST['account_holder_name'] ?? '');
        $branch_name = trim($_POST['branch_name'] ?? '');

        if (empty($pan_number) || empty($bank_name) || empty($account_number) || empty($ifsc_code) || empty($account_holder_name)) {
            $error = "Please fill in all required fields.";
        } else {
            if ($kyc) {
                // Update
                $stmt = $db->prepare("UPDATE user_kyc SET
                    pan_number = ?,
                    bank_name = ?,
                    account_number = ?,
                    ifsc_code = ?,
                    account_holder_name = ?,
                    branch_name = ?,
                    status = 'pending',
                    remarks = NULL
                    WHERE user_id = ?");
                $stmt->execute([$pan_number, $bank_name, $account_number, $ifsc_code, $account_holder_name, $branch_name, $userId]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO user_kyc
                    (user_id, pan_number, bank_name, account_number, ifsc_code, account_holder_name, branch_name, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$userId, $pan_number, $bank_name, $account_number, $ifsc_code, $account_holder_name, $branch_name]);
            }
            $success = "KYC details submitted successfully. It is now pending review.";

            // Re-fetch KYC details to update the page state
            $stmt = $db->prepare("SELECT * FROM user_kyc WHERE user_id = ?");
            $stmt->execute([$userId]);
            $kyc = $stmt->fetch();
        }
    }
}

$pageTitle = 'KYC Details';
$config = require __DIR__ . '/includes/config.php';
$rankName = ($user['rank_id'] > 0) ? $config['ranks'][$user['rank_id']-1]['name'] : 'None';
include __DIR__ . '/includes/header.php';
?>

<div class="loader d-none"></div>

<div class="container-fluid content-inner pb-5 p-3">
    <div class="row">
        <div class="col-lg-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>KYC & Bank Details Configuration</h3>
            </div>

            <!-- KYC Status Summary Widget -->
            <div class="card p-4 bg-dark text-white mb-4" style="border: 1px solid #cca354 !important;">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <h4 class="mb-1 text-white"><i class="fa-solid fa-address-card me-2 text-primary"></i>KYC Verification Status</h4>
                        <p class="text-muted mb-0">Please provide accurate PAN and bank account details for verification.</p>
                    </div>
                    <div>
                        <?php if (!$kyc): ?>
                            <span class="badge bg-secondary fs-6 py-2 px-3 text-uppercase">Not Submitted</span>
                        <?php else: ?>
                            <?php if ($kyc['status'] === 'approved'): ?>
                                <span class="badge bg-success fs-6 py-2 px-3 text-uppercase"><i class="fa-solid fa-circle-check me-1"></i>Approved</span>
                            <?php elseif ($kyc['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark fs-6 py-2 px-3 text-uppercase"><i class="fa-solid fa-clock me-1"></i>Pending Review</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 py-2 px-3 text-uppercase"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($kyc && !empty($kyc['remarks'])): ?>
                    <div class="mt-3 p-3 rounded <?php echo $kyc['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?> bg-opacity-10 border <?php echo $kyc['status'] === 'approved' ? 'border-success' : 'border-danger'; ?>">
                        <strong>Admin Feedback:</strong>
                        <p class="mb-0 mt-1"><?php echo htmlspecialchars($kyc['remarks']); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- KYC Form -->
            <div class="card p-4 bg-dark text-white" style="border: 1px solid #cca354 !important;">
                <h4 class="mb-4 text-white">KYC Information Form</h4>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php
                // Form controls behavior: lock if approved or pending
                $isLocked = $kyc && ($kyc['status'] === 'approved' || $kyc['status'] === 'pending');
                $attr = $isLocked ? 'readonly' : '';
                ?>

                <form method="post" class="needs-validation">
                    <div class="row">
                        <!-- PAN Details Section -->
                        <div class="col-md-12 mb-4">
                            <h5 class="text-primary border-bottom border-secondary pb-2 mb-3"><i class="fa-solid fa-id-badge me-2"></i>Identity Verification</h5>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-white">PAN Number <span class="text-danger">*</span></label>
                                <input type="text" name="pan_number" class="form-control text-uppercase bg-dark text-white border-secondary"
                                       value="<?php echo htmlspecialchars($kyc['pan_number'] ?? ''); ?>"
                                       placeholder="ABCDE1234F" required maxlength="20" <?php echo $attr; ?>>
                                <div class="form-text text-muted">Enter your 10-character alphanumeric Permanent Account Number.</div>
                            </div>
                        </div>

                        <!-- Bank Details Section -->
                        <div class="col-md-12 mb-3">
                            <h5 class="text-primary border-bottom border-secondary pb-2 mb-3"><i class="fa-solid fa-building-columns me-2"></i>Bank Account Information</h5>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-white">Account Holder Name <span class="text-danger">*</span></label>
                            <input type="text" name="account_holder_name" class="form-control bg-dark text-white border-secondary"
                                   value="<?php echo htmlspecialchars($kyc['account_holder_name'] ?? ''); ?>"
                                   placeholder="John Doe" required <?php echo $attr; ?>>
                            <div class="form-text text-muted">Must match the name on your bank passbook/statement.</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-white">Bank Name <span class="text-danger">*</span></label>
                            <input type="text" name="bank_name" class="form-control bg-dark text-white border-secondary"
                                   value="<?php echo htmlspecialchars($kyc['bank_name'] ?? ''); ?>"
                                   placeholder="e.g. State Bank of India" required <?php echo $attr; ?>>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-white">Account Number <span class="text-danger">*</span></label>
                            <input type="text" name="account_number" class="form-control bg-dark text-white border-secondary"
                                   value="<?php echo htmlspecialchars($kyc['account_number'] ?? ''); ?>"
                                   placeholder="e.g. 1234567890" required <?php echo $attr; ?>>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-white">IFSC / Swift Code <span class="text-danger">*</span></label>
                            <input type="text" name="ifsc_code" class="form-control text-uppercase bg-dark text-white border-secondary"
                                   value="<?php echo htmlspecialchars($kyc['ifsc_code'] ?? ''); ?>"
                                   placeholder="SBIN0001234" required <?php echo $attr; ?>>
                        </div>

                        <div class="col-md-12 mb-4">
                            <label class="form-label fw-bold text-white">Branch Name & Address</label>
                            <input type="text" name="branch_name" class="form-control bg-dark text-white border-secondary"
                                   value="<?php echo htmlspecialchars($kyc['branch_name'] ?? ''); ?>"
                                   placeholder="e.g. Downtown Branch, Mumbai" <?php echo $attr; ?>>
                        </div>
                    </div>

                    <?php if (!$isLocked): ?>
                        <div class="d-grid mt-2">
                            <button type="submit" class="btn btn-warning text-white fw-bold py-2 fs-5">
                                <i class="fa-solid fa-paper-plane me-2"></i>Submit KYC Details
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info d-flex align-items-center mt-2 bg-dark text-white border-info" role="alert">
                            <i class="fa-solid fa-lock me-3 fs-4 text-info"></i>
                            <div>
                                <?php if ($kyc['status'] === 'approved'): ?>
                                    Your KYC has been approved. If you need to make corrections, please contact support.
                                <?php else: ?>
                                    Your KYC is currently under verification. Editing is disabled until verification is complete.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
