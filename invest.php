<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Fetch Wallet Balance
$stmt = $db->prepare("SELECT COALESCE(SUM(net_amount), 0) as wallet_balance FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

$config = require __DIR__ . '/includes/config.php';
$pageTitle = 'Invest';
include __DIR__ . '/includes/header.php';
?>

<style>
    .card-custom {
      border-radius: 15px;
      box-shadow: 0 2px 8px #000000;
      background: linear-gradient(135deg, #4b6cb7, #182848);
    }
    .icon-box {
      width: 50px;
      height: 50px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 10px; background-color: #000000;
    }
</style>

<div class="container-fluid content-inner pb-0">
    <div class="row">
      <div class="col-lg-12">
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php
                    if($_GET['error'] == 'insufficient_balance') echo "Insufficient balance in E-Wallet.";
                    else echo htmlspecialchars($_GET['error']);
                ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success">Investment successful.</div>
        <?php endif; ?>
        <div class="card">
          <div class="card-header"><h4 class="card-title mb-0">Invest</h4></div>
          <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <div class="card card-custom p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                      <h5 class="mb-0 text-white">E-Wallet Balance</h5>
                      <div class="icon-box">
                        <i class="fa fa-wallet text-warning"></i>
                      </div>
                    </div>
                    <h4 class="text-primary mt-2">$<?php echo number_format($wallet['wallet_balance'], 2); ?></h4>
                    <div class="mt-2">
                        <a href="e_wallet.php" class="btn btn-sm btn-outline-warning text-white">Add Funds</a>
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="card card-custom p-4">
                    <h5 class="text-center mb-3 text-white">Purchase Package</h5>
                    <form action="create_invest.php" method="post" class="deposit-form">
                      <div class="mb-3">
                        <label for="amount" class="form-label text-white">Select Package</label>
                        <select class="form-select" id="amount" name="amount" required>
                            <?php foreach($config['packages'] as $pkg): ?>
                                <option value="<?php echo $pkg; ?>">$<?php echo number_format($pkg); ?></option>
                            <?php endforeach; ?>
                        </select>
                      </div>
                      <button type="submit" class="btn btn-primary text-white w-100">
                        <i class="fa fa-check-circle me-2"></i>Invest Now
                      </button>
                    </form>

                    <hr class="bg-white my-4">

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
