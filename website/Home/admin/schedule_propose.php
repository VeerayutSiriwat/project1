<?php
// Home/admin/schedule_propose.php  (fixed + token required)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
  echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

/* helpers */
function col_exists(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $res && $res->num_rows>0;
}
function to_mysql_dt(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $s)) {
    $s = str_replace('T',' ',$s);
    if (strlen($s)===16) $s .= ':00';
    return $s;
  }
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})(:\d{2})?$/', $s, $m)) {
    return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}".(!empty($m[6])?$m[6]:':00');
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $s)) {
    if (strlen($s)===16) $s .= ':00';
    return $s;
  }
  return null;
}

/* input */
$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$start_in  = $_POST['slot_start'] ?? $_POST['start'] ?? '';
$end_in    = $_POST['slot_end']   ?? $_POST['end']   ?? '';
$duration  = (int)($_POST['duration'] ?? $_POST['duration_minutes'] ?? 0);
$note      = trim($_POST['note'] ?? '');

if ($ticket_id<=0) { echo json_encode(['ok'=>false,'error'=>'invalid_ticket']); exit; }

$slot_start = to_mysql_dt($start_in);
$slot_end   = to_mysql_dt($end_in);
if (!$slot_start) { echo json_encode(['ok'=>false,'error'=>'invalid_datetime']); exit; }

$dur = $duration > 0 ? $duration : 60;
if (!$slot_end) {
  $dt = new DateTime($slot_start);
  $dt->modify("+$dur minutes");
  $slot_end = $dt->format('Y-m-d H:i:s');
}

/* owner */
$owner_id = 0;
if ($st=$conn->prepare("SELECT user_id FROM service_tickets WHERE id=? LIMIT 1")){
  $st->bind_param('i',$ticket_id); $st->execute();
  $owner_id = (int)($st->get_result()->fetch_assoc()['user_id'] ?? 0);
  $st->close();
}
if ($owner_id<=0) { echo json_encode(['ok'=>false,'error'=>'ticket_not_found']); exit; }

$conn->begin_transaction();
try {
  /* describe table once */
  $colsRes = $conn->query("SHOW COLUMNS FROM `schedule_proposals`");
  if(!$colsRes){ throw new Exception("describe_failed: ".$conn->error); }
  $have = [];
  while($c = $colsRes->fetch_assoc()){ $have[$c['Field']] = true; }

  /* dynamic insert */
  $insCols = [];
  $place   = [];
  $types   = '';
  $params  = [];

  if (!empty($have['ticket_type'])) { $insCols[]='ticket_type'; $place[]='?'; $types.='s'; $params[]='repair'; }
  $insCols[]='ticket_id'; $place[]='?'; $types.='i'; $params[]=$ticket_id;

  if (!empty($have['slot_start'])) { $insCols[]='slot_start'; $place[]='?'; $types.='s'; $params[]=$slot_start; }
  if (!empty($have['slot_end']))   { $insCols[]='slot_end';   $place[]='?'; $types.='s'; $params[]=$slot_end; }
  if (!empty($have['duration_minutes'])) { $insCols[]='duration_minutes'; $place[]='?'; $types.='i'; $params[]=$dur; }
  if (!empty($have['note']))       { $insCols[]='note';       $place[]='?'; $types.='s'; $params[]=$note; }

  /* REQUIRED: token (NOT NULL, no default) */
  if (!empty($have['token'])) {
    $token = bin2hex(random_bytes(16)); // 32 chars
    $insCols[]='token'; $place[]='?'; $types.='s'; $params[]=$token;
  }

  if (!empty($have['status']))     { $insCols[]='status';     $place[]='?'; $types.='s'; $params[]='pending'; }
  if (!empty($have['created_by'])) { $insCols[]='created_by'; $place[]='?'; $types.='i'; $params[]=(int)$_SESSION['user_id']; }
  if (!empty($have['customer_id'])){ $insCols[]='customer_id';$place[]='?'; $types.='i'; $params[]=$owner_id; }
  if (!empty($have['created_at'])) { $insCols[]='created_at'; $place[]='NOW()'; }
  if (!empty($have['updated_at'])) { $insCols[]='updated_at'; $place[]='NOW()'; }

  $sql = "INSERT INTO schedule_proposals (".implode(',', $insCols).") VALUES (".implode(',', $place).")";
  $st = $conn->prepare($sql);
  if(!$st){ throw new Exception('prepare_failed: '.$conn->error); }
  if ($types!==''){ $st->bind_param($types, ...$params); }
  if(!$st->execute()){
    $err = $st->error ?: $conn->error;
    $st->close();
    throw new Exception('execute_failed: '.$err);
  }
  $prop_id = $st->insert_id;
  $st->close();

  /* sync to service_tickets */
  $sets = [];
  if (col_exists($conn,'service_tickets','appointment_status')) $sets[]="appointment_status='pending'";
  if (col_exists($conn,'service_tickets','schedule_status'))     $sets[]="schedule_status='proposed'";
  if (col_exists($conn,'service_tickets','appointment_start'))   $sets[]="appointment_start='".$conn->real_escape_string($slot_start)."'";
  if (col_exists($conn,'service_tickets','appointment_end'))     $sets[]="appointment_end='".$conn->real_escape_string($slot_end)."'";
  if ($sets) {
    if(!$conn->query("UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=".$ticket_id)){
      throw new Exception('update_ticket_failed: '.$conn->error);
    }
  }

  /* notification (optional) */
  if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows>0){
    $st = $conn->prepare("INSERT INTO notifications (user_id,title,message,type,ref_id,is_read,created_at)
                          VALUES (?,?,?,?,?,0,NOW())");
    if($st){
      $title = 'มีข้อเสนอเวลานัดใหม่';
      $msg   = 'แอดมินเสนอเวลา: '.$slot_start.' - '.$slot_end.($note?(' • '.$note):'');
      $type  = 'schedule_proposed';
      $ref   = $ticket_id;
      $st->bind_param('isssi', $owner_id, $title, $msg, $type, $ref);
      $st->execute(); $st->close();
    }
  }

  $conn->commit();
  echo json_encode(['ok'=>true,'proposal_id'=>$prop_id,'start'=>$slot_start,'end'=>$slot_end]);
} catch (Throwable $e) {
  $conn->rollback();
  echo json_encode(['ok'=>false,'error'=>'db_error','error_text'=>$e->getMessage()]);
}
