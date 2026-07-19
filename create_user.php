<?php
session_start();
require_once __DIR__ . '/includes/engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $postOfficeNumber = trim($_POST['post_office_number'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';
    $sponsorId = $_POST['referral_id'] ?? null;
    $position = $_POST['position'] ?? 'left';
    $pinCode = trim($_POST['pin_code'] ?? '');

    if ($password !== $confirmPassword) {
        die("Passwords do not match. <a href='javascript:history.back()'>Go back</a>");
    }

    $db = Database::getInstance()->getConnection();

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        die("Username or Email already exists. <a href='javascript:history.back()'>Go back</a>");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO users (username, full_name, phone, address, post_office_number, state, country, email, password, sponsor_id, placement_id, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $fullName, $phone, $address, $postOfficeNumber, $state, $country, $email, $hashedPassword, $sponsorId, $sponsorId, $position]);
        $newUserId = $db->lastInsertId();

        if ($sponsorId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorId, $sponsorId, $position);
        }

        // If a PIN code was provided during registration, activate the package instantly!
        if (!empty($pinCode)) {
            $engine = new MLMEngine();
            $engine->activateWithPin($newUserId, $pinCode);
        }

        $db->commit();

        // Save user ID to session so we can display their credentials on the success page once securely
        $_SESSION['new_user_id'] = $newUserId;
        header("Location: registration_success.php");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "Registration failed: " . $e->getMessage() . " <a href='javascript:history.back()'>Go back</a>";
    }
} else {
    header("Location: register.php");
}
