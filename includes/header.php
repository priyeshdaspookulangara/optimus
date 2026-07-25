<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$headerUser = null;
$headerRankName = 'None';
if (isset($_SESSION['user_id'])) {
    $headerUserId = $_SESSION['user_id'];
    $headerDb = Database::getInstance()->getConnection();
    $headerStmt = $headerDb->prepare("SELECT * FROM users WHERE id = ?");
    $headerStmt->execute([$headerUserId]);
    $headerUser = $headerStmt->fetch();
    if ($headerUser) {
        $user = $headerUser; // Ensure $user has all fields
        $headerConfig = require __DIR__ . '/config.php';
        $headerRankName = ($headerUser['rank_id'] > 0) ? $headerConfig['ranks'][$headerUser['rank_id']-1]['name'] : 'None';
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?php echo $pageTitle ?? 'App'; ?></title>
  <link rel="shortcut icon" href="https://optimusinfinity.com/assets/fav.png">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/core/libs.min.css">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/coinex.min.css?v=4.1.0">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/custom.min.css?v=4.1.0">
  <link rel="stylesheet" href="https://optimusinfinity.com/assets/css/customized.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700;1,800&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
  <style>
    body { background: #fff; }
    .dropdown-item { color: #3f2259 !important; font-weight: 500; }
    .dropdown-item:hover { color: #fff !important; background-color: #3f2259 !important; }
    .card { border: 1px solid #dedede !important; border-radius: 10px !important; }
    .card-title { text-transform: uppercase !important; font-size: 18px !important; }
    .text-primary { color: #cca354 !important; }
    .overview-box { background: #3f2259; color: #fff; padding: 20px; border-radius: 12px; text-align: center; flex: 1; margin: 5px; }
    .overview-row { display: flex; justify-content: space-between; margin-bottom: 15px; }
  </style>
</head>
<body class=" ">
  <div class="loader d-none"></div>
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="main-content">
    <div class="position-relative">
      <nav class="nav navbar navbar-expand-lg navbar-light iq-navbar" style="background:#ffffff;">
        <div class="container-fluid navbar-inner">
          <div class="sidebar-toggle" data-toggle="sidebar" data-active="true">
            <i class="icon">
              <svg width="20px" height="20px" viewBox="0 0 24 24">
                <path fill="currentColor" d="M4,11V13H16L10.5,18.5L11.92,19.92L19.84,12L11.92,4.08L10.5,5.5L16,11H4Z"></path>
              </svg>
            </i>
          </div>
          <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto navbar-list mb-2 mb-lg-0 align-items-center">
              <li class="nav-item">
                <button type="button" id="copy_btn" class="text-white btn btn-sm me-2 btn-primary">
                  Referral Link
                </button>
              </li>
              <li class="nav-item dropdown">
                <a class="nav-link py-0 d-flex align-items-center dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                  <i class="fa-solid fa-circle-user fa-xl text-primary me-2"></i>
                  <div class="caption">
                    <h6 class="mb-0 caption-title text-dark"><?php echo htmlspecialchars($user['username'] ?? 'User'); ?> <i class="fa fa-chevron-down ms-1 text-muted" style="font-size: 0.75rem;"></i></h6>
                  </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="navbarDropdown" style="background-color: #ffffff; min-width: 220px;">
                  <li class="px-3 py-2 text-dark">
                    <div class="fw-bold"><?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Member'); ?></div>
                    <small class="text-muted d-block"><?php echo htmlspecialchars($user['email'] ?? ''); ?></small>
                    <span class="badge bg-primary text-white mt-1">Rank: <?php echo htmlspecialchars($headerRankName); ?></span>
                  </li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item py-2" href="dashboard.php"><i class="fa fa-tachometer-alt me-2 text-primary"></i>Dashboard</a></li>
                  <li><a class="dropdown-item py-2" href="withdraw_wallet.php"><i class="fa fa-wallet me-2 text-primary"></i>Withdraw Wallet</a></li>
                  <li><a class="dropdown-item py-2" href="my_pins.php"><i class="fa fa-key me-2 text-primary"></i>My PINs</a></li>
                  <li><a class="dropdown-item py-2" href="my_team.php"><i class="fa fa-users me-2 text-primary"></i>My Team</a></li>
                  <li><a class="dropdown-item py-2" href="support.php"><i class="fa fa-headset me-2 text-primary"></i>Support</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa fa-sign-out-alt me-2 text-danger"></i>Logout</a></li>
                </ul>
              </li>
            </ul>
          </div>
        </div>
      </nav>
    </div>
