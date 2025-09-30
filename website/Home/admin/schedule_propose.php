<?php
// Home/admin/schedule_propose.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

/* ------- helpers ------- */
function col_exists(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $res && $res->num_rows>0;
}
function to_mysql_dt(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='') return null;
  // <input type="datetime-local"> => 2025-09-07T14:30  or 2025-09-07T14:30:00
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $s)) {
    $s = str_replace('T',' ',$s);
    if (strlen($s)===16) $s .= ':00';
    return $s;
  }
  // dd/mm/yyyy HH:MM
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})(:\d{2})?$/', $s, $m)) {
    return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}".(!empty($m[6])?$m[6]:':00');
  }
  // เผื่อส่งมาเป็นฟอร์แมตถูกอยู่แล้ว
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $s)) {
    if (strlen($s)===16) $s .= ':00';
    return $s;
  }
  return null;
}
/* ----------------------- */

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$start_in  = $_POST['slot_start'] ?? $_POST['start'] ?? '';
$end_in    = $_POST['slot_end']   ?? $_POST['end']   ?? '';
$duration  = (int)($_POST['duration'] ?? $_POST['duration_minutes'] ?? 0);
$note      = trim($_POST['note'] ?? '');

if ($ticket_id<=0) { echo json_encode(['ok'=>false,'error'=>'invalid_ticket']); exit; }

$slot_start = to_mysql_dt($start_in);
$slot_end   = to_mysql_dt($end_in);

// ถ้าไม่ได้ส่ง end มา แต่มี duration ให้คำนวณเพิ่ม
if (!$slot_end && $slot_start && $duration>0) {
  $dt = new DateTime($slot_start);
  $dt->modify("+$duration minutes");
  $slot_end = $dt->format('Y-m-d H:i:s');
}
if (!$slot_start) { echo json_encode(['ok'=>false,'error'=>'invalid_datetime']); exit; }

/* ดึงเจ้าของใบงาน */
$owner_id = 0;
if ($st=$conn->prepare("SELECT user_id FROM service_tickets WHERE id=? LIMIT 1")){
  $st->bind_param('i',$ticket_id); $st->execute();
  $owner_id = (int)($st->get_result()->fetch_assoc()['user_id'] ?? 0);
  $st->close();
}
if ($owner_id<=0) { echo json_encode(['ok'=>false,'error'=>'ticket_not_found']); exit; }

$conn->begin_transaction();
try {
  // บันทึกข้อเสนอเวลานัด
  $sql = "INSERT INTO schedule_proposals
            (ticket_type,ticket_id,slot_start,slot_end,duration_minutes,note,status,created_by,customer_id,created_at,updated_at)
          VALUES
            ('repair',?,?,?,?,?, 'pending', ?, ?, NOW(), NOW())";
  $st = $conn->prepare($sql);
  $dur = $duration>0 ? $duration : null;
  $admin_id = (int)$_SESSION['user_id'];
  $st->bind_param('isssisi', $ticket_id, $slot_start, $slot_end, $dur, $note, $admin_id, $owner_id);
  $st->execute();
  $prop_id = $st->insert_id;
  $st->close();

  // อัปเดตสถานะนัดในตารางใบงาน (ถ้ามีคอลัมน์)
  $sets = [];
  if (col_exists($conn,'service_tickets','appointment_status')) $sets[]="appointment_status='pending'";
  if (col_exists($conn,'service_tickets','schedule_status'))     $sets[]="schedule_status='proposed'";
  if (col_exists($conn,'service_tickets','appointment_start'))   $sets[]="appointment_start=".($slot_start?"'".$conn->real_escape_string($slot_start)."'":"NULL");
  if (col_exists($conn,'service_tickets','appointment_end'))     $sets[]="appointment_end=".($slot_end?"'".$conn->real_escape_string($slot_end)."'":"NULL");
  if ($sets) $conn->query("UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=".$ticket_id);

  // แจ้งเตือนลูกค้า
  if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows>0){
    $st = $conn->prepare("INSERT INTO notifications (user_id,title,message,type,ref_id,is_read,created_at)
                          VALUES (?,?,?,?,?,0,NOW())");
    $title = 'มีข้อเสนอเวลานัดใหม่';
    $msg   = 'แอดมินเสนอเวลา: '.$slot_start.($slot_end?(' - '.$slot_end):'').($note?(' • '.$note):'');
    $type  = 'schedule_proposed';
    $ref   = $ticket_id;
    $st->bind_param('isssi', $owner_id, $title, $msg, $type, $ref);
    $st->execute(); $st->close();
  }

  $conn->commit();
  echo json_encode(['ok'=>true,'proposal_id'=>$prop_id,'start'=>$slot_start,'end'=>$slot_end]);
} catch (Throwable $e) {
  $conn->rollback();
  echo json_encode(['ok'=>false,'error'=>'db_error']);
}
