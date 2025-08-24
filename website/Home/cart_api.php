<?php
// Home/cart_api.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['status'=>'error','message'=>'กรุณาเข้าสู่ระบบก่อน']); exit;
}

$user_id = (int)$_SESSION['user_id'];

// รับค่าจาก JSON หรือ POST
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { $payload = $_POST; }

$action = $payload['action'] ?? '';

/* ฟังก์ชันนับจำนวนในรถเข็น */
function cart_count($conn, $uid){
  $st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM cart_items WHERE user_id=?");
  $st->bind_param("i", $uid);
  $st->execute();
  return (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
}

/* ตรวจให้มี Unique(user_id,product_id) */
$res = $conn->query("SHOW INDEX FROM cart_items WHERE Key_name='uniq_user_product'");
if (!$res || $res->num_rows === 0) {
  @$conn->query("ALTER TABLE cart_items ADD UNIQUE KEY uniq_user_product (user_id, product_id)");
}

if ($action === 'add') {
  $pid = (int)($payload['product_id'] ?? 0);
  $qty = max(1, (int)($payload['qty'] ?? 1));

  $st = $conn->prepare("SELECT id, stock, status FROM products WHERE id=? LIMIT 1");
  $st->bind_param("i", $pid);
  $st->execute();
  $p = $st->get_result()->fetch_assoc();
  if (!$p || ($p['status'] ?? 'active') !== 'active') {
    echo json_encode(['status'=>'error','message'=>'ไม่พบสินค้าหรือสินค้าถูกปิด']); exit;
  }

  $max = max(1, (int)$p['stock']);
  $qty = min($qty, $max);

  $sql = "INSERT INTO cart_items (user_id, product_id, quantity, created_at)
          VALUES (?,?,?,NOW())
          ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ?)";
  $st = $conn->prepare($sql);
  $st->bind_param("iiii", $user_id, $pid, $qty, $max);
  if ($st->execute()) {
    echo json_encode(['status'=>'success','cart_count'=>cart_count($conn,$user_id)]);
  } else {
    echo json_encode(['status'=>'error','message'=>'เพิ่มตะกร้าไม่สำเร็จ']);
  }
  exit;
}

if ($action === 'update') {
  $id  = (int)($payload['id']  ?? 0);
  $qty = (int)($payload['qty'] ?? 1);

  $st = $conn->prepare("
    SELECT ci.id, p.stock
    FROM cart_items ci JOIN products p ON p.id=ci.product_id
    WHERE ci.id=? AND ci.user_id=? LIMIT 1
  ");
  $st->bind_param("ii", $id, $user_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  if (!$row) { echo json_encode(['status'=>'error','message'=>'ไม่พบรายการ']); exit; }

  if ($qty <= 0) {
    $del = $conn->prepare("DELETE FROM cart_items WHERE id=? AND user_id=?");
    $del->bind_param("ii", $id, $user_id);
    $del->execute();
  } else {
    $qty = min($qty, max(1,(int)$row['stock']));
    $up = $conn->prepare("UPDATE cart_items SET quantity=? WHERE id=? AND user_id=?");
    $up->bind_param("iii", $qty, $id, $user_id);
    $up->execute();
  }
  echo json_encode(['status'=>'success','cart_count'=>cart_count($conn,$user_id)]);
  exit;
}

if ($action === 'remove') {
  // ✅ ใช้ product_id แทน
  $pid = (int)($payload['product_id'] ?? 0);
  $del = $conn->prepare("DELETE FROM cart_items WHERE user_id=? AND product_id=?");
  $del->bind_param("ii", $user_id, $pid);
  $del->execute();
  echo json_encode(['status'=>'success','cart_count'=>cart_count($conn,$user_id)]);
  exit;
}

echo json_encode(['status'=>'error','message'=>'Invalid action']);
