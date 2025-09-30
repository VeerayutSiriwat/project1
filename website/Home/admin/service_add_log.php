<?php
// Home/admin/service_add_log.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  http_response_code(403); exit('forbidden');
}

/* รับได้ทั้ง id และ ticket_id */
$id     = (int)($_POST['id'] ?? ($_POST['ticket_id'] ?? 0));
$status = trim((string)($_POST['status'] ?? ''));
$note   = trim((string)($_POST['note'] ?? ''));

if($id<=0 || $status===''){ header('Location: service_tickets.php'); exit; }

function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}
function canonical_status($s){
  $s = strtolower($s);
  switch ($s) {
    case 'queue':    return 'queued';
    case 'checking': return 'diagnose';
    case 'confirm':  return 'queued';
    default:         return $s;
  }
}
$canon = canonical_status($status);

/* เพิ่ม log (เก็บค่าที่พิมพ์จริง) */
if($st=$conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")){
  $st->bind_param("iss",$id,$status,$note);
  $st->execute(); $st->close();
}

/* sync status หลัก */
if($st=$conn->prepare("UPDATE service_tickets SET status=?, updated_at=NOW() WHERE id=?")){
  $st->bind_param("si",$canon,$id); $st->execute(); $st->close();
}

/* ถ้าเป็น confirm ให้ตั้ง appointment_status เป็น confirmed (ถ้ามีคอลัมน์) */
if (strtolower($status)==='confirm') {
  $set = [];
  if (has_col($conn,'service_tickets','appointment_status')) $set[] = "appointment_status='confirmed'";
  if (has_col($conn,'service_tickets','schedule_status'))    $set[] = "schedule_status='confirmed'";
  if ($set) { $conn->query("UPDATE service_tickets SET ".implode(',', $set)." WHERE id=".$id); }
}

header('Location: service_ticket_detail.php?id='.$id);
