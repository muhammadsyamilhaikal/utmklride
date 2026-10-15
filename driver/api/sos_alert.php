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

// Send Telegram server-side; the bot token never reaches the browser.
$success = send_telegram_message($bot_token, $admin_chat_id, $message);
echo json_encode([
    'success' => $success,
    'message' => $success ? 'SOS Alert sent successfully to the admin.' : 'Unable to send SOS alert.'
]);
