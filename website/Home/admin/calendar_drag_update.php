<?php
// Home/admin/calendar_drag_update.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$kind  = $_POST['kind']   ?? 'repair'; // repair | proposal | tradein
$id    = (int)($_POST['id'] ?? 0);
$start = trim($_POST['start'] ?? '');
$end   = trim($_POST['end'] ?? '');

if ($id<=0 || $start==='') { echo json_encode(['ok'=>false,'error'=>'bad_request']); exit; }

function norm($s){
  // รับรูปแบบ "YYYY-MM-DD HH:MM:SS"
  $s = substr($s,0,19);
  $s = str_replace('T', ' ', $s);
  return $s;
}
$start = norm($start);
$end   = $end ? norm($end) : null;

try{
  switch ($kind) {
    case 'repair':
      // ถ้า schema ใหม่: ใช้ appointment_start/end; ถ้าไม่มี ใช้ scheduled_at
      $hasAppStart = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'appointment_start'")->num_rows>0;
      $hasAppEnd   = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'appointment_end'")->num_rows>0;
      if ($hasAppStart) {
        if ($hasAppEnd) {
          $st = $conn->prepare("UPDATE service_tickets SET appointment_start=?, appointment_end=?, appointment_status='confirmed', schedule_status='confirmed', updated_at=NOW() WHERE id=?");
          $st->bind_param('ssi', $start, $end, $id);
        } else {
          $st = $conn->prepare("UPDATE service_tickets SET appointment_start=?, appointment_status='confirmed', schedule_status='confirmed', updated_at=NOW() WHERE id=?");
          $st->bind_param('si', $start, $id);
        }
      } else {
        // fallback schema เก่า
        $st = $conn->prepare("UPDATE service_tickets SET scheduled_at=?, updated_at=NOW() WHERE id=?");
        $st->bind_param('si', $start, $id);
      }
      $st->execute(); $st->close();
      echo json_encode(['ok'=>true]); exit;

    case 'proposal':
      // อัปเดตช่วงเวลาของข้อเสนอ (เฉพาะ pending เท่านั้น)
      $hasDur = $conn->query("SHOW COLUMNS FROM schedule_proposals LIKE 'duration_minutes'")->num_rows>0;
      if ($end === null && $hasDur) {
        // ถ้าไม่มี end ให้คำนวณจาก duration_minutes เดิม
        $st = $conn->prepare("UPDATE schedule_proposals SET slot_start=?, status='pending', updated_at=NOW() WHERE id=? AND status='pending'");
        $st->bind_param('si', $start, $id);
      } else {
        $st = $conn->prepare("UPDATE schedule_proposals SET slot_start=?, slot_end=?, status='pending', updated_at=NOW() WHERE id=? AND status='pending'");
        $st->bind_param('ssi', $start, $end, $id);
      }
      $st->execute();
      $ok = $st->affected_rows > 0; $st->close();
      echo json_encode(['ok'=>$ok]); exit;

    case 'tradein':
      // เทิร์น: ใช้ appointment_start/end หรือ scheduled_at แล้วแต่ schema
      $trStart = $conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'appointment_start'")->num_rows>0 ? 'appointment_start' : 'scheduled_at';
      $trEnd   = $conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'appointment_end'")->num_rows>0 ? 'appointment_end' : null;
      if ($trStart) {
        if ($trEnd) {
          $st = $conn->prepare("UPDATE tradein_requests SET $trStart=?, $trEnd=?, updated_at=NOW() WHERE id=?");
          $st->bind_param('ssi', $start, $end, $id);
        } else {
          $st = $conn->prepare("UPDATE tradein_requests SET $trStart=?, updated_at=NOW() WHERE id=?");
          $st->bind_param('si', $start, $id);
        }
        $st->execute(); $st->close();
        echo json_encode(['ok'=>true]); exit;
      }
      echo json_encode(['ok'=>false,'error'=>'schema_missing']); exit;

    default:
      echo json_encode(['ok'=>false,'error'=>'unknown_kind']); exit;
  }
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'exception']); exit;
}
