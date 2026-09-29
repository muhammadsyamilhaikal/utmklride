<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['is_online'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$is_online = (int)$data['is_online'];
$driver_id = $_SESSION['driver_id'];

// Robust migration: Check if column exists, if not, add it
$check_col = $conn->query("SHOW COLUMNS FROM drivers LIKE 'is_online'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE drivers ADD COLUMN is_online TINYINT(1) DEFAULT 1");
}

$stmt = $conn->prepare("UPDATE drivers SET is_online = ? WHERE id = ?");
$stmt->bind_param("ii", $is_online, $driver_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
}
$stmt->close();
?>
