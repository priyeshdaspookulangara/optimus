<?php
require_once __DIR__ . '/includes/engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $sponsorId = $_POST['sponsor_id'] ?? null;
    $placementId = $_POST['placement_id'] ?? null;
    $position = $_POST['position'] ?? 'left';

    $db = Database::getInstance()->getConnection();
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO users (username, email, password, sponsor_id, placement_id, position) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $hashedPassword, $sponsorId, $placementId, $position]);
        $newUserId = $db->lastInsertId();

        if ($sponsorId) {
            $engine = new MLMEngine();
            $engine->addToGenealogy($newUserId, $sponsorId, $placementId, $position);
        }

        $db->commit();
        echo "Registration successful! <a href='login.php'>Login here</a>";
    } catch (Exception $e) {
        $db->rollBack();
        echo "Registration failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - MLM App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card p-4 mx-auto" style="max-width: 500px;">
            <h2 class="mb-4">Register</h2>
            <form method="post">
                <div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                <div class="mb-3"><label>Sponsor ID (optional)</label><input type="number" name="sponsor_id" class="form-control"></div>
                <div class="mb-3"><label>Placement ID (optional)</label><input type="number" name="placement_id" class="form-control"></div>
                <div class="mb-3"><label>Position</label>
                    <select name="position" class="form-select">
                        <option value="left">Left</option>
                        <option value="right">Right</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Register</button>
            </form>
        </div>
    </div>
</body>
</html>
