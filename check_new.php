<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json');

// Konfigurasi Database (Sama seperti index.php)
$host = "sql306.infinityfree.com";
$user = "if0_42164453";
$pass = "rkKbbG05Q2";  
$db   = "if0_42164453_ride_booking";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode(["status" => "error"]);
    exit;
}

// Ambil ID tempahan yang paling baru (tertinggi) & status pending/aktif
$sql = "SELECT id, nama, pickup, dropoff FROM tempahan ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        "status" => "success",
        "latest_id" => (int)$row['id'],
        "nama" => $row['nama'],
        "pickup" => $row['pickup'],
        "dropoff" => $row['dropoff']
    ]);
} else {
    echo json_encode(["status" => "empty", "latest_id" => 0]);
}

$conn->close();
?>