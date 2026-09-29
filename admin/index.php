<?php
require_once 'auth_guard.php';
require_once '../shared/config.php';

// =========================================================================
// SERVICE STATUS: AVAILABLE / LIMITED / OFF
// =========================================================================
$conn->query("CREATE TABLE IF NOT EXISTS system_status (id INT PRIMARY KEY, status VARCHAR(10))");
$res_stat = $conn->query("SELECT status FROM system_status WHERE id = 1");

if ($res_stat && $res_stat->num_rows > 0) {
    $row_stat = $res_stat->fetch_assoc();
    $current_status = strtoupper(trim($row_stat['status']));

    if ($current_status === 'ON') {
        $current_status = 'AVAILABLE';
    }

    if (!in_array($current_status, ['AVAILABLE', 'LIMITED', 'OFF'], true)) {
        $current_status = 'AVAILABLE';
    }
} else {
    $conn->query("INSERT INTO system_status (id, status) VALUES (1, 'AVAILABLE')");
    $current_status = 'AVAILABLE';
}

if (isset($_GET['set_service_status'])) {
    $new_status = strtoupper(trim($_GET['set_service_status']));

    if (in_array($new_status, ['AVAILABLE', 'LIMITED', 'OFF'], true)) {
        $stmt_status = $conn->prepare("UPDATE system_status SET status = ? WHERE id = 1");
        $stmt_status->bind_param("s", $new_status);
        $stmt_status->execute();
        $stmt_status->close();
    }

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$message = "";
$calendar_cancel_payload = null;
$gas_webhook_url = "https://script.google.com/macros/s/AKfycbxV4WE6JgF31nCjwyy2Nh1Tda85EntbDSBeR8WNMZEUzZthpWBpb3E5xJI23BCVLy761A/exec";
$bot_token = get_setting($conn, 'telegram_bot_token');

// =========================================================================
// ACTION: UNASSIGN DRIVER FROM BOOKING
// =========================================================================
if (isset($_GET['unassign'])) {
    $id_unassign = intval($_GET['unassign']);
    $stmt = $conn->prepare("UPDATE tempahan SET driver_id = NULL, status = 'pending', accepted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $id_unassign);
    if ($stmt->execute()) {
        $booking_ref = 'UTM-' . str_pad($id_unassign, 4, '0', STR_PAD_LEFT);
        $message = "
            <div class='alert alert-success'>
                <span class='alert-icon'>✓</span>
                <div><b>" . htmlspecialchars($booking_ref) . " unassigned.</b><br><small>Returned to the open pool for other drivers to claim.</small></div>
            </div>
        ";
    }
    $stmt->close();
}

// =========================================================================
// ACTION: FORCE ASSIGN DRIVER (SUPERADMIN DISPATCH)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_driver_action'])) {
    $b_id = intval($_POST['assign_booking_id'] ?? 0);
    $d_id = intval($_POST['assign_driver_id'] ?? 0);
    if ($b_id > 0 && $d_id > 0) {
        $stmt = $conn->prepare("UPDATE tempahan SET driver_id = ?, status = 'confirmed', accepted_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $d_id, $b_id);
        if ($stmt->execute()) {
            $booking_ref = 'UTM-' . str_pad($b_id, 4, '0', STR_PAD_LEFT);
            $message = "
                <div class='alert alert-success'>
                    <span class='alert-icon'>✓</span>
                    <div><b>" . htmlspecialchars($booking_ref) . " allocated to driver.</b><br><small>Booking status updated to Confirmed.</small></div>
                </div>
            ";
        }
        $stmt->close();
    }
}

// =========================================================================
// DRIVER NOTICE
// =========================================================================
$notice_active = false;
$notice_text = '';
$notice_updated_at = '';
$notice_table_ready = $conn->query("
    CREATE TABLE IF NOT EXISTS driver_notice (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

if ($notice_table_ready) {
    $conn->query("INSERT IGNORE INTO driver_notice (id, is_active, message) VALUES (1, 0, '')");

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notice_action'])) {
        $notice_action = $_POST['notice_action'];
        $notice_status = 'error';

        if ($notice_action === 'disable') {
            $stmt_notice = $conn->prepare("UPDATE driver_notice SET is_active = 0 WHERE id = 1");
            if ($stmt_notice && $stmt_notice->execute()) {
                $notice_status = 'disabled';
            }
            if ($stmt_notice) $stmt_notice->close();
        } elseif ($notice_action === 'save') {
            $submitted_notice = trim($_POST['notice_message'] ?? '');
            $submitted_notice = function_exists('mb_substr')
                ? mb_substr($submitted_notice, 0, 500, 'UTF-8')
                : substr($submitted_notice, 0, 500);
            $submitted_active = isset($_POST['notice_active']) ? 1 : 0;

            if ($submitted_notice === '') {
                $submitted_active = 0;
                $notice_status = 'empty';
            }

            $stmt_notice = $conn->prepare("UPDATE driver_notice SET is_active = ?, message = ? WHERE id = 1");
            if ($stmt_notice) {
                $stmt_notice->bind_param('is', $submitted_active, $submitted_notice);
                if ($stmt_notice->execute() && $notice_status !== 'empty') {
                    $notice_status = $submitted_active ? 'published' : 'saved';
                }
                $stmt_notice->close();
            }
        }

        header('Location: index.php?notice_status=' . urlencode($notice_status));
        exit;
    }

    $notice_result = $conn->query("SELECT is_active, message, updated_at FROM driver_notice WHERE id = 1 LIMIT 1");
    if ($notice_result && $notice_row = $notice_result->fetch_assoc()) {
        $notice_active = ((int) $notice_row['is_active'] === 1) && trim($notice_row['message']) !== '';
        $notice_text = $notice_row['message'];
        $notice_updated_at = $notice_row['updated_at'];
    }
}

if (isset($_GET['notice_status'])) {
    $notice_flash = [
        'published' => ["Admin Notice is live.", "Customers will see the popup when they open the booking page."],
        'saved'     => ["Notice draft saved.", "The popup remains hidden until you switch it on."],
        'disabled'  => ["Admin Notice turned off.", "The saved message is kept for your next update."],
        'empty'     => ["Notice was not published.", "Write a message before switching the popup on."],
        'error'     => ["Notice could not be updated.", "Please try again."]
    ];
    $notice_flash_key = $_GET['notice_status'];

    if (isset($notice_flash[$notice_flash_key])) {
        $notice_flash_type = in_array($notice_flash_key, ['published', 'saved', 'disabled'], true) ? 'alert-success' : 'alert-warning';
        $message = "
            <div class='alert " . $notice_flash_type . "'>
                <span class='alert-icon'>" . ($notice_flash_type === 'alert-success' ? '✓' : '!') . "</span>
                <div><b>" . htmlspecialchars($notice_flash[$notice_flash_key][0]) . "</b><br><small>" . htmlspecialchars($notice_flash[$notice_flash_key][1]) . "</small></div>
            </div>
        ";
    }
}

$notice_character_count = function_exists('mb_strlen') ? mb_strlen($notice_text, 'UTF-8') : strlen($notice_text);

// =========================================================================
// CONFIRM BOOKING
// =========================================================================
if (isset($_GET['confirm'])) {
    $id_confirm = intval($_GET['confirm']);
    $stmt = $conn->prepare("UPDATE tempahan SET status = 'confirmed' WHERE id = ? AND (status = 'pending' OR status IS NULL OR status = '')");
    $stmt->bind_param("i", $id_confirm);

    if ($stmt->execute()) {
        $booking_ref = 'UTM-' . str_pad($id_confirm, 4, '0', STR_PAD_LEFT);
        $message = "
            <div class='alert alert-success'>
                <span class='alert-icon'>✓</span>
                <div><b>" . htmlspecialchars($booking_ref) . " confirmed.</b><br><small>Customer can now download the official PDF receipt.</small></div>
            </div>
        ";
    }
    $stmt->close();
}

// =========================================================================
// DONE
// =========================================================================
if (isset($_GET['selesai'])) {
    $id_done = intval($_GET['selesai']);
    $stmt = $conn->prepare("UPDATE tempahan SET status = 'selesai', completed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id_done);

    if ($stmt->execute()) {
        $booking_ref = 'UTM-' . str_pad($id_done, 4, '0', STR_PAD_LEFT);
        $message = "
            <div class='alert alert-success'>
                <span class='alert-icon'>✓</span>
                <div><b>" . htmlspecialchars($booking_ref) . " completed.</b><br><small>The booking has been archived.</small></div>
            </div>
        ";
    }
    $stmt->close();
}

// =========================================================================
// CANCEL
// =========================================================================
if (isset($_GET['batal'])) {
    $id_cancel = intval($_GET['batal']);

    $lookup = $conn->prepare("SELECT id, nama, telefon, pickup, dropoff, tarikh, masa FROM tempahan WHERE id = ? LIMIT 1");
    $lookup->bind_param("i", $id_cancel);
    $lookup->execute();
    $booking_result = $lookup->get_result();
    $booking_to_cancel = $booking_result ? $booking_result->fetch_assoc() : null;
    $lookup->close();

    if ($booking_to_cancel) {
        $stmt = $conn->prepare("UPDATE tempahan SET status = 'batal' WHERE id = ?");
        $stmt->bind_param("i", $id_cancel);

        if ($stmt->execute()) {
            $booking_ref = 'UTM-' . str_pad($id_cancel, 4, '0', STR_PAD_LEFT);
            $calendar_cancel_payload = [
                'action'     => 'cancel',
                'booking_id' => $booking_ref,
                'nama'       => $booking_to_cancel['nama'],
                'telefon'    => $booking_to_cancel['telefon'],
                'pickup'     => $booking_to_cancel['pickup'],
                'dropoff'    => $booking_to_cancel['dropoff'],
                'tarikh'     => $booking_to_cancel['tarikh'],
                'masa'       => $booking_to_cancel['masa']
            ];

            $message = "
                <div class='alert alert-warning'>
                    <span class='alert-icon'>!</span>
                    <div>
                        <b>" . htmlspecialchars($booking_ref) . " cancelled.</b><br>
                        <small id='calendar-sync-status'>Sending the delete request to Google Calendar…</small>
                    </div>
                </div>
            ";
        }
        $stmt->close();
    }
}

// =========================================================================
// DRIVER FLEET QUERY (PANTAU SEMUA DRIVER)
// =========================================================================
$sql_fleet = "
    SELECT d.*, 
           COUNT(CASE WHEN t.status IN ('confirmed', 'in_progress') THEN 1 END) AS active_trips, 
           COUNT(CASE WHEN t.status = 'selesai' THEN 1 END) AS completed_trips,
           MAX(CASE WHEN t.status IN ('confirmed', 'in_progress') THEN t.id END) AS current_booking_id
    FROM drivers d 
    LEFT JOIN tempahan t ON d.id = t.driver_id 
    GROUP BY d.id 
    ORDER BY d.status ASC, d.nama ASC
";
$res_fleet = $conn->query($sql_fleet);
$drivers_fleet = [];
$total_drivers_count = 0;
$active_drivers_count = 0;
$busy_drivers_count = 0;

if ($res_fleet) {
    while ($dr = $res_fleet->fetch_assoc()) {
        $drivers_fleet[] = $dr;
        $total_drivers_count++;
        if ($dr['status'] === 'active') {
            $active_drivers_count++;
            if ($dr['active_trips'] > 0) {
                $busy_drivers_count++;
            }
        }
    }
}
$available_drivers_count = $active_drivers_count - $busy_drivers_count;

// =========================================================================
// LATEST ID FOR LIVE NOTIFICATION POLLING
// =========================================================================
$sql_latest = "SELECT MAX(id) AS max_id FROM tempahan";
$res_latest = $conn->query($sql_latest);
$row_latest = $res_latest ? $res_latest->fetch_assoc() : null;
$current_latest_id = ($row_latest && $row_latest['max_id']) ? intval($row_latest['max_id']) : 0;

// =========================================================================
// ACTIVE BOOKINGS = PENDING + CONFIRMED + IN_PROGRESS
// =========================================================================
$sql = "
    SELECT t.*, 
           d.nama AS driver_nama,
           d.no_plat AS driver_plate,
           d.no_telefon AS driver_telefon,
           d.model_kenderaan AS driver_car
    FROM tempahan t
    LEFT JOIN drivers d ON t.driver_id = d.id
    WHERE (
        t.status = 'pending'
        OR t.status = 'confirmed'
        OR t.status = 'in_progress'
        OR t.status IS NULL
        OR t.status = ''
    )
    ORDER BY t.created_at DESC
";

$result = $conn->query($sql);
$bookings = [];
$pending_count = 0;
$unclaimed_count = 0;
$in_progress_count = 0;
$confirmed_count = 0;
$today_count = 0;
$today = date('Y-m-d');

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $booking_status = strtolower(trim($row['status'] ?? ''));
        if ($booking_status === '') {
            $booking_status = 'pending';
        }
        $row['_status_normalized'] = $booking_status;
        $has_driver = !empty($row['driver_id']) && intval($row['driver_id']) > 0;
        $row['_has_driver'] = $has_driver;
        $bookings[] = $row;

        if ($booking_status === 'in_progress') {
            $in_progress_count++;
        } elseif ($booking_status === 'confirmed') {
            $confirmed_count++;
        } else {
            $pending_count++;
        }

        if ($booking_status === 'pending' && !$has_driver) {
            $unclaimed_count++;
        }

        if (!empty($row['tarikh']) && date('Y-m-d', strtotime($row['tarikh'])) === $today) {
            $today_count++;
        }
    }
}

$active_count = count($bookings);
$claimed_count = $in_progress_count + $confirmed_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111827">
    <title>Superadmin Operations Command — UTMKL Ride</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body>

<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-logo">🚕</div>
            <div>
                <strong>UTMKL RIDE</strong>
                <span>Superadmin Console</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="#dashboard" class="nav-item active">
                <span class="nav-icon">▦</span>
                <span>Operations</span>
            </a>
            <a href="#fleet-monitor" class="nav-item">
                <span class="nav-icon">👨‍✈️</span>
                <span>Fleet Monitor</span>
                <span class="nav-count" style="background:#0284c7;"><?= $active_drivers_count; ?></span>
            </a>
            <a href="#bookings" class="nav-item">
                <span class="nav-icon">◫</span>
                <span>Active Bookings</span>
                <span class="nav-count"><?= $active_count; ?></span>
            </a>
            <a href="#driver-notice" class="nav-item">
                <span class="nav-icon">✦</span>
                <span>Customer Notice</span>
                <?php if ($notice_active): ?>
                    <span class="nav-live">LIVE</span>
                <?php endif; ?>
            </a>
            <a href="drivers.php" class="nav-item">
                <span class="nav-icon">👨‍💼</span>
                <span>Manage Drivers</span>
            </a>
            <a href="settings.php" class="nav-item">
                <span class="nav-icon">⚙️</span>
                <span>Settings & Telegram</span>
            </a>
        </nav>

        <div class="service-panel">
            <div class="service-panel-head">
                <span>Service Status</span>
                <?php if ($current_status === 'AVAILABLE'): ?>
                    <span class="service-dot dot-green"></span>
                <?php elseif ($current_status === 'LIMITED'): ?>
                    <span class="service-dot dot-yellow"></span>
                <?php else: ?>
                    <span class="service-dot dot-red"></span>
                <?php endif; ?>
            </div>

            <div class="service-current <?= strtolower($current_status); ?>">
                <?php if ($current_status === 'AVAILABLE'): ?>
                    <strong>Available</strong>
                    <small>Customer bookings open</small>
                <?php elseif ($current_status === 'LIMITED'): ?>
                    <strong>Limited</strong>
                    <small>Limited availability</small>
                <?php else: ?>
                    <strong>Off Duty</strong>
                    <small>Bookings closed</small>
                <?php endif; ?>
            </div>

            <div class="service-buttons">
                <a href="?set_service_status=AVAILABLE"
                   class="service-btn available <?= $current_status === 'AVAILABLE' ? 'active' : ''; ?>"
                   onclick="return confirm('Set service to AVAILABLE and open customer bookings?');">
                    <span>●</span> Available
                </a>
                <a href="?set_service_status=LIMITED"
                   class="service-btn limited <?= $current_status === 'LIMITED' ? 'active' : ''; ?>"
                   onclick="return confirm('Set service to LIMITED availability?');">
                    <span>●</span> Limited
                </a>
                <a href="?set_service_status=OFF"
                   class="service-btn off <?= $current_status === 'OFF' ? 'active' : ''; ?>"
                   onclick="return confirm('Set service to OFF DUTY and close new bookings?');">
                    <span>●</span> Off Duty
                </a>
            </div>
        </div>

        <div class="sidebar-footer">
            <span class="pulse"></span>
            Live monitor active
            &nbsp;|&nbsp;
            <a href="logout.php" style="color:#f87171;text-decoration:none;font-size:11px;" onclick="return confirm('Log out from superadmin?');">🚪 Logout</a>
        </div>
    </aside>

    <main class="main-content" id="dashboard">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" type="button" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
                <div>
                    <p class="eyebrow">SUPERADMIN OPERATIONS COMMAND</p>
                    <h1>Ride Dispatch & Fleet Monitor</h1>
                    <p class="subtitle">Monitor active drivers, real-time trip claims, and dispatch queues.</p>
                </div>
            </div>

            <div class="topbar-actions">
                <div class="top-status <?= strtolower($current_status); ?>">
                    <span></span>
                    <?= $current_status === 'OFF' ? 'Off Duty' : ucfirst(strtolower($current_status)); ?>
                </div>
                <div class="refresh-info">
                    <span>Last updated</span>
                    <strong id="lastRefresh">Just now</strong>
                </div>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-refresh">↻ Refresh</a>
            </div>
        </header>

        <?= $message; ?>

        <!-- METRICS ROW -->
        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-icon blue">◫</div>
                <div>
                    <span>Active Bookings</span>
                    <strong><?= $active_count; ?></strong>
                    <small>Open in system</small>
                </div>
            </article>

            <article class="stat-card" style="<?= $unclaimed_count > 0 ? 'border: 2px solid #f59e0b; background: #fffbeb;' : ''; ?>">
                <div class="stat-icon amber">⚠️</div>
                <div>
                    <span>Unclaimed Rides</span>
                    <strong style="<?= $unclaimed_count > 0 ? 'color: #b45309;' : ''; ?>"><?= $unclaimed_count; ?></strong>
                    <small><?= $unclaimed_count > 0 ? 'Needs driver allocation' : 'All rides claimed'; ?></small>
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-icon green">🚀</div>
                <div>
                    <span>In Progress</span>
                    <strong><?= $in_progress_count; ?></strong>
                    <small>Currently on the road</small>
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-icon violet">👨‍✈️</div>
                <div>
                    <span>Active Drivers</span>
                    <strong><?= $active_drivers_count; ?></strong>
                    <small><?= $available_drivers_count; ?> free &bull; <?= $busy_drivers_count; ?> busy</small>
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-icon" style="background:#f1f5f9;color:#334155;">📅</div>
                <div>
                    <span>Today's Rides</span>
                    <strong><?= $today_count; ?></strong>
                    <small>Scheduled for today</small>
                </div>
            </article>
        </section>

        <!-- SECTION: DRIVER FLEET LIVE MONITOR -->
        <section class="fleet-section" id="fleet-monitor">
            <div class="fleet-head">
                <div>
                    <p class="eyebrow" style="color:var(--blue);font-weight:700;">LIVE DRIVER TRACKING</p>
                    <h2 style="font-size:20px;margin-top:2px;">👨‍✈️ Driver Fleet Live Monitor</h2>
                    <p style="font-size:13px;color:var(--muted);margin-top:2px;">Track driver assignments, real-time availability, and Telegram notifications.</p>
                </div>
                <div>
                    <a href="drivers.php" class="btn-fleet-action" style="padding:8px 14px;font-size:12px;background:var(--blue);color:#fff;border:none;">
                        + Manage / Add Driver
                    </a>
                </div>
            </div>

            <?php if (empty($drivers_fleet)): ?>
                <div style="padding:30px;text-align:center;color:var(--muted);background:var(--surface-soft);border-radius:12px;">
                    No registered drivers in the system. Click "+ Manage / Add Driver" to onboard your first driver.
                </div>
            <?php else: ?>
                <div class="fleet-grid">
                    <?php foreach ($drivers_fleet as $df): ?>
                        <?php
                            $df_clean_phone = clean_phone_my($df['no_telefon']);
                            $is_on_trip = ($df['active_trips'] > 0);
                            $df_booking_ref = $df['current_booking_id'] ? 'UTM-' . str_pad($df['current_booking_id'], 4, '0', STR_PAD_LEFT) : '';
                        ?>
                        <div class="fleet-card">
                            <div class="fleet-card-top">
                                <div>
                                    <div class="fleet-driver-name"><?= htmlspecialchars($df['nama']); ?></div>
                                    <div class="fleet-driver-user">@<?= htmlspecialchars($df['username']); ?></div>
                                </div>
                                <?php if ($df['status'] !== 'active'): ?>
                                    <span class="fleet-status-pill fleet-status-off">Inactive</span>
                                <?php elseif ($is_on_trip): ?>
                                    <span class="fleet-status-pill fleet-status-busy">
                                        ● On Trip <?= htmlspecialchars($df_booking_ref); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="fleet-status-pill fleet-status-free">
                                        ● Available (Free)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="fleet-vehicle">
                                <span>🚗</span>
                                <strong><?= htmlspecialchars($df['no_plat'] ?: 'No Plate'); ?></strong>
                                <span style="color:var(--muted);font-size:12px;">(<?= htmlspecialchars($df['model_kenderaan'] ?: 'N/A'); ?>)</span>
                            </div>

                            <div class="fleet-meta-row">
                                <span>📞 <?= htmlspecialchars($df['no_telefon'] ?: '—'); ?></span>
                                <span><b><?= intval($df['completed_trips']); ?></b> trips done</span>
                            </div>

                            <div class="fleet-card-actions">
                                <?php if ($df_clean_phone): ?>
                                    <a href="https://api.whatsapp.com/send?phone=<?= $df_clean_phone; ?>" target="_blank" rel="noopener" class="btn-fleet-action wa">
                                        💬 WhatsApp
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($df['telegram_chat_id'])): ?>
                                    <button type="button" class="btn-fleet-action ping" onclick="pingDriverTg('<?= htmlspecialchars($df['telegram_chat_id'], ENT_QUOTES); ?>', '<?= htmlspecialchars($df['nama'], ENT_QUOTES); ?>')">
                                        🔔 Ping TG
                                    </button>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;align-self:center;">TG not connected</span>
                                <?php endif; ?>

                                <?php if ($is_on_trip && $df['current_booking_id']): ?>
                                    <a href="#row-<?= $df['current_booking_id']; ?>" class="btn-fleet-action" style="color:#b45309;border-color:#fde68a;background:#fef3c7;">
                                        View Ride &rarr;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- SECTION: CUSTOMER ANNOUNCEMENT NOTICE -->
        <section class="notice-panel" id="driver-notice">
            <div class="notice-panel-head">
                <div class="notice-title-wrap">
                    <span class="notice-title-icon">✦</span>
                    <div>
                        <p class="eyebrow">CUSTOMER ANNOUNCEMENT</p>
                        <h2>Customer Notice Popup</h2>
                        <p>Publish a live alert popup on the booking page for all passengers.</p>
                    </div>
                </div>

                <span class="notice-state <?= $notice_active ? 'live' : 'hidden'; ?>">
                    <span></span>
                    <?= $notice_active ? 'Popup Live' : 'Popup Hidden'; ?>
                </span>
            </div>

            <form method="post" class="notice-form">
                <div class="notice-field">
                    <label for="noticeMessage">Message for Passengers</label>
                    <textarea
                        id="noticeMessage"
                        name="notice_message"
                        maxlength="500"
                        rows="3"
                        placeholder="Example: Peak hours expect 10 mins delay. Safe travels!"
                        aria-describedby="noticeHelp"
                        required
                    ><?= htmlspecialchars($notice_text, ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <div class="notice-field-meta" id="noticeHelp">
                        <span>Keep it clear and helpful.</span>
                        <span><strong id="noticeCharacterCount"><?= $notice_character_count; ?></strong>/500</span>
                    </div>
                </div>

                <div class="notice-control-row">
                    <label class="notice-toggle">
                        <input type="checkbox" name="notice_active" value="1" <?= $notice_active ? 'checked' : ''; ?>>
                        <span class="notice-toggle-track" aria-hidden="true"><span></span></span>
                        <span class="notice-toggle-copy">
                            <strong>Display popup on passenger booking page</strong>
                            <small>Switch off to save as a hidden draft.</small>
                        </span>
                    </label>

                    <div class="notice-actions">
                        <?php if ($notice_active): ?>
                            <button type="submit" name="notice_action" value="disable" class="notice-btn secondary" formnovalidate>
                                Turn Off
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="notice_action" value="save" class="notice-btn primary">
                            Save Notice
                        </button>
                    </div>
                </div>

                <p class="notice-last-update">
                    Last updated:
                    <strong><?= $notice_updated_at ? date('d M Y, h:i A', strtotime($notice_updated_at)) : 'Not yet'; ?></strong>
                </p>
            </form>
        </section>

        <!-- SECTION: BOOKINGS DISPATCH QUEUE -->
        <section class="booking-panel" id="bookings">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">DISPATCH QUEUE</p>
                    <h2>Active Bookings</h2>
                    <p>Track ride assignments, set fares, and manage passenger rides.</p>
                </div>
                <div class="panel-count"><span id="visibleCount"><?= $active_count; ?></span> shown</div>
            </div>

            <!-- FILTER TABS -->
            <div class="filter-tabs">
                <button type="button" class="filter-tab active" data-tab="all" onclick="setFilterTab('all', this)">
                    All Active <span class="tab-badge"><?= $active_count; ?></span>
                </button>
                <button type="button" class="filter-tab" data-tab="unclaimed" onclick="setFilterTab('unclaimed', this)">
                    ⚠️ Unclaimed <span class="tab-badge" style="background:#fef3c7;color:#92400e;"><?= $unclaimed_count; ?></span>
                </button>
                <button type="button" class="filter-tab" data-tab="inprogress" onclick="setFilterTab('inprogress', this)">
                    🚀 In Progress <span class="tab-badge"><?= $in_progress_count; ?></span>
                </button>
                <button type="button" class="filter-tab" data-tab="confirmed" onclick="setFilterTab('confirmed', this)">
                    ✓ Confirmed <span class="tab-badge"><?= $confirmed_count; ?></span>
                </button>
            </div>

            <div class="toolbar">
                <label class="search-box">
                    <span>⌕</span>
                    <input type="search" id="bookingSearch" placeholder="Search ID, customer, phone, driver or route..." autocomplete="off">
                </label>
            </div>

            <div class="table-wrap">
                <table class="booking-table">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Route</th>
                            <th>Schedule</th>
                            <th>Assigned Driver</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bookingTableBody">
                    <?php if ($active_count > 0): ?>
                        <?php foreach ($bookings as $row): ?>
                            <?php
                            $booking_reference = 'UTM-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT);
                            $booking_status = $row['_status_normalized'];
                            $has_driver = $row['_has_driver'];

                            $phone_no = clean_phone_my($row['telefon']);

                            $date_format = date('d/m/Y', strtotime($row['tarikh']));
                            $time_format = date('h:i A', strtotime($row['masa']));
                            $created_format = !empty($row['created_at'])
                                ? date('d/m/Y, h:i A', strtotime($row['created_at']))
                                : 'N/A';

                            $msg_ws_reject =
                                "Dear " . $row['nama'] . ",\n\n" .
                                "Regarding your booking *" . $booking_reference . "* from *" . $row['pickup'] . "* to *" . $row['dropoff'] . "* on *" . $date_format . " at " . $time_format . "*, unfortunately I am unable to accept this ride due to a scheduling conflict.\n\n" .
                                "Please rearrange or cancel your booking. I sincerely apologise for the inconvenience.";

                            $link_ws_reject = 'https://api.whatsapp.com/send?phone=' . $phone_no . '&text=' . urlencode($msg_ws_reject);

                            $driver_name_search = $has_driver ? $row['driver_nama'] . ' ' . $row['driver_plate'] : 'unclaimed';
                            $search_blob = strtolower(
                                $booking_reference . ' ' .
                                $row['nama'] . ' ' .
                                $row['telefon'] . ' ' .
                                $row['pickup'] . ' ' .
                                $row['dropoff'] . ' ' .
                                $driver_name_search
                            );

                            $tab_category = 'confirmed';
                            if ($booking_status === 'in_progress') {
                                $tab_category = 'inprogress';
                            } elseif ($booking_status === 'pending' && !$has_driver) {
                                $tab_category = 'unclaimed';
                            } elseif ($booking_status === 'pending' && $has_driver) {
                                $tab_category = 'confirmed';
                            }
                            ?>

                            <tr class="booking-row"
                                id="row-<?= $row['id']; ?>"
                                data-status="<?= htmlspecialchars($booking_status); ?>"
                                data-tab-category="<?= htmlspecialchars($tab_category); ?>"
                                data-search="<?= htmlspecialchars($search_blob, ENT_QUOTES, 'UTF-8'); ?>">

                                <td data-label="Booking">
                                    <div class="booking-id-line">
                                        <span class="booking-ref"><?= htmlspecialchars($booking_reference); ?></span>
                                        <?php if ($booking_status === 'in_progress'): ?>
                                            <span class="status-badge" style="background:#fef3c7;color:#92400e;"><i></i>In Progress</span>
                                        <?php elseif ($booking_status === 'confirmed'): ?>
                                            <span class="status-badge confirmed"><i></i>Confirmed</span>
                                        <?php else: ?>
                                            <span class="status-badge pending"><i></i>Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="muted">Booked <?= htmlspecialchars($created_format); ?></small>
                                </td>

                                <td data-label="Customer">
                                    <div class="customer-name"><?= htmlspecialchars($row['nama']); ?></div>
                                    <button type="button"
                                            class="phone-btn"
                                            data-booking-ref="<?= htmlspecialchars($booking_reference, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?= htmlspecialchars($row['nama'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-pickup="<?= htmlspecialchars($row['pickup'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-dropoff="<?= htmlspecialchars($row['dropoff'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-date="<?= htmlspecialchars($date_format, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-time="<?= htmlspecialchars($time_format, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?= htmlspecialchars($phone_no, ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="sendPriceWSFromButton(this)">
                                        <span>💬</span>
                                        <?= htmlspecialchars($row['telefon']); ?>
                                        <b>Send Fare</b>
                                    </button>
                                </td>

                                <td data-label="Route">
                                    <div class="route-box">
                                        <div class="route-point pickup">
                                            <span class="route-dot"></span>
                                            <div><small>Pick-up</small><strong><?= htmlspecialchars($row['pickup']); ?></strong></div>
                                        </div>
                                        <div class="route-line"></div>
                                        <div class="route-point dropoff">
                                            <span class="route-dot"></span>
                                            <div><small>Drop-off</small><strong><?= htmlspecialchars($row['dropoff']); ?></strong></div>
                                        </div>
                                    </div>
                                </td>

                                <td data-label="Schedule">
                                    <div class="schedule-date"><?= htmlspecialchars($date_format); ?></div>
                                    <div class="schedule-time">◷ <?= htmlspecialchars($time_format); ?></div>
                                </td>

                                <td data-label="Assigned Driver" class="driver-cell">
                                    <?php if ($has_driver): ?>
                                        <div class="driver-name-text">👨‍✈️ <?= htmlspecialchars($row['driver_nama']); ?></div>
                                        <div class="driver-plate-text"><?= htmlspecialchars($row['driver_plate'] ?: 'No Plate'); ?> (<?= htmlspecialchars($row['driver_car'] ?: 'N/A'); ?>)</div>
                                        <?php if (!empty($row['driver_telefon'])): ?>
                                            <?php $d_wa = clean_phone_my($row['driver_telefon']); ?>
                                            <div style="margin-top:2px;">
                                                <a href="https://api.whatsapp.com/send?phone=<?= $d_wa; ?>" target="_blank" rel="noopener" style="font-size:11px;color:#16a34a;text-decoration:none;font-weight:600;">
                                                    💬 WhatsApp Driver
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <a href="?unassign=<?= intval($row['id']); ?>"
                                               class="btn-unassign-tiny"
                                               onclick="return confirm('Remove driver from <?= htmlspecialchars($booking_reference); ?> and return to open job pool?');">
                                                ↺ Unassign Driver
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="driver-unassigned-badge">⚠️ Unclaimed</span>
                                        <form method="POST" style="margin-top:4px;display:flex;gap:4px;align-items:center;">
                                            <input type="hidden" name="assign_driver_action" value="1">
                                            <input type="hidden" name="assign_booking_id" value="<?= intval($row['id']); ?>">
                                            <select name="assign_driver_id" class="assign-select-inline" required>
                                                <option value="">Assign Driver...</option>
                                                <?php foreach ($drivers_fleet as $df): ?>
                                                    <?php if ($df['status'] === 'active'): ?>
                                                        <option value="<?= $df['id']; ?>">
                                                            <?= htmlspecialchars($df['nama']); ?> (<?= $df['active_trips'] > 0 ? 'Busy' : 'Free'; ?>)
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-fleet-action" style="padding:4px 8px;font-size:11px;background:var(--blue);color:#fff;border:none;">
                                                Set
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Actions">
                                    <div class="action-grid">
                                        <?php if ($booking_status !== 'confirmed' && $booking_status !== 'in_progress'): ?>
                                            <a href="?confirm=<?= intval($row['id']); ?>"
                                               class="action-btn primary"
                                               onclick="return confirm('Confirm <?= htmlspecialchars($booking_reference, ENT_QUOTES, 'UTF-8'); ?>? Customer will then be able to download the official PDF receipt.');">
                                                ✓ Confirm
                                            </a>
                                        <?php else: ?>
                                            <span class="action-btn confirmed-static">✓ Confirmed</span>
                                        <?php endif; ?>

                                        <a href="<?= htmlspecialchars($link_ws_reject, ENT_QUOTES, 'UTF-8'); ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="action-btn warning">
                                            ↻ Rearrange
                                        </a>

                                        <a href="?selesai=<?= intval($row['id']); ?>"
                                           class="action-btn success"
                                           onclick="return confirm('Mark <?= htmlspecialchars($booking_reference, ENT_QUOTES, 'UTF-8'); ?> as completed?');">
                                            ✓ Done
                                        </a>

                                        <a href="?batal=<?= intval($row['id']); ?>"
                                           class="action-btn danger"
                                           onclick="return confirm('Cancel <?= htmlspecialchars($booking_reference, ENT_QUOTES, 'UTF-8'); ?>? A delete request will also be sent to Google Calendar.');">
                                            × Cancel
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-icon">🚕</div>
                                    <h3>No active bookings</h3>
                                    <p>All rides are completed, cancelled or no customers have booked yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- AUDIO AND TOAST ALERT -->
<audio id="notifSound" preload="auto">
    <source src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" type="audio/mpeg">
</audio>

<div class="toast-container" id="toastContainer"></div>

<script>
let latestBookingId = <?= $current_latest_id; ?>;
const BOT_TOKEN = <?= json_encode($bot_token); ?>;
let currentTab = 'all';

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

function playNotificationSound() {
    const sound = document.getElementById('notifSound');
    if (sound) {
        sound.play().catch(() => {});
    }
}

function showVisualToast(nama, pickup, dropoff) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'custom-toast';
    toast.innerHTML = `
        <div class="toast-indicator"></div>
        <div class="toast-content">
            <strong>🚨 New Booking Received!</strong>
            <p>${nama} &bull; ${pickup} &rarr; ${dropoff}</p>
        </div>
    `;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 5000);
}

function cleanPhoneMY(phone) {
    if (!phone) return '';
    let digits = String(phone).replace(/\D/g, '');
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('600')) {
        digits = '60' + digits.substring(3);
    } else if (digits.startsWith('0')) {
        digits = '60' + digits.substring(1);
    } else if (!digits.startsWith('60')) {
        digits = '60' + digits;
    }
    return digits;
}

function pingDriverTg(chatId, driverName) {
    if (!BOT_TOKEN) {
        alert("Telegram Bot Token is not configured in Settings!");
        return;
    }
    const text = encodeURIComponent(`🔔 *UTMKL Ride Notification*\nHello ${driverName}! Superadmin test ping successful.`);
    const url = `https://api.telegram.org/bot${BOT_TOKEN}/sendMessage?chat_id=${chatId}&text=${text}&parse_mode=Markdown`;

    fetch(url, { mode: 'no-cors' })
        .then(() => alert(`Ping sent to ${driverName} (Chat ID: ${chatId}) via Telegram!`))
        .catch(err => alert(`Error sending ping: ${err}`));
}

function sendPriceWSFromButton(button) {
    sendPriceWS(
        button.dataset.bookingRef,
        button.dataset.name,
        button.dataset.pickup,
        button.dataset.dropoff,
        button.dataset.date,
        button.dataset.time,
        button.dataset.phone
    );
}

function sendPriceWS(bookingRef, name, pickup, dropoff, date, time, rawPhone) {
    const phoneNo = cleanPhoneMY(rawPhone);
    let price = prompt(
`Enter the price offer (RM)\n\nBooking: ${bookingRef}\nCustomer: ${name}\n\n📍 From:\n${pickup}\n\n🏁 To:\n${dropoff}`
    );

    if (price !== null && price.trim() !== '') {
        price = price.trim();

        const wsMsg =
`Hi ${name}! 👋

Your ride fare has been prepared.

🎫 *Booking ID: ${bookingRef}*

📍 *Pick-up:* ${pickup}
🏁 *Drop-off:* ${dropoff}
📅 *Date:* ${date}
🕐 *Time:* ${time}

💰 *Total Fare: RM ${price}*

Please reply *YES* to confirm your booking.

🔎 *Check Booking Status*
Use Booking ID *${bookingRef}* together with your registered phone number on the UTMKL RIDE booking status page.

Please keep this Booking ID for future reference.

Thank you! 🚗`;

        const wsLink = `https://api.whatsapp.com/send?phone=${phoneNo}&text=${encodeURIComponent(wsMsg)}`;
        window.open(wsLink, '_blank');
    } else {
        alert('Process cancelled. No WhatsApp message was sent.');
    }
}

// FILTER TABS & SEARCH
function setFilterTab(tabName, el) {
    currentTab = tabName;
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const query = document.getElementById('bookingSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.booking-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const searchBlob = row.dataset.search || '';
        const tabCategory = row.dataset.tabCategory || '';
        const status = row.dataset.status || '';

        let matchesTab = false;
        if (currentTab === 'all') {
            matchesTab = true;
        } else if (currentTab === 'unclaimed') {
            matchesTab = (tabCategory === 'unclaimed');
        } else if (currentTab === 'inprogress') {
            matchesTab = (status === 'in_progress');
        } else if (currentTab === 'confirmed') {
            matchesTab = (status === 'confirmed');
        }

        const matchesSearch = query === '' || searchBlob.includes(query);

        if (matchesTab && matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const visibleEl = document.getElementById('visibleCount');
    if (visibleEl) visibleEl.textContent = visibleCount;
}

document.getElementById('bookingSearch').addEventListener('input', applyFilters);

// DRIVER NOTICE CHAR COUNT
const noticeTextarea = document.getElementById('noticeMessage');
const noticeCount = document.getElementById('noticeCharacterCount');
if (noticeTextarea && noticeCount) {
    noticeTextarea.addEventListener('input', () => {
        noticeCount.textContent = noticeTextarea.value.length;
    });
}

// GOOGLE CALENDAR WEBHOOK CANCEL
<?php if ($calendar_cancel_payload !== null): ?>
const calendarCancelUrl = <?= json_encode($gas_webhook_url, JSON_UNESCAPED_SLASHES); ?>;
const calendarCancelData = <?= json_encode($calendar_cancel_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const calendarSyncStatus = document.getElementById('calendar-sync-status');

fetch(calendarCancelUrl, {
    method: 'POST',
    mode: 'no-cors',
    keepalive: true,
    headers: { 'Content-Type': 'text/plain' },
    body: JSON.stringify(calendarCancelData)
})
.then(() => {
    if (calendarSyncStatus) calendarSyncStatus.textContent = 'Google Calendar event deleted successfully.';
})
.catch(() => {
    if (calendarSyncStatus) calendarSyncStatus.textContent = 'Calendar sync request completed.';
});
<?php endif; ?>

// LIVE POLLING FOR NEW BOOKINGS
setInterval(() => {
    fetch('check_new.php', {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success' && data.latest_id > latestBookingId) {
            latestBookingId = data.latest_id;
            playNotificationSound();
            showVisualToast(data.nama, data.pickup, data.dropoff);

            if ('Notification' in window && Notification.permission === 'granted') {
                const bookingRef = 'UTM-' + String(data.latest_id).padStart(4, '0');
                new Notification('🚕 New Ride Booking!', {
                    body: `Booking: ${bookingRef}\nCustomer: ${data.nama}\n${data.pickup} → ${data.dropoff}`,
                    icon: 'https://cdn-icons-png.flaticon.com/512/3097/3097180.png'
                });
            }

            setTimeout(() => location.reload(), 3000);
        }
    })
    .catch(() => {});
}, 5000);

// CLEAN URL PARAMS
if (window.history.replaceState) {
    const url = new URL(window.location.href);
    const paramsToClean = ['confirm', 'selesai', 'batal', 'unassign', 'set_service_status', 'notice_status'];
    let changed = false;

    paramsToClean.forEach(p => {
        if (url.searchParams.has(p)) {
            url.searchParams.delete(p);
            changed = true;
        }
    });

    if (changed) {
        window.history.replaceState({ path: url.href }, '', url.href);
    }
}
</script>

</body>
</html>
<?php $conn->close(); ?>
