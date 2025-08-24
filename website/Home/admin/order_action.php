<?php
// order_action.php
session_start();
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role']!=='admin') {
    header("Location: login.php");
    exit;
}

$id  = (int)($_GET['id'] ?? 0);
$act = $_GET['act'] ?? '';

if ($id<=0 || !in_array($act,['approve','reject'])) {
    die("ข้อมูลไม่ถูกต้อง");
}

if($act==='approve'){
    $st = $conn->prepare("UPDATE orders SET payment_status='paid', updated_at=NOW() WHERE id=?");
    $st->bind_param("i",$id);
    $st->execute();
} elseif($act==='reject'){
    $st = $conn->prepare("UPDATE orders SET payment_status='unpaid', updated_at=NOW() WHERE id=?");
    $st->bind_param("i",$id);
    $st->execute();
}

header("Location: admin_orders.php");
exit;
?>