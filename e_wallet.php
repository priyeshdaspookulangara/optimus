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
        <div class="card bg-dark text-white">
          <div class="card-header">
            <h4 class="card-title mb-0 text-white">Deposit</h4>
          </div>
          <div class="card-body">
                      <div class="row">
                          <div class="col-md-6">
                              <div class="card p-3 mb-3" style="background: #01526f;">
                                  <div class="d-flex justify-content-between align-items-center">
                                      <h5 class="mb-0 text-white">USDT Wallet Balance</h5>
                                  </div>
                                  <h4 class="text-primary mt-2">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h4>
                              </div>
                          </div>

                          <div class="col-md-6">
                              <div class="card p-4" style="background: #01526f;">
                                  <h5 class="text-center mb-3 text-white">Transfer USDT</h5>
                                  <form action="usdt_to_ewallet.php" method="post">
                                      <div class="mb-3">
                                          <label class="form-label text-white">Enter USDT Amount</label>
                                          <input type="number" step="any" name="amount" class="form-control" placeholder="0.0" required>
                                      </div>
                                      <button type="submit" class="btn btn-primary w-100">DEPOSIT</button>
                                  </form>
                              </div>
                          </div>
                      </div>

                      <div class="row mt-4">
                          <div class="col-md-6">
                              <div class="card p-4 text-center bg-dark border-secondary">
                                  <h5 class="text-white">Network: TRC20</h5>
                                  <p class="text-white">Deposit Address</p>
                                  <input type="text" class="form-control text-center" value="TMWeDnoyYugjLbawBbTqab47NMox4zEnWL" readonly>
                                  <div class="mt-3">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;data=TMWeDnoyYugjLbawBbTqab47NMox4zEnWL" alt="QR Code" width="100px;">
                                  </div>
                              </div>
                          </div>
                          <div class="col-md-6 text-center">
                            <div class="card p-3" style="background: #01526f;">
                              <div class="d-flex justify-content-between align-items-center">
                                  <h5 class="mb-0 text-white">E-Wallet Balance</h5>
                              </div>
                              <h4 class="text-primary mt-2">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h4>
                            </div>
                          </div>
                      </div>
          </div>
        </div>
      </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
