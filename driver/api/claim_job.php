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

// Check if driver is online
$check_driver = $conn->prepare("SELECT is_online FROM drivers WHERE id = ?");
if ($check_driver) {
    $check_driver->bind_param("i", $driver_id);
    $check_driver->execute();
    $res = $check_driver->get_result();
    if ($res && $res->num_rows > 0) {
        $d = $res->fetch_assoc();
        if (isset($d['is_online']) && $d['is_online'] == 0) {
            echo json_encode(["success" => false, "message" => "You are offline. Please go online to claim trips."]);
            exit;
        }
    }
}

$stmt = $conn->prepare("UPDATE tempahan SET driver_id = ?, status = 'confirmed', accepted_at = NOW() WHERE id = ? AND (driver_id IS NULL OR driver_id = 0) AND status = 'pending'");
$stmt->bind_param("ii", $driver_id, $booking_id);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    $driver_nama = $_SESSION['driver_nama'];
    $booking_ref = "UTM-" . str_pad($booking_id, 4, '0', STR_PAD_LEFT);

    $tg_token   = get_setting($conn, 'telegram_bot_token');
    $tg_chat_id = get_setting($conn, 'telegram_chat_id_admin');
    $tg_url     = "";

    if ($tg_token && $tg_chat_id) {
        $msg    = "🚗 *TRIP CLAIMED*\nBooking $booking_ref has been claimed by driver *$driver_nama*.";
        $tg_url = "https://api.telegram.org/bot{$tg_token}/sendMessage?chat_id={$tg_chat_id}&parse_mode=Markdown&text=" . urlencode($msg);
    }

    echo json_encode(["success" => true, "message" => "Trip claimed successfully!", "tg_url" => $tg_url]);
} else {
    echo json_encode(["success" => false, "message" => "This trip has already been taken by another driver."]);
}
