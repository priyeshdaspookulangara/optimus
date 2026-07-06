<?php
require_once __DIR__ . '/includes/engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';
    $sponsorId = $_POST['referral_id'] ?? null;
    $position = $_POST['position'] ?? 'left';

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

        // If sponsorId is empty, it might be a root registration if allowed,
        // but typically it should be mandatory in MLM.
        // For this task, we assume it's provided.

        $stmt = $db->prepare("INSERT INTO users (username, full_name, phone, email, password, sponsor_id, placement_id, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        // We'll use sponsorId as placementId for simplicity if not specified otherwise in registration form
        $stmt->execute([$username, $fullName, $phone, $email, $hashedPassword, $sponsorId, $sponsorId, $position]);
        $newUserId = $db->lastInsertId();

        if ($sponsorId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorId, $sponsorId, $position);
        }

        $db->commit();
        echo "Registration successful! <a href='login.php'>Login here</a>";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "Registration failed: " . $e->getMessage();
    }
} else {
    header("Location: registration_new.php");
}
