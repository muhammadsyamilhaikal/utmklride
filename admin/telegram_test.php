<?php
require_once 'auth_guard.php';
require_once '../shared/config.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!hash_equals($_SESSION['admin_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$chat_id = trim(strip_tags($_POST['chat_id'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));
if ($chat_id === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing Telegram message data']);
    exit;
}

$token = get_setting($conn, 'telegram_bot_token');
$success = send_telegram_message($token, $chat_id, $message);
echo json_encode(['success' => $success, 'message' => $success ? 'Telegram notification sent.' : 'Telegram notification failed.']);
