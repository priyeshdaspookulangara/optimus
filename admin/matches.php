<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Verify admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$pageTitle = 'Recent Match & Rank Achievements';
include __DIR__ . '/includes/header.php';

// Fetch the 10 most recent matches (matching schedules) and associated details
$query = "
    SELECT ms.*,
           u.id as achiever_id, u.username as achiever_username, u.mid as achiever_mid, u.full_name as achiever_fullname, u.email as achiever_email,
           sp.username as sponsor_username, sp.mid as sponsor_mid,
           pl.username as placement_username, pl.mid as placement_mid,
           r.name as rank_name
    FROM matching_schedules ms
    JOIN users u ON ms.user_id = u.id
    LEFT JOIN users sp ON u.sponsor_id = sp.id
    LEFT JOIN users pl ON u.placement_id = pl.id
    LEFT JOIN ranks r ON ms.slab_amount = r.matching_business
    ORDER BY ms.created_at DESC, ms.id DESC
    LIMIT 10
";

try {
    $stmt = $db->query($query);
    $recentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentMatches = [];
    $errorMsg = "Error loading matches: " . $e->getMessage();
}

// Prepared statements for children lookup
$stmtLeft = $db->prepare("SELECT username, mid FROM users WHERE placement_id = ? AND position = 'left' LIMIT 1");
$stmtRight = $db->prepare("SELECT username, mid FROM users WHERE placement_id = ? AND position = 'right' LIMIT 1");
?>

<div class="container-fluid pb-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-1 text-dark"><i class="fa fa-trophy text-warning me-2"></i>Recent Matches & Rank Accomplishments</h3>
                <p class="text-muted mb-0">Monitor the 10 most recent binary slab matches, associated ranks, and all team members involved.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Back to Dashboard</a>
        </div>
    </div>

    <?php if (isset($errorMsg)): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 text-primary fw-bold"><i class="fa fa-list me-2"></i>Latest 10 Matching Slab Contracts</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Match ID</th>
                                    <th>Achiever & Rank</th>
                                    <th>Slab Match Tier</th>
                                    <th>Daily Income Details</th>
                                    <th>Key Sponsors / Parents</th>
                                    <th>Binary Placement Children</th>
                                    <th>Match Date & Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentMatches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="fa fa-folder-open fa-3x mb-3 text-secondary"></i>
                                                <p class="mb-0 fw-bold">No matching slab contracts found in the system yet.</p>
                                                <small class="text-muted">Matches are generated automatically when members accumulate team volumes across legs.</small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentMatches as $index => $match):
                                        // Retrieve direct children for this achiever
                                        $stmtLeft->execute([$match['achiever_id']]);
                                        $leftChild = $stmtLeft->fetch(PDO::FETCH_ASSOC);

                                        $stmtRight->execute([$match['achiever_id']]);
                                        $rightChild = $stmtRight->fetch(PDO::FETCH_ASSOC);

                                        $rankName = $match['rank_name'] ?: 'None (Slab Achieved)';
                                    ?>
                                        <tr>
                                            <td class="ps-4">
                                                <span class="text-secondary fw-semibold">#<?php echo htmlspecialchars($match['id']); ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div>
                                                        <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($match['achiever_username']); ?></span>
                                                        <small class="text-muted d-block"><?php echo htmlspecialchars($match['achiever_fullname'] ?: 'No Full Name'); ?> (<?php echo htmlspecialchars($match['achiever_mid'] ?: 'No MID'); ?>)</small>
                                                        <span class="badge bg-warning text-dark mt-1" style="font-size: 0.75rem;"><i class="fa fa-star me-1"></i><?php echo htmlspecialchars($rankName); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span class="fw-bold text-primary fs-5">$<?php echo number_format($match['slab_amount'], 2); ?></span>
                                                    <small class="text-muted">Required Volume Match</small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span class="fw-semibold text-success">$<?php echo number_format($match['daily_income'], 2); ?>/day</span>
                                                    <small class="text-muted">Progress: <?php echo htmlspecialchars($match['days_passed']); ?> / <?php echo htmlspecialchars($match['max_days']); ?> days</small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="lh-sm">
                                                    <div class="mb-1">
                                                        <small class="text-muted d-block">Direct Sponsor:</small>
                                                        <?php if ($match['sponsor_username']): ?>
                                                            <strong class="text-dark"><?php echo htmlspecialchars($match['sponsor_username']); ?></strong> <span class="text-secondary small">(<?php echo htmlspecialchars($match['sponsor_mid']); ?>)</span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">None (Root Node)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <small class="text-muted d-block">Placement Parent:</small>
                                                        <?php if ($match['placement_username']): ?>
                                                            <strong class="text-dark"><?php echo htmlspecialchars($match['placement_username']); ?></strong> <span class="text-secondary small">(<?php echo htmlspecialchars($match['placement_mid']); ?>)</span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">None (Root Node)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="lh-sm">
                                                    <div class="mb-1">
                                                        <small class="text-muted d-block">Left Child Node:</small>
                                                        <?php if ($leftChild): ?>
                                                            <strong class="text-dark"><?php echo htmlspecialchars($leftChild['username']); ?></strong> <span class="text-secondary small">(<?php echo htmlspecialchars($leftChild['mid']); ?>)</span>
                                                        <?php else: ?>
                                                            <span class="text-danger small"><i class="fa fa-times me-1"></i>Empty</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <small class="text-muted d-block">Right Child Node:</small>
                                                        <?php if ($rightChild): ?>
                                                            <strong class="text-dark"><?php echo htmlspecialchars($rightChild['username']); ?></strong> <span class="text-secondary small">(<?php echo htmlspecialchars($rightChild['mid']); ?>)</span>
                                                        <?php else: ?>
                                                            <span class="text-danger small"><i class="fa fa-times me-1"></i>Empty</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column align-items-start">
                                                    <span class="small text-muted mb-1"><i class="fa fa-calendar-alt me-1"></i><?php echo date('Y-m-d H:i', strtotime($match['created_at'])); ?></span>
                                                    <?php if ($match['status'] === 'active'): ?>
                                                        <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Active Contract</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><i class="fa fa-history me-1"></i>Completed</span>
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
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
