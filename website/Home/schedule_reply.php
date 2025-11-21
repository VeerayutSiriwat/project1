<?php
// Home/schedule_reply.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_login']);
    exit;
}
$uid      = (int)$_SESSION['user_id'];
$action   = $_POST['action']  ?? '';
$propId   = (int)($_POST['prop_id']   ?? 0);
$ticketId = (int)($_POST['ticket_id'] ?? 0);

if (!$propId || !$ticketId || !in_array($action, ['accept','decline'], true)) {
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

/* helper: check column exist */
function has_col(mysqli $conn, string $table, string $col): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
    $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $q && $q->num_rows > 0;
}

/* โหลด proposal + ticket และเช็คว่าเป็นของ user นี้จริง */
$sql = "
  SELECT p.*,
         t.user_id, t.status,
         t.desired_date
  FROM schedule_proposals p
  JOIN service_tickets t ON t.id = p.ticket_id
  WHERE p.id = ? AND p.ticket_type = 'repair' AND p.ticket_id = ?
  LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param('ii', $propId, $ticketId);
$st->execute();
$prop = $st->get_result()->fetch_assoc();
$st->close();

if (!$prop) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}
if ((int)$prop['user_id'] !== $uid) {
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {
    $conn->begin_transaction();

    if ($action === 'accept') {
        // เวลาเริ่ม/จบของนัดนี้
        $start = $prop['slot_start'];
        $end   = $prop['slot_end'];

        if (!$end) {
            $dur = (int)($prop['duration_minutes'] ?? 60);
            if ($dur <= 0) $dur = 60;
            $ts = strtotime($start ?: 'now');
            $start = date('Y-m-d H:i:s', $ts);
            $end   = date('Y-m-d H:i:s', $ts + $dur*60);
        }

        // 1) mark ข้อนี้เป็น accepted
        $st = $conn->prepare("UPDATE schedule_proposals SET status='accepted', updated_at=NOW() WHERE id=?");
        $st->bind_param('i', $propId);
        $st->execute();
        $st->close();

        // 2) ข้อเสนออื่นของใบงานเดียวกันที่ยัง pending ให้เปลี่ยนเป็น declined
        $st = $conn->prepare("
          UPDATE schedule_proposals 
          SET status='declined', updated_at=NOW()
          WHERE ticket_type='repair' AND ticket_id=? AND id<>? AND status='pending'
        ");
        $st->bind_param('ii', $ticketId, $propId);
        $st->execute();
        $st->close();

        // 3) เขียนลงใบงานหลัก -> appointment_* + status = confirm
        $hasAppStart = has_col($conn,'service_tickets','appointment_start');
        $hasAppEnd   = has_col($conn,'service_tickets','appointment_end');
        $hasAppStat  = has_col($conn,'service_tickets','appointment_status');
        $hasSchStat  = has_col($conn,'service_tickets','schedule_status');
        $hasSchedAt  = has_col($conn,'service_tickets','scheduled_at');

        $set = [];
        $types = '';
        $vals  = [];

        if ($hasAppStart) { $set[] = 'appointment_start=?'; $types.='s'; $vals[]=$start; }
        if ($hasAppEnd)   { $set[] = 'appointment_end=?';   $types.='s'; $vals[]=$end;   }
        if ($hasAppStat)  { $set[] = "appointment_status='confirmed'"; }
        if ($hasSchStat)  { $set[] = "schedule_status='confirmed'"; }
        if ($hasSchedAt)  { $set[] = 'scheduled_at=?';      $types.='s'; $vals[]=$start; }

        // สถานะหลักของใบงานให้เป็น "ยืนยันคิว"
        $set[] = 'status=?';        $types.='s'; $vals[]='confirm';
        $set[] = 'updated_at=NOW()';

        $sql = "UPDATE service_tickets SET ".implode(',', $set)." WHERE id=?";
        $types .= 'i';
        $vals[] = $ticketId;

        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();

        // 4) log สถานะ
        if ($st = $conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")) {
            $statusLog = 'confirm';
            $note = 'ลูกค้ายืนยันเวลานัดแล้วผ่านระบบ';
            $st->bind_param('iss', $ticketId, $statusLog, $note);
            $st->execute();
            $st->close();
        }

    } elseif ($action === 'decline') {
        // ปฏิเสธนัดนี้
        $st = $conn->prepare("UPDATE schedule_proposals SET status='declined', updated_at=NOW() WHERE id=?");
        $st->bind_param('i', $propId);
        $st->execute();
        $st->close();

        // อัปเดตสถานะนัด (ไม่บังคับเปลี่ยน status หลักของใบงาน)
        $hasAppStat = has_col($conn,'service_tickets','appointment_status');
        $hasSchStat = has_col($conn,'service_tickets','schedule_status');

        if ($hasAppStat || $hasSchStat) {
            $set = [];
            if ($hasAppStat) $set[] = "appointment_status='declined'";
            if ($hasSchStat) $set[] = "schedule_status='declined'";
            $sql = "UPDATE service_tickets SET ".implode(',', $set).", updated_at=NOW() WHERE id=?";
            $st  = $conn->prepare($sql);
            $st->bind_param('i', $ticketId);
            $st->execute();
            $st->close();
        }

        if ($st = $conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")) {
            $statusLog = 'queued'; // ยังอยู่สถานะเข้าคิว แต่บันทึกว่าเวลานัดนี้ถูกปฏิเสธ
            $note = 'ลูกค้าปฏิเสธเวลานัดที่เสนอ';
            $st->bind_param('iss', $ticketId, $statusLog, $note);
            $st->execute();
            $st->close();
        }
    }

    $conn->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => 'internal']);
}
