<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php?redirect=admin/index.php");
    exit;
}
echo "<h1>Welcome Admin ".$_SESSION['username']."</h1>";
