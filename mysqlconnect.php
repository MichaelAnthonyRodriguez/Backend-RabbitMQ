#!/usr/bin/php
<?php
// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'testUser');
define('DB_PASS', '12345');
define('DB_NAME', 'testdb');

// Create a MySQLi connection
$mydb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check for connection errors
if ($mydb->errno) {
    die("failed to connect to database: " . $mydb->error . PHP_EOL);
}

echo "successfully connected to database".PHP_EOL;

?>
