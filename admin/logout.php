<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('UTMKL_ADMIN_SESS');
    session_start();
}
session_destroy();
header('Location: login.php');
exit;
?>
