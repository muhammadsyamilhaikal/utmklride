<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

$booking = null;
$message = "";
$booking_id_input = isset($_GET['id']) ? trim($_GET['id']) : '';
$phone_input = '';

function normalizePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (substr($phone, 0, 1) === '0') {
        $phone = '60' . substr($phone, 1);
    }

    return $phone;
}

function getBookingStatus($status) {
    $status = strtolower(trim((string)$status));

    switch ($status) {
        case 'confirmed':
            return [
                'class' => 'confirmed',
                'icon'  => '🟢',
                'title' => 'BOOKING CONFIRMED',
                'text'  => 'Your ride has been confirmed by the driver.'
            ];

        case 'selesai':
            return [
                'class' => 'completed',
                'icon'  => '✅',
                'title' => 'RIDE COMPLETED',
                'text'  => 'This ride has been completed. Thank you for riding with us!'
            ];

        case 'batal':
            return [
                'class' => 'cancelled',
                'icon'  => '🔴',
                'title' => 'BOOKING CANCELLED',
                'text'  => 'This booking has been cancelled.'
            ];

        case 'pending':
        case '':
        default:
            return [
                'class' => 'pending',
                'icon'  => '🟡',
                'title' => 'PENDING CONFIRMATION',
                'text'  => 'Your booking request has been received and is waiting for driver confirmation.'
            ];
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_rating'])) {
    $r_booking = intval($_POST['booking_id']);
    $r_rating = intval($_POST['rating']);
    
    $check_col = $conn->query("SHOW COLUMNS FROM tempahan LIKE 'rating'");
    if ($check_col && $check_col->num_rows == 0) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN rating INT DEFAULT NULL");
    }
    
    $stmt = $conn->prepare("UPDATE tempahan SET rating = ? WHERE id = ?");
    if($stmt) {
        $stmt->bind_param("ii", $r_rating, $r_booking);
        $stmt->execute();
        $stmt->close();
    }
    
    // Set variables so the page re-renders the same booking
    $_POST['phone'] = $_POST['phone'] ?? '';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $booking_id_input = trim($_POST['booking_id'] ?? '');
    $phone_input = trim($_POST['phone'] ?? '');

    $clean_booking_id = strtoupper($booking_id_input);
    $clean_booking_id = str_replace(['UTM-', 'UTM', '#'], '', $clean_booking_id);
    $clean_booking_id = trim($clean_booking_id);

    $clean_phone = normalizePhone($phone_input);

    if ($clean_booking_id === '' || !ctype_digit($clean_booking_id)) {
        $message = "<div class='status-alert error'>⚠️ Please enter a valid Booking ID.</div>";
    } elseif ($clean_phone === '') {
        $message = "<div class='status-alert error'>⚠️ Please enter your phone number.</div>";
    } else {
        $booking_id = intval($clean_booking_id);

        $stmt = $conn->prepare("
            SELECT t.*,
                   d.nama AS driver_nama, d.no_telefon AS driver_telefon,
                   d.model_kenderaan AS driver_car, d.no_plat AS driver_plate
            FROM tempahan t
            LEFT JOIN drivers d ON t.driver_id = d.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $db_phone = normalizePhone($row['telefon']);

            if ($db_phone === $clean_phone) {
                $booking = $row;
            } else {
                $message = "<div class='status-alert error'>❌ Booking not found. Please check your Booking ID and phone number.</div>";
            }
        } else {
            $message = "<div class='status-alert error'>❌ Booking not found. Please check your Booking ID and phone number.</div>";
        }

        $stmt->close();
    }
}

// Graceful fallback if drivers table doesn't exist yet (pre-migration)
if ($booking && !array_key_exists('driver_nama', $booking)) {
    $booking['driver_nama']    = null;
    $booking['driver_telefon'] = null;
    $booking['driver_car']     = null;
    $booking['driver_plate']   = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#146EF5">
    <title>Check Booking Status - UTMKL RIDE</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">

    <style>
        .status-page{position:relative;z-index:2;width:min(580px,100%);margin:0 auto;padding:30px 0}
        .status-card{width:100%;padding:30px 36px;background:rgba(255,255,255,.97);border:1px solid rgba(255,255,255,.72);border-radius:24px;box-shadow:0 28px 80px rgba(24,28,109,.24),0 6px 22px rgba(38,43,122,.12);backdrop-filter:blur(16px)}
        .status-icon{display:grid;place-items:center;width:54px;height:54px;margin:0 auto 12px;border-radius:15px;color:#fff;font-size:22px;background:linear-gradient(135deg,#0787f5,#9d3df5)}
        .status-card h1{text-align:center;color:#101f5c;font-size:1.75rem;margin-bottom:5px}
        .status-subtitle{text-align:center;color:#68739a;font-size:13px;margin-bottom:25px}
        .status-form-group{margin-bottom:15px}
        .status-form-group label{display:block;margin-bottom:6px;color:#18265f;font-size:12px;font-weight:600}
        .status-input-wrap{position:relative}
        .status-input-wrap i{position:absolute;top:50%;left:15px;transform:translateY(-50%);color:#8c98b8;font-size:14px}
        .status-input-wrap input{width:100%;height:46px;padding:0 14px 0 43px;border:1px solid #dbe2ef;border-radius:9px;outline:none;color:#263462;background:#fff;font:inherit;font-size:13px}
        .status-input-wrap input:focus{border-color:#6a69ee;box-shadow:0 0 0 4px rgba(80,96,238,.10)}
        .status-search-btn{width:100%;min-height:48px;margin-top:5px;border:0;border-radius:9px;cursor:pointer;color:#fff;font:inherit;font-size:14px;font-weight:600;background:linear-gradient(90deg,#0787f5,#9d3df5)}
        .status-alert{margin:20px 0;padding:13px 15px;border-radius:10px;text-align:center;font-size:12px;font-weight:500}
        .status-alert.error{color:#8b2332;border:1px solid #f2c3ca;background:#fff0f2}
        .booking-result{margin-top:25px;border:1px solid #e7eaf2;border-radius:16px;overflow:hidden;background:#fff}
        .booking-status-header{padding:23px 20px;text-align:center}
        .booking-status-header.pending{background:linear-gradient(135deg,#fff7dc,#fffdf3)}
        .booking-status-header.confirmed{background:linear-gradient(135deg,#e4f8e9,#f4fff7)}
        .booking-status-header.completed{background:linear-gradient(135deg,#e4f7ff,#f3fbff)}
        .booking-status-header.cancelled{background:linear-gradient(135deg,#ffe9ed,#fff7f8)}
        .status-big-icon{font-size:30px;margin-bottom:5px}
        .booking-status-header h2{margin:0 0 5px;color:#101f5c;font-size:17px}
        .booking-status-header p{margin:0;color:#68739a;font-size:12px}
        .booking-details{padding:22px}
        .booking-reference{text-align:center;margin-bottom:20px}
        .booking-reference small{display:block;margin-bottom:4px;color:#8490ad;font-size:10px;text-transform:uppercase;letter-spacing:.8px}
        .booking-reference strong{color:#1556e8;font-size:23px;letter-spacing:.5px}
        .booking-row{display:flex;justify-content:space-between;gap:20px;padding:11px 0;border-bottom:1px solid #eef1f6;font-size:12px}
        .booking-row:last-child{border-bottom:0}
        .booking-label{color:#7b86a3}
        .booking-value{max-width:62%;color:#17245a;text-align:right;font-weight:600}
        .pending-note{margin-top:18px;padding:13px;border-radius:10px;background:#fff8df;color:#856404;border:1px solid #ffeeba;font-size:11px;line-height:1.6;text-align:center}
        .result-actions{display:flex;gap:10px;margin-top:20px}
        .result-btn{display:flex;align-items:center;justify-content:center;gap:7px;flex:1;min-height:43px;padding:10px;border-radius:9px;text-decoration:none;border:0;cursor:pointer;font:inherit;font-size:12px;font-weight:600}
        .result-btn.primary{color:#fff;background:linear-gradient(90deg,#0787f5,#9d3df5)}
        .result-btn.secondary{color:#1556e8;border:1px solid #dbe2ef;background:#f8faff}
        .result-btn.pdf{color:#fff;background:#27ae60}
        .back-home{display:block;margin-top:22px;color:rgba(255,255,255,.9);text-align:center;text-decoration:none;font-size:12px;font-weight:500}

        /* Printable/Downloadable official receipt */
        #pdf-receipt{background:#fff;color:#333}
        .pdf-header{background:#1e3c72;color:#fff;padding:22px 25px;text-align:center}
        .pdf-header h3{margin:0;color:#fff;font-size:22px}
        .pdf-header small{color:#e0e0e0;font-size:12px}
        .pdf-body{padding:25px}
        .pdf-status{display:inline-block;background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:5px;padding:7px 12px;font-size:12px;font-weight:700;margin-bottom:20px}
        .pdf-table{width:100%;border-collapse:collapse;font-size:14px}
        .pdf-table td{padding:7px 0}
        .pdf-table td:first-child{color:#666;width:40%}
        .pdf-table td:last-child{font-weight:600;color:#111}
        .driver-box{margin-top:20px;background:#f8fafc;padding:15px;border-left:4px solid #f39c12;border-radius:4px}
        .driver-box h4{margin:0 0 10px;color:#1e3c72;font-size:15px}
        .driver-box p{margin:4px 0;font-size:14px}
        .pdf-footer{margin-top:24px;text-align:center;font-size:10px;color:#888;line-height:1.5}

        @media(max-width:640px){
            .status-page{padding:20px 0}
            .status-card{padding:25px 20px;border-radius:20px}
            .status-card h1{font-size:1.5rem}
            .booking-row{flex-direction:column;gap:4px}
            .booking-value{max-width:100%;text-align:left}
            .result-actions{flex-direction:column}
        }
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

    <main class="status-page">
        <section class="status-card">
            <div class="status-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <h1>Check Your Booking</h1>
            <p class="status-subtitle">Enter your Booking ID and phone number to view your ride status.</p>

            <form method="POST">
                <div class="status-form-group">
                    <label for="booking_id">Booking ID</label>
                    <div class="status-input-wrap">
                        <i class="fa-solid fa-hashtag"></i>
                        <input
                            id="booking_id"
                            name="booking_id"
                            type="text"
                            placeholder="Example: UTM-0184"
                            value="<?= htmlspecialchars($booking_id_input); ?>"
                            required
                        >
                    </div>
                </div>

                <div class="status-form-group">
                    <label for="phone">Phone Number</label>
                    <div class="status-input-wrap">
                        <i class="fa-solid fa-phone"></i>
                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            placeholder="Example: 0133670031"
                            value="<?= htmlspecialchars($phone_input); ?>"
                            required
                        >
                    </div>
                </div>

                <button class="status-search-btn" type="submit">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    Check Booking Status
                </button>
            </form>

            <?= $message; ?>

            <?php if ($booking): ?>
                <?php
                    $status_info = getBookingStatus($booking['status']);
                    $booking_status = strtolower(trim((string)$booking['status']));
                    if ($booking_status === '') $booking_status = 'pending';

                    $formatted_id = 'UTM-' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT);
                    $formatted_date = date("d/m/Y", strtotime($booking['tarikh']));
                    $formatted_time = date("h:i A", strtotime($booking['masa']));

                    // PDF becomes available only after confirmation.
                    // Keep it available after completion as well.
                    $pdf_allowed = in_array($booking_status, ['confirmed', 'selesai', 'in_progress'], true);

                    // Real driver info from DB join
                    $driver_nama    = $booking['driver_nama'] ?? null;
                    $driver_telefon = $booking['driver_telefon'] ?? null;
                    $driver_car     = $booking['driver_car'] ?? null;
                    $driver_plate   = $booking['driver_plate'] ?? null;
                    $has_driver     = !empty($driver_nama);
                ?>

                <div class="booking-result">
                    <div class="booking-status-header <?= $status_info['class']; ?>">
                        <div class="status-big-icon"><?= $status_info['icon']; ?></div>
                        <h2><?= $status_info['title']; ?></h2>
                        <p><?= $status_info['text']; ?></p>
                    </div>

                    <div class="booking-details">
                        <div class="booking-reference">
                            <small>Booking Reference</small>
                            <strong><?= htmlspecialchars($formatted_id); ?></strong>
                        </div>

                        <div class="booking-row">
                            <span class="booking-label">Passenger</span>
                            <span class="booking-value"><?= htmlspecialchars($booking['nama']); ?></span>
                        </div>

                        <div class="booking-row">
                            <span class="booking-label">Pick-up</span>
                            <span class="booking-value">📍 <?= htmlspecialchars($booking['pickup']); ?></span>
                        </div>

                        <div class="booking-row">
                            <span class="booking-label">Drop-off</span>
                            <span class="booking-value">🏁 <?= htmlspecialchars($booking['dropoff']); ?></span>
                        </div>

                        <div class="booking-row">
                            <span class="booking-label">Date</span>
                            <span class="booking-value"><?= $formatted_date; ?></span>
                        </div>

                        <div class="booking-row">
                            <span class="booking-label">Time</span>
                            <span class="booking-value"><?= $formatted_time; ?></span>
                        </div>

                        <?php if ($booking_status === 'pending'): ?>
                            <div class="pending-note">
                                ⏳ Your request is still waiting for driver confirmation.<br>
                                The official PDF confirmation receipt will appear here once the ride is confirmed.
                            </div>
                        <?php endif; ?>

                        <?php if ($booking_status === 'selesai' && $has_driver): ?>
                            <div class="rating-box">
                                <?php if (isset($booking['rating']) && $booking['rating'] > 0): ?>
                                    <h3>You rated your driver</h3>
                                    <div style="font-size:28px; color:#f59e0b;">
                                        <?php echo str_repeat('★', $booking['rating']) . str_repeat('☆', 5 - $booking['rating']); ?>
                                    </div>
                                    <div class="rated-message">Thank you for your feedback!</div>
                                <?php else: ?>
                                    <h3>Rate your driver</h3>
                                    <p style="font-size:12px; color:#68739a; margin-bottom:15px;">How was your ride with <?= htmlspecialchars($driver_nama); ?>?</p>
                                    <form method="POST">
                                        <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
                                        <input type="hidden" name="phone" value="<?= htmlspecialchars($phone_input); ?>">
                                        <input type="hidden" name="submit_rating" value="1">
                                        
                                        <div class="rating-stars">
                                            <input type="radio" id="star5" name="rating" value="5" required /><label for="star5" title="5 stars">★</label>
                                            <input type="radio" id="star4" name="rating" value="4" /><label for="star4" title="4 stars">★</label>
                                            <input type="radio" id="star3" name="rating" value="3" /><label for="star3" title="3 stars">★</label>
                                            <input type="radio" id="star2" name="rating" value="2" /><label for="star2" title="2 stars">★</label>
                                            <input type="radio" id="star1" name="rating" value="1" /><label for="star1" title="1 star">★</label>
                                        </div>
                                        <button type="submit" class="rating-btn">Submit Rating</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="result-actions">
                            <a href="./" class="result-btn secondary">
                                <i class="fa-solid fa-house"></i> Home
                            </a>

                            <?php if ($pdf_allowed): ?>
                                <button type="button" class="result-btn pdf" onclick="generatePDF()">
                                    <i class="fa-solid fa-file-pdf"></i> Download Receipt
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($pdf_allowed): ?>
                            <div id="pdf-receipt" style="margin-top:22px;border:1px solid #e7eaf2;border-radius:10px;overflow:hidden;">
                                <div class="pdf-header">
                                    <h3>UTMKL RIDE</h3>
                                    <small>Official Ride Confirmation Receipt</small>
                                </div>

                                <div class="pdf-body">
                                    <div class="pdf-status">
                                        <?= $booking_status === 'selesai' ? '✅ RIDE COMPLETED' : '✔ BOOKING CONFIRMED'; ?>
                                    </div>

                                    <table class="pdf-table">
                                        <tr>
                                            <td>Booking Reference:</td>
                                            <td style="color:#1556e8;"><?= htmlspecialchars($formatted_id); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Passenger Name:</td>
                                            <td><?= htmlspecialchars($booking['nama']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Phone Number:</td>
                                            <td><?= htmlspecialchars($booking['telefon']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Pick-up Location:</td>
                                            <td><?= htmlspecialchars($booking['pickup']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Drop-off Location:</td>
                                            <td><?= htmlspecialchars($booking['dropoff']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Date & Time:</td>
                                            <td><?= $formatted_date; ?> @ <?= $formatted_time; ?></td>
                                        </tr>
                                    </table>

                                    <div class="driver-box">
                                        <h4>🚗 Assigned Driver Details</h4>
                                        <?php if ($has_driver): ?>
                                            <p><b>Driver Name:</b> <?= htmlspecialchars($driver_nama); ?></p>
                                            <?php if ($driver_car): ?>
                                            <p><b>Vehicle:</b> <?= htmlspecialchars($driver_car); ?></p>
                                            <?php endif; ?>
                                            <?php if ($driver_plate): ?>
                                            <p><b>Plate Number:</b> <?= htmlspecialchars($driver_plate); ?></p>
                                            <?php endif; ?>
                                             <?php if ($driver_telefon): ?>
                                             <?php
                                                 $wa_number = clean_phone_my($driver_telefon);
                                                 $wa_msg = urlencode("Hi, I am a UTMKL Ride passenger. My booking ref: {$formatted_id}.");
                                             ?>
                                             <p style="margin-top:10px;">
                                                 <a href="https://api.whatsapp.com/send?phone=<?= $wa_number; ?>&text=<?= $wa_msg; ?>"
                                                    style="display:inline-flex;align-items:center;gap:6px;background:#25d366;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;"
                                                    target="_blank" rel="noopener">
                                                     💬 WhatsApp Driver
                                                 </a>
                                             </p>
                                             <?php endif; ?>
                                        <?php else: ?>
                                            <p style="color:#856404;font-size:13px;">⏳ No driver assigned yet. Please check back shortly.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="pdf-footer">
                                        This receipt is generated only after the ride has been confirmed by the driver.<br>
                                        Please keep your booking reference for future communication.
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($pdf_allowed): ?>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
                    <script>
                    function generatePDF() {
                        const element = document.getElementById('pdf-receipt');
                        if (!element) return;

                        const clone = element.cloneNode(true);
                        const tempContainer = document.createElement('div');

                        tempContainer.style.position = 'absolute';
                        tempContainer.style.top = '0';
                        tempContainer.style.left = '-9999px';
                        tempContainer.style.width = '680px';
                        tempContainer.style.background = '#ffffff';

                        clone.style.width = '100%';
                        clone.style.margin = '0';
                        clone.style.boxShadow = 'none';

                        tempContainer.appendChild(clone);
                        document.body.appendChild(tempContainer);

                        const opt = {
                            margin: 15,
                            filename: 'UTMKL_Ride_<?= htmlspecialchars($formatted_id, ENT_QUOTES); ?>.pdf',
                            image: { type: 'jpeg', quality: 1.0 },
                            html2canvas: { scale: 2, useCORS: true, scrollY: 0, scrollX: 0 },
                            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                        };

                        html2pdf().set(opt).from(clone).save().then(() => {
                            document.body.removeChild(tempContainer);
                        });
                    }
                    </script>
                <?php endif; ?>

            <?php endif; ?>
        </section>

        <a href="./" class="back-home">← Back to UTMKL Ride</a>
    </main>
</div>
</body>
</html>

<?php $conn->close(); ?>
