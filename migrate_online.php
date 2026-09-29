<?php
require 'shared/config.php';
$res = $conn->query("DESCRIBE drivers");
while($row = $res->fetch_assoc()){
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
// check if is_online exists
$res2 = $conn->query("SHOW COLUMNS FROM drivers LIKE 'is_online'");
if($res2->num_rows == 0){
    $conn->query("ALTER TABLE drivers ADD COLUMN is_online TINYINT(1) DEFAULT 1");
    echo "Added is_online column!\n";
}
?>
