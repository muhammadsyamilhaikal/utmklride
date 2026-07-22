<?php
// 1. Set PHP timezone to Malaysia (Asia/Kuala_Lumpur)
date_default_timezone_set('Asia/Kuala_Lumpur');

$host = "your_db_host";
$user = "your_db_user";
$pass = "your_db_password";   
$db   = "your_db_name";

// Use try-catch for a cleaner and more reliable database connection
try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed.");
    }
} catch (Exception $e) {
    die("<div style='color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; text-align:center; padding:15px; border-radius:8px; margin:20px; font-family:sans-serif;'>⚠️ <b>System Alert:</b> Unable to connect to the database. Please make sure your database server is running!</div>");
}

// =========================================================================
// 0. CEK STATUS SISTEM (KAWALAN SHUTDOWN / OFF DUTY DARI MYSQL)
// =========================================================================
$conn->query("CREATE TABLE IF NOT EXISTS system_status (id INT PRIMARY KEY, status VARCHAR(10))");
$res_stat = $conn->query("SELECT status FROM system_status WHERE id = 1");
$sys_status = 'ON'; // Default ON jika tak jumpa

if ($res_stat && $res_stat->num_rows > 0) {
    $row_stat = $res_stat->fetch_assoc();
    $sys_status = $row_stat['status'];
}

// Jika admin set kepada OFF, terus keluar skrin merah ini dan matikan borang!
if ($sys_status === 'OFF') {
    die("
    <!DOCTYPE html>
    <html lang='ms'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>UTMKL RIDE - Off Duty</title>
        <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap' rel='stylesheet'>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
            body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
            .box { background: white; padding: 40px 30px; border-radius: 15px; text-align: center; max-width: 450px; width: 100%; box-shadow: 0 15px 30px rgba(0,0,0,0.2); }
            .box h2 { color: #dc3545; margin-bottom: 15px; font-size: 22px; }
            .box p { color: #555; line-height: 1.6; font-size: 14px; margin-bottom: 20px; }
            .badge-off { background: #f8d7da; color: #721c24; padding: 8px 16px; border-radius: 20px; font-weight: 600; display: inline-block; margin-bottom: 20px; font-size: 13px; border: 1px solid #f5c6cb; }
            .btn-retry { display: inline-block; background: #1e3c72; color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; transition: 0.3s; }
            .btn-retry:hover { background: #2a5298; }
        </style>
    </head>
    <body>
        <div class='box'>
            <div class='badge-off'>🛑 SISTEM DITUTUP SEMENTARA</div>
            <h2>Driver Off Duty / Maintenance</h2>
            <p>Maaf, perkhidmatan <b>UTMKL Ride</b> sedang direhatkan buat masa ini atau sedang menjalani kemaskini sistem.<br><br>Sila cuba lagi sebentar lagi atau hubungi driver secara terus menerusi WhatsApp jika ada kecemasan.</p>
            <a href='' class='btn-retry'>🔄 Semak Semula</a>
        </div>
    </body>
    </html>
    ");
}
// =========================================================================

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Clean and sanitize basic form inputs
    $name    = trim(strip_tags($_POST['name']));
    $phone   = trim(strip_tags($_POST['phone']));
    $pickup  = trim(strip_tags($_POST['pickup']));
    $dropoff = trim(strip_tags($_POST['dropoff']));
    $date    = $_POST['date'];
    $time    = $_POST['time'];

    // =====================================================================
    // STEP 1: PRE-CHECK FOR DUPLICATE BOOKING (PHP Layer Defense)
    // =====================================================================
    $check_stmt = $conn->prepare("SELECT id FROM tempahan WHERE telefon = ? AND tarikh = ? AND masa = ?");
    $check_stmt->bind_param("sss", $phone, $date, $time);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        $message = "<div class='alert alert-danger'>⚠️ <b>Duplicate Order Detected!</b> You have already booked a ride for this date and time.</div>";
        $check_stmt->close();
    } else {
        $check_stmt->close();
        $created_at = date("Y-m-d H:i:s");

        // =====================================================================
        // STEP 2: INSERT NEW BOOKING
        // =====================================================================
        $stmt = $conn->prepare("INSERT INTO tempahan (nama, telefon, pickup, dropoff, tarikh, masa, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $phone, $pickup, $dropoff, $date, $time, $created_at);

        if ($stmt->execute()) {
            // TELEGRAM NOTIFICATION SETUP
            $botToken = "YOUR_TELEGRAM_BOT_TOKEN";
            $chatId   = "YOUR_TELEGRAM_CHAT_ID";

            $date_formatted = date("d/m/Y", strtotime($date));
            $time_formatted = date("h:i A", strtotime($time));

            $tg_msg  = "🚨 *NEW RIDE BOOKING RECEIVED!* 🚨\n\n";
            $tg_msg .= "👤 *Passenger:* " . $name . "\n";
            $tg_msg .= "📞 *Phone:* " . $phone . "\n";
            $tg_msg .= "📍 *Pick-up:* " . $pickup . "\n";
            $tg_msg .= "🏁 *Drop-off:* " . $dropoff . "\n";
            $tg_msg .= "📅 *Date/Time:* " . $date_formatted . " (" . $time_formatted . ")\n\n";
            $tg_msg .= "⚡ _Please check the Admin Panel now!_";

            $url_tg = "https://api.telegram.org/bot" . $botToken . "/sendMessage?chat_id=" . $chatId . "&text=" . urlencode($tg_msg) . "&parse_mode=Markdown";
            $safe_url = json_encode($url_tg);

            // DRIVER INFO & PDF RECEIPT
            $driver_name  = "Syamil";
            $driver_car   = "Red Perodua Axia";
            $driver_plate = "VBU6953";

            $message = "
            <div class='alert alert-success'>
                🎉 <b>Awesome!</b> Your ride has been booked successfully. Your driver will get in touch with you shortly!
            </div>

            <button onclick='generatePDF()' type='button' class='btn' style='background: #27ae60; margin-bottom: 20px;'>
                📄 Download Receipt (PDF)
            </button>

            <div id='pdf-receipt' style='background: #ffffff; padding: 0; border: none; box-shadow: 0 0 10px rgba(0,0,0,0.05); margin-bottom: 20px; text-align: left; color: #333; border-radius: 8px; overflow: hidden;'>
                <div style='background: #1e3c72; color: #ffffff; padding: 20px 25px; text-align: center;'>
                    <h3 style='color: #ffffff; margin: 0; font-size: 22px;'>UTMKL RIDE</h3>
                    <small style='color: #e0e0e0; font-size: 12px;'>Official Ride Confirmation & Receipt</small>
                </div>
                <div style='padding: 25px;'>
                    <div style='background: #d4edda; color: #155724; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block; margin-bottom: 20px; border: 1px solid #c3e6cb;'>
                        ✔ BOOKING CONFIRMED
                    </div>
                    <table style='width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 20px;'>
                        <tr>
                            <td style='padding: 6px 0; color: #666; width: 40%;'>Passenger Name:</td>
                            <td style='padding: 6px 0; font-weight: bold; color: #000;'>" . htmlspecialchars($name) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #666;'>Phone Number:</td>
                            <td style='padding: 6px 0; font-weight: bold; color: #000;'>" . htmlspecialchars($phone) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #666;'>Pick-up Location:</td>
                            <td style='padding: 6px 0; font-weight: bold; color: #000;'>" . htmlspecialchars($pickup) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #666;'>Drop-off Location:</td>
                            <td style='padding: 6px 0; font-weight: bold; color: #000;'>" . htmlspecialchars($dropoff) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #666;'>Date & Time:</td>
                            <td style='padding: 6px 0; font-weight: bold; color: #1e3c72;'>" . $date_formatted . " @ " . $time_formatted . "</td>
                        </tr>
                    </table>
                    <hr style='border: none; border-top: 2px dashed #eee; margin: 20px 0;'>
                    <div style='background: #f8fafc; padding: 15px; border-left: 4px solid #f39c12; border-radius: 4px;'>
                        <h4 style='color: #1e3c72; margin-top: 0; margin-bottom: 10px; font-size: 15px;'>🚗 Assigned Driver Details</h4>
                        <p style='margin: 4px 0; font-size: 14px;'><b>Driver Name:</b> " . $driver_name . "</p>
                        <p style='margin: 4px 0; font-size: 14px;'><b>Vehicle:</b> " . $driver_car . "</p>
                        <p style='margin: 4px 0; font-size: 14px;'><b>Plate Number:</b> <span style='background:#e2e8f0; padding:3px 8px; border-radius:4px; font-weight:bold; color:#000;'>" . $driver_plate . "</span></p>
                    </div>
                    <p style='font-size: 11px; color: #888; text-align: center; margin-top: 25px; margin-bottom: 0;'>Please present this receipt to your driver upon boarding.</p>
                </div>
            </div>

            <script src='https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'></script>
            <script>
                function generatePDF() {
                    const element = document.getElementById('pdf-receipt');
                    const tempContainer = document.createElement('div');
                    tempContainer.style.position = 'absolute';
                    tempContainer.style.top = '0';
                    tempContainer.style.left = '0';
                    tempContainer.style.width = '680px';
                    tempContainer.style.background = '#ffffff';
                    tempContainer.style.zIndex = '-9999';
                    
                    const clone = element.cloneNode(true);
                    clone.style.boxShadow = 'none';
                    clone.style.margin = '0';
                    clone.style.width = '100%';
                    
                    tempContainer.appendChild(clone);
                    document.body.appendChild(tempContainer);
                    
                    const opt = {
                        margin:       15,
                        filename:     'UTMKL_Ride_Receipt.pdf',
                        image:        { type: 'jpeg', quality: 1.0 },
                        html2canvas:  { scale: 2, useCORS: true, scrollY: 0, scrollX: 0 },
                        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                    };
                    
                    html2pdf().set(opt).from(clone).save().then(() => {
                        document.body.removeChild(tempContainer);
                    });
                }
            </script>";
            
            // InfinityFree Bypass Trick
            $message .= "<script>
                const tgUrl = " . $safe_url . ";
                fetch(tgUrl, { mode: 'no-cors' }).catch(() => {
                    new Image().src = tgUrl;
                });
            </script>";
        } else {
            if ($conn->errno === 1062) {
                $message = "<div class='alert alert-danger'>⚠️ <b>Duplicate Order!</b> You already booked a ride at this exact date and time.</div>";
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
    <title>UTMKL RIDE</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { background: #ffffff; max-width: 500px; width: 100%; padding: 40px 30px; border-radius: 15px; box-shadow: 0 15px 30px rgba(0,0,0,0.2); }
        .container h2 { text-align: center; margin-bottom: 25px; color: #1e3c72; font-weight: 600; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 8px; color: #444; font-weight: 500; font-size: 14px; }
        .input-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px; outline: none; transition: all 0.3s ease; }
        .input-group input:focus { border-color: #2a5298; box-shadow: 0 0 8px rgba(42, 82, 152, 0.3); }
        .row { display: flex; gap: 15px; }
        .row .input-group { flex: 1; }
        .btn { width: 100%; padding: 15px; background: #f39c12; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn:hover:not([disabled]) { background: #e67e22; transform: translateY(-2px); }
        .btn[disabled] { background: #95a5a6; cursor: not-allowed; transform: none; }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-size: 14px; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<div class="container">
    <h2>Book Your Ride 🚗</h2>
    <?= $message; ?>

    <?php if (strpos($message, 'alert-success') === false): ?>
    <form action="" method="POST" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '⏳ Booking... Please wait';">
        <div class="input-group">
            <label>Full Name</label>
            <input type="text" name="name" placeholder="E.g., Ahmad Ali" required autocomplete="name">
        </div>
        <div class="input-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="E.g., 0123456789" required autocomplete="tel">
        </div>
        <div class="input-group">
            <label>Pick-up Location</label>
            <input type="text" name="pickup" placeholder="Where should we pick you up?" required>
        </div>
        <div class="input-group">
            <label>Drop-off Location</label>
            <input type="text" name="dropoff" placeholder="Where are you heading?" required>
        </div>
        <div class="row">
            <div class="input-group">
                <label>Date</label>
                <input type="date" name="date" min="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="input-group">
                <label>Time</label>
                <input type="time" name="time" required>
            </div>
        </div>
        <button type="submit" class="btn">Book Now</button>
    </form>
    <?php endif; ?>
</div>

</body>
</html>
