<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
mysqli_report(MYSQLI_REPORT_OFF);

// 1. Sambung ke Database dulu
$host = "sql306.infinityfree.com";
$user = "if0_42164453";
$pass = "rkKbbG05Q2";   
$db   = "if0_42164453_ride_booking";

$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("<div style='background:#f8d7da; color:#721c24; padding:20px; font-family:sans-serif; margin:20px; border-radius:8px; border:1px solid #f5c6cb;'>
            <b>🚨 Ralat Sambungan Database:</b><br>" . htmlspecialchars($conn->connect_error) . "
         </div>");
}

// =========================================================================
// KAWALAN SHUTDOWN / ON DUTY (GUNA DATABASE MYSQL - DIJAMIN TEMBUS!)
// =========================================================================
// Cipta table automatik jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS system_status (id INT PRIMARY KEY, status VARCHAR(10))");

// Cek status sekarang
$res_stat = $conn->query("SELECT status FROM system_status WHERE id = 1");
if ($res_stat && $res_stat->num_rows > 0) {
    $row_stat = $res_stat->fetch_assoc();
    $current_status = $row_stat['status'];
} else {
    // Kalau belum ada data langsung, set default kepada 'ON'
    $conn->query("INSERT INTO system_status (id, status) VALUES (1, 'ON')");
    $current_status = 'ON';
}

// Bila admin tekan butang ON/OFF
if (isset($_GET['toggle_status'])) {
    $new_status = ($current_status === 'ON') ? 'OFF' : 'ON';
    $conn->query("UPDATE system_status SET status = '$new_status' WHERE id = 1");
    
    // Refresh page supaya URL bersih & status bertukar
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); 
    exit;
}
// =========================================================================

$mesej = "";

// LOGIK SIMPAN DATA VIA BORANG ADMIN (JIKA PERLU)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['nama'])) {
    $nama    = $_POST['nama'];
    $telefon = $_POST['telefon'];
    $pickup  = $_POST['pickup'];
    $dropoff = $_POST['dropoff'];
    $tarikh  = $_POST['tarikh'];
    $masa    = $_POST['masa'];

    $stmt_insert = $conn->prepare("INSERT INTO tempahan (nama, telefon, pickup, dropoff, tarikh, masa) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_insert->bind_param("ssssss", $nama, $telefon, $pickup, $dropoff, $tarikh, $masa);
    
    if ($stmt_insert->execute()) {
        $botToken = "8601874885:AAGzZSB5Fs6HiRkcdDCDkxRBomApBNOAKcs";
        $chatId   = "1359073968";

        $tarikh_tg = date("d/m/Y", strtotime($tarikh));
        $masa_tg   = date("h:i A", strtotime($masa));

        $mesej_tg  = "🚨 *TEMPAHAN RIDE BARU MASUK!* 🚨\n\n";
        $mesej_tg .= "👤 *Nama:* " . $nama . "\n";
        $mesej_tg .= "📞 *Tel:* " . $telefon . "\n";
        $mesej_tg .= "📍 *Pick-up:* " . $pickup . "\n";
        $mesej_tg .= "🏁 *Drop-off:* " . $dropoff . "\n";
        $mesej_tg .= "📅 *Tarikh/Masa:* " . $tarikh_tg . " (" . $masa_tg . ")\n\n";
        $mesej_tg .= "⚡ _Sila buka Admin Panel sekarang!_";

        $url_tg = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
        $data_tg = ['chat_id' => $chatId, 'text' => $mesej_tg, 'parse_mode' => 'Markdown'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_tg);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data_tg));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);

        $mesej = "<div class='alert alert-success'>Tempahan berjaya ditambah!</div>";
    } else {
        $mesej = "<div class='alert alert-danger'>Ralat menambah tempahan.</div>";
    }
    $stmt_insert->close();
}

// LOGIK SOFT DELETE (SELESAI & BATAL)
if (isset($_GET['selesai'])) {
    $id_hapus = intval($_GET['selesai']);
    $stmt = $conn->prepare("UPDATE tempahan SET status = 'selesai' WHERE id = ?");
    $stmt->bind_param("i", $id_hapus);
    if ($stmt->execute()) {
        $mesej = "<div class='alert alert-success'>Tempahan #$id_hapus telah ditandakan selesai & diarkibkan!</div>";
    }
    $stmt->close();
}

if (isset($_GET['batal'])) {
    $id_batal = intval($_GET['batal']);
    $stmt = $conn->prepare("UPDATE tempahan SET status = 'batal' WHERE id = ?");
    $stmt->bind_param("i", $id_batal);
    if ($stmt->execute()) {
        $mesej = "<div class='alert alert-warning'>Tempahan #$id_batal telah dibatalkan!</div>";
    }
    $stmt->close();
}

$sql_latest = "SELECT MAX(id) as max_id FROM tempahan";
$res_latest = $conn->query($sql_latest);
$row_latest = $res_latest->fetch_assoc();
$current_latest_id = $row_latest['max_id'] ? intval($row_latest['max_id']) : 0;

$sql = "SELECT * FROM tempahan WHERE (status = 'pending' OR status IS NULL OR status = '') ORDER BY created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ride Booking</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background-color: #f4f7f6; color: #333; padding: 20px 15px; }
        .header { max-width: 1100px; margin: 0 auto 20px; display: flex; justify-content: space-between; align-items: center; background: #1e3c72; color: white; padding: 20px 25px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 20px; font-weight: 600; }
        .btn-refresh { background: #f39c12; color: white; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-weight: 500; transition: 0.3s; white-space: nowrap; }
        .btn-refresh:hover { background: #e67e22; }
        .container { max-width: 1100px; margin: 0 auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { padding: 15px 12px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px; vertical-align: middle; }
        table th { background-color: #f8f9fa; color: #1e3c72; font-weight: 600; text-transform: uppercase; font-size: 13px; }
        table tr:hover { background-color: #f1f5f9; }
        .badge { background: #e3f2fd; color: #1565c0; padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
        .btn-done { background: #28a745; color: white; text-decoration: none; padding: 8px 12px; border-radius: 5px; font-size: 12px; font-weight: 500; transition: 0.2s; display: inline-block; margin-bottom: 4px; }
        .btn-done:hover { background: #218838; }
        .btn-cancel { background: #dc3545; color: white; text-decoration: none; padding: 8px 12px; border-radius: 5px; font-size: 12px; font-weight: 500; transition: 0.2s; display: inline-block; }
        .btn-cancel:hover { background: #c82333; }
        .btn-ws { display: inline-flex; align-items: center; gap: 5px; background: #25D366; color: white; padding: 8px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13px; transition: 0.2s; }
        .btn-ws:hover { background: #1ebc57; }
        .btn-ws-reject { background: #e67e22; color: white; text-decoration: none; padding: 8px 12px; border-radius: 5px; font-size: 12px; font-weight: 500; transition: 0.2s; display: inline-block; margin-bottom: 6px; }
        .btn-ws-reject:hover { background: #d35400; }
        .empty-state { text-align: center; padding: 40px 0; color: #777; }
        .alert { max-width: 1100px; margin: 0 auto 15px; padding: 15px; border-radius: 8px; font-size: 14px; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; }
        .custom-toast { background: #1e3c72; color: white; padding: 16px 20px; border-radius: 10px; box-shadow: 0 8px 20px rgba(0,0,0,0.25); display: flex; align-items: center; gap: 15px; border-left: 6px solid #25D366; transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); min-width: 280px; }
        .custom-toast.show { transform: translateX(0); }
        .toast-icon { font-size: 28px; }
        .toast-text h4 { margin: 0; font-size: 15px; color: #25D366; }
        .toast-text p { margin: 3px 0 0; font-size: 13px; color: #efefef; }

        @media screen and (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; padding: 15px; }
            .header div { width: 100%; justify-content: center; }
            .btn-refresh, .btn-cancel, .btn-done { width: 100%; text-align: center; }
            .container { padding: 15px 10px; background: transparent; box-shadow: none; }
            table thead { display: none; }
            table, table tbody, table tr, table td { display: block; width: 100%; }
            table tr { background: white; margin-bottom: 20px; border: 1px solid #e1e8ed; border-radius: 12px; padding: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            table td { text-align: right; padding: 12px 0; border-bottom: 1px dashed #eee; display: flex; justify-content: space-between; align-items: center; }
            table td:last-child { border-bottom: none; flex-direction: column; gap: 8px; padding-top: 15px; }
            table td::before { content: attr(data-label); float: left; font-weight: 600; color: #555; font-size: 13px; }
            .btn-ws, .btn-ws-reject, .btn-done, .btn-cancel { width: 100%; text-align: center; justify-content: center; margin-bottom: 0; padding: 10px; font-size: 14px; }
            .toast-container { left: 15px; right: 15px; top: 15px; }
            .custom-toast { min-width: 100%; }
        }
    </style>
</head>
<body>

    <?= $mesej; ?>

    <div class="header">
        <h1>🚕 Admin Panel - Senarai Tempahan Aktif</h1>
        <div style="display: flex; gap: 10px; align-items: center;">
            <?php if ($current_status === 'ON'): ?>
                <a href="?toggle_status=1" class="btn-cancel" style="padding: 10px 16px; font-size:14px;" onclick="return confirm('Pasti nak SHUTDOWN (tutup) borang tempahan sekarang?');">🔴 SHUTDOWN SISTEM</a>
            <?php else: ?>
                <a href="?toggle_status=1" class="btn-done" style="padding: 10px 16px; font-size:14px;" onclick="return confirm('Buka semula borang tempahan kepada pelajar?');">🟢 BUKA SISTEM (ON DUTY)</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-refresh">🔄 Refresh</a>
        </div>
    </div>

    <div class="container">
        <table>
            <thead>
                <tr>
                    <th>#ID</th>
                    <th>Nama Pelanggan</th>
                    <th>No. Telefon (WhatsApp)</th>
                    <th>Pick-up</th>
                    <th>Drop-off</th>
                    <th>Tarikh & Masa</th>
                    <th>Waktu Ditempah</th>
                    <th>Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                            $no_tel = preg_replace('/[^0-9]/', '', $row['telefon']);
                            if (substr($no_tel, 0, 1) === '0') {
                                $no_tel = '60' . substr($no_tel, 1);
                            } elseif (substr($no_tel, 0, 2) !== '60') {
                                $no_tel = '60' . $no_tel;
                            }
                            
                            $tarikh_format = date("d/m/Y", strtotime($row['tarikh']));
                            $masa_format = date("h:i A", strtotime($row['masa']));

                            $ayat_ws = "Hi " . $row['nama'] . "! Please confirm your ride booking from *" . $row['pickup'] . "* to *" . $row['dropoff'] . "* on *" . $tarikh_format . "* at *" . $masa_format . "*. Please reply 'YES' to confirm your booking. Thank you!";
                            $link_ws = "https://wa.me/" . $no_tel . "?text=" . urlencode($ayat_ws);

                            $ayat_ws_reject = "Dear " . $row['nama'] . ",\n\nI kindly request that you rearrange or cancel your ride booking from *" . $row['pickup'] . "* to *" . $row['dropoff'] . "* on *" . $tarikh_format . " at " . $masa_format . "*. Unfortunately, I am unable to accept this ride due to a scheduling conflict. Please accept my sincere apologies for any inconvenience this may cause.";
                            $link_ws_reject = "https://wa.me/" . $no_tel . "?text=" . urlencode($ayat_ws_reject);
                        ?>
                        <tr>
                            <td data-label="#ID"><strong>#<?= $row['id']; ?></strong></td>
                            <td data-label="Nama Pelanggan"><?= htmlspecialchars($row['nama']); ?></td>
                            <td data-label="No. WhatsApp">
                                <a href="<?= $link_ws; ?>" target="_blank" class="btn-ws">
                                    💬 <?= htmlspecialchars($row['telefon']); ?>
                                </a>
                            </td>
                            <td data-label="Pick-up"><span class="badge">📍 <?= htmlspecialchars($row['pickup']); ?></span></td>
                            <td data-label="Drop-off"><span class="badge" style="background: #fce4ec; color: #c2185b;">🏁 <?= htmlspecialchars($row['dropoff']); ?></span></td>
                            <td data-label="Tarikh & Masa"><strong><?= $tarikh_format; ?></strong><br><small><?= $masa_format; ?></small></td>
                            <td data-label="Ditempah Pada"><small style="color: #666;"><?= date("d/m/Y, h:i A", strtotime($row['created_at'])); ?></small></td>
                            <td data-label="Tindakan">
                                <a href="<?= $link_ws_reject; ?>" target="_blank" class="btn-ws-reject">⚠️ Minta Rearrange/Cancel</a>
                                <a href="?selesai=<?= $row['id']; ?>" class="btn-done" onclick="return confirm('Adakah ride ini sudah selesai?');">✔ Selesai</a>
                                <a href="?batal=<?= $row['id']; ?>" class="btn-cancel" onclick="return confirm('Pasti mahu membatalkan tempahan ini?');">❌ Batal</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <h3>Belum ada tempahan baru 😴</h3>
                                <p>Semua ride dah setel atau belum ada pelanggan masuk.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="toast-container">
        <div id="liveToast" class="custom-toast">
            <div class="toast-icon">🚕</div>
            <div class="toast-text">
                <h4>Tempahan Baru Masuk!</h4>
                <p id="toastBody">Sistem sedang memuat turun data...</p>
            </div>
        </div>
    </div>

    <script>
        let latestBookingId = <?= $current_latest_id; ?>;
        let audioUnlocked = false;

        document.addEventListener("DOMContentLoaded", function() {
            if ("Notification" in window && Notification.permission !== "granted") {
                Notification.requestPermission();
            }
            document.body.addEventListener('click', function() {
                if (!audioUnlocked) {
                    try {
                        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        audioCtx.resume();
                        audioUnlocked = true;
                    } catch(e) {}
                }
            }, { once: true });
        });

        function playNotificationSound() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gainNode = audioCtx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(587.33, audioCtx.currentTime); 
                oscillator.frequency.exponentialRampToValueAtTime(880, audioCtx.currentTime + 0.1); 
                gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.6);
                oscillator.connect(gainNode);
                gainNode.connect(audioCtx.destination);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.6);
            } catch(e) {}
        }

        function showVisualToast(nama, pickup, dropoff) {
            const toast = document.getElementById('liveToast');
            document.getElementById('toastBody').innerHTML = `<strong>${nama}</strong><br>📍 ${pickup} ➔ 🏁 ${dropoff}`;
            toast.classList.add('show');
        }

        setInterval(function() {
            fetch('check_new.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === "success" && data.latest_id > latestBookingId) {
                        latestBookingId = data.latest_id;
                        playNotificationSound();
                        showVisualToast(data.nama, data.pickup, data.dropoff);
                        if ("Notification" in window && Notification.permission === "granted") {
                            new Notification("🚕 Tempahan Ride Baru!", {
                                body: `Pelanggan: ${data.nama}\nDari: ${data.pickup} ➔ ${data.dropoff}`,
                                icon: "https://cdn-icons-png.flaticon.com/512/3097/3097180.png"
                            });
                        }
                        setTimeout(() => { location.reload(); }, 3000);
                    }
                })
                .catch(() => {});
        }, 5000);

        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('selesai') || url.searchParams.has('batal') || url.searchParams.has('toggle_status')) {
                url.searchParams.delete('selesai');
                url.searchParams.delete('batal');
                url.searchParams.delete('toggle_status');
                window.history.replaceState({path: url.href}, '', url.href);
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>