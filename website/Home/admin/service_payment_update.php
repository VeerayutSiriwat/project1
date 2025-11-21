<?php
// Home/admin/service_payment_update.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}

// เช็คสิทธิ์ admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$action   = $_POST['action'] ?? '';

if ($ticketId <= 0 || !in_array($action, ['mark_paid','mark_pending','mark_unpaid'], true)) {
  header('Location: service_ticket_detail.php?id='.$ticketId);
  exit;
}

// detect ชื่อคอลัมน์ใน table service_tickets แบบยืดหยุ่น
$statusCol = null;
if (has_col($conn,'service_tickets','payment_status')) {
  $statusCol = 'payment_status';
} elseif (has_col($conn,'service_tickets','pay_status')) {
  $statusCol = 'pay_status';
}

$hasPaidAt = has_col($conn,'service_tickets','paid_at');

// ถ้าไม่เจอคอลัมน์สถานะการจ่าย ก็ไม่ทำอะไร แค่เด้งกลับ
if (!$statusCol) {
  $_SESSION['flash'] = 'ไม่พบคอลัมน์สถานะการชำระเงินใน service_tickets (payment_status/pay_status)';
  header('Location: service_ticket_detail.php?id='.$ticketId);
  exit;
}

$newStatus = 'unpaid';
$paidAtSql = 'NULL';

switch ($action) {
  case 'mark_paid':
    $newStatus = 'paid';
    if ($hasPaidAt) {
      $paidAtSql = "NOW()";
    }
    break;

  case 'mark_pending':
    $newStatus = 'pending';
    // รอตรวจสอบ -> ยังไม่บันทึกเวลาจ่าย
    if ($hasPaidAt) {
      $paidAtSql = "NULL";
    }
    break;

  case 'mark_unpaid':
  default:
    $newStatus = 'unpaid';
    if ($hasPaidAt) {
      $paidAtSql = "NULL";
    }
    break;
}

// สร้าง SQL ให้รองรับทั้งมี/ไม่มีคอลัมน์ paid_at
if ($hasPaidAt) {
  $sql = "UPDATE service_tickets 
          SET `$statusCol` = ?, paid_at = $paidAtSql 
          WHERE id = ?";
} else {
  $sql = "UPDATE service_tickets 
          SET `$statusCol` = ? 
          WHERE id = ?";
}

if ($st = $conn->prepare($sql)) {
  $st->bind_param('si', $newStatus, $ticketId);
  $st->execute();
  $st->close();
}

// set flash สำหรับโชว์ข้อความเตือน (ใช้หรือไม่ใช้ก็ได้ แล้วแต่หน้าที่คุณโชว์)
$label = [
  'unpaid'  => 'ยังไม่ชำระ',
  'pending' => 'รอตรวจสอบ',
  'paid'    => 'ชำระแล้ว',
];

$_SESSION['flash'] = 'อัปเดตสถานะการชำระเงินเป็น "'.($label[$newStatus] ?? $newStatus).'" แล้ว';

header('Location: service_ticket_detail.php?id='.$ticketId);
exit;
