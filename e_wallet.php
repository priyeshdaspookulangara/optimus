<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch User Data for header
$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Fetch Wallet Balance
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as wallet_balance FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

$pageTitle = 'E-Wallet';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid content-inner pb-0 bg-dark">
    <div class="row">
      <div class="col-lg-12">
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success">Package activated successfully.</div>
        <?php endif; ?>
        <div class="card bg-dark text-white">
          <div class="card-header">
            <h4 class="card-title mb-0 text-white">Deposit / Activate Package</h4>
          </div>
          <div class="card-body">
                      <div class="row">
                          <div class="col-md-6">
                              <div class="card p-3 mb-3" style="background: #01526f;">
                                  <div class="d-flex justify-content-between align-items-center">
                                      <h5 class="mb-0 text-white">E-Wallet Balance</h5>
                                  </div>
                                  <h4 class="text-primary mt-2">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h4>
                              </div>
                          </div>

                          <div class="col-md-6">
                              <div class="card p-4" style="background: #01526f;">
                                  <h5 class="text-center mb-3 text-white">Activate with PIN</h5>
                                  <form action="activate_pin.php" method="post">
                                      <div class="mb-3">
                                          <label class="form-label text-white">Enter PIN Code</label>
                                          <input type="text" name="pin_code" class="form-control" required placeholder="XXXXXXXXXX">
                                      </div>
                                      <button type="submit" class="btn btn-success text-white w-100">
                                          <i class="fa fa-key me-2"></i>Activate PIN
                                      </button>
                                  </form>
                              </div>
                          </div>
                      </div>
          </div>
        </div>
      </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
