<?php
// Home/schedule_reply.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows>0;
}

$uid       = (int)$_SESSION['user_id'];
$action    = $_POST['action'] ?? '';
$prop_id   = (int)($_POST['prop_id'] ?? 0);
$ticket_id = (int)($_POST['ticket_id'] ?? 0);

if($prop_id<=0 || $ticket_id<=0 || !in_array($action,['accept','decline'],true)){
  echo json_encode(['ok'=>false,'error'=>'invalid']); exit;
}

/* ดึงข้อเสนอ + ตรวจสอบสิทธิ์เจ้าของใบงาน */
$prop = null; $ticket = null;
if($st=$conn->prepare("SELECT * FROM schedule_proposals WHERE id=? AND ticket_type='repair' LIMIT 1")){
  $st->bind_param('i',$prop_id); $st->execute();
  $prop = $st->get_result()->fetch_assoc(); $st->close();
}
if(!$prop || (int)$prop['ticket_id']!==$ticket_id){ echo json_encode(['ok'=>false,'error'=>'proposal_not_found']); exit; }

if($st=$conn->prepare("SELECT id,user_id FROM service_tickets WHERE id=? LIMIT 1")){
  $st->bind_param('i',$ticket_id); $st->execute();
  $ticket = $st->get_result()->fetch_assoc(); $st->close();
}
if(!$ticket || (int)$ticket['user_id'] !== $uid){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

if($action==='decline'){
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='declined',updated_at=NOW() WHERE id=? AND status='pending'")){
    $st->bind_param('i',$prop_id); $ok=$st->execute(); $st->close();
  } else $ok=false;

  // อัปเดตสถานะนัดของใบงานเป็น declined ถ้ามีคอลัมน์
  $sets=[];
  if (has_col($conn,'service_tickets','appointment_status')) $sets[]="appointment_status='declined'";
  if (has_col($conn,'service_tickets','schedule_status'))   $sets[]="schedule_status='declined'";
  if ($sets) $conn->query("UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=".$ticket_id);

  // แจ้งเตือนแอดมิน
  if($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows>0){
    $admins = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 20");
    while($a = $admins->fetch_assoc()){
      if($st=$conn->prepare("INSERT INTO notifications(user_id,title,message,type,ref_id,is_read,created_at) VALUES(?,?,?,?,?,0,NOW())")){
        $title='ลูกค้าปฏิเสธเวลานัด';
        $msg='ลูกค้าปฏิเสธข้อเสนอเวลานัดของใบงาน ST-'.$ticket_id;
        $type='schedule_declined';
        $ref=$ticket_id;
        $st->bind_param('isssi',$a['id'],$title,$msg,$type,$ref);
        $st->execute(); $st->close();
      }
    }
  }
  echo json_encode(['ok'=> (bool)($ok ?? false)]); exit;
}

/* accept */
$conn->begin_transaction();
try{
  // ยืนยันข้อเสนอ
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='accepted',updated_at=NOW() WHERE id=? AND status='pending'")){
    $st->bind_param('i',$prop_id); $st->execute(); $st->close();
  }
  // ยกเลิกข้อเสนออื่นของใบงานนี้ที่ยัง pending
  if($st=$conn->prepare("UPDATE schedule_proposals SET status='cancelled',updated_at=NOW() WHERE ticket_type='repair' AND ticket_id=? AND id<>? AND status='pending'")){
    $st->bind_param('ii',$ticket_id,$prop_id); $st->execute(); $st->close();
  }
  // อัปเดต service_tickets ให้เป็นนัดยืนยันแล้ว
  $sets=[];
  if (has_col($conn,'service_tickets','appointment_start')) $sets[]="appointment_start='". $conn->real_escape_string($prop['slot_start'])."'";
  if (has_col($conn,'service_tickets','appointment_end'))   $sets[]="appointment_end='".   $conn->real_escape_string($prop['slot_end'])."'";
  if (has_col($conn,'service_tickets','appointment_status'))$sets[]="appointment_status='confirmed'";
  if (has_col($conn,'service_tickets','schedule_status'))   $sets[]="schedule_status='confirmed'";
  if (has_col($conn,'service_tickets','scheduled_at') && !has_col($conn,'service_tickets','appointment_start'))
    $sets[]="scheduled_at='". $conn->real_escape_string($prop['slot_start'])."'";
  if ($sets) $conn->query("UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=".$ticket_id);

  $conn->commit();
}catch(Throwable $e){
  $conn->rollback();
  echo json_encode(['ok'=>false,'error'=>'tx_fail']); exit;
}

/* แจ้งเตือนแอดมิน */
if($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows>0){
  $admins = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 20");
  while($a=$admins->fetch_assoc()){
    if($st=$conn->prepare("INSERT INTO notifications(user_id,title,message,type,ref_id,is_read,created_at) VALUES(?,?,?,?,?,0,NOW())")){
      $title='ลูกค้ายืนยันเวลานัด';
      $msg='ลูกค้ายืนยันเวลานัดของใบงาน ST-'.$ticket_id;
      $type='schedule_confirmed';
      $ref=$ticket_id;
      $st->bind_param('isssi',$a['id'],$title,$msg,$type,$ref);
      $st->execute(); $st->close();
    }
  }
}

echo json_encode(['ok'=>true]);
