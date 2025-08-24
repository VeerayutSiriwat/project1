<?php
// Home/cancel_request.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php"); exit;
}

$user_id  = (int)$_SESSION['user_id'];
$order_id = (int)($_POST['id'] ?? 0);
$reason   = trim($_POST['reason'] ?? '');

if ($order_id <= 0 || $reason === '') {
  $_SESSION['flash'] = "ข้อมูลไม่ถูกต้อง";
  header("Location: my_orders.php"); exit;
}

// ตรวจว่าเป็นออเดอร์ของคนนี้และสถานะยังยกเลิกไม่ได้/ไม่ได้ร้องขอไปแล้ว
$st = $conn->prepare("SELECT id, status FROM orders WHERE id=? AND user_id=? LIMIT 1");
$st->bind_param("ii", $order_id, $user_id);
$st->execute();
$ord = $st->get_result()->fetch_assoc();
$st->close();

if (!$ord) {
  $_SESSION['flash'] = "ไม่พบคำสั่งซื้อ";
  header("Location: my_orders.php"); exit;
}
if (in_array($ord['status'], ['cancelled','cancel_requested','completed'], true)) {
  $_SESSION['flash'] = "ไม่สามารถยกเลิกคำสั่งซื้อนี้ได้";
  header("Location: my_orders.php"); exit;
}

// ดึงชื่อผู้ใช้ไว้ใส่ข้อความแจ้งเตือน (จะใช้จาก session ก็ได้ถ้ามี)
$username = '';
$stU = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$stU->bind_param("i", $user_id);
$stU->execute();
$username = (string)($stU->get_result()->fetch_assoc()['username'] ?? '');
$stU->close();

$conn->begin_transaction();
try {
  // อัปเดตสถานะ + เหตุผล
  $st2 = $conn->prepare("
    UPDATE orders
    SET status='cancel_requested', cancel_reason=?, cancel_requested_at=NOW()
    WHERE id=? AND user_id=?
  ");
  $st2->bind_param("sii", $reason, $order_id, $user_id);
  $st2->execute();
  $st2->close();

  // แจ้งเตือนแอดมินทุกคน
  $admins = $conn->query("SELECT id FROM users WHERE role='admin'");
  $title  = "มีคำขอยกเลิกใหม่";
  $msg    = "คำสั่งซื้อ #{$order_id} จากผู้ใช้ " . ($username ?: "UID {$user_id}");
  $type   = "cancel_request";

  // เตรียม statement สำหรับ insert เพื่อใช้ซ้ำ
  $ins = $conn->prepare("
    INSERT INTO notifications (user_id, type, ref_id, title, message, is_read)
    VALUES (?, ?, ?, ?, ?, 0)
  ");

  while ($ad = $admins->fetch_assoc()) {
    $admin_id = (int)$ad['id'];
    $ins->bind_param("isiss", $admin_id, $type, $order_id, $title, $msg);
    $ins->execute();
  }
  $ins->close();

  $conn->commit();
  $_SESSION['flash'] = "ส่งคำขอยกเลิกเรียบร้อยแล้ว";
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash'] = "ส่งคำขอยกเลิกไม่สำเร็จ: " . $e->getMessage();
}

header("Location: my_orders.php");
exit;
