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
    .dropdown-item:hover { color: #fff; background-color: #000; }
    .card { border: 1px solid #dedede !important; border-radius: 10px !important; }
    .card-title { text-transform: uppercase !important; font-size: 18px !important; }
    .text-primary { color: #cca354 !important; }
    .overview-box { background: #3f2259; color: #fff; padding: 20px; border-radius: 12px; text-align: center; flex: 1; margin: 5px; }
    .overview-row { display: flex; justify-content: space-between; margin-bottom: 15px; }
  </style>
</head>
<body class=" ">
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
                <a class="nav-link py-0 d-flex align-items-center" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                  <div class="caption ms-3">
                    <h6 class="mb-0 caption-title"><?php echo htmlspecialchars($user['username'] ?? 'User'); ?></h6>
                  </div>
                </a>
              </li>
            </ul>
          </div>
        </div>
      </nav>
    </div>
