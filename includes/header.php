<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
$headerDb = Database::getInstance()->getConnection();
$headerUserId = $_SESSION['user_id'] ?? null;

if ($headerUserId) {
    if (!isset($user) || !isset($user['mid'])) {
        $stmtHeaderUser = $headerDb->prepare("SELECT * FROM users WHERE id = ?");
        $stmtHeaderUser->execute([$headerUserId]);
        $user = $stmtHeaderUser->fetch(PDO::FETCH_ASSOC);
    }
    if (!isset($rankName) && $user) {
        $headerConfig = require __DIR__ . '/config.php';
        $rankName = ($user['rank_id'] > 0) ? $headerConfig['ranks'][$user['rank_id']-1]['name'] : 'None';
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

    /* ---------- Overview boxes ---------- */
    .overview-row { display: flex; flex-wrap: wrap; justify-content: space-between; margin-bottom: 15px; gap: 10px; }
    .overview-box {
      background: #3f2259; color: #fff; padding: 20px; border-radius: 12px;
      text-align: center; flex: 1 1 200px; margin: 0; min-width: 0;
    }
    .overview-box h3 { font-size: clamp(0.75rem, 2.2vw, 1rem); margin: 0; word-break: break-word; }
    .overview-box p { font-size: clamp(1.1rem, 3vw, 1.5rem); margin: 0; }

    /* Always-visible top-right nav area (no toggler exists to un-collapse Bootstrap's .collapse) */
    .navbar-collapse-mobile-fix { display: block; width: auto; margin-left: auto; }
    .navbar-collapse-mobile-fix .navbar-nav { flex-direction: row; }
    .navbar-inner { flex-wrap: nowrap; }

    /* ---------- Member context menu (top right) ---------- */
    .member-menu-toggle {
      display: flex; align-items: center; gap: 8px; cursor: pointer;
      padding: 6px 10px; border-radius: 30px; transition: background .2s ease;
    }
    .member-menu-toggle:hover { background: rgba(63,34,89,0.06); }
    .member-menu-toggle .caption-title { font-size: 15px; }
    .member-dropdown-menu { min-width: 240px; }
    @media (max-width: 480px) {
      .member-menu-toggle { padding: 4px 6px; gap: 6px; }
      .member-menu-toggle .caption-title { font-size: 13px; white-space: nowrap; }
      .member-dropdown-menu { min-width: 210px; }
    }
    @media (max-width: 360px) {
      #copy_btn { font-size: 11px; padding: 5px 8px; }
      .member-menu-toggle .caption-title { font-size: 12px; }
    }

    /* ---------- Achievement / rank scroller ---------- */
    .achievement-section {
      background: linear-gradient(135deg, #1a0f26 0%, #2a1740 100%);
      border-radius: 14px;
      padding: 22px 20px;
      margin-top: 20px;
      position: relative;
    }
    .achievement-section h5 {
      color: #fff;
      font-weight: 700;
      margin-bottom: 16px;
      text-transform: uppercase;
      font-size: 1.75rem;
      letter-spacing: .5px;
    }
    .achievement-section h5 i { color: #4fc2da; margin-right: 8px; }
    .rank-scroller-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .rank-scroller {
      display: flex;
      gap: 16px;
      overflow-x: auto;
      scroll-behavior: smooth;
      scroll-snap-type: x mandatory;
      padding-bottom: 8px;
      flex: 1;
      scrollbar-width: none;
    }
    .rank-scroller::-webkit-scrollbar { display: none; }
    .rank-card {
      scroll-snap-align: start;
      flex: 0 0 320px;
      background: rgba(255,255,255,0.04);
      border-left: 4px solid #cca354;
      border-top: 2px solid #cca354;
      border-radius: 10px;
      padding: 26px 22px;
      min-height: 160px;
      position: relative;
      overflow: hidden;
      background-image: url('https://optimusinfinity.com/images/rank.png');
      background-repeat: no-repeat;
      background-position: right -10px center;
      background-size: 140px 140px;
      box-shadow: 0 6px 10px -4px rgba(0,0,0,0.5);
    }
    .rank-card.rk-achieved { background-color: rgba(76, 201, 161, 0.08); }
    .rank-card.rk-current { box-shadow: 0 0 0 2px #4fc2da inset, 0 6px 10px -4px rgba(0,0,0,0.5); }
    .rank-card .rk-content {
      position: relative;
      z-index: 1;
      max-width: 55%;
    }
    .rk-content .rk-name {
      color: #9aa0b4;
      font-size: 14px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .rk-content .rk-target {
      color: #fff;
      font-size: 26px;
      font-weight: 700;
      margin-bottom: 6px;
    }
    .rk-content .rk-desc {
      color: #7d8299;
      font-size: 13px;
    }
    .rk-badge-check { color: #37c9a1; font-size: 12px; }
    .rk-badge-current { color: #4fc2da; font-size: 10px; text-transform: uppercase; }
    .rk-mentor    { border-left-color: #5c9ded; border-top-color: #5c9ded; }
    .rk-pioneer   { border-left-color: #8a8fa3; border-top-color: #8a8fa3; }
    .rk-elite     { border-left-color: #f2c14e; border-top-color: #f2c14e; }
    .rk-titan     { border-left-color: #4fc2da; border-top-color: #4fc2da; }
    .rk-master    { border-left-color: #e8873e; border-top-color: #e8873e; }
    .rk-grandmaster { border-left-color: #a259e6; border-top-color: #a259e6; }
    .rk-icon      { border-left-color: #37c9a1; border-top-color: #37c9a1; }
    .rk-legend    { border-left-color: #e15a97; border-top-color: #e15a97; }
    .rk-director  { border-left-color: #5c7cfa; border-top-color: #5c7cfa; }
    .rk-ambassador { border-left-color: #f2994a; border-top-color: #f2994a; }
    .rank-scroll-btn {
      background: #fff;
      color: #3f2259;
      border: none;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
      z-index: 2;
    }
    .rank-scroll-btn.left { margin-right: 12px; }
    .rank-scroll-btn.right { margin-left: 12px; }
    .rank-scroll-btn:hover { background: #cca354; color: #fff; }

    /* ---------- Ceiling limit button ---------- */
    .ceiling-limit-btn h3 { font-size: clamp(1rem, 4vw, 1.75rem) !important; }

    /* ================= RESPONSIVE BREAKPOINTS ================= */
    @media (max-width: 991px) {
      .overview-box { flex: 1 1 45%; }
      .achievement-section h5 { font-size: 1.35rem; }
      .rank-card { flex: 0 0 270px; padding: 20px 18px; min-height: 140px; }
      .rk-content .rk-target { font-size: 20px; }
    }

    @media (max-width: 767px) {
      .content-inner { padding: 10px !important; }
      .overview-row { gap: 8px; margin-bottom: 10px; }
      .overview-box { flex: 1 1 47%; padding: 14px 10px; border-radius: 10px; }
      .achievement-section { padding: 16px 12px; }
      .achievement-section h5 { font-size: 1.1rem; margin-bottom: 10px; }
      .rank-card { flex: 0 0 220px; padding: 16px 14px; min-height: 120px; background-size: 90px 90px; }
      .rank-card .rk-content { max-width: 65%; }
      .rk-content .rk-target { font-size: 17px; }
      .rk-content .rk-name { font-size: 11px; }
      .rk-content .rk-desc { font-size: 11px; }
      .rank-scroll-btn { width: 28px; height: 28px; }
      .rank-scroll-btn.left { margin-right: 6px; }
      .rank-scroll-btn.right { margin-left: 6px; }
    }

    @media (max-width: 480px) {
      .overview-box { flex: 1 1 100%; }
      .overview-box h3 { font-size: 0.85rem; }
      .overview-box p { font-size: 1.3rem; }
      .navbar-inner { padding-left: 8px; padding-right: 8px; }
      #copy_btn { font-size: 12px; padding: 6px 10px; margin-right: 6px !important; }
    }
  </style>
</head>
<body class=" ">
  <!-- Hidden loader element to prevent coinex.js page-load execution crashes -->
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
          <div class="navbar-collapse-mobile-fix" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto navbar-list mb-2 mb-lg-0 align-items-center">
              <li class="nav-item">
                <button type="button" id="copy_btn" class="text-white btn btn-sm me-2 btn-primary">
                  Referral Link
                </button>
              </li>
              <li class="nav-item dropdown">
                <a class="member-menu-toggle dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer; text-decoration:none;">
                  <i class="fa-solid fa-circle-user fa-xl text-primary"></i>
                  <div class="caption text-start ms-2">
                    <h6 class="mb-0 caption-title text-dark"><?php echo htmlspecialchars($user['username'] ?? 'User'); ?></h6>
                  </div>
                  <i class="fa fa-chevron-down ms-1 text-muted" style="font-size: 0.7rem;"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end member-dropdown-menu shadow border-0" aria-labelledby="navbarDropdown" style="background-color: #ffffff;">
                  <li class="px-3 py-2 text-dark">
                    <div class="fw-bold"><?php echo htmlspecialchars($user['full_name'] ?? 'Optimus Member'); ?></div>
                    <small class="text-muted d-block"><?php echo htmlspecialchars($user['email'] ?? ''); ?></small>
                    <span class="badge bg-primary text-white mt-1">Rank: <?php echo htmlspecialchars($rankName ?? 'None'); ?></span>
                  </li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item py-2" href="dashboard.php"><i class="fa fa-tachometer-alt me-2 text-primary"></i>Dashboard</a></li>
                  <li><a class="dropdown-item py-2" href="e_wallet.php"><i class="fa fa-wallet me-2 text-primary"></i>E-wallet</a></li>
                  <li><a class="dropdown-item py-2" href="withdraw_wallet.php"><i class="fa fa-credit-card me-2 text-primary"></i>Withdraw Wallet</a></li>
                  <li><a class="dropdown-item py-2" href="my_pins.php"><i class="fa fa-key me-2 text-primary"></i>My PINs</a></li>
                  <li><a class="dropdown-item py-2" href="my_team.php"><i class="fa fa-users me-2 text-primary"></i>My Team</a></li>
                  <li><a class="dropdown-item py-2" href="kyc_details.php"><i class="fa fa-id-card me-2 text-primary"></i>KYC Details</a></li>
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
