<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// =========================================================================
// 0. CHECK SERVICE STATUS
// AVAILABLE = booking open normally
// LIMITED   = booking open, but availability is limited
// OFF       = booking closed, but customer can still check schedule/status
// Old ON value is treated as AVAILABLE for backward compatibility.
// =========================================================================
$conn->query("CREATE TABLE IF NOT EXISTS system_status (id INT PRIMARY KEY, status VARCHAR(10))");
$res_stat = $conn->query("SELECT status FROM system_status WHERE id = 1");
$sys_status = 'AVAILABLE';

if ($res_stat && $res_stat->num_rows > 0) {
    $row_stat = $res_stat->fetch_assoc();
    $sys_status = strtoupper(trim($row_stat['status']));

    if ($sys_status === 'ON') {
        $sys_status = 'AVAILABLE';
    }

    if (!in_array($sys_status, ['AVAILABLE', 'LIMITED', 'OFF'], true)) {
        $sys_status = 'AVAILABLE';
    }
} else {
    $conn->query("INSERT INTO system_status (id, status) VALUES (1, 'AVAILABLE')");
}

// =========================================================================
// DRIVER NOTICE POPUP
// The admin controls the message and whether it appears on this page.
// =========================================================================
$driver_notice_active = false;
$driver_notice_text = '';
$driver_notice_table_ready = $conn->query("
    CREATE TABLE IF NOT EXISTS driver_notice (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

if ($driver_notice_table_ready) {
    $conn->query("INSERT IGNORE INTO driver_notice (id, is_active, message) VALUES (1, 0, '')");
    $driver_notice_result = $conn->query("
        SELECT is_active, message
        FROM driver_notice
        WHERE id = 1
        LIMIT 1
    ");

    if ($driver_notice_result && $driver_notice_row = $driver_notice_result->fetch_assoc()) {
        $driver_notice_text = trim($driver_notice_row['message']);
        $driver_notice_active = ((int) $driver_notice_row['is_active'] === 1)
            && $driver_notice_text !== '';
    }
}

$booking_open = ($sys_status !== 'OFF');
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $booking_open) {
    $name    = trim(strip_tags($_POST['name'] ?? ''));
    $phone   = trim(strip_tags($_POST['phone'] ?? ''));
    $pickup  = trim(strip_tags($_POST['pickup'] ?? ''));
    $dropoff = trim(strip_tags($_POST['dropoff'] ?? ''));
    $date    = $_POST['date'] ?? '';
    $time    = $_POST['time'] ?? '';

    $check_stmt = $conn->prepare("
        SELECT id FROM tempahan
        WHERE telefon = ?
        AND tarikh = ?
        AND masa = ?
        AND (status = 'pending' OR status = 'confirmed' OR status IS NULL OR status = '')
    ");
    $check_stmt->bind_param("sss", $phone, $date, $time);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        $message = "<div class='alert alert-danger'>⚠️ <b>Duplicate Order Detected!</b> You already have an active booking for this date and time.</div>";
        $check_stmt->close();
    } else {
        $check_stmt->close();

        $created_at = date("Y-m-d H:i:s");
        $status_baru = 'pending';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

        $stmt = $conn->prepare("
            INSERT INTO tempahan
            (nama, telefon, pickup, dropoff, tarikh, masa, created_at, status, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssss",
            $name, $phone, $pickup, $dropoff, $date, $time,
            $created_at, $status_baru, $ip_address
        );

        if ($stmt->execute()) {
            $booking_id = $stmt->insert_id;
            $booking_reference = 'UTM-' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);

            $date_formatted = date("d/m/Y", strtotime($date));
            $time_formatted = date("h:i A", strtotime($time));

            // =============================================================
            // TELEGRAM NOTIFICATION — Config dibaca dari jadual `settings`
            // =============================================================
            // Baca dari DB; fallback ke nilai asal jika belum migrate
            $botToken = $conn->query("SELECT value FROM settings WHERE key_name='telegram_bot_token' LIMIT 1");
            $botToken = ($botToken && $r = $botToken->fetch_assoc()) ? $r['value'] : '8601874885:AAGzZSB5Fs6HiRkcdDCDkxRBomApBNOAKcs';

            $chatIdAdmin = $conn->query("SELECT value FROM settings WHERE key_name='telegram_chat_id_admin' LIMIT 1");
            $chatIdAdmin = ($chatIdAdmin && $r = $chatIdAdmin->fetch_assoc()) ? $r['value'] : '1359073968';

            $chatIdDrivers = $conn->query("SELECT value FROM settings WHERE key_name='telegram_chat_id_drivers' LIMIT 1");
            $chatIdDrivers = ($chatIdDrivers && $r = $chatIdDrivers->fetch_assoc()) ? $r['value'] : '';

            // Mesej ke Admin
            $tg_msg  = "🚨 *NEW RIDE BOOKING RECEIVED!* 🚨\n\n";
            $tg_msg .= "🎫 *Booking ID:* " . $booking_reference . "\n";
            $tg_msg .= "👤 *Passenger:* " . $name . "\n";
            $tg_msg .= "📞 *Phone:* " . $phone . "\n";
            $tg_msg .= "📍 *Pick-up:* " . $pickup . "\n";
            $tg_msg .= "🏁 *Drop-off:* " . $dropoff . "\n";
            $tg_msg .= "📅 *Date/Time:* " . $date_formatted . " (" . $time_formatted . ")\n";
            $tg_msg .= "🌐 *IP Address:* " . $ip_address . "\n\n";
            $tg_msg .= "⚡ _Please check the Admin Panel now!_";

            $url_tg = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $chatIdAdmin . "&text=" . urlencode($tg_msg) . "&parse_mode=Markdown";
            $safe_url = json_encode($url_tg);

            // Mesej ke Drivers (Group & Semua Driver Aktif)
            $tg_driver_msg  = "🚗 *NEW RIDE JOB AVAILABLE!* 🚗\n\n";
            $tg_driver_msg .= "🎫 *Booking ID:* " . $booking_reference . "\n";
            $tg_driver_msg .= "📅 *Date:* " . $date_formatted . " (" . $time_formatted . ")\n";
            $tg_driver_msg .= "📍 *Pick-up:* " . $pickup . "\n";
            $tg_driver_msg .= "🏁 *Drop-off:* " . $dropoff . "\n";
            $tg_driver_msg .= "👤 *Passenger:* " . $name . "\n\n";
            $tg_driver_msg .= "⚡ _Log in to the Driver Portal now to claim this trip!_";

            // Kumpul semua chat ID penerima driver (Group + Semua Driver Aktif)
            $driver_recipients = [];
            if (!empty($chatIdDrivers)) {
                $driver_recipients[] = trim($chatIdDrivers);
            }

            // Dapatkan chat ID dari semua driver yang berstatus active
            $d_q = $conn->query("SELECT DISTINCT telegram_chat_id FROM drivers WHERE status = 'active' AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) != ''");
            if ($d_q) {
                while ($d_r = $d_q->fetch_assoc()) {
                    $t_id = trim($d_r['telegram_chat_id']);
                    if ($t_id !== '' && !in_array($t_id, $driver_recipients, true)) {
                        $driver_recipients[] = $t_id;
                    }
                }
            }

            $driver_tg_urls = [];
            foreach ($driver_recipients as $d_chat_id) {
                $driver_tg_urls[] = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $d_chat_id . "&text=" . urlencode($tg_driver_msg) . "&parse_mode=Markdown";
            }
            $safe_driver_urls = json_encode($driver_tg_urls);

            // =============================================================
            // SERVER-SIDE PARALLEL BROADCAST (cURL Multi)
            // Instant delivery on Localhost / VPS / cPanel
            // =============================================================
            if (function_exists('curl_multi_init')) {
                $all_tg_broadcast_urls = array_merge([$url_tg], $driver_tg_urls);
                if (!empty($all_tg_broadcast_urls)) {
                    $mh = curl_multi_init();
                    $curl_handles = [];
                    foreach ($all_tg_broadcast_urls as $tg_url_item) {
                        $ch = curl_init($tg_url_item);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_multi_add_handle($mh, $ch);
                        $curl_handles[] = $ch;
                    }
                    $running = null;
                    do {
                        curl_multi_exec($mh, $running);
                        curl_multi_select($mh, 0.05);
                    } while ($running > 0);
                    foreach ($curl_handles as $ch) {
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                }
            }

            // =============================================================
            // GOOGLE CALENDAR SETUP (cURL dibuang, diganti dengan JS)
            // =============================================================
            $gas_webhook_url = "https://script.google.com/macros/s/AKfycbxV4WE6JgF31nCjwyy2Nh1Tda85EntbDSBeR8WNMZEUzZthpWBpb3E5xJI23BCVLy761A/exec";

            $data_gcal = [
                "nama"       => $name,
                "telefon"    => $phone,
                "pickup"     => $pickup,
                "dropoff"    => $dropoff,
                "tarikh"     => $date,
                "masa"       => $time,
                "booking_id" => $booking_reference
            ];

            $gas_safe_url = json_encode($gas_webhook_url);
            $gas_safe_data = json_encode($data_gcal);

            // =============================================================
            // ONSITE ACKNOWLEDGEMENT ONLY — NO PDF WHILE PENDING
            // =============================================================
            $message = "
                <div class='alert alert-success'>
                    🎉 <b>Booking request received!</b><br>
                    Your request has been submitted successfully. Please wait for driver confirmation.
                </div>

                <div style='background:linear-gradient(135deg,#eef7ff,#f6efff);border:1px solid #dce5ff;border-radius:14px;padding:20px;margin-bottom:16px;text-align:left;'>
                    <div style='text-align:center;margin-bottom:18px;'>
                        <small style='color:#7783a4;font-size:11px;text-transform:uppercase;letter-spacing:1px;'>Booking Reference</small>
                        <div style='color:#1556e8;font-size:26px;font-weight:700;letter-spacing:1px;'>" . htmlspecialchars($booking_reference) . "</div>
                        <span style='display:inline-block;margin-top:7px;background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:20px;padding:5px 11px;font-size:11px;font-weight:600;'>🟡 PENDING CONFIRMATION</span>
                    </div>

                    <div style='font-size:13px;color:#263462;line-height:1.9;'>
                        <div><b>Passenger:</b> " . htmlspecialchars($name) . "</div>
                        <div><b>Phone:</b> " . htmlspecialchars($phone) . "</div>
                        <div><b>Pick-up:</b> " . htmlspecialchars($pickup) . "</div>
                        <div><b>Drop-off:</b> " . htmlspecialchars($dropoff) . "</div>
                        <div><b>Date:</b> " . $date_formatted . "</div>
                        <div><b>Time:</b> " . $time_formatted . "</div>
                    </div>

                    <p style='margin:15px 0 0;color:#68739a;font-size:11px;line-height:1.6;text-align:center;'>
                        This is only an acknowledgement that your request was received.<br>
                        A downloadable confirmation receipt will be available after the driver confirms your ride.
                    </p>
                </div>

                <a href='check_booking.php?id=" . $booking_id . "' class='btn' style='background:linear-gradient(90deg,#0787f5,#9d3df5);text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;'>
                    🔎 Check Booking Status
                </a>
            ";

            // InfinityFree Telegram & Google Calendar bypass (via JS fetch fallback)
            $message .= "<script>
                if (!localStorage.getItem('notified_{$booking_reference}')) {
                    // 1. Notifikasi Telegram ke Admin
                    const tgUrl = " . $safe_url . ";
                    fetch(tgUrl, { mode: 'no-cors' }).catch(() => { new Image().src = tgUrl; });

                    // 2. Notifikasi Telegram ke Semua Driver Aktif & Group
                    const driverTgUrls = " . $safe_driver_urls . ";
                    if (Array.isArray(driverTgUrls)) {
                        driverTgUrls.forEach(url => {
                            fetch(url, { mode: 'no-cors' }).catch(() => { new Image().src = url; });
                        });
                    }

                    // 3. Hantar Data ke Webhook Google Calendar
                    const gasUrl = " . $gas_safe_url . ";
                    const gasData = " . $gas_safe_data . ";
                    fetch(gasUrl, {
                        method: 'POST',
                        mode: 'no-cors',
                        headers: { 'Content-Type': 'text/plain' },
                        body: JSON.stringify(gasData)
                    }).catch(e => console.error('GCal Error:', e));

                    localStorage.setItem('notified_{$booking_reference}', 'true');
                }
            </script>";
            
        } else {
            if ($conn->errno === 1062) {
                $message = "<div class='alert alert-danger'>⚠️ <b>Duplicate Order!</b> You already booked an active ride at this exact date and time.</div>";
            } else {
                $message = "<div class='alert alert-danger'>⚠️ <b>Oops!</b> We couldn't save your booking right now. Please try again.</div>";
            }
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#146EF5">
    <title>UTMKL RIDE</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">

    <style>
        .btn-check-booking{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            margin:0 0 14px;
            color:#1556e8;
            border:1px solid #dbe2ef;
            background:rgba(255,255,255,.96);
            text-decoration:none;
            box-shadow:0 7px 18px rgba(41,65,145,.08)
        }
        .btn-check-booking:hover{background:#f7f9ff;border-color:#aab6ee}
    </style>

    <style>
        .service-status-card{margin:0 0 14px;padding:14px 16px;border-radius:13px;border:1px solid transparent;text-align:left}
        .service-status-card .service-top{display:flex;align-items:center;gap:9px;font-weight:700;font-size:13px}
        .service-status-card .service-desc{margin-top:4px;font-size:11px;line-height:1.55}
        .service-available{background:#eaf8ee;border-color:#c7ead1;color:#155724}
        .service-limited{background:#fff7dc;border-color:#ffe7a3;color:#7a5b00}
        .service-off{background:#fff0f2;border-color:#f2c3ca;color:#8b2332}
        .btn-check-booking{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 0 14px;color:#1556e8;border:1px solid #dbe2ef;background:rgba(255,255,255,.96);text-decoration:none;box-shadow:0 7px 18px rgba(41,65,145,.08)}
        .booking-closed-box{margin-top:14px;padding:18px;border-radius:13px;background:#f8fafc;border:1px solid #e4e9f2;text-align:center;color:#68739a;font-size:12px;line-height:1.65}
    </style>
</head>

<body>
<div class="page-shell">
    <div class="background-orb orb-one"></div>
    <div class="background-orb orb-two"></div>
    <div class="dot-pattern dots-left"></div>
    <div class="dot-pattern dots-right"></div>
    <div class="glow-line"></div>

    <header class="site-header">
        <a class="brand" href="./" aria-label="UTMKL Ride home">
            <span class="brand-icon"><i class="fa-solid fa-car-side"></i></span>
            <span>UTMKL RIDE</span>
        </a>

        <div class="trust-note">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Safe. Reliable. On Time.</span>
        </div>
    </header>

    <div class="car-decoration" aria-hidden="true">
        <svg viewBox="0 0 520 220" role="presentation">
            <defs>
                <linearGradient id="carBody" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#eaf7ff" stop-opacity="0.93"/>
                    <stop offset="55%" stop-color="#9bd9ff" stop-opacity="0.76"/>
                    <stop offset="100%" stop-color="#9a73ff" stop-opacity="0.68"/>
                </linearGradient>
                <linearGradient id="carGlass" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#1d72c9" stop-opacity="0.72"/>
                    <stop offset="100%" stop-color="#6d4ce7" stop-opacity="0.78"/>
                </linearGradient>
                <filter id="carGlow" x="-40%" y="-40%" width="180%" height="180%">
                    <feGaussianBlur stdDeviation="9" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>
            <ellipse cx="260" cy="186" rx="218" ry="24" fill="#67cfff" opacity="0.18"/>
            <path d="M74 150 C92 118 122 101 174 94 L231 48 C249 34 282 29 326 32 C357 34 382 45 402 64 L445 105 C469 111 487 124 493 146 L487 166 L78 166 Z" fill="url(#carBody)" stroke="#dff6ff" stroke-opacity="0.55" stroke-width="3"/>
            <path d="M207 91 L248 54 C259 45 279 42 309 43 C339 44 360 53 376 69 L409 101 Z" fill="url(#carGlass)" opacity="0.88"/>
            <path d="M274 49 L274 96" stroke="#d7efff" stroke-opacity="0.42" stroke-width="3"/>
            <path d="M390 108 C420 112 445 119 467 132" stroke="#ffffff" stroke-opacity="0.55" stroke-width="3" fill="none"/>
            <path d="M96 136 C138 127 180 123 232 121" stroke="#ffffff" stroke-opacity="0.38" stroke-width="3" fill="none"/>
            <rect x="431" y="126" width="42" height="10" rx="5" fill="#ff6bc7" opacity="0.9" filter="url(#carGlow)"/>
            <circle cx="161" cy="165" r="33" fill="#142c7a" opacity="0.75"/>
            <circle cx="161" cy="165" r="20" fill="#a9dfff" opacity="0.75"/>
            <circle cx="407" cy="165" r="33" fill="#142c7a" opacity="0.75"/>
            <circle cx="407" cy="165" r="20" fill="#a9dfff" opacity="0.75"/>
        </svg>
    </div>

    <main class="main-content">
        <section class="booking-card" aria-labelledby="booking-title">
            <div class="card-icon"><i class="fa-regular fa-calendar-days"></i></div>
            <h1 id="booking-title">Book Your Ride <span aria-hidden="true">🚗</span></h1>
            <p class="card-subtitle">Plan your trip in just a few steps</p>

            <?php if ($sys_status === 'AVAILABLE'): ?>
                <div class="service-status-card service-available">
                    <div class="service-top">🟢 AVAILABLE — Booking Open</div>
                    <div class="service-desc">Booking is currently open. Please check the schedule before submitting your request.</div>
                </div>
            <?php elseif ($sys_status === 'LIMITED'): ?>
                <div class="service-status-card service-limited">
                    <div class="service-top">🟡 LIMITED — Limited Availability</div>
                    <div class="service-desc">Only selected time slots may be available. Please check the schedule before booking.</div>
                </div>
            <?php else: ?>
                <div class="service-status-card service-off">
                    <div class="service-top">🔴 OFF DUTY — Booking Closed</div>
                    <div class="service-desc">New bookings are currently closed. You can still view the schedule or check an existing booking.</div>
                </div>
            <?php endif; ?>

            <?= $message; ?>

            <?php if (strpos($message, 'alert-success') === false): ?>

                <button type="button" class="btn btn-view-schedule" onclick="document.getElementById('calModal').style.display='flex'">
                    <i class="fa-regular fa-calendar-days"></i>
                    <span>View Full Schedule</span>
                </button>

                <a href="check_booking.php" class="btn btn-check-booking">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Check Booking Status</span>
                </a>

                <?php if ($booking_open): ?>

                <form
                    action=""
                    method="POST"
                    class="booking-form"
                    onsubmit="const btn=this.querySelector('button[type=submit]');btn.disabled=true;btn.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i><span>Booking... Please wait</span>';"
                >
                    <div class="input-group">
                        <label for="name">Full Name</label>
                        <div class="input-wrap">
                            <i class="fa-regular fa-user input-icon"></i>
                            <input id="name" type="text" name="name" placeholder="E.g., Ahmad Ali" required autocomplete="name">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="phone">Phone Number</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-phone input-icon"></i>
                            <input id="phone" type="tel" name="phone" placeholder="E.g., 0123456789" required autocomplete="tel" inputmode="tel">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="pickup">Pick-up Location</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-location-dot input-icon"></i>
                            <input id="pickup" type="text" name="pickup" placeholder="Where should we pick you up?" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="dropoff">Drop-off Location</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-location-arrow input-icon"></i>
                            <input id="dropoff" type="text" name="dropoff" placeholder="Where are you heading?" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="input-group">
                            <label for="date">Date</label>
                            <div class="input-wrap">
                                <i class="fa-regular fa-calendar input-icon"></i>
                                <input id="date" type="date" name="date" min="<?= date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="time">Time</label>
                            <div class="input-wrap">
                                <i class="fa-regular fa-clock input-icon"></i>
                                <input id="time" type="time" name="time" required>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-book">
                        <i class="fa-regular fa-circle-check"></i>
                        <span>Book Now</span>
                    </button>

                    <p class="privacy-note">
                        <i class="fa-solid fa-lock"></i>
                        Your information is used only for booking and service management.
                    </p>
                </form>

                <?php else: ?>

                    <div class="booking-closed-box">
                        🔒 <b>New booking is currently unavailable.</b><br>
                        Please check again later. Existing bookings can still be tracked using <b>Check Booking Status</b>.
                    </div>

                <?php endif; ?>

            <?php endif; ?>
        </section>
    </main>

    <section class="features" aria-label="Why choose UTMKL Ride">
        <article class="feature-item">
            <span class="feature-icon"><i class="fa-regular fa-clock"></i></span>
            <div>
                <h2>Punctual &amp; Reliable</h2>
                <p>Your time is our top priority.</p>
            </div>
        </article>

        <article class="feature-item">
            <span class="feature-icon"><i class="fa-solid fa-user-group"></i></span>
            <div>
                <h2>Campus-Friendly</h2>
                <p>Driven by trusted UTM community students.</p>
            </div>
        </article>

        <article class="feature-item">
            <span class="feature-icon"><i class="fa-regular fa-circle-check"></i></span>
            <div>
                <h2>Hassle-Free Booking</h2>
                <p>Easy, fast, and secure rides.</p>
            </div>
        </article>
    </section>
</div>

<?php if ($driver_notice_active): ?>
<div id="driverNotice" class="driver-notice-overlay" role="dialog" aria-modal="true" aria-labelledby="driver-notice-title">
    <div class="driver-notice-card">
        <button type="button" class="driver-notice-close" aria-label="Close admin notice" onclick="closeDriverNotice()">&times;</button>

        <span class="driver-notice-kicker"><i class="fa-solid fa-bullhorn"></i> NEW FROM ADMIN</span>
        <div class="driver-notice-heading">
            <span class="driver-notice-icon"><i class="fa-solid fa-bell"></i></span>
            <div>
                <h2 id="driver-notice-title">Admin Notice</h2>
                <p>Please read this latest update before booking your ride.</p>
            </div>
        </div>

        <div class="driver-notice-message"><?= nl2br(htmlspecialchars($driver_notice_text, ENT_QUOTES, 'UTF-8')); ?></div>

        <button type="button" class="driver-notice-confirm" onclick="closeDriverNotice()">
            Okay, got it
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Keep the modal outside .booking-card. The card's backdrop-filter creates a
     containing block for fixed descendants and previously pushed this modal
     to the right instead of covering the viewport. -->
<div id="calModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="schedule-title">
    <div class="modal-content">
        <button type="button" class="close-btn" aria-label="Close schedule" onclick="document.getElementById('calModal').style.display='none'">&times;</button>

        <div class="modal-heading">
            <span class="modal-icon"><i class="fa-regular fa-calendar-check"></i></span>
            <div>
                <h3 id="schedule-title">Availability Schedule</h3>
                <p>Please check the booked time blocks before making a reservation.</p>
            </div>
        </div>

        <div class="iframe-container">
            <iframe
                src="https://calendar.google.com/calendar/embed?height=600&wkst=1&ctz=Asia%2FKuala_Lumpur&showPrint=0&mode=AGENDA&title=UTMKL%20RIDE&showCalendars=0&src=NWNkMmE5OTM3ZTU0MjU2NmYyZmQwYTBlYmRhMGQ4MmJiYmI3NDE2YWUwNTAxN2NjNjZiNDc2ZWE0YzdhOTFjZEBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&src=ZW4ubWFsYXlzaWEjaG9saWRheUBncm91cC52LmNhbGVuZGFyLmdvb2dsZS5jb20&color=%23f4511e&color=%230b8043"
                frameborder="0"
                scrolling="no"
                title="UTMKL Ride availability schedule"
            ></iframe>
        </div>
    </div>
</div>

<script>
const calendarModal = document.getElementById('calModal');
const driverNotice = document.getElementById('driverNotice');

function closeDriverNotice() {
    if (driverNotice) {
        driverNotice.hidden = true;
    }
}

window.addEventListener('click', function(event) {
    if (driverNotice && event.target === driverNotice) {
        closeDriverNotice();
    }

    if (calendarModal && event.target === calendarModal) {
        calendarModal.style.display = 'none';
    }
});

window.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (driverNotice && !driverNotice.hidden) {
        closeDriverNotice();
        return;
    }

    if (calendarModal) {
        calendarModal.style.display = 'none';
    }
});

if (driverNotice) {
    const noticeConfirmButton = driverNotice.querySelector('.driver-notice-confirm');
    if (noticeConfirmButton) {
        noticeConfirmButton.focus();
    }
}
</script>

</body>
</html>

<?php $conn->close(); ?>
