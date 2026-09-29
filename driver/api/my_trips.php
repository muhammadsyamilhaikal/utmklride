<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

$driver_id = $_SESSION['driver_id'];

$stmt = $conn->prepare("SELECT * FROM tempahan WHERE driver_id = ? AND status IN ('confirmed', 'in_progress') ORDER BY tarikh ASC, masa ASC");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();

$trips = [];
while ($row = $result->fetch_assoc()) {
    $trips[] = [
        "id"           => $row['id'],
        "booking_ref"  => "UTM-" . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        "nama"         => $row['nama'],
        "telefon"      => $row['telefon'],
        "pickup"       => $row['pickup'],
        "dropoff"      => $row['dropoff'],
        "tarikh"       => $row['tarikh'],
        "masa"         => $row['masa'],
        "status"       => $row['status'],
        "accepted_at"  => $row['accepted_at'],
        "tarikh_format" => date('d M Y', strtotime($row['tarikh'])),
        "masa_format"  => date('h:i A', strtotime($row['masa']))
    ];
}

// --- Shift Stats: today's earnings grouped by payment method ---
$shift_stats = ['tunai' => 0.00, 'qr' => 0.00];

$ss_stmt = $conn->prepare(
    "SELECT cara_bayaran, COALESCE(SUM(tambang), 0) AS total
     FROM tempahan
     WHERE driver_id = ?
       AND status = 'selesai'
       AND DATE(completed_at) = CURDATE()
       AND cara_bayaran IS NOT NULL
     GROUP BY cara_bayaran"
);
$ss_stmt->bind_param("i", $driver_id);
$ss_stmt->execute();
$ss_result = $ss_stmt->get_result();
while ($ss_row = $ss_result->fetch_assoc()) {
    $key = $ss_row['cara_bayaran']; // 'tunai' or 'qr'
    $shift_stats[$key] = (float) $ss_row['total'];
}
$ss_stmt->close();

echo json_encode(['trips' => $trips, 'shift_stats' => $shift_stats]);
