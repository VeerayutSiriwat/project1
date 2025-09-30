<?php
// Home/admin/coupon_delete.php
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  header('Location: ../login.php'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ header('Location: coupons_list.php'); exit; }

function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $q = $c->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}

// ลบความสัมพันธ์ (ถ้ามี)
if (table_exists($conn,'coupon_products')) {
  $st=$conn->prepare("DELETE FROM coupon_products WHERE coupon_id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}
if (table_exists($conn,'coupon_categories')) {
  $st=$conn->prepare("DELETE FROM coupon_categories WHERE coupon_id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}

// ลบคูปอง
$st=$conn->prepare("DELETE FROM coupons WHERE id=? LIMIT 1");
$st->bind_param('i',$id);
$st->execute(); $st->close();

$_SESSION['flash'] = 'ลบคูปองแล้ว';
header('Location: coupons_list.php');
