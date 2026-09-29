<?php
session_name('UTMKL_DRIVER_SESS');
session_start();

require_once '../shared/config.php';

if (isset($_SESSION['driver_logged_in']) && $_SESSION['driver_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Rate Limiting — max 5 attempts per IP per 60 seconds
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_key = 'driver_login_attempts_' . md5($ip);
$rate_time_key = 'driver_login_time_' . md5($ip);

if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = 0;
    $_SESSION[$rate_time_key] = time();
}

// Reset counter if 60 seconds has passed
if ((time() - $_SESSION[$rate_time_key]) > 60) {
    $_SESSION[$rate_key] = 0;
    $_SESSION[$rate_time_key] = time();
}

$error = '';
$rate_limited = false;

if ($_SESSION[$rate_key] >= 5) {
    $wait = 60 - (time() - $_SESSION[$rate_time_key]);
    $error = "Too many failed attempts. Please wait {$wait} seconds before trying again.";
    $rate_limited = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rate_limited) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $conn->prepare("SELECT id, password, nama, no_telefon, status, must_change_password FROM drivers WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($driver = $result->fetch_assoc()) {
            if ($driver['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact the administrator.';
                $_SESSION[$rate_key]++;
            } elseif (password_verify($password, $driver['password'])) {
                // Reset rate limit on success
                $_SESSION[$rate_key] = 0;
                $_SESSION['driver_logged_in'] = true;
                $_SESSION['driver_id'] = $driver['id'];
                $_SESSION['driver_nama'] = $driver['nama'];
                $_SESSION['driver_no_telefon'] = $driver['no_telefon'];
                $_SESSION['driver_must_change_password'] = (int)$driver['must_change_password'];

                if ($driver['must_change_password']) {
                    header('Location: change_password.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                $error = 'Invalid username or password.';
                $_SESSION[$rate_key]++;
            }
        } else {
            $error = 'Invalid username or password.';
            $_SESSION[$rate_key]++;
        }
        $stmt->close();
    } else {
        $error = 'Please enter both username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Driver Portal - UTMKL Ride</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1556e8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="stylesheet" href="driver.css">
    <style>
        body {
            background: linear-gradient(135deg, #0d3fb0, #9d3df5);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .login-card {
            background: #ffffff;
            color: #333;
            border-radius: 20px;
            padding: 30px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            text-align: center;
        }
        .login-card h2 {
            margin-top: 10px;
            margin-bottom: 20px;
            color: #1556e8;
        }
        .logo-emoji {
            font-size: 50px;
            margin: 0;
        }
        .form-group {
            margin-bottom: 15px;
            text-align: left;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 36px;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-login {
            background: linear-gradient(135deg, #1556e8, #9d3df5);
            color: #fff;
            border: none;
            padding: 14px;
            width: 100%;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        .error-msg {
            color: #dc2626;
            background: #fef2f2;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .footer-text {
            margin-top: 20px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-emoji">🚗</div>
        <h2>Driver Portal</h2>
        
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Enter your username">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="password" required placeholder="Enter password">
                <button type="button" class="toggle-password" onclick="togglePassword()">Show</button>
            </div>
            <button type="submit" class="btn-login">Log In</button>
        </form>
        <div class="footer-text">Driver Portal &bull; UTMKL Ride</div>
    </div>

    <script>
        function togglePassword() {
            var pwd = document.getElementById('password');
            var btn = document.querySelector('.toggle-password');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                btn.textContent = 'Hide';
            } else {
                pwd.type = 'password';
                btn.textContent = 'Show';
            }
        }
    </script>
</body>
</html>
