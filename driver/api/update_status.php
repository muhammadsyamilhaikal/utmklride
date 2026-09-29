<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

$input      = json_decode(file_get_contents('php://input'), true);
$booking_id = $input['booking_id'] ?? null;
$new_status = $input['new_status'] ?? null;
$driver_id  = $_SESSION['driver_id'];

if (!$booking_id || !in_array($new_status, ['in_progress', 'selesai'])) {
    echo json_encode(["success" => false, "message" => "Invalid parameters."]);
    exit;
}

$tg_url = "";

if ($new_status === 'in_progress') {
    // Check trip time first to prevent early starts
    $chk_stmt = $conn->prepare("SELECT tarikh, masa FROM tempahan WHERE id = ? AND driver_id = ? AND status = 'confirmed'");
    $chk_stmt->bind_param("ii", $booking_id, $driver_id);
    $chk_stmt->execute();
    $res = $chk_stmt->get_result();
    
    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $trip_time = strtotime($row['tarikh'] . ' ' . $row['masa']);
        $current_time = time();
        
        // 5 minutes = 300 seconds
        if ($current_time < ($trip_time - 300)) {
            echo json_encode(["success" => false, "message" => "To prevent scamming, you can only start the trip 5 minutes before the scheduled time."]);
            exit;
        }
    } else {
        echo json_encode(["success" => false, "message" => "Failed to start trip. Invalid booking or not confirmed."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE tempahan SET status = 'in_progress' WHERE id = ? AND driver_id = ? AND status = 'confirmed'");
    $stmt->bind_param("ii", $booking_id, $driver_id);
    $stmt->execute();

} elseif ($new_status === 'selesai') {
    // Accept fare and payment method from the request body
    $tambang      = isset($input['tambang']) ? (float) $input['tambang'] : null;
    $cara_bayaran = isset($input['cara_bayaran']) && in_array($input['cara_bayaran'], ['tunai', 'qr'])
                    ? $input['cara_bayaran'] : null;

    $stmt = $conn->prepare(
        "UPDATE tempahan
         SET status = 'selesai', completed_at = NOW(), tambang = ?, cara_bayaran = ?
         WHERE id = ? AND driver_id = ? AND status = 'in_progress'"
    );
    $stmt->bind_param("dsii", $tambang, $cara_bayaran, $booking_id, $driver_id);
    $stmt->execute();

    if ($stmt->affected_rows === 1) {
        $driver_nama = $_SESSION['driver_nama'];
        $booking_ref = "UTM-" . str_pad($booking_id, 4, '0', STR_PAD_LEFT);

        $tg_token   = get_setting($conn, 'telegram_bot_token');
        $tg_chat_id = get_setting($conn, 'telegram_chat_id_admin');

        if ($tg_token && $tg_chat_id) {
            $msg    = "✅ *TRIP COMPLETED*\nBooking $booking_ref has been completed by driver *$driver_nama*.";
            $tg_url = "https://api.telegram.org/bot{$tg_token}/sendMessage?chat_id={$tg_chat_id}&parse_mode=Markdown&text=" . urlencode($msg);
        }
    }
}

if ($stmt->affected_rows === 1) {
    echo json_encode(["success" => true, "message" => "Status updated successfully.", "tg_url" => $tg_url]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update status."]);
}
