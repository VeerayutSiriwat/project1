<?php
// Home/admin/calendar_drag_update.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

/* input */
$kind  = $_POST['kind']  ?? 'repair'; // repair | proposal | tradein
$id    = (int)($_POST['id'] ?? 0);
$start = trim($_POST['start'] ?? '');
$end   = trim($_POST['end']   ?? '');

/* guard */
if ($id<=0 || $start===''){ echo json_encode(['ok'=>false,'error'=>'invalid_param']); exit; }

function toMysql($s){
  $s = trim($s);
  if ($s==='') return null;
  // already 'Y-m-d H:i:s' ?
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/',$s)) return $s;
  // ISO
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',$s)) return str_replace('T',' ',substr($s,0,19));
  return $s;
}
$start = toMysql($start);
$end   = $end ? toMysql($end) : null;

$conn->begin_transaction();
try{
  if ($kind==='repair'){
    // ย้ายเวลานัดของใบงานซ่อม -> ตั้งเป็นรอยืนยันอีกครั้ง + แจ้งเตือน
    $st = $conn->prepare("UPDATE service_tickets SET appointment_start=?, appointment_end=?, appointment_status='pending' WHERE id=?");
    $st->bind_param('ssi', $start, $end, $id);
    $st->execute(); $st->close();

    // สร้าง proposal ใหม่ (optional เพื่อให้ลูกค้ายืนยัน)
    if ($conn->query("SHOW TABLES LIKE 'schedule_proposals'")->num_rows){
      $uid = 0;
      if ($x=$conn->prepare("SELECT user_id FROM service_tickets WHERE id=?")){ $x->bind_param('i',$id); $x->execute(); $uid=(int)($x->get_result()->fetch_assoc()['user_id']??0); $x->close(); }
      $dur = null;
      if ($start && $end) { $dur = (int) round((strtotime($end)-strtotime($start))/60); }
      $q = $conn->prepare("INSERT INTO schedule_proposals(ticket_type,ticket_id,slot_start,slot_end,duration_minutes,status,created_by,customer_id,created_at,updated_at)
                           VALUES('repair',?,?,?,?, 'pending', ?, ?, NOW(), NOW())");
      $admin = (int)$_SESSION['user_id'];
      $q->bind_param('sssiii', $id, $start, $end, $dur, $admin, $uid);
      $q->execute(); $q->close();

      // แจ้งเตือน
      if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows){
        $title='มีการเปลี่ยนเวลานัด (รอยืนยัน)'; $msg='โปรดตรวจสอบเวลานัดใหม่ในหน้าสถานะงานซ่อม'; $type='schedule_proposed'; $ref=$id;
        $n=$conn->prepare("INSERT INTO notifications(user_id,title,message,type,ref_id,is_read,created_at) VALUES(?,?,?,?,?,0,NOW())");
        $n->bind_param('isssi',$uid,$title,$msg,$type,$ref); $n->execute(); $n->close();
      }
    }
  }
  elseif ($kind==='proposal'){
    $st = $conn->prepare("UPDATE schedule_proposals SET slot_start=?, slot_end=?, updated_at=NOW() WHERE id=? AND status='pending'");
    $st->bind_param('ssi', $start, $end, $id);
    $st->execute(); $st->close();
  }
  else /* tradein */ {
    if ($conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'scheduled_at'")->num_rows){
      $st = $conn->prepare("UPDATE tradein_requests SET scheduled_at=? WHERE id=?");
      $st->bind_param('si', $start, $id);
      $st->execute(); $st->close();
    }
  }

  $conn->commit();
  echo json_encode(['ok'=>true]);
} catch(Throwable $e){
  $conn->rollback();
  echo json_encode(['ok'=>false,'error'=>'db_error']);
}
