<?php
session_name('UTMKL_DRIVER_SESS');
session_start();
session_destroy();
header('Location: login.php');
exit;
