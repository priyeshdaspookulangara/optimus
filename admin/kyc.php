<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Authentication check before running state-changing operations
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();

$success = "";
$error = "";

// Handle Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        $error = "CSRF token validation failed.";
    } else {
        $kycId = $_POST['kyc_id'] ?? '';
        $action = $_POST['action'];

        if (empty($kycId)) {
            $error = "Invalid KYC ID.";
        } else {
            if ($action === 'approve') {
                $stmt = $db->prepare("UPDATE user_kyc SET status = 'approved', remarks = 'Verified & Approved by Admin' WHERE id = ?");
                $stmt->execute([$kycId]);
                $success = "KYC approved successfully.";
            } elseif ($action === 'reject') {
                $remarks = trim($_POST['remarks'] ?? '');
                if (empty($remarks)) {
                    $error = "Please specify rejection remarks.";
                } else {
                    $stmt = $db->prepare("UPDATE user_kyc SET status = 'rejected', remarks = ? WHERE id = ?");
                    $stmt->execute([$remarks, $kycId]);
                    $success = "KYC rejected with remarks.";
                }
            }
        }
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';

// Build dynamic query
$query = "SELECT k.*, u.username, u.mid, u.full_name, u.email
          FROM user_kyc k
          JOIN users u ON k.user_id = u.id";
$params = [];

if (!empty($statusFilter)) {
    $query .= " WHERE k.status = ?";
    $params[] = $statusFilter;
}

$query .= " ORDER BY k.updated_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$kycRecords = $stmt->fetchAll();

$pageTitle = 'KYC Management';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>KYC Verification Management</h3>
    </div>

    <!-- Status Filters Form -->
    <form method="get" class="row g-2 align-items-center bg-light p-3 rounded border">
        <div class="col-md-4">
            <select name="status" class="form-select">
                <option value="">-- All Statuses --</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="kyc.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
    </form>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Member Details</th>
                        <th>PAN Number</th>
                        <th>Bank Information</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kycRecords)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No KYC records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($kycRecords as $k): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($k['username']); ?></strong>
                                <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($k['mid'] ?? ''); ?></span>
                                <div class="small text-muted"><?php echo htmlspecialchars($k['full_name'] ?? ''); ?></div>
                                <div class="small text-muted" style="font-size: 11px;"><?php echo htmlspecialchars($k['email']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-dark fs-6 text-warning text-uppercase px-2 py-1"><?php echo htmlspecialchars($k['pan_number']); ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($k['account_holder_name']); ?></div>
                                <div class="small text-muted"><strong>Bank:</strong> <?php echo htmlspecialchars($k['bank_name']); ?></div>
                                <div class="small text-muted"><strong>A/C:</strong> <?php echo htmlspecialchars($k['account_number']); ?></div>
                                <div class="small text-muted"><strong>IFSC/Swift:</strong> <span class="text-uppercase"><?php echo htmlspecialchars($k['ifsc_code']); ?></span></div>
                                <?php if (!empty($k['branch_name'])): ?>
                                    <div class="small text-muted"><strong>Branch:</strong> <?php echo htmlspecialchars($k['branch_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($k['status'] === 'approved'): ?>
                                    <span class="badge bg-success text-uppercase px-2 py-1"><i class="fa fa-check-circle me-1"></i>Approved</span>
                                <?php elseif ($k['status'] === 'pending'): ?>
                                    <span class="badge bg-warning text-dark text-uppercase px-2 py-1"><i class="fa fa-clock me-1"></i>Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-uppercase px-2 py-1"><i class="fa fa-times-circle me-1"></i>Rejected</span>
                                <?php endif; ?>

                                <?php if (!empty($k['remarks'])): ?>
                                    <div class="small text-muted mt-1" style="max-width: 200px; font-style: italic;">
                                        <strong>Notes:</strong> <?php echo htmlspecialchars($k['remarks']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($k['updated_at'])); ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <?php if ($k['status'] !== 'approved'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to approve this KYC?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                            <input type="hidden" name="kyc_id" value="<?php echo $k['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i>Approve</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($k['status'] !== 'rejected'): ?>
                                        <button class="btn btn-sm btn-danger" onclick='openRejectModal(<?php echo $k['id']; ?>, <?php echo json_encode($k['username']); ?>)'>
                                            <i class="fa fa-times me-1"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="kyc_id" id="reject_kyc_id">

            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectModalLabel">Reject KYC Submission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>You are rejecting the KYC submission for member <strong id="reject_username"></strong>. Please specify the reason below.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Rejection Remarks / Reason <span class="text-danger">*</span></label>
                    <textarea name="remarks" id="reject_remarks" class="form-control" rows="3" required placeholder="e.g. Invalid PAN card details or mismatching holder name."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(kycId, username) {
    document.getElementById('reject_kyc_id').value = kycId;
    document.getElementById('reject_username').innerText = username;
    document.getElementById('reject_remarks').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
