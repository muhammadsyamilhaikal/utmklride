<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$booking_id = $input['booking_id'] ?? null;
$driver_id  = $_SESSION['driver_id'];

if (!$booking_id) {
    echo json_encode(["success" => false, "message" => "Invalid booking ID."]);
    exit;
}

// Revert the job to the pool: set driver_id to NULL and status to 'pending'
$stmt = $conn->prepare("UPDATE tempahan SET driver_id = NULL, status = 'pending', accepted_at = NULL WHERE id = ? AND driver_id = ? AND status IN ('confirmed', 'in_progress')");
$stmt->bind_param("ii", $booking_id, $driver_id);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    $driver_nama = $_SESSION['driver_nama'];
    $booking_ref = "UTM-" . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
    
    // Telegram Notification to Admin
    $tg_token   = get_setting($conn, 'telegram_bot_token');
    $tg_chat_id = get_setting($conn, 'telegram_chat_id_admin');
    $tg_url     = "";

    if ($tg_token && $tg_chat_id) {
        $msg    = "⚠️ *JOB RELEASED (ANTIGRAVITY)*\nBooking $booking_ref has been cancelled by driver *$driver_nama* and is floating back in the open pool.";
        $tg_url = "https://api.telegram.org/bot{$tg_token}/sendMessage?chat_id={$tg_chat_id}&parse_mode=Markdown&text=" . urlencode($msg);
    }

    echo json_encode(["success" => true, "message" => "Order cancelled. Antigravity engaged!", "tg_url" => $tg_url]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to cancel. The trip might already be completed or reassigned."]);
}

$stmt->close();
?>
