<?php
// Home/admin/schedule_action.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}

$action   = $_POST['action'] ?? '';
$prop_id  = (int)($_POST['prop_id'] ?? 0);
$ticket_id= (int)($_POST['ticket_id'] ?? 0);

if($action==='cancel-prop'){
  if($prop_id<=0){ echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='cancelled',updated_at=NOW() WHERE id=? AND status='pending'")){
    $st->bind_param('i',$prop_id);
    $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'update_failed']); exit;
}

if($action==='force-confirm'){
  if($prop_id<=0 || $ticket_id<=0){ echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }

  // ดึงข้อเสนอ
  $p=null;
  if($st=$conn->prepare("SELECT * FROM schedule_proposals WHERE id=? AND ticket_type='repair' LIMIT 1")){
    $st->bind_param('i',$prop_id); $st->execute();
    $p=$st->get_result()->fetch_assoc(); $st->close();
  }
  if(!$p){ echo json_encode(['ok'=>false,'error'=>'proposal_not_found']); exit; }

  // ยืนยันข้อเสนอ
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='accepted',updated_at=NOW() WHERE id=?")){
    $st->bind_param('i',$prop_id); $st->execute(); $st->close();
  }
  // ยกเลิกข้อเสนออื่นๆ ของใบงานเดียวกันที่ยัง pending
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='cancelled',updated_at=NOW() WHERE ticket_type='repair' AND ticket_id=? AND id<>? AND status='pending'")){
    $st->bind_param('ii',$ticket_id,$prop_id); $st->execute(); $st->close();
  }

  // อัปเดต service_tickets (ตั้งเวลา + สถานะ)
  $set = [];
  if (has_col($conn,'service_tickets','appointment_start')) $set[]="appointment_start='". $conn->real_escape_string($p['slot_start'])."'";
  if (has_col($conn,'service_tickets','appointment_end'))   $set[]="appointment_end='".   $conn->real_escape_string($p['slot_end'])."'";
  if (has_col($conn,'service_tickets','appointment_status'))$set[]="appointment_status='confirmed'";
  if (has_col($conn,'service_tickets','schedule_status'))   $set[]="schedule_status='confirmed'";
  if ($set){
    $conn->query("UPDATE service_tickets SET ".implode(',', $set)." WHERE id=".$ticket_id);
  }

  // แจ้งเตือน
  $hasNoti = ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows>0);
  if($hasNoti){
    // หาเจ้าของใบงาน
    $t=null;
    if($st=$conn->prepare("SELECT user_id FROM service_tickets WHERE id=?")){
      $st->bind_param('i',$ticket_id); $st->execute();
      $t=$st->get_result()->fetch_assoc(); $st->close();
    }
    if($t){
      if($st=$conn->prepare("INSERT INTO notifications(user_id,title,message,type,ref_id,is_read,created_at) VALUES(?,?,?,?,?,0,NOW())")){
        $title='ยืนยันนัดหมายซ่อมแล้ว';
        $msg='ระบบยืนยันนัดหมายตามเวลาที่เลือก';
        $type='schedule_confirmed';
        $ref=$ticket_id;
        $st->bind_param('isssi', $t['user_id'], $title, $msg, $type, $ref);
        $st->execute(); $st->close();
      }
    }
  }

  echo json_encode(['ok'=>true]); exit;
}

if($action==='clear-appointment'){
  if($ticket_id<=0){ echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }
  $sets = [];
  if (has_col($conn,'service_tickets','appointment_start')) $sets[]="appointment_start=NULL";
  if (has_col($conn,'service_tickets','appointment_end'))   $sets[]="appointment_end=NULL";
  if (has_col($conn,'service_tickets','appointment_status'))$sets[]="appointment_status='none'";
  if (has_col($conn,'service_tickets','schedule_status'))   $sets[]="schedule_status='none'";
  if ($sets){
    $ok = $conn->query("UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=".$ticket_id);
    echo json_encode(['ok'=> (bool)$ok]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'no_columns']); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown_action']);
