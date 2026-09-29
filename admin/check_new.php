<?php
header('Content-Type: application/json');

require_once 'config.php';

// Latest booking created.
// Admin uses this only to detect whether a new booking arrived.
$sql = "
    SELECT id, nama, pickup, dropoff
    FROM tempahan
    ORDER BY id DESC
    LIMIT 1
";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();

    echo json_encode([
        "status"    => "success",
        "latest_id" => (int)$row['id'],
        "nama"      => $row['nama'],
        "pickup"    => $row['pickup'],
        "dropoff"   => $row['dropoff']
    ]);
} else {
    echo json_encode([
        "status"    => "empty",
        "latest_id" => 0
    ]);
}

$conn->close();
?>
