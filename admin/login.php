<?php
require_once '../shared/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('UTMKL_ADMIN_SESS');
    session_start();
}

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

$res = $conn->query("SELECT COUNT(*) as count FROM admins");
$row = $res->fetch_assoc();
$admin_count = $row['count'];

// Rate Limiting — max 5 attempts per IP per 60 seconds
$ip_key = 'admin_login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ip_time_key = 'admin_login_time_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!isset($_SESSION[$ip_key])) { $_SESSION[$ip_key] = 0; $_SESSION[$ip_time_key] = time(); }
if ((time() - $_SESSION[$ip_time_key]) > 60) { $_SESSION[$ip_key] = 0; $_SESSION[$ip_time_key] = time(); }
$rate_limited = false;
if ($_SESSION[$ip_key] >= 5) {
    $wait = 60 - (time() - $_SESSION[$ip_time_key]);
    $error = "Too many failed login attempts. Please wait {$wait} seconds.";
    $rate_limited = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rate_limited) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Sila isi semua medan.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, nama FROM admins WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                $_SESSION[$ip_key] = 0;
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_nama'] = $admin['nama'];
                header("Location: index.php");
                exit;
            } else {
                $error = "Username atau laluan tidak sah.";
                $_SESSION[$ip_key]++;
            }
        } else {
            $error = "Username atau laluan tidak sah.";
            $_SESSION[$ip_key]++;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Login - UTMKL Ride</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background: #2a2a2a;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            color: #fff;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
            border: 1px solid #444;
            border-radius: 4px;
            background: #333;
            color: #fff;
        }
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-primary {
            background: #007bff;
            color: #fff;
        }
        .text-center { text-align: center; }
        .text-danger { color: #ff4d4f; }
        .text-info { color: #17a2b8; }
    </style>
</head>
<body style="background: #1a1a1a; font-family: sans-serif;">
    <div class="login-container">
        <h2 class="text-center">Superadmin Login</h2>
        
        <?php if ($admin_count == 0): ?>
            <div class="text-info" style="margin-bottom: 15px; text-align: center;">
                No admin account found. Please create the first account using the <br><a href="setup_admin.php" style="color: #fff; text-decoration: underline;">setup script</a>.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="text-danger" style="margin-bottom: 15px; text-align: center;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" required>
                    <button type="button" id="togglePassword" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #fff;">👁️</button>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">Login</button>
        </form>
    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });
    </script>
</body>
</html>
