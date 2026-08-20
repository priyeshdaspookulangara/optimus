<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// AJAX Endpoint for Upline Chain lookup
if (isset($_GET['action']) && $_GET['action'] === 'get_uplines') {
    header('Content-Type: application/json');
    $fromUserId = isset($_GET['from_id']) ? (int)$_GET['from_id'] : 0;

    if (!$fromUserId) {
        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
        exit();
    }

    // Fetch downline member details
    $stmt = $db->prepare("SELECT id, mid, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$fromUserId]);
    $downline = $stmt->fetch();

    if (!$downline) {
        echo json_encode(['success' => false, 'error' => 'Member not found']);
        exit();
    }

    // Fetch upline chain starting from downline up to the logged-in user
    $stmt = $db->prepare("
        SELECT g.level, u.id, u.mid, u.username, u.full_name
        FROM genealogy g
        JOIN users u ON g.parent_id = u.id
        WHERE g.user_id = ?
        ORDER BY g.level ASC
    ");
    $stmt->execute([$fromUserId]);
    $allParents = $stmt->fetchAll();

    $uplines = [];
    $uplines[] = [
        'level' => 0,
        'mid' => $downline['mid'] ?? $downline['id'],
        'username' => $downline['username'],
        'full_name' => $downline['full_name'],
        'is_logged_user' => ($downline['id'] == $userId)
    ];

    foreach ($allParents as $p) {
        $uplines[] = [
            'level' => (int)$p['level'],
            'mid' => $p['mid'] ?? $p['id'],
            'username' => $p['username'],
            'full_name' => $p['full_name'],
            'is_logged_user' => ($p['id'] == $userId)
        ];

        if ($p['id'] == $userId) {
            break;
        }
    }

    echo json_encode(['success' => true, 'downline' => $downline, 'uplines' => $uplines]);
    exit();
}

// Fetch Level Income Transactions
$stmt = $db->prepare("
    SELECT t.*, u.username as from_username, u.mid as from_mid,
           COALESCE(i.amount, (
               SELECT inv.amount FROM investments inv
               WHERE inv.user_id = t.related_user_id AND inv.created_at <= t.created_at
               ORDER BY inv.created_at DESC LIMIT 1
           ), (
               SELECT inv.amount FROM investments inv
               WHERE inv.user_id = t.related_user_id
               ORDER BY inv.created_at ASC LIMIT 1
           ), u.total_investment, 0) as investment_amount
    FROM transactions t
    LEFT JOIN users u ON t.related_user_id = u.id
    LEFT JOIN investments i ON t.investment_id = i.id
    WHERE t.user_id = ? AND t.type = 'LEVEL_INCOME'
    ORDER BY t.created_at DESC
");
$stmt->execute([$userId]);
$level_transactions = $stmt->fetchAll();

$pageTitle = 'Level Income';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">Level Income</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive mt-3">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Investment Amount</th>
                    <th>Credit</th>
                    <th>From</th>
                    <th>Level</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($level_transactions as $index => $t): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo !empty($t['roi_date']) ? date('d M, Y', strtotime($t['roi_date'])) : date('d M, Y', strtotime($t['created_at'])); ?></td>
                      <td>$<?php echo number_format((float)($t['investment_amount'] ?? 0), 2); ?></td>
                      <td class="text-success">$<?php echo number_format($t['amount'], 2); ?></td>
                      <td>
                        <a href="#" class="view-uplines-link text-primary fw-bold text-decoration-none" data-from-id="<?php echo $t['related_user_id']; ?>" data-mid="<?php echo htmlspecialchars($t['from_mid'] ?? $t['related_user_id']); ?>" data-username="<?php echo htmlspecialchars($t['from_username'] ?? 'Unknown'); ?>">
                          <?php echo htmlspecialchars($t['from_mid'] ?? $t['related_user_id']); ?>
                        </a><br>
                        <?php echo htmlspecialchars($t['from_username'] ?? 'Unknown'); ?>
                      </td>
                      <td><?php echo $t['level']; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
</div>

<!-- Uplines Modal -->
<div class="modal fade" id="uplinesModal" tabindex="-1" aria-labelledby="uplinesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title text-white" id="uplinesModalLabel"><i class="fa fa-sitemap me-2"></i>Upline Hierarchy Path</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div id="uplinesLoading" class="text-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-2 text-muted mb-0">Tracing upline hierarchy chain...</p>
        </div>
        <div id="uplinesError" class="alert alert-danger d-none mb-0"></div>
        <div id="uplinesContent" class="d-none">
          <p class="text-muted mb-3">Showing genealogy upline path from member <strong id="sourceMemberName"></strong> up to your account:</p>
          <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Level Distance</th>
                  <th>Member MID</th>
                  <th>Username</th>
                  <th>Full Name</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="uplinesTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
      var table = $('#example').DataTable();

      $('#example').on('click', '.view-uplines-link', function(e) {
        e.preventDefault();
        var fromId = $(this).data('from-id');
        var mid = $(this).data('mid');
        var username = $(this).data('username');

        $('#sourceMemberName').text(mid + ' (' + username + ')');
        $('#uplinesLoading').removeClass('d-none');
        $('#uplinesError').addClass('d-none');
        $('#uplinesContent').addClass('d-none');
        $('#uplinesTableBody').empty();

        var uplinesModal = new bootstrap.Modal(document.getElementById('uplinesModal'));
        uplinesModal.show();

        $.ajax({
          url: 'level_income.php',
          type: 'GET',
          data: { action: 'get_uplines', from_id: fromId },
          dataType: 'json',
          success: function(response) {
            $('#uplinesLoading').addClass('d-none');
            if (response.success) {
              var html = '';
              response.uplines.forEach(function(u) {
                var levelLabel = (u.level === 0) ? '<span class="badge bg-secondary">Source Member</span>' : '<span class="badge bg-info">Level ' + u.level + ' Upline</span>';
                var userStatus = u.is_logged_user ? '<span class="badge bg-success"><i class="fa fa-user me-1"></i> You (Logged In)</span>' : '<span class="badge bg-light text-dark">Upline Member</span>';
                var rowClass = u.is_logged_user ? 'table-success fw-bold' : '';

                html += '<tr class="' + rowClass + '">';
                html += '<td>' + levelLabel + '</td>';
                html += '<td>' + (u.mid || '-') + '</td>';
                html += '<td>' + (u.username || '-') + '</td>';
                html += '<td>' + (u.full_name || '-') + '</td>';
                html += '<td>' + userStatus + '</td>';
                html += '</tr>';
              });
              $('#uplinesTableBody').html(html);
              $('#uplinesContent').removeClass('d-none');
            } else {
              $('#uplinesError').text(response.error || 'Failed to fetch upline hierarchy.').removeClass('d-none');
            }
          },
          error: function() {
            $('#uplinesLoading').addClass('d-none');
            $('#uplinesError').text('An error occurred while communicating with the server.').removeClass('d-none');
          }
        });
      });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
