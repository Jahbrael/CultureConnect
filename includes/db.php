<?php
// includes/db.php — MySQLi connection
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'culture_connect_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die('DB connection failed: ' . htmlspecialchars($conn->connect_error));
}
$conn->set_charset('utf8mb4');
