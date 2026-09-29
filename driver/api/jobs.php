<?php
require_once 'auth_guard.php';
require_once '../../shared/config.php';

header('Content-Type: application/json');

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'min',
        's' => 'sec',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

$stmt = $conn->prepare("SELECT * FROM tempahan WHERE status = 'pending' AND (driver_id IS NULL OR driver_id = 0) ORDER BY tarikh ASC, masa ASC");
$stmt->execute();
$result = $stmt->get_result();

$jobs = [];
while ($row = $result->fetch_assoc()) {
    $jobs[] = [
        "id" => $row['id'],
        "booking_ref" => "UTM-" . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        "nama" => $row['nama'],
        "telefon" => $row['telefon'],
        "pickup" => $row['pickup'],
        "dropoff" => $row['dropoff'],
        "tarikh" => $row['tarikh'],
        "masa" => $row['masa'],
        "tarikh_format" => date('d M Y', strtotime($row['tarikh'])),
        "masa_format" => date('h:i A', strtotime($row['masa'])),
        "created_ago" => time_elapsed_string($row['created_at'])
    ];
}

echo json_encode($jobs);
