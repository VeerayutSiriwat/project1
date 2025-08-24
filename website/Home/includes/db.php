<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "webapp_db";
$conn = new mysqli($host, $user, $pass, $dbname);
date_default_timezone_set('Asia/Bangkok');
$conn->query("SET time_zone = '+07:00'");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
