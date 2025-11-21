<?php
// Home/admin/service_ticket_items_save.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

function h($s){ return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8'); }
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
$do       = $_POST['do'] ?? '';

if ($ticketId <= 0 || !in_array($do, ['add','delete'], true)) {
  header('Location: service_ticket_detail.php?id='.$ticketId);
  exit;
}

// เช็คว่ามีตาราง service_ticket_items มั้ย
$hasItemsTable = $conn->query("SHOW TABLES LIKE 'service_ticket_items'")->num_rows>0;
if (!$hasItemsTable) {
  $_SESSION['flash'] = 'ยังไม่มีตาราง service_ticket_items ในฐานข้อมูล';
  header('Location: service_ticket_detail.php?id='.$ticketId);
  exit;
}

if ($do === 'add') {
  $itemType    = $_POST['item_type'] ?? 'part';
  $description = trim((string)($_POST['description'] ?? ''));
  $qty         = (float)($_POST['qty'] ?? 1);
  $unitPrice   = (float)($_POST['unit_price'] ?? 0);

  if ($description === '' || $qty <= 0) {
    $_SESSION['flash'] = 'กรุณากรอกรายละเอียดและจำนวนให้ถูกต้อง';
    header('Location: service_ticket_detail.php?id='.$ticketId);
    exit;
  }

  if ($st = $conn->prepare("INSERT INTO service_ticket_items (ticket_id,item_type,description,qty,unit_price) VALUES (?,?,?,?,?)")) {
    $st->bind_param('issdd', $ticketId, $itemType, $description, $qty, $unitPrice);
    $st->execute();
    $st->close();
  }

} elseif ($do === 'delete') {
  $itemId = (int)($_POST['item_id'] ?? 0);
  if ($itemId > 0) {
    if ($st = $conn->prepare("DELETE FROM service_ticket_items WHERE id=? AND ticket_id=?")) {
      $st->bind_param('ii', $itemId, $ticketId);
      $st->execute();
      $st->close();
    }
  }
}

/* คำนวณยอดรวมใหม่ และอัปเดต service_price (ถ้ามีคอลัมน์) */
$sum = 0.0;
if ($st = $conn->prepare("SELECT qty, unit_price FROM service_ticket_items WHERE ticket_id=?")) {
  $st->bind_param('i', $ticketId);
  $st->execute();
  $rs = $st->get_result();
  while($row = $rs->fetch_assoc()){
    $sum += (float)$row['qty'] * (float)$row['unit_price'];
  }
  $st->close();
}

if (has_col($conn,'service_tickets','service_price')) {
  if ($st = $conn->prepare("UPDATE service_tickets SET service_price=? WHERE id=?")) {
    $st->bind_param('di', $sum, $ticketId);
    $st->execute();
    $st->close();
  }
}

$_SESSION['flash'] = 'อัปเดตรายการซ่อมเรียบร้อยแล้ว (ยอดรวม: '.number_format($sum,2).' ฿)';
header('Location: service_ticket_detail.php?id='.$ticketId);
exit;
