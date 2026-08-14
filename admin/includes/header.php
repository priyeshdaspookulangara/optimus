<?php
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}
$db = Database::getInstance()->getConnection();
$currentPage = basename($_SERVER['PHP_SELF']);

// Operations group is active if current page is members.php or packages.php
$isOperationsActive = in_array($currentPage, ['members.php', 'packages.php']);

// Financials group is active if current page is pins.php, withdrawals.php, reports.php, or reset_commissions.php
$isFinancialsActive = in_array($currentPage, ['pins.php', 'withdrawals.php', 'reports.php', 'reset_commissions.php']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Admin Panel'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }

        /* New Restructured Sidebar Styles */
        .sidebar {
            height: 100vh;
            width: 260px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #1a1c23; /* Darker, sleek modern background */
            padding-top: 20px;
            color: #fff;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.15);
            z-index: 1030;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            padding: 10px 25px 25px 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 20px;
        }

        .sidebar-brand h4 {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #fff;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-brand h4 i {
            color: #3b82f6;
        }

        .sidebar-brand small {
            color: #94a3b8;
            font-weight: 500;
            font-size: 13px;
        }

        .sidebar .nav {
            display: flex;
            flex-direction: column;
            padding: 0 15px;
            gap: 5px;
        }

        .sidebar .nav-item {
            width: 100%;
            list-style: none;
        }

        .sidebar .nav-link {
            padding: 12px 20px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            color: #94a3b8; /* Cool gray */
            display: flex;
            align-items: center;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link i.icon {
            font-size: 18px;
            width: 25px;
            margin-right: 12px;
            color: #64748b;
            transition: color 0.2s ease;
        }

        .sidebar .nav-link:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.05);
        }

        .sidebar .nav-link:hover i.icon {
            color: #fff;
        }

        .sidebar .nav-link.active {
            color: #fff !important;
            background-color: #3b82f6 !important;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .sidebar .nav-link.active i.icon {
            color: #fff !important;
        }

        /* Group toggles styling */
        .sidebar .nav-link[data-bs-toggle="collapse"] {
            cursor: pointer;
            position: relative;
        }

        .sidebar .nav-link[data-bs-toggle="collapse"] .right-icon {
            font-size: 12px;
            transition: transform 0.2s ease;
            color: #64748b;
        }

        .sidebar .nav-link[data-bs-toggle="collapse"][aria-expanded="true"] {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.03);
        }

        .sidebar .nav-link[data-bs-toggle="collapse"][aria-expanded="true"] .right-icon {
            transform: rotate(90deg);
            color: #fff;
        }

        /* Sub nav menu styling */
        .sidebar .sub-nav {
            list-style: none;
            padding: 5px 0 5px 15px;
            margin: 5px 0;
            background-color: rgba(0, 0, 0, 0.15);
            border-radius: 8px;
            border-left: 2px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar .sub-nav .nav-link {
            padding: 8px 15px;
            font-size: 14px;
        }

        .sidebar .sub-nav .nav-link i.icon {
            font-size: 15px;
            width: 20px;
        }

        .sidebar hr {
            border-color: rgba(255, 255, 255, 0.1);
            margin: 15px 0;
        }

        /* Adjust main content layout for the new sidebar width */
        .main-content { margin-left: 260px; padding: 25px; transition: all 0.3s ease; }

        .card-stat { border-radius: 12px; border: none; transition: transform 0.3s, box-shadow 0.3s; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); }
        .card-stat:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand text-center">
            <h4><i class="fa fa-shield-alt"></i> Admin Panel</h4>
            <small><?php echo htmlspecialchars($_SESSION['admin_username']); ?></small>
        </div>

        <ul class="nav">
            <!-- Dashboard Link (Standalone) -->
            <li class="nav-item">
                <a href="dashboard.php" id="link-dashboard" class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="icon fa fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Operations Group (Collapsible) -->
            <li class="nav-item">
                <a class="nav-link <?php echo $isOperationsActive ? '' : 'collapsed'; ?>"
                   data-bs-toggle="collapse"
                   href="#sidebar-operations"
                   role="button"
                   aria-expanded="<?php echo $isOperationsActive ? 'true' : 'false'; ?>"
                   aria-controls="sidebar-operations">
                    <i class="icon fa fa-tasks"></i>
                    <span class="me-auto">Operations</span>
                    <i class="right-icon fa fa-chevron-right"></i>
                </a>
                <ul class="sub-nav collapse <?php echo $isOperationsActive ? 'show' : ''; ?>" id="sidebar-operations">
                    <li class="nav-item">
                        <a href="members.php" id="link-members" class="nav-link <?php echo $currentPage == 'members.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-users"></i>
                            <span>Members</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="packages.php" id="link-packages" class="nav-link <?php echo $currentPage == 'packages.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-box"></i>
                            <span>Packages</span>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Financials Group (Collapsible) -->
            <li class="nav-item">
                <a class="nav-link <?php echo $isFinancialsActive ? '' : 'collapsed'; ?>"
                   data-bs-toggle="collapse"
                   href="#sidebar-financials"
                   role="button"
                   aria-expanded="<?php echo $isFinancialsActive ? 'true' : 'false'; ?>"
                   aria-controls="sidebar-financials">
                    <i class="icon fa fa-wallet"></i>
                    <span class="me-auto">Financials</span>
                    <i class="right-icon fa fa-chevron-right"></i>
                </a>
                <ul class="sub-nav collapse <?php echo $isFinancialsActive ? 'show' : ''; ?>" id="sidebar-financials">
                    <li class="nav-item">
                        <a href="pins.php" id="link-pins" class="nav-link <?php echo $currentPage == 'pins.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-key"></i>
                            <span>PIN Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="withdrawals.php" id="link-withdrawals" class="nav-link <?php echo $currentPage == 'withdrawals.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-money-bill-wave"></i>
                            <span>Withdrawals</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" id="link-reports" class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-chart-bar"></i>
                            <span>Business Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reset_commissions.php" id="link-reset-commissions" class="nav-link <?php echo $currentPage == 'reset_commissions.php' ? 'active' : ''; ?>">
                            <i class="icon fa fa-sync-alt"></i>
                            <span>Recalculate System</span>
                        </a>
                    </li>
                </ul>
            </li>

            <hr>

            <!-- Logout Link (Standalone) -->
            <li class="nav-item">
                <a href="logout.php" class="nav-link text-danger">
                    <i class="icon fa fa-sign-out-alt text-danger"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="main-content">