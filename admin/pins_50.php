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

// Retroactively sync pin_id and pin_type for existing data if possible
try {
    // 1. Ensure pin_type column exists in pins and investments
    try { $db->exec("ALTER TABLE `pins` ADD COLUMN `pin_type` ENUM('paid', 'free') DEFAULT 'paid'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE `investments` ADD COLUMN `pin_id` INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE `investments` ADD COLUMN `pin_type` ENUM('paid', 'free') DEFAULT 'paid'"); } catch (Exception $e) {}

    // 2. Link investments to their corresponding PIN activations where missing
    $db->exec("UPDATE investments i
               INNER JOIN pins p ON i.user_id = p.used_by AND i.package_id = p.package_id
               SET i.pin_id = p.id, i.pin_type = p.pin_type
               WHERE i.pin_id IS NULL");
} catch (Exception $e) {
    // Ignore migration errors if database driver/environment differs slightly
}

// Fetch the $50 Package ID
$pkgStmt = $db->prepare("SELECT id FROM packages WHERE amount = 50.00 LIMIT 1");
$pkgStmt->execute();
$package50 = $pkgStmt->fetch();
$package50Id = $package50 ? $package50['id'] : 2; // Default to ID 2 from seed data if not found

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
        header("Location: pins_50.php?error=" . urlencode("CSRF token validation failed."));
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'generate_50') {
        $count = (int)($_POST['count'] ?? 1);
        $pinType = $_POST['pin_type'] ?? 'paid';
        $assignUsername = trim($_POST['assign_username'] ?? '');

        if (!in_array($pinType, ['paid', 'free'])) {
            $pinType = 'paid';
        }

        $assignedToId = null;
        if (!empty($assignUsername)) {
            $userStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $userStmt->execute([$assignUsername]);
            $targetUser = $userStmt->fetch();
            if ($targetUser) {
                $assignedToId = $targetUser['id'];
            } else {
                header("Location: pins_50.php?error=" . urlencode("Username '{$assignUsername}' not found. PINs were not generated."));
                exit();
            }
        }

        $stmt = $db->prepare("INSERT INTO pins (pin_code, package_id, assigned_to, pin_type) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < $count; $i++) {
            $uniqueDigits = mt_rand(100000, 999999);
            $pin = "OPT" . $uniqueDigits;

            // Simple uniqueness retry
            $chk = $db->prepare("SELECT id FROM pins WHERE pin_code = ?");
            $chk->execute([$pin]);
            if ($chk->fetch()) {
                $pin = "OPT" . mt_rand(100000, 999999);
            }

            $stmt->execute([$pin, $package50Id, $assignedToId, $pinType]);
        }
        header("Location: pins_50.php?success=" . urlencode("Successfully generated {$count} x \$50 {$pinType} PINs."));
        exit();
    }

    if ($action === 'toggle_pin_type') {
        $pinId = (int)($_POST['pin_id'] ?? 0);
        $newType = $_POST['pin_type'] ?? '';

        if (in_array($newType, ['paid', 'free'])) {
            $db->beginTransaction();
            try {
                // Update pin
                $stmt = $db->prepare("UPDATE pins SET pin_type = ? WHERE id = ?");
                $stmt->execute([$newType, $pinId]);

                // Update associated investment if used
                $stmtInst = $db->prepare("UPDATE investments SET pin_type = ? WHERE pin_id = ?");
                $stmtInst->execute([$newType, $pinId]);

                $db->commit();
                header("Location: pins_50.php?success=" . urlencode("PIN type updated successfully."));
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                header("Location: pins_50.php?error=" . urlencode("Failed to update PIN type: " . $e->getMessage()));
                exit();
            }
        }
    }

    if ($action === 'toggle_investment_type') {
        $investmentId = (int)($_POST['investment_id'] ?? 0);
        $newType = $_POST['pin_type'] ?? '';

        if (in_array($newType, ['paid', 'free'])) {
            $db->beginTransaction();
            try {
                // Update investment
                $stmt = $db->prepare("UPDATE investments SET pin_type = ? WHERE id = ?");
                $stmt->execute([$newType, $investmentId]);

                // Also update its linked PIN if it exists
                $stmtGetPin = $db->prepare("SELECT pin_id FROM investments WHERE id = ?");
                $stmtGetPin->execute([$investmentId]);
                $inv = $stmtGetPin->fetch();
                if ($inv && $inv['pin_id']) {
                    $stmtPin = $db->prepare("UPDATE pins SET pin_type = ? WHERE id = ?");
                    $stmtPin->execute([$newType, $inv['pin_id']]);
                }

                $db->commit();
                header("Location: pins_50.php?success=" . urlencode("Member investment payment classification updated successfully."));
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                header("Location: pins_50.php?error=" . urlencode("Failed to update classification: " . $e->getMessage()));
                exit();
            }
        }
    }
}

// Filter variables
$pinStatusFilter = $_GET['pin_status'] ?? '';
$pinTypeFilter = $_GET['pin_type'] ?? '';
$memberTypeFilter = $_GET['member_type'] ?? '';

// --- STATS CALCULATION ---
// Total $50 Members (Investments of $50)
$statsTotal50Count = $db->query("SELECT COUNT(*) FROM investments WHERE amount = 50.00")->fetchColumn();
$statsPaid50Count = $db->query("SELECT COUNT(*) FROM investments WHERE amount = 50.00 AND pin_type = 'paid'")->fetchColumn();
$statsFree50Count = $db->query("SELECT COUNT(*) FROM investments WHERE amount = 50.00 AND pin_type = 'free'")->fetchColumn();

// $50 PINs Inventory
$statsTotalPins = $db->prepare("SELECT COUNT(*) FROM pins WHERE package_id = ?");
$statsTotalPins->execute([$package50Id]);
$totalPinsCount = $statsTotalPins->fetchColumn();

$statsUnusedFreePins = $db->prepare("SELECT COUNT(*) FROM pins WHERE package_id = ? AND status = 'unused' AND pin_type = 'free'");
$statsUnusedFreePins->execute([$package50Id]);
$unusedFreePinsCount = $statsUnusedFreePins->fetchColumn();

$statsUnusedPaidPins = $db->prepare("SELECT COUNT(*) FROM pins WHERE package_id = ? AND status = 'unused' AND pin_type = 'paid'");
$statsUnusedPaidPins->execute([$package50Id]);
$unusedPaidPinsCount = $statsUnusedPaidPins->fetchColumn();


// --- FETCH $50 PINS ---
$pinQuery = "SELECT p.*, u_used.username as used_by_user, u_used.mid as used_by_mid, u_ass.username as assigned_to_user
             FROM pins p
             LEFT JOIN users u_used ON p.used_by = u_used.id
             LEFT JOIN users u_ass ON p.assigned_to = u_ass.id
             WHERE p.package_id = ?";
$pinParams = [$package50Id];

if ($pinStatusFilter === 'used') {
    $pinQuery .= " AND p.status = 'used'";
} elseif ($pinStatusFilter === 'unused') {
    $pinQuery .= " AND p.status = 'unused'";
}

if ($pinTypeFilter === 'paid') {
    $pinQuery .= " AND p.pin_type = 'paid'";
} elseif ($pinTypeFilter === 'free') {
    $pinQuery .= " AND p.pin_type = 'free'";
}

$pinQuery .= " ORDER BY p.created_at DESC";
$pinStmt = $db->prepare($pinQuery);
$pinStmt->execute($pinParams);
$pins = $pinStmt->fetchAll();


// --- FETCH $50 PACKAGE MEMBERS ---
$memberQuery = "SELECT i.id as investment_id, i.amount as investment_amount, i.created_at as investment_date, i.status as investment_status, i.pin_type as investment_pin_type,
                       u.id as user_id, u.username, u.mid, u.full_name, u.email,
                       p.pin_code as used_pin_code, p.pin_type as linked_pin_type
                FROM investments i
                JOIN users u ON i.user_id = u.id
                LEFT JOIN pins p ON i.pin_id = p.id
                WHERE i.amount = 50.00";
$memberParams = [];

if ($memberTypeFilter === 'paid') {
    $memberQuery .= " AND i.pin_type = 'paid'";
} elseif ($memberTypeFilter === 'free') {
    $memberQuery .= " AND i.pin_type = 'free'";
}

$memberQuery .= " ORDER BY i.created_at DESC";
$memberStmt = $db->prepare($memberQuery);
$memberStmt->execute($memberParams);
$members = $memberStmt->fetchAll();


$pageTitle = '$50 PINs & Members Separation';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">$50 PINs & Members Separation</h2>
            <p class="text-muted mb-0">Separate and track free vs paid $50 packages, manage $50 PIN inventories, and audit $50 package holders.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generate50PinModal">
            <i class="fa fa-magic me-2"></i> Generate $50 PINs
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Overview Statistics Row -->
    <div class="row mb-4">
        <!-- Card 1 -->
        <div class="col-md-3">
            <div class="card card-stat bg-white shadow-sm h-100 border-0">
                <div class="card-body d-flex align-items-center">
                    <div class="p-3 rounded bg-light-primary text-primary me-3" style="background-color: rgba(13, 110, 253, 0.1);">
                        <i class="fa fa-users fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-1">Total $50 Members</h6>
                        <h3 class="mb-0 fw-bold"><?php echo $statsTotal50Count; ?></h3>
                        <small class="text-muted">Active/Historical</small>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card 2 -->
        <div class="col-md-3">
            <div class="card card-stat bg-white shadow-sm h-100 border-0">
                <div class="card-body d-flex align-items-center">
                    <div class="p-3 rounded bg-light-success text-success me-3" style="background-color: rgba(25, 135, 84, 0.1);">
                        <i class="fa fa-hand-holding-usd fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-1">Paid $50 Members</h6>
                        <h3 class="mb-0 fw-bold text-success"><?php echo $statsPaid50Count; ?></h3>
                        <small class="text-muted">E-wallet & Paid PINs</small>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card 3 -->
        <div class="col-md-3">
            <div class="card card-stat bg-white shadow-sm h-100 border-0">
                <div class="card-body d-flex align-items-center">
                    <div class="p-3 rounded bg-light-warning text-warning me-3" style="background-color: rgba(255, 193, 7, 0.1);">
                        <i class="fa fa-gift fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-1">Free $50 Members</h6>
                        <h3 class="mb-0 fw-bold text-warning"><?php echo $statsFree50Count; ?></h3>
                        <small class="text-muted">Free Awarded PINs</small>
                    </div>
                </div>
            </div>
        </div>
        <!-- Card 4 -->
        <div class="col-md-3">
            <div class="card card-stat bg-white shadow-sm h-100 border-0">
                <div class="card-body d-flex align-items-center">
                    <div class="p-3 rounded bg-light-info text-info me-3" style="background-color: rgba(13, 202, 240, 0.1);">
                        <i class="fa fa-key fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-1">Unused $50 PINs</h6>
                        <h4 class="mb-1 fw-bold"><?php echo ($unusedFreePinsCount + $unusedPaidPinsCount); ?> <span class="fs-6 text-muted">left</span></h4>
                        <small class="text-muted">Paid: <?php echo $unusedPaidPinsCount; ?> | Free: <?php echo $unusedFreePinsCount; ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation Tabs -->
    <ul class="nav nav-pills mb-4" id="separationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active px-4 py-2 me-2" id="members-tab" data-bs-toggle="tab" data-bs-target="#membersContent" type="button" role="tab" aria-selected="true">
                <i class="fa fa-users me-2"></i> $50 Package Members
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2" id="pins-tab" data-bs-toggle="tab" data-bs-target="#pinsContent" type="button" role="tab" aria-selected="false">
                <i class="fa fa-key me-2"></i> $50 PIN Management
            </button>
        </li>
    </ul>

    <div class="tab-content" id="separationTabsContent">
        <!-- TAB 1: $50 PACKAGE MEMBERS -->
        <div class="tab-pane fade show active" id="membersContent" role="tabpanel" aria-labelledby="members-tab">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center border-bottom-0">
                    <h5 class="mb-0 fw-bold">Members list with $50 Package</h5>
                    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                        <span class="text-muted small">Filter by Payment Status:</span>
                        <a href="pins_50.php?member_type=" class="btn btn-sm <?php echo $memberTypeFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All Members</a>
                        <a href="pins_50.php?member_type=paid" class="btn btn-sm <?php echo $memberTypeFilter === 'paid' ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="fa fa-check-circle me-1"></i> Paid Only</a>
                        <a href="pins_50.php?member_type=free" class="btn btn-sm <?php echo $memberTypeFilter === 'free' ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="fa fa-gift me-1"></i> Free Only</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Member Info</th>
                                    <th>MID</th>
                                    <th>Investment Date</th>
                                    <th>Activation Method</th>
                                    <th>Status</th>
                                    <th>Payment Classification</th>
                                    <th class="text-end pe-4">Actions / Toggle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($members)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No members found holding a $50 package under the selected filter.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($members as $m): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold"><?php echo htmlspecialchars($m['username']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($m['full_name'] ?? '-'); ?></small>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($m['mid'] ?? 'None'); ?></code></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($m['investment_date'])); ?></td>
                                            <td>
                                                <?php if (!empty($m['used_pin_code'])): ?>
                                                    <span class="badge bg-light text-dark border"><i class="fa fa-key me-1 text-muted"></i> PIN: <?php echo htmlspecialchars($m['used_pin_code']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark border"><i class="fa fa-wallet me-1 text-muted"></i> Direct E-Wallet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php
                                                    if ($m['investment_status'] == 'active') echo 'bg-success';
                                                    elseif ($m['investment_status'] == 'completed') echo 'bg-secondary';
                                                    else echo 'bg-warning';
                                                ?>">
                                                    <?php echo strtoupper($m['investment_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($m['investment_pin_type'] === 'free'): ?>
                                                    <span class="badge bg-warning text-dark"><i class="fa fa-gift me-1"></i> FREE PACKAGE</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i> PAID PACKAGE</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="investment_id" value="<?php echo $m['investment_id']; ?>">
                                                    <input type="hidden" name="action" value="toggle_investment_type">

                                                    <?php if ($m['investment_pin_type'] === 'free'): ?>
                                                        <input type="hidden" name="pin_type" value="paid">
                                                        <button type="submit" class="btn btn-xs btn-outline-success py-1 px-2" style="font-size: 0.75rem;">
                                                            <i class="fa fa-check-circle me-1"></i> Mark as Paid
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="pin_type" value="free">
                                                        <button type="submit" class="btn btn-xs btn-outline-warning py-1 px-2" style="font-size: 0.75rem;">
                                                            <i class="fa fa-gift me-1"></i> Mark as Free
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: $50 PIN MANAGEMENT -->
        <div class="tab-pane fade" id="pinsContent" role="tabpanel" aria-labelledby="pins-tab">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center border-bottom-0">
                    <h5 class="mb-0 fw-bold">$50 Packages PIN Inventory</h5>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 mt-md-0">
                        <div class="btn-group btn-group-sm">
                            <a href="pins_50.php?pin_status=&pin_type=<?php echo $pinTypeFilter; ?>&tab=pins" class="btn <?php echo $pinStatusFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All Status</a>
                            <a href="pins_50.php?pin_status=unused&pin_type=<?php echo $pinTypeFilter; ?>&tab=pins" class="btn <?php echo $pinStatusFilter === 'unused' ? 'btn-primary' : 'btn-outline-primary'; ?>">Unused Only</a>
                            <a href="pins_50.php?pin_status=used&pin_type=<?php echo $pinTypeFilter; ?>&tab=pins" class="btn <?php echo $pinStatusFilter === 'used' ? 'btn-primary' : 'btn-outline-primary'; ?>">Used Only</a>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <a href="pins_50.php?pin_type=&pin_status=<?php echo $pinStatusFilter; ?>&tab=pins" class="btn <?php echo $pinTypeFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All Types</a>
                            <a href="pins_50.php?pin_type=paid&pin_status=<?php echo $pinStatusFilter; ?>&tab=pins" class="btn <?php echo $pinTypeFilter === 'paid' ? 'btn-primary' : 'btn-outline-primary'; ?>">Paid Only</a>
                            <a href="pins_50.php?pin_type=free&pin_status=<?php echo $pinStatusFilter; ?>&tab=pins" class="btn <?php echo $pinTypeFilter === 'free' ? 'btn-primary' : 'btn-outline-primary'; ?>">Free Only</a>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">PIN Code</th>
                                    <th>Status</th>
                                    <th>PIN Type</th>
                                    <th>Assigned To</th>
                                    <th>Used By</th>
                                    <th>Created At</th>
                                    <th class="text-end pe-4">Actions / Toggle Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pins)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No $50 PINs found matching the filter criteria.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pins as $p): ?>
                                        <tr>
                                            <td class="ps-4"><code><?php echo htmlspecialchars($p['pin_code']); ?></code></td>
                                            <td>
                                                <span class="badge <?php echo $p['status'] === 'unused' ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo strtoupper($p['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $p['pin_type'] === 'free' ? 'bg-warning text-dark' : 'bg-primary'; ?>">
                                                    <?php echo strtoupper($p['pin_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($p['assigned_to_user']): ?>
                                                    <span class="text-dark fw-bold"><?php echo htmlspecialchars($p['assigned_to_user']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted small">Public</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($p['used_by_user']): ?>
                                                    <span class="text-dark fw-bold"><?php echo htmlspecialchars($p['used_by_user']); ?></span> <code>(<?php echo htmlspecialchars($p['used_by_mid'] ?? ''); ?>)</code>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></td>
                                            <td class="text-end pe-4">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
                                                    <input type="hidden" name="pin_id" value="<?php echo $p['id']; ?>">
                                                    <input type="hidden" name="action" value="toggle_pin_type">

                                                    <?php if ($p['pin_type'] === 'free'): ?>
                                                        <input type="hidden" name="pin_type" value="paid">
                                                        <button type="submit" class="btn btn-xs btn-outline-primary py-1 px-2" style="font-size: 0.75rem;">
                                                            <i class="fa fa-hand-holding-usd me-1"></i> Make Paid
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="pin_type" value="free">
                                                        <button type="submit" class="btn btn-xs btn-outline-warning py-1 px-2" style="font-size: 0.75rem;">
                                                            <i class="fa fa-gift me-1"></i> Make Free (Awarded)
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Generate $50 PIN Modal -->
<div class="modal fade" id="generate50PinModal" tabindex="-1" aria-labelledby="generate50PinModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf']; ?>">
            <input type="hidden" name="action" value="generate_50">
            <div class="modal-header">
                <h5 class="modal-title" id="generate50PinModalLabel">Generate $50 Activation PINs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Package Type</label>
                    <input type="text" class="form-control" value="Package $50" readonly disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">PIN Allocation Type</label>
                    <select name="pin_type" class="form-select" required>
                        <option value="paid" selected>Paid PIN (Standard Purchased)</option>
                        <option value="free">Free PIN (Awarded to Team Leaders)</option>
                    </select>
                    <small class="text-muted d-block mt-1">Free PINs are marked for separation. They activate the user's $50 package without requiring investment funds but keep the package separated for reporting.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Quantity to Generate</label>
                    <input type="number" name="count" class="form-control" value="1" min="1" max="100" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Assign to Member (Optional - Username)</label>
                    <input type="text" name="assign_username" class="form-control" placeholder="e.g. teamleader101">
                    <small class="text-muted">Specify the username to restrict visibility of this PIN specifically to their "My PINs" tab.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-magic me-1"></i> Generate PINs</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Keep active tab on query params reload
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    if (activeTab === 'pins') {
        var triggerEl = document.querySelector('#pins-tab');
        if (triggerEl) {
            var tabObj = new bootstrap.Tab(triggerEl);
            tabObj.show();
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
