<?php
// Home/admin/calendar_events.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  echo json_encode([]); exit;
}

/* รับช่วงเวลา (FullCalendar จะส่งมา) */
$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;
if (!$start || !$end) { echo json_encode([]); exit; }

$events = [];

/* ===== 1) ใบงานซ่อมที่มีนัด (appointment_*) ===== */
$fields = "
  id, user_id, device_type, brand, model, status, urgency,
  appointment_start, appointment_end, appointment_status
";
$q1 = $conn->prepare("
  SELECT $fields
  FROM service_tickets
  WHERE appointment_start IS NOT NULL
    AND appointment_start < ?
    AND (appointment_end IS NULL OR appointment_end >= ?)
    AND status <> 'cancelled'
");
$q1->bind_param('ss', $end, $start);
$q1->execute();
$res1 = $q1->get_result();
while($r = $res1->fetch_assoc()){
  $title = 'ซ่อม • '.$r['brand'].' '.$r['model'];
  $color = '#0d6efd'; // ปกติ
  if (($r['urgency']??'')==='urgent') $color = '#dc3545';
  if (($r['appointment_status']??'')==='confirmed') $color = '#198754';

  $events[] = [
    'id'    => 'st-'.$r['id'],
    'title' => $title,
    'start' => $r['appointment_start'],
    'end'   => $r['appointment_end'] ?: null,
    'url'   => 'service_ticket_detail.php?id='.$r['id'],
    'editable' => true,
    'backgroundColor' => $color,
    'borderColor'     => $color,
    'textColor'       => '#fff',
    'extendedProps' => [
      'kind' => 'repair',
      'data_id' => (int)$r['id'],
      'status' => $r['status'],
      'appointment_status' => $r['appointment_status'],
      'urgency' => $r['urgency'],
    ],
  ];
}
$q1->close();

/* ===== 2) ข้อเสนอเวลานัด (ยังรอยืนยัน) จาก schedule_proposals ===== */
$q2 = $conn->prepare("
  SELECT id, ticket_type, ticket_id, slot_start, slot_end, status, note
  FROM schedule_proposals
  WHERE status='pending'
    AND slot_start < ?
    AND (slot_end IS NULL OR slot_end >= ?)
");
$q2->bind_param('ss', $end, $start);
$q2->execute();
$res2 = $q2->get_result();
while($r = $res2->fetch_assoc()){
  $title = ($r['ticket_type']==='tradein' ? 'ข้อเสนอเทิร์น' : 'ข้อเสนอซ่อม').' • #'.$r['ticket_id'];
  $events[] = [
    'id'    => 'sp-'.$r['id'],
    'title' => $title,
    'start' => $r['slot_start'],
    'end'   => $r['slot_end'] ?: null,
    'url'   => ($r['ticket_type']==='tradein'
                ? 'tradein_detail.php?id='.$r['ticket_id']
                : 'service_ticket_detail.php?id='.$r['ticket_id']),
    'editable' => true,
    'backgroundColor' => '#ffc107',
    'borderColor'     => '#ffc107',
    'textColor'       => '#111',
    'extendedProps' => [
      'kind' => 'proposal',
      'data_id' => (int)$r['id'],
      'ticket_type' => $r['ticket_type'],
      'ticket_id'   => (int)$r['ticket_id'],
    ],
  ];
}
$q2->close();

/* ===== 3) เทิร์นสินค้าที่มีเวลา (scheduled_at) ===== */
if ($conn->query("SHOW COLUMNS FROM tradein_requests LIKE 'scheduled_at'")->num_rows){
  $q3 = $conn->prepare("
    SELECT id, device_type, brand, model, scheduled_at
    FROM tradein_requests
    WHERE scheduled_at IS NOT NULL
      AND scheduled_at >= DATE_SUB(?, INTERVAL 1 DAY)
      AND scheduled_at <  DATE_ADD(?, INTERVAL 1 DAY)
  ");
  $q3->bind_param('ss', $end, $start);
  $q3->execute();
  $res3 = $q3->get_result();
  while($r = $res3->fetch_assoc()){
    $title = 'เทิร์น • '.$r['brand'].' '.$r['model'];
    $startAt = $r['scheduled_at'];
    // ปลายเวลา (default 60 นาที ถ้าไม่มีคอลัมน์ระยะเวลา)
    $endAt = (new DateTime($startAt))->modify('+60 minutes')->format('Y-m-d H:i:s');

    $events[] = [
      'id'    => 'tr-'.$r['id'],
      'title' => $title,
      'start' => $startAt,
      'end'   => $endAt,
      'url'   => 'tradein_detail.php?id='.$r['id'],
      'editable' => true,
      'backgroundColor' => '#0dcaf0',
      'borderColor'     => '#0dcaf0',
      'textColor'       => '#111',
      'extendedProps' => [
        'kind' => 'tradein',
        'data_id' => (int)$r['id'],
      ],
    ];
  }
  $q3->close();
}

echo json_encode($events, JSON_UNESCAPED_UNICODE);
