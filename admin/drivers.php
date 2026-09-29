<?php
require_once 'auth_guard.php';
require_once '../shared/config.php';

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['admin_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $nama = trim($_POST['nama'] ?? '');
        $no_telefon = trim($_POST['no_telefon'] ?? '');
        $no_plat = trim($_POST['no_plat'] ?? '');
        $model_kenderaan = trim($_POST['model_kenderaan'] ?? '');
        $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO drivers (username, password, must_change_password, nama, no_telefon, no_plat, model_kenderaan, telegram_chat_id) VALUES (?, ?, 1, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssss', $username, $hash, $nama, $no_telefon, $no_plat, $model_kenderaan, $telegram_chat_id);
        $stmt->execute();
        $stmt->close();
        header('Location: drivers.php?added=1');
        exit;
    }

    if ($action === 'update_contact') {
        if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
            header('Location: drivers.php?edit_error=1');
            exit;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $no_telefon = trim($_POST['no_telefon'] ?? '');
        $nama_length = function_exists('mb_strlen') ? mb_strlen($nama, 'UTF-8') : strlen($nama);

        if ($id < 1 || $nama === '' || $nama_length > 100 || $no_telefon === '' || strlen($no_telefon) > 20) {
            header('Location: drivers.php?edit_error=1');
            exit;
        }

        $stmt = $conn->prepare('UPDATE drivers SET nama = ?, no_telefon = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $nama, $no_telefon, $id);
            $updated = $stmt->execute();
            $stmt->close();
            header('Location: drivers.php?' . ($updated ? 'updated=1' : 'edit_error=1'));
        } else {
            header('Location: drivers.php?edit_error=1');
        }
        exit;
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        
        $stmt = $conn->prepare("SELECT status FROM drivers WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $driver = $res->fetch_assoc();
        $stmt->close();
        
        if ($driver) {
            $new_status = ($driver['status'] === 'active') ? 'inactive' : 'active';
            $stmt2 = $conn->prepare("UPDATE drivers SET status = ? WHERE id = ?");
            $stmt2->bind_param('si', $new_status, $id);
            $stmt2->execute();
            $stmt2->close();
        }
        header('Location: drivers.php?toggled=1');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM drivers WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        header('Location: drivers.php?deleted=1');
        exit;
    }

    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $new_pass = trim($_POST['new_password'] ?? '');
        if ($id && strlen($new_pass) >= 6) {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE drivers SET password = ?, must_change_password = 1 WHERE id = ?");
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            $stmt->close();
            header('Location: drivers.php?reset=1');
            exit;
        }
    }
}


$drivers = [];
$res = $conn->query("SELECT id, username, nama, no_telefon, no_plat, model_kenderaan, telegram_chat_id, status, must_change_password, created_at FROM drivers ORDER BY created_at DESC");
while ($row = $res->fetch_assoc()) {
    $drivers[] = $row;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Drivers - UTMKL Ride</title>
    <link rel="stylesheet" href="admin.css">
    <style>
        .container { max-width: 1000px; margin: 30px auto; padding: 20px; background: #2a2a2a; border-radius: 8px; color: #fff; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; box-sizing: border-box; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px; }
        .btn { padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; color: #fff; }
        .btn-primary { background: #007bff; }
        .btn-danger { background: #dc3545; }
        .btn-warning { background: #ffc107; color: #212529; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .nav-link { color: #fff; text-decoration: underline; margin-bottom: 20px; display: inline-block; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #444; padding: 10px; text-align: left; }
        th { background: #333; }
        .badge { padding: 5px 8px; border-radius: 4px; font-size: 12px; }
        .badge-active { background: #28a745; color: white; }
        .badge-inactive { background: #dc3545; color: white; }
        
        #addForm { display: none; margin-top: 20px; background: #333; padding: 15px; border-radius: 4px; }
    </style>
</head>
<body style="background: #1a1a1a; font-family: sans-serif;">

<?php
$bot_token = get_setting($conn, 'telegram_bot_token');
?>
<div class="container">
    <a href="index.php" class="nav-link">&larr; Back to Dashboard</a>
    <h2>👨‍💼 Manage Drivers</h2>

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Driver added successfully!</div>
    <?php elseif (isset($_GET['toggled'])): ?>
        <div class="alert alert-success">Driver status updated!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Driver deleted.</div>
    <?php elseif (isset($_GET['reset'])): ?>
        <div class="alert alert-success">Driver password has been reset. They must change it on next login.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Driver contact details updated.</div>
    <?php elseif (isset($_GET['edit_error'])): ?>
        <div class="alert alert-danger" style="background:#f8d7da;color:#721c24;">Contact details could not be updated. Check the name and phone number, then try again.</div>
    <?php endif; ?>

    <button class="btn btn-primary" onclick="document.getElementById('addForm').style.display = document.getElementById('addForm').style.display === 'none' ? 'block' : 'none'">
        + Add Driver
    </button>

    <div id="addForm" style="display:none;">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="nama" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="no_telefon" placeholder="e.g. 0133670031">
                </div>
                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" name="no_plat" placeholder="e.g. WXX 1234">
                </div>
                <div class="form-group">
                    <label>Vehicle Model</label>
                    <input type="text" name="model_kenderaan" placeholder="e.g. Perodua Myvi">
                </div>
                <div class="form-group">
                    <label>Telegram Chat ID <small style="font-weight:normal;opacity:0.7;">(from @userinfobot or /start)</small></label>
                    <input type="text" name="telegram_chat_id" placeholder="e.g. 8138127062">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top: 15px;">Save Driver</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Phone</th>
                <th>Vehicle</th>
                <th>Telegram</th>
                <th>Status</th>
                <th>Auth</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($drivers)): ?>
                <tr><td colspan="8" style="text-align: center;">No drivers found.</td></tr>
            <?php else: ?>
                <?php foreach ($drivers as $d): ?>
                <?php
                    $clean_phone = clean_phone_my($d['no_telefon']);
                    $has_tg = !empty($d['telegram_chat_id']);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($d['nama']) ?></strong></td>
                    <td><code><?= htmlspecialchars($d['username']) ?></code></td>
                    <td>
                        <?= htmlspecialchars($d['no_telefon']) ?><br>
                        <?php if ($clean_phone): ?>
                            <a href="https://api.whatsapp.com/send?phone=<?= $clean_phone ?>" target="_blank" rel="noopener" style="color:#25d366;text-decoration:none;font-size:12px;font-weight:600;">
                                💬 WhatsApp
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($d['no_plat']) ?><br>
                        <small><?= htmlspecialchars($d['model_kenderaan']) ?></small>
                    </td>
                    <td>
                        <?php if ($has_tg): ?>
                            <code style="background:#222;padding:2px 6px;border-radius:4px;font-size:11px;"><?= htmlspecialchars($d['telegram_chat_id']) ?></code><br>
                            <button type="button" class="btn btn-sm" style="background:#0284c7;color:#fff;border:none;margin-top:4px;padding:3px 8px;font-size:11px;border-radius:4px;cursor:pointer;"
                                    onclick="pingDriverTg('<?= htmlspecialchars($d['telegram_chat_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($d['nama'], ENT_QUOTES) ?>')">
                                🔔 Test Ping
                            </button>
                        <?php else: ?>
                            <span style="color:#666;font-size:12px;">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $d['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                            <?= htmlspecialchars($d['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($d['must_change_password'])): ?>
                            <span style="background:#fbbf24;color:#78350f;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;">⚠️ Temp PW</span>
                        <?php else: ?>
                            <span style="background:#d1fae5;color:#065f46;padding:3px 8px;border-radius:4px;font-size:11px;">✅ Set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Toggle Status</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this driver?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                        <button type="button" class="btn btn-sm" style="background:#0f766e;color:#fff;margin-top:4px;"
                            onclick="const f=document.getElementById('contact-form-<?= $d['id'] ?>');f.style.display=f.style.display==='none'?'block':'none'">
                            ✏️ Edit Contact
                        </button>
                        <div id="contact-form-<?= $d['id'] ?>" style="display:none;margin-top:8px;min-width:220px;">
                            <form method="POST" style="display:flex;flex-direction:column;gap:6px;">
                                <input type="hidden" name="action" value="update_contact">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <label for="driver-name-<?= (int) $d['id'] ?>" style="font-size:11px;">Full Name</label>
                                <input id="driver-name-<?= (int) $d['id'] ?>" type="text" name="nama" value="<?= htmlspecialchars($d['nama'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required style="padding:6px 8px;border-radius:4px;border:1px solid #555;background:#333;color:#fff;font-size:12px;">
                                <label for="driver-phone-<?= (int) $d['id'] ?>" style="font-size:11px;">Phone Number</label>
                                <input id="driver-phone-<?= (int) $d['id'] ?>" type="tel" name="no_telefon" value="<?= htmlspecialchars($d['no_telefon'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="20" required style="padding:6px 8px;border-radius:4px;border:1px solid #555;background:#333;color:#fff;font-size:12px;">
                                <button type="submit" class="btn btn-primary" style="margin-top:2px;">Save Contact</button>
                            </form>
                        </div>
                        <button type="button" class="btn btn-sm" style="background:#7c3aed;color:#fff;margin-top:4px;"
                            onclick="document.getElementById('reset-form-<?= $d['id'] ?>').style.display='block'">
                            🔑 Reset PW
                        </button>
                        <div id="reset-form-<?= $d['id'] ?>" style="display:none;margin-top:6px;">
                            <form method="POST" style="display:flex;gap:4px;">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="text" name="new_password" placeholder="New temp password" required minlength="6"
                                       style="padding:4px 8px;border-radius:4px;border:1px solid #555;background:#444;color:#fff;font-size:12px;flex:1;">
                                <button type="submit" class="btn" style="background:#7c3aed;color:#fff;padding:4px 8px;font-size:12px;">Set</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const BOT_TOKEN = <?= json_encode($bot_token); ?>;

function pingDriverTg(chatId, driverName) {
    if (!BOT_TOKEN) {
        alert("Telegram Bot Token is not configured in Settings!");
        return;
    }
    const text = encodeURIComponent(`🔔 *UTMKL Ride Test Notification*\nHello ${driverName}! Your Telegram notification is working perfectly.`);
    const url = `https://api.telegram.org/bot${BOT_TOKEN}/sendMessage?chat_id=${chatId}&text=${text}&parse_mode=Markdown`;

    fetch(url, { mode: 'no-cors' })
        .then(() => {
            alert(`Test notification sent to ${driverName} (Chat ID: ${chatId})! Check their Telegram.`);
        })
        .catch(err => {
            alert(`Error sending test ping: ${err}`);
        });
}
</script>

</body>
</html>
