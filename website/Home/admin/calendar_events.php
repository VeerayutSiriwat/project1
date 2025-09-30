<?php
// Home/admin/calendar_events.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  http_response_code(403); echo json_encode([]); exit;
}

/* รับช่วงวันที่จาก FullCalendar */
$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

function parse_iso_dt($s){
  // FullCalendar ส่งรูปแบบ ISO เช่น 2025-10-01 หรือ 2025-10-01T00:00:00Z
  if(!$s) return null;
  $s = substr($s, 0, 19);
  $s = str_replace('T', ' ', $s);
  return $s;
}

$startDt = parse_iso_dt($start) ?: date('Y-m-d 00:00:00');
$endDt   = parse_iso_dt($end)   ?: date('Y-m-d 23:59:59');

$events = [];

/* ---------- งานซ่อมที่ยืนยันแล้ว (appointment_* หรือ scheduled_at) ---------- */
/* ใช้ได้ทั้ง schema ใหม่ (appointment_start/end, appointment_status) และเก่า (scheduled_at) */
$hasAppStart = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'appointment_start'")->num_rows>0;
$hasAppEnd   = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'appointment_end'")->num_rows>0;
$hasAppStat  = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'appointment_status'")->num_rows>0;
$hasSchedAt  = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'scheduled_at'")->num_rows>0;

$timeStartExpr = $hasAppStart ? 'appointment_start' : ($hasSchedAt ? 'scheduled_at' : null);
$timeEndExpr   = $hasAppEnd   ? 'appointment_end'   : ($hasSchedAt ? "DATE_ADD(scheduled_at, INTERVAL 60 MINUTE)" : null);
if ($timeStartExpr) {
  $whereConfirm = "($timeStartExpr IS NOT NULL)";
  if ($hasAppStat) { // ถ้ามีคอลัมน์สถานะนัด ให้แสดงเฉพาะยืนยันแล้ว
    $whereConfirm .= " AND (appointment_status='confirmed' OR schedule_status='confirmed' OR schedule_status='proposed')"; 
    // หมายเหตุ: บางระบบย้ายจาก proposed->confirmed โดยยังค้างค่า schedule_status=proposed; เผื่อไว้
  }
  $sqlRepair = "
    SELECT id, device_type, brand, model, urgency,
           $timeStartExpr AS s_start,
           ".($timeEndExpr ?: 'NULL')." AS s_end
    FROM service_tickets
    WHERE $whereConfirm
      AND $timeStartExpr < ?
      AND (".($timeEndExpr ?: $timeStartExpr).") >= ?
  ";
  if ($st = $conn->prepare($sqlRepair)) {
    $st->bind_param('ss', $endDt, $startDt);
    $st->execute();
    $rs = $st->get_result();
    while($r = $rs->fetch_assoc()){
      $title = 'ซ่อม: '.trim(($r['device_type']?:'').' '.$r['brand'].' '.$r['model']);
      $isUrgent = ($r['urgency']??'')==='urgent';
      $events[] = [
        'id'    => 'repair-'.$r['id'],
        'title' => $title,
        'start' => $r['s_start'],
        'end'   => $r['s_end'],
        'url'   => 'service_ticket_detail.php?id='.(int)$r['id'],
        'allDay'=> false,
        // ถ้าเป็นงานยืนยันแล้ว ให้สี success; ถ้าเร่งด่วนให้ danger
        'backgroundColor' => $isUrgent ? '#dc3545' : '#198754',
        'borderColor'     => $isUrgent ? '#dc3545' : '#198754',
        'textColor'       => '#fff',
        'extendedProps'   => ['kind'=>'repair','data_id'=>(int)$r['id']]
      ];
    }
    $st->close();
  }
}

/* ---------- ข้อเสนอเวลานัด (รอยืนยัน) จาก schedule_proposals ---------- */
$hasProposals = $conn->query("SHOW TABLES LIKE 'schedule_proposals'")->num_rows>0;
if ($hasProposals) {
  $sqlProp = "
    SELECT id, ticket_type, ticket_id, slot_start, slot_end, status
    FROM schedule_proposals
    WHERE status='pending'
      AND slot_start < ?
      AND (COALESCE(slot_end, DATE_ADD(slot_start, INTERVAL duration_minutes MINUTE))) >= ?
  ";
  if ($st = $conn->prepare($sqlProp)) {
    $st->bind_param('ss', $endDt, $startDt);
    $st->execute();
    $rs = $st->get_result();
    while($r = $rs->fetch_assoc()){
      $isRepair = ($r['ticket_type']==='repair');
      $title = $isRepair ? ('ข้อเสนอเวลา (ซ่อม) #'.$r['ticket_id']) : ('ข้อเสนอเวลา (เทิร์น) #'.$r['ticket_id']);
      $events[] = [
        'id'    => 'prop-'.$r['id'],
        'title' => $title,
        'start' => $r['slot_start'],
        'end'   => $r['slot_end'],
        'url'   => $isRepair
                    ? 'service_ticket_detail.php?id='.(int)$r['ticket_id']
                    : 'tradein_detail.php?id='.(int)$r['ticket_id'],
        'allDay'=> false,
        'backgroundColor' => '#ffc107', // warning
        'borderColor'     => '#ffc107',
        'textColor'       => '#000',
        'extendedProps'   => ['kind'=>'proposal','data_id'=>(int)$r['id']]
      ];
    }
    $st->close();
  }
}

/* ---------- เทิร์น (ถ้ามีคอลัมน์นัด) ---------- */
$hasTR = $conn->query("SHOW TABLES LIKE 'tradein_requests'")->num_rows>0;
if ($hasTR) {
  $trStartCol = $conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'appointment_start'")->num_rows>0
    ? 'appointment_start'
    : ($conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'scheduled_at'")->num_rows>0 ? 'scheduled_at' : null);
  $trEndExpr  = $conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'appointment_end'")->num_rows>0
    ? 'appointment_end'
    : ($trStartCol ? "DATE_ADD($trStartCol, INTERVAL 60 MINUTE)" : null);

  if ($trStartCol) {
    $sqlTR = "
      SELECT id, device_type, brand, model,
             $trStartCol AS s_start,
             ".($trEndExpr ?: 'NULL')." AS s_end
      FROM tradein_requests
      WHERE $trStartCol IS NOT NULL
        AND $trStartCol < ?
        AND (".($trEndExpr ?: $trStartCol).") >= ?
    ";
    if ($st = $conn->prepare($sqlTR)) {
      $st->bind_param('ss', $endDt, $startDt);
      $st->execute();
      $rs = $st->get_result();
      while($r = $rs->fetch_assoc()){
        $title = 'เทิร์น: '.trim(($r['device_type']?:'').' '.$r['brand'].' '.$r['model']);
        $events[] = [
          'id'    => 'trade-'.$r['id'],
          'title' => $title,
          'start' => $r['s_start'],
          'end'   => $r['s_end'],
          'url'   => 'tradein_detail.php?id='.(int)$r['id'],
          'allDay'=> false,
          'backgroundColor' => '#0dcaf0', // info
          'borderColor'     => '#0dcaf0',
          'textColor'       => '#000',
          'extendedProps'   => ['kind'=>'tradein','data_id'=>(int)$r['id']]
        ];
      }
      $st->close();
    }
  }
}

echo json_encode($events, JSON_UNESCAPED_UNICODE);
