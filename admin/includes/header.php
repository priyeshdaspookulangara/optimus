<?php
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}
$db = Database::getInstance()->getConnection();
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
        .sidebar { height: 100vh; width: 250px; position: fixed; top: 0; left: 0; background-color: #343a40; padding-top: 20px; color: #fff; }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 18px; color: #adb5bd; display: block; }
        .sidebar a:hover { color: #fff; background-color: #495057; }
        .sidebar a.active { color: #fff; background-color: #007bff; }
        .main-content { margin-left: 250px; padding: 20px; }
        .card-stat { border-radius: 10px; border: none; transition: transform 0.3s; }
        .card-stat:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="text-center mb-4">
            <h4>Admin Panel</h4>
            <small><?php echo $_SESSION['admin_username']; ?></small>
        </div>
        <a href="dashboard.php" id="link-dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fa fa-tachometer-alt me-2"></i> Dashboard</a>
        <a href="members.php" id="link-members" class="<?php echo basename($_SERVER['PHP_SELF']) == 'members.php' ? 'active' : ''; ?>"><i class="fa fa-users me-2"></i> Members</a>
        <a href="sponsored_members.php" id="link-sponsored-members" class="<?php echo basename($_SERVER['PHP_SELF']) == 'sponsored_members.php' ? 'active' : ''; ?>"><i class="fa fa-user-plus me-2"></i> Sponsored Members</a>
        <a href="packages.php" id="link-packages" class="<?php echo basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : ''; ?>"><i class="fa fa-box me-2"></i> Packages</a>
        <a href="pins.php" id="link-pins" class="<?php echo basename($_SERVER['PHP_SELF']) == 'pins.php' ? 'active' : ''; ?>"><i class="fa fa-key me-2"></i> PIN Management</a>
        <a href="withdrawals.php" id="link-withdrawals" class="<?php echo basename($_SERVER['PHP_SELF']) == 'withdrawals.php' ? 'active' : ''; ?>"><i class="fa fa-money-bill-wave me-2"></i> Withdrawals</a>
        <a href="reports.php" id="link-reports" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>"><i class="fa fa-chart-bar me-2"></i> Business Reports</a>
        <hr>
        <a href="logout.php"><i class="fa fa-sign-out-alt me-2"></i> Logout</a>
    </div>
    <div class="main-content">
