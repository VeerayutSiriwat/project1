<?php
// Home/cart_update.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=cart_view.php");
  exit;
}

$user_id = (int)$_SESSION['user_id'];
$id  = (int)($_POST['id']  ?? 0);
$qty = (int)($_POST['qty'] ?? 0);

if ($id <= 0) {
  header("Location: cart_view.php"); exit;
}

// ดึงข้อมูลรายการ + สต็อกสินค้า
$stmt = $conn->prepare("
  SELECT ci.id, ci.product_id, p.stock
  FROM cart_items ci
  JOIN products p ON p.id = ci.product_id
  WHERE ci.id = ? AND ci.user_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) { header("Location: cart_view.php"); exit; }

$max = max(1, (int)$row['stock']);

if ($qty <= 0) {
  // ถ้า qty <= 0 ให้ลบทิ้ง
  $del = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
  $del->bind_param("ii", $id, $user_id);
  $del->execute();
} else {
  // อัปเดตจำนวนโดยไม่เกิน stock
  $qty = min($qty, $max);
  $upd = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?");
  $upd->bind_param("iii", $qty, $id, $user_id);
  $upd->execute();
}

header("Location: cart_view.php");
exit;
