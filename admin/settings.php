<?php
require_once 'auth_guard.php';
require_once '../shared/config.php';

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['admin_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $keys = ['telegram_chat_id_admin', 'telegram_chat_id_drivers'];
    $stmt = $conn->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()");

    foreach ($keys as $key) {
        $val = trim(strip_tags($_POST[$key] ?? ''));
        $stmt->bind_param('ss', $key, $val);
        $stmt->execute();
    }

    // Keep the bot token server-side and never render the stored secret into HTML.
    $new_token = trim((string)($_POST['telegram_bot_token'] ?? ''));
    if ($new_token !== '') {
        $token_key = 'telegram_bot_token';
        $stmt->bind_param('ss', $token_key, $new_token);
        $stmt->execute();
    }
    $stmt->close();
    
    header('Location: settings.php?saved=1');
    exit;
}

$chat_id_admin = get_setting($conn, 'telegram_chat_id_admin');
$chat_id_drivers = get_setting($conn, 'telegram_chat_id_drivers');
$driver_tg_list = [];
$d_tg_res = $conn->query("SELECT nama, telegram_chat_id FROM drivers WHERE status = 'active' AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) != ''");
if ($d_tg_res) {
    while ($r = $d_tg_res->fetch_assoc()) {
        $driver_tg_list[] = [
            'id' => trim($r['telegram_chat_id']),
            'name' => $r['nama']
        ];
    }
}
$driver_tg_count = count($driver_tg_list);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram & Notification Settings — UTMKL Ride</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .container { max-width: 650px; margin: 30px auto; padding: 25px; background: #1e293b; border-radius: 12px; color: #fff; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #e2e8f0; }
        .form-group small { display: block; color: #94a3b8; font-size: 12px; margin-top: 4px; }
        .form-group input { width: 100%; padding: 10px 14px; box-sizing: border-box; background: #0f172a; color: #fff; border: 1px solid #334155; border-radius: 8px; font-size: 14px; }
        .btn { padding: 10px 16px; border: none; border-radius: 8px; cursor: pointer; color: #fff; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #2563eb; }
        .btn-info { background: #0284c7; }
        .btn-success { background: #16a34a; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
        .nav-link { color: #38bdf8; text-decoration: none; margin-bottom: 20px; display: inline-block; font-size: 14px; }
        .nav-link:hover { text-decoration: underline; }
        .test-section { margin-top: 25px; padding-top: 20px; border-top: 1px solid #334155; }
        .test-section h4 { margin-bottom: 12px; font-size: 14px; color: #cbd5e1; }
        .test-btn-row { display: flex; flex-wrap: wrap; gap: 8px; }
        
        #toast {
            visibility: hidden; min-width: 280px; background-color: #0f172a; color: #fff; 
            text-align: center; border-radius: 8px; padding: 14px 20px; position: fixed; 
            z-index: 999; left: 50%; bottom: 30px; font-size: 14px; transform: translateX(-50%);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #334155;
        }
        #toast.show { visibility: visible; animation: fadein 0.4s, fadeout 0.4s 2.6s; }
        @keyframes fadein { from {bottom: 0; opacity: 0;} to {bottom: 30px; opacity: 1;} }
        @keyframes fadeout { from {bottom: 30px; opacity: 1;} to {bottom: 0; opacity: 0;} }
    </style>
</head>
<body style="background: #0b1329; font-family: 'Poppins', sans-serif;">

<div class="container">
    <a href="index.php" class="nav-link">&larr; Back to Operations Dashboard</a>
    <h2 style="margin-bottom: 20px;">⚙️ Telegram Notification Settings</h2>

    <?php if (isset($_GET['saved']) && $_GET['saved'] == 1): ?>
        <div class="alert alert-success">Settings saved successfully!</div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label>Telegram Bot Token</label>
            <input type="password" id="telegram_bot_token" name="telegram_bot_token" value="" autocomplete="new-password" placeholder="Leave blank to keep the current token">
            <small>Enter a new BotFather token only when rotating it. The stored token is never displayed.</small>
        </div>
        <div class="form-group">
            <label>Admin Chat ID</label>
            <input type="text" id="telegram_chat_id_admin" name="telegram_chat_id_admin" value="<?= htmlspecialchars($chat_id_admin) ?>" required>
            <small>Your personal Telegram Chat ID (from @userinfobot)</small>
        </div>
        <div class="form-group">
            <label>Driver Group Chat ID <span style="font-weight: normal; color: #94a3b8;">(Optional)</span></label>
            <input type="text" id="telegram_chat_id_drivers" name="telegram_chat_id_drivers" value="<?= htmlspecialchars($chat_id_drivers) ?>" placeholder="e.g. -100xxxxxxxxxx">
            <small>If you have a shared Telegram Group for all drivers, put the group Chat ID here.</small>
        </div>
        
        <button type="submit" class="btn btn-primary" style="margin-top: 10px;">💾 Save Settings</button>
    </form>

    <div class="test-section">
        <h4>🔔 Real-Time Telegram Test Pings</h4>
        <p style="font-size: 12px; color: #94a3b8; margin-bottom: 12px;">
            Test message delivery directly from your browser. Note: Users must have pressed <code>/start</code> in your bot first.
        </p>
        <div class="test-btn-row">
            <button type="button" class="btn btn-info" onclick="testAdminNotification()">
                👤 Ping Admin
            </button>
            <button type="button" class="btn btn-info" onclick="testGroupNotification()">
                👥 Ping Driver Group
            </button>
            <button type="button" class="btn btn-success" onclick="testBroadcastAllDrivers()">
                📢 Ping All Registered Drivers (<?= $driver_tg_count ?> Active)
            </button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token); ?>;
const DRIVER_LIST = <?= json_encode($driver_tg_list); ?>;

async function sendTelegramTest(chatId, message) {
    const body = new URLSearchParams({csrf_token: CSRF_TOKEN, chat_id: chatId, message});
    const response = await fetch('telegram_test.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
    return response.json();
}

function showToast(message, isError = false) {
    const x = document.getElementById("toast");
    x.innerText = message;
    x.style.borderColor = isError ? "#ef4444" : "#10b981";
    x.style.background = isError ? "#450a0a" : "#064e3b";
    x.className = "show";
    setTimeout(() => { x.className = x.className.replace("show", ""); }, 3000);
}

function testAdminNotification() {
    const chatId = document.getElementById('telegram_chat_id_admin').value.trim();
    if (!chatId) {
        showToast("Please fill in both Bot Token and Admin Chat ID first.", true);
        return;
    }
    sendTelegramTest(chatId, "🔔 *UTMKL Ride* — Test notification to Admin successful!")
        .then(data => showToast(data.message, !data.success))
        .catch(() => showToast("Failed to send test ping.", true));
}

function testGroupNotification() {
    const groupId = document.getElementById('telegram_chat_id_drivers').value.trim();
    if (!groupId) {
        showToast("Driver Group Chat ID is empty.", true);
        return;
    }
    sendTelegramTest(groupId, "🚗 *UTMKL Ride* — Test notification to Driver Group successful!")
        .then(data => showToast(data.message, !data.success))
        .catch(() => showToast("Failed to send to Driver Group.", true));
}

function testBroadcastAllDrivers() {
    if (!DRIVER_LIST || DRIVER_LIST.length === 0) {
        showToast("No active drivers with Telegram Chat ID registered yet.", true);
        return;
    }

    let count = 0;
    DRIVER_LIST.forEach(d => {
        sendTelegramTest(d.id, `🔔 *UTMKL Ride Test Notification*\nHello ${d.name}! Your Telegram notification is working.`).catch(() => {});
        count++;
    });

    showToast(`Broadcast test sent to ${count} active driver(s)!`);
}
</script>

</body>
</html>
