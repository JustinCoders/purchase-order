<?php
date_default_timezone_set('Asia/Manila');

// Database connection for purchase_order_db
$host = 'localhost';
$user = 'root'; // Change if your MySQL user is different
$pass = '';
$db = 'purchase_order_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");
?> 