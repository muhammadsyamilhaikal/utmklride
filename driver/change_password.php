<?php
session_name('UTMKL_DRIVER_SESS');
session_start();

require_once '../shared/config.php';

if (!isset($_SESSION['driver_logged_in']) || $_SESSION['driver_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['driver_must_change_password']) && $_SESSION['driver_must_change_password'] == 0) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $error = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error = 'Password must contain at least one number.';
    } elseif (!preg_match('/[@$!%*?&#^_]/', $new_password)) {
        $error = 'Password must contain at least one special character (@$!%*?&#^_).';
    } else {
        $driver_id = $_SESSION['driver_id'];
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE drivers SET password = ?, must_change_password = 0 WHERE id = ?");
        $stmt->bind_param("si", $hash, $driver_id);
        
        if ($stmt->execute()) {
            $_SESSION['driver_must_change_password'] = 0;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Failed to update password. Please try again.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Change Password - UTMKL Ride</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1556e8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="driver.css">
    <style>
        body {
            background: linear-gradient(135deg, #0d3fb0, #9d3df5);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            margin: 0;
            font-family: sans-serif;
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
            margin-bottom: 5px;
            color: #1556e8;
        }
        .subtitle {
            font-size: 14px;
            color: #555;
            margin-bottom: 20px;
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
            text-align: left;
            border: 1px solid #fecaca;
        }
        .temp-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 13px;
            text-align: left;
        }
        .pw-hint {
            font-size: 11px;
            color: #64748b;
            background: #f8fafc;
            border-radius: 6px;
            padding: 8px 10px;
            margin-top: 6px;
            text-align: left;
            line-height: 1.6;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-emoji">🔐</div>
        <h2>Change Your Password</h2>
        <div class="subtitle">For security, you must set a new password before continuing.</div>
        
        <div class="temp-warning">
            ⚠️ Your account uses a temporary password. Please change it now.
        </div>
        
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="change_password.php">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" id="new_password" required placeholder="Enter new password">
                <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)">Show</button>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" id="confirm_password" required placeholder="Confirm new password">
                <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)">Show</button>
                <div class="pw-hint">Password must contain: Uppercase, Lowercase, Number, Special character (@$!%*?&#^_), Min. 8 characters</div>
            </div>
            <button type="submit" class="btn-login">Set New Password &rarr;</button>
        </form>
    </div>

    <script>
        function togglePassword(inputId, btn) {
            var pwd = document.getElementById(inputId);
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
