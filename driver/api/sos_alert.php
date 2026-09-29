<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$issue_type = $input['issue_type'] ?? '';
$booking_ref = $input['booking_ref'] ?? 'None (No active trip selected)';
$driver_id = $_SESSION['driver_id'];

if (empty($issue_type)) {
    echo json_encode(['success' => false, 'message' => 'Issue type is required.']);
    exit;
}

// Fetch driver details
$stmt = $conn->prepare("SELECT nama, no_plat FROM drivers WHERE id = ?");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$driver) {
    echo json_encode(['success' => false, 'message' => 'Driver not found.']);
    exit;
}

// Fetch admin Telegram settings
$bot_token = get_setting($conn, 'telegram_bot_token');
$admin_chat_id = get_setting($conn, 'telegram_chat_id_admin');

if (empty($bot_token) || empty($admin_chat_id)) {
    echo json_encode(['success' => false, 'message' => 'Database Error: Telegram bot token or admin chat ID is missing from settings.']);
    exit;
}

// Construct SOS message
$message = "🚨 *SOS / EMERGENCY ALERT* 🚨\n\n";
$message .= "🚘 *Driver:* " . htmlspecialchars($driver['nama']) . " (" . htmlspecialchars($driver['no_plat']) . ")\n";
$message .= "⚠️ *Issue:* " . htmlspecialchars($issue_type) . "\n";
$message .= "🎫 *Booking Ref:* " . htmlspecialchars($booking_ref) . "\n\n";
$message .= "Please contact the driver or manage the passenger immediately!";

// Send Telegram message
$telegram_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
$post_fields = [
    'chat_id' => $admin_chat_id,
    'text' => $message,
    'parse_mode' => 'Markdown'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegram_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds timeout
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Prevent local SSL cert issues

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $curl_error]);
} elseif ($http_code === 200) {
    echo json_encode(['success' => true, 'message' => 'SOS Alert sent successfully to the admin.']);
} else {
    // Optionally log the exact response for debugging
    error_log("Telegram SOS Error HTTP {$http_code}: " . $response);
    
    // Parse Telegram error if possible
    $tg_err = json_decode($response, true);
    $tg_msg = isset($tg_err['description']) ? $tg_err['description'] : 'Unknown Telegram error';
    
    echo json_encode(['success' => false, 'message' => "Telegram API Error ({$http_code}): " . $tg_msg]);
}
