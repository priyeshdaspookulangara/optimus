<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$pageTitle = 'Support';
include __DIR__ . '/includes/header.php';
?>
<div class='container-fluid p-4'>
    <div class='card bg-dark text-white p-5'>
        <h2><?php echo htmlspecialchars($pageTitle); ?></h2>
        <p>This module is under development.</p>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
