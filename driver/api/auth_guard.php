<?php
session_name('UTMKL_DRIVER_SESS');
session_start();

if (!isset($_SESSION['driver_logged_in']) || $_SESSION['driver_logged_in'] !== true) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(["status" => "unauthorized"]);
    } else {
        header('Location: ../login.php');
    }
    exit;
}

// Block API access if driver must change password first
if (!empty($_SESSION['driver_must_change_password'])) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(["status" => "password_change_required", "message" => "You must change your password before accessing this resource."]);
    } else {
        header('Location: ../change_password.php');
    }
    exit;
}
