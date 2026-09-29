<?php
// Set PHP timezone to Malaysia (Asia/Kuala_Lumpur)
date_default_timezone_set('Asia/Kuala_Lumpur');
mysqli_report(MYSQLI_REPORT_OFF);

$host = "localhost";
$user = "root"; 
$pass = "8080";     
$db   = "ride_booking";

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
} catch (Exception $e) {
    // Jika ada request jenis JSON (seperti dari check_new.php), kita keluarkan JSON
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit;
    }
    
    // If accessing from a regular browser, output an alert
    die("<div style='background:#f8d7da; color:#721c24; padding:20px; font-family:sans-serif; margin:20px; border-radius:8px; border:1px solid #f5c6cb; text-align:center;'>
            <b>🚨 Database Connection Error:</b><br>Unable to connect to the database. Please ensure the database server is running!
         </div>");
}
?>