<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address'])) {
    $address = trim($_POST['address']);
    if (!empty($address)) {
        $stmt = $db->prepare("SELECT id FROM user_wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE user_wallets SET address = ? WHERE user_id = ?");
            $stmt->execute([$address, $userId]);
        } else {
            $stmt = $db->prepare("INSERT INTO user_wallets (user_id, address) VALUES (?, ?)");
            $stmt->execute([$userId, $address]);
        }
        $success = "Wallet address updated successfully.";
    }
}

// Fetch Current Wallet
$stmt = $db->prepare("SELECT * FROM user_wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$userWallet = $stmt->fetch();

// Fetch Withdrawal Transactions
$stmt = $db->prepare("
    SELECT * FROM transactions
    WHERE user_id = ? AND type = 'WITHDRAWAL'
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$withdrawals = $stmt->fetchAll();

$pageTitle = 'Withdraw Wallet';
include __DIR__ . '/includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css" />

<style>
    td { color: #000 !important; }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3>USDT Wallet Configuration</h3>
            <a href="withdraw_fund.php" class="btn btn-warning text-white fw-bold"><i class="fa fa-money-bill-wave me-2"></i>Withdraw Funds Now</a>
        </div>
        <div class="card p-4 bg-dark text-white mb-4">
            <h4>Update USDT (TRC20) Wallet</h4>
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <div class="mb-3">
                    <label class="form-label">Wallet Address</label>
                    <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($userWallet['address'] ?? ''); ?>" required placeholder="Enter TRC20 Address">
                </div>
                <button type="submit" class="btn btn-primary">Save Address</button>
            </form>
        </div>
      </div>

      <div class="col-lg-12 DT-col">
        <div class="card p-2">
          <div class="card-header">
            <h4 class="card-title mb-0">Withdrawal History</h4>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="example" class="table table-striped" style="width:100%">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Fee</th>
                    <th>Net Received</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($withdrawals as $index => $w): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td><?php echo date('d M, Y h:i:s a', strtotime($w['created_at'])); ?></td>
                      <td>$<?php echo number_format($w['amount'], 2); ?></td>
                      <td>$<?php echo number_format($w['fee'], 2); ?></td>
                      <td class="text-danger">$<?php echo number_format($w['amount'] - $w['fee'], 2); ?></td>
                      <td><?php echo htmlspecialchars($w['description']); ?></td>
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

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
      $('#example').DataTable();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
