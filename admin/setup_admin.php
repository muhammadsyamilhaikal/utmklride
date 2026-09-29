<?php
require_once '../shared/config.php';

$res = $conn->query("SELECT COUNT(*) as count FROM admins");
$row = $res->fetch_assoc();
if ($row['count'] > 0) {
    die("An admin account already exists! This setup script is disabled.");
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $nama = trim($_POST['nama'] ?? '');

    if (empty($username) || empty($password) || empty($nama)) {
        $message = "Please fill in all fields.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admins (username, password, nama) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $username, $hash, $nama);
        
        if ($stmt->execute()) {
            $message = "Admin created successfully! Please delete this file immediately.";
            $success = true;
        } else {
            $message = "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup First Admin — UTMKL Ride</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 50px; }
        .container { max-width: 400px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 8px; box-sizing: border-box; }
        .btn { padding: 10px 15px; background: #28a745; color: #fff; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .msg { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Setup First Admin</h2>
        
        <?php if ($message): ?>
            <div class="msg <?= $success ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <script>
                setTimeout(() => { window.location.href = 'login.php'; }, 3000);
            </script>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="nama" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn">Create Admin</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
