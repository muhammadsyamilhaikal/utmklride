<?php
require_once 'shared/config.php';

$res = $conn->query("SHOW COLUMNS FROM tempahan LIKE 'rating'");
if($res->num_rows == 0){
    $conn->query("ALTER TABLE tempahan ADD COLUMN rating INT DEFAULT NULL");
    $conn->query("ALTER TABLE tempahan ADD COLUMN review TEXT DEFAULT NULL");
    echo "Added rating and review columns to tempahan!\n";
} else {
    echo "rating column already exists.\n";
}
?>
