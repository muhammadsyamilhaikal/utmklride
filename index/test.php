<?php

echo '<pre>';

echo "PHP version: " . PHP_VERSION . PHP_EOL;
echo "SAPI: " . PHP_SAPI . PHP_EOL;
echo "INI: " . php_ini_loaded_file() . PHP_EOL;

echo "mysqli loaded: ";
var_dump(extension_loaded('mysqli'));

echo "mysqli_report exists: ";
var_dump(function_exists('mysqli_report'));

echo '</pre>';