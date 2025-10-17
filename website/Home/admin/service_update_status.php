<?php
// Home/admin/service_update_status.php
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

/* helper: มีคอลัมน์ไหม */
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}

/* canonical สถานะไป enum ของตารางหลัก */
function canonical_status($s){
  $s = strtolower($s);
  switch ($s) {
    case 'queued':    return 'queued';
    case 'checking': return 'diagnose';
    case 'confirm':  return 'queued';   // << สำคัญ: ยืนยันคิว = เข้าคิว
    default:         return $s;         // pricing, repairing, done, returned, cancelled ...
  }
}

$canon = canonical_status($status);

/* เตรียมคอลัมน์นัดหมายตาม schema ที่มีจริง */
$hasAppStart = has_col($conn,'service_tickets','appointment_start');
$hasAppEnd   = has_col($conn,'service_tickets','appointment_end');
$hasAppStat  = has_col($conn,'service_tickets','appointment_status');
$hasSchStat  = has_col($conn,'service_tickets','schedule_status');
$hasSchedAt  = has_col($conn,'service_tickets','scheduled_at'); // schema เก่า

$conn->begin_transaction();
try{
  $apptStart = null; $apptEnd = null;

  /* กรณีผู้ใช้กดยืนยันคิว: พยายามกำหนดวัน-เวลาให้ใบงานด้วย */
  if (strtolower($status)==='confirm') {

    // 1) เอาข้อเสนอเวลาที่ pending ล่าสุด
    $hasProp = $conn->query("SHOW TABLES LIKE 'schedule_proposals'")->num_rows>0;
    if ($hasProp) {
      if ($st=$conn->prepare("SELECT id, slot_start, COALESCE(slot_end, DATE_ADD(slot_start, INTERVAL COALESCE(duration_minutes,60) MINUTE)) AS slot_end
                              FROM schedule_proposals 
                              WHERE ticket_type='repair' AND ticket_id=? AND status='pending'
                              ORDER BY id DESC LIMIT 1")){
        $st->bind_param('i',$id); $st->execute();
        $prop = $st->get_result()->fetch_assoc(); $st->close();
        if ($prop) { $apptStart=$prop['slot_start']; $apptEnd=$prop['slot_end']; }
      }
    }

    // 2) ถ้าไม่มี proposal ให้ใช้ desired_date ของใบงาน
    if (!$apptStart) {
      if ($st=$conn->prepare("SELECT desired_date FROM service_tickets WHERE id=?")){
        $st->bind_param('i',$id); $st->execute();
        $row=$st->get_result()->fetch_assoc(); $st->close();
        $dd = trim((string)($row['desired_date'] ?? ''));
        if ($dd!=='') {
          // ปรับให้เป็น Y-m-d H:i:s ถ้าได้มาเป็นวันที่ล้วน
          $ts = strtotime($dd);
          if ($ts!==false) {
            $apptStart = date('Y-m-d H:i:s', $ts);
            $apptEnd   = date('Y-m-d H:i:s', $ts + 60*60);
          }
        }
      }
    }

    // 3) ถ้าทั้งสองทางไม่มี ให้ใช้เวลาปัจจุบัน +60 นาที
    if (!$apptStart) {
      $apptStart = date('Y-m-d H:i:s');
      $apptEnd   = date('Y-m-d H:i:s', time()+60*60);
    }

    // เซ็ตนัดหมายลงตารางหลักตาม schema
    if ($hasAppStart) {
      if ($hasAppEnd) {
        $st = $conn->prepare("UPDATE service_tickets 
                              SET appointment_start=?, appointment_end=?, 
                                  ".($hasAppStat?"appointment_status='confirmed',":"")."
                                  ".($hasSchStat?"schedule_status='confirmed',":"")."
                                  updated_at=NOW()
                              WHERE id=?");
        $st->bind_param('ssi',$apptStart,$apptEnd,$id);
      } else {
        $st = $conn->prepare("UPDATE service_tickets 
                              SET appointment_start=?, 
                                  ".($hasAppStat?"appointment_status='confirmed',":"")."
                                  ".($hasSchStat?"schedule_status='confirmed',":"")."
                                  updated_at=NOW()
                              WHERE id=?");
        $st->bind_param('si',$apptStart,$id);
      }
      $st->execute(); $st->close();
    } elseif ($hasSchedAt) {
      $st = $conn->prepare("UPDATE service_tickets SET scheduled_at=?, updated_at=NOW() WHERE id=?");
      $st->bind_param('si',$apptStart,$id);
      $st->execute(); $st->close();
    }

    // ถ้ามี proposal และเราเลือกใช้ตัวล่าสุด ให้ mark accepted
    if (!empty($prop['id'])) {
      if ($st=$conn->prepare("UPDATE schedule_proposals SET status='accepted', updated_at=NOW() WHERE id=? AND status='pending'")){
        $st->bind_param('i',$prop['id']); $st->execute(); $st->close();
      }
    }
  }

  /* อัปเดตสถานะหลัก (canonical) */
  if($st=$conn->prepare("UPDATE service_tickets SET status=?, updated_at=NOW() WHERE id=?")){
    $st->bind_param("si",$canon,$id); $st->execute(); $st->close();
  }

  /* เติม log (เก็บค่าที่พิมพ์จริง) */
  if($st=$conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")){
    $st->bind_param("iss",$id,$status,$note); $st->execute(); $st->close();
  }

  $conn->commit();
}catch(Throwable $e){
  $conn->rollback();
}

header('Location: service_ticket_detail.php?id='.$id);
