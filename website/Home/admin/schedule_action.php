<?php
// Home/admin/schedule_action.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$action   = $_POST['action'] ?? '';
$propId   = (int)($_POST['prop_id']   ?? 0);
$ticketId = (int)($_POST['ticket_id'] ?? 0);

function has_col(mysqli $conn, string $table, string $col): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
    $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $q && $q->num_rows > 0;
}

if (!in_array($action, ['cancel-prop','force-confirm','clear-appointment'], true)) {
    echo json_encode(['ok'=>false,'error'=>'bad_action']); exit;
}
if ($ticketId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'no_ticket']); exit;
}

try{
    $conn->begin_transaction();

    if ($action === 'cancel-prop') {
        if ($propId <= 0) { throw new Exception('no_prop'); }
        $st = $conn->prepare("
          UPDATE schedule_proposals
          SET status='cancelled', updated_at=NOW()
          WHERE id=? AND ticket_type='repair'
        ");
        $st->bind_param('i', $propId);
        $st->execute();
        $st->close();

    } elseif ($action === 'force-confirm') {
        if ($propId <= 0) { throw new Exception('no_prop'); }

        // ดึง proposal เพื่อใช้เวลานัด
        $st = $conn->prepare("
          SELECT * FROM schedule_proposals
          WHERE id=? AND ticket_type='repair' AND ticket_id=?
          LIMIT 1
        ");
        $st->bind_param('ii', $propId, $ticketId);
        $st->execute();
        $prop = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$prop) { throw new Exception('prop_not_found'); }

        $start = $prop['slot_start'];
        $end   = $prop['slot_end'];
        if (!$end) {
            $dur = (int)($prop['duration_minutes'] ?? 60);
            if ($dur <= 0) $dur = 60;
            $ts = strtotime($start ?: 'now');
            $start = date('Y-m-d H:i:s', $ts);
            $end   = date('Y-m-d H:i:s', $ts + $dur*60);
        }

        // mark proposal นี้เป็น accepted
        $st = $conn->prepare("UPDATE schedule_proposals SET status='accepted', updated_at=NOW() WHERE id=?");
        $st->bind_param('i', $propId);
        $st->execute();
        $st->close();

        // อื่น ๆ ที่ยัง pending ให้ declined
        $st = $conn->prepare("
          UPDATE schedule_proposals
          SET status='declined', updated_at=NOW()
          WHERE ticket_type='repair' AND ticket_id=? AND id<>? AND status='pending'
        ");
        $st->bind_param('ii', $ticketId, $propId);
        $st->execute();
        $st->close();

        // เขียนลงใบงานหลัก
        $hasAppStart = has_col($conn,'service_tickets','appointment_start');
        $hasAppEnd   = has_col($conn,'service_tickets','appointment_end');
        $hasAppStat  = has_col($conn,'service_tickets','appointment_status');
        $hasSchStat  = has_col($conn,'service_tickets','schedule_status');
        $hasSchedAt  = has_col($conn,'service_tickets','scheduled_at');

        $set   = [];
        $types = '';
        $vals  = [];

        if ($hasAppStart) { $set[]='appointment_start=?'; $types.='s'; $vals[]=$start; }
        if ($hasAppEnd)   { $set[]='appointment_end=?';   $types.='s'; $vals[]=$end; }
        if ($hasAppStat)  { $set[]="appointment_status='confirmed'"; }
        if ($hasSchStat)  { $set[]="schedule_status='confirmed'"; }
        if ($hasSchedAt)  { $set[]='scheduled_at=?';       $types.='s'; $vals[]=$start; }

        $set[] = 'status=?';        $types.='s'; $vals[]='confirm';
        $set[] = 'updated_at=NOW()';

        $sql = "UPDATE service_tickets SET ".implode(',', $set)." WHERE id=?";
        $types .= 'i';
        $vals[] = $ticketId;

        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();

        // log
        if ($st = $conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")) {
            $statusLog = 'confirm';
            $note = 'แอดมินยืนยันเวลานัดให้ลูกค้า';
            $st->bind_param('iss', $ticketId, $statusLog, $note);
            $st->execute();
            $st->close();
        }

    } elseif ($action === 'clear-appointment') {

        // เคลียร์ทุก field นัดในใบงาน + schedule_proposals
        $hasAppStart = has_col($conn,'service_tickets','appointment_start');
        $hasAppEnd   = has_col($conn,'service_tickets','appointment_end');
        $hasAppStat  = has_col($conn,'service_tickets','appointment_status');
        $hasSchStat  = has_col($conn,'service_tickets','schedule_status');
        $hasSchedAt  = has_col($conn,'service_tickets','scheduled_at');

        $set = [];
        if ($hasAppStart) $set[]="appointment_start=NULL";
        if ($hasAppEnd)   $set[]="appointment_end=NULL";
        if ($hasAppStat)  $set[]="appointment_status='none'";
        if ($hasSchStat)  $set[]="schedule_status='none'";
        if ($hasSchedAt)  $set[]="scheduled_at=NULL";
        $set[] = "updated_at=NOW()";

        $sql = "UPDATE service_tickets SET ".implode(',', $set)." WHERE id=?";
        $st  = $conn->prepare($sql);
        $st->bind_param('i', $ticketId);
        $st->execute();
        $st->close();

        // ยกเลิก proposal ที่เคย pending/accepted
        $st = $conn->prepare("
          UPDATE schedule_proposals
          SET status='cancelled', updated_at=NOW()
          WHERE ticket_type='repair' AND ticket_id=? AND status IN ('pending','accepted')
        ");
        $st->bind_param('i', $ticketId);
        $st->execute();
        $st->close();

        // log แจ้งเตือนว่าเคลียร์นัดแล้ว (ไม่เปลี่ยนสถานะใบงาน)
        if ($st = $conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")) {
            $statusLog = 'queued';
            $note = 'แอดมินล้างนัดหมายที่ตั้งไว้';
            $st->bind_param('iss', $ticketId, $statusLog, $note);
            $st->execute();
            $st->close();
        }
    }

    $conn->commit();
    echo json_encode(['ok'=>true]);
}catch(Throwable $e){
    $conn->rollback();
    echo json_encode(['ok'=>false,'error'=>'internal']);
}
