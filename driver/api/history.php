<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

$driver_id = $_SESSION['driver_id'];

$stmt = $conn->prepare("
    SELECT id, nama, pickup, dropoff, tarikh, masa, status,
           completed_at, tambang, cara_bayaran, rating
    FROM tempahan
    WHERE driver_id = ?
      AND status IN ('selesai', 'batal')
    ORDER BY completed_at DESC, id DESC
    LIMIT 50
");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = [
        'id'            => (int) $row['id'],
        'booking_ref'   => 'UTM-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        'nama'          => $row['nama'],
        'pickup'        => $row['pickup'],
        'dropoff'       => $row['dropoff'],
        'status'        => $row['status'],
        'tarikh_format' => date('d M Y', strtotime($row['tarikh'])),
        'masa_format'   => date('h:i A', strtotime($row['masa'])),
        'tambang'       => $row['tambang'] ? number_format((float)$row['tambang'], 2) : null,
        'cara_bayaran'  => $row['cara_bayaran'] ?? null,
        'rating'        => $row['rating'] ? (int)$row['rating'] : null,
    ];
}

$stmt->close();
echo json_encode($history);
