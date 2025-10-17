
<?php
// Home/admin/calendar_events.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

// ปิดการแสดง error บนหน้า ให้ log แทน (response เป็น JSON จะไม่พัง)
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

// รองรับทั้ง mysqli ($conn) และ PDO ($db)
$mysqli = $conn ?? null;
$pdo    = $db   ?? null;

if (!$mysqli && !$pdo) {
  http_response_code(500);
  echo json_encode(['error'=>'db_connection_missing']); exit;
}

function parse_iso_dt($s){
  if(!$s) return null;
  $s = substr($s, 0, 19);
  $s = str_replace('T', ' ', $s);
  return $s;
}

function table_has_column($table, $col){
  global $mysqli, $pdo;
  if ($mysqli) {
    $r = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '".$mysqli->real_escape_string($col)."'");
    return $r && $r->num_rows>0;
  } else {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return $st->rowCount()>0;
  }
}
function table_exists($name){
  global $mysqli, $pdo;
  if ($mysqli) {
    $r = $mysqli->query("SHOW TABLES LIKE '".$mysqli->real_escape_string($name)."'");
    return $r && $r->num_rows>0;
  } else {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$name]);
    return $st->rowCount()>0;
  }
}
function fetch_rows($sql, $params){
  global $mysqli, $pdo;
  if ($mysqli) {
    $st = $mysqli->prepare($sql);
    if ($st === false) return [];
    if ($params) {
      $types = str_repeat('s', count($params));
      // bind_param requires references
      $refs = [];
      foreach($params as $k => $v){ $refs[$k] = &$params[$k]; }
      array_unshift($refs, $types);
      call_user_func_array([$st, 'bind_param'], $refs);
    }
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
  } else {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

try {
  $start = $_GET['start'] ?? null;
  $end   = $_GET['end']   ?? null;
  $startDt = parse_iso_dt($start) ?: date('Y-m-d 00:00:00');
  $endDt   = parse_iso_dt($end)   ?: date('Y-m-d 23:59:59');

  $events = [];

  // repair / service_tickets
  $hasAppStart = table_has_column('service_tickets', 'appointment_start');
  $hasAppEnd   = table_has_column('service_tickets', 'appointment_end');
  $hasAppStat  = table_has_column('service_tickets', 'appointment_status');
  $hasSchedAt  = table_has_column('service_tickets', 'scheduled_at');

  $timeStartExpr = $hasAppStart ? 'appointment_start' : ($hasSchedAt ? 'scheduled_at' : null);
  $timeEndExpr   = $hasAppEnd   ? 'appointment_end'   : ($hasSchedAt ? "DATE_ADD(scheduled_at, INTERVAL 60 MINUTE)" : null);

  if ($timeStartExpr) {
    $sqlRepair = "
      SELECT id, device_type, brand, model, urgency, status,
             ".($hasAppStat ? "appointment_status," : "NULL AS appointment_status,")."
             ".($hasSchedAt ? "schedule_status," : "NULL AS schedule_status,")."
             $timeStartExpr AS s_start,
             ".($timeEndExpr ?: 'NULL')." AS s_end
      FROM service_tickets
      WHERE $timeStartExpr IS NOT NULL
        AND $timeStartExpr < ?
        AND (".($timeEndExpr ?: $timeStartExpr).") >= ?
    ";
    $rows = fetch_rows($sqlRepair, [$endDt, $startDt]);
    foreach($rows as $r){
      $title = 'ซ่อม: '.trim(($r['device_type']?:'').' '.$r['brand'].' '.$r['model']);
      $isUrgent = ($r['urgency']??'')==='urgent';
      $bg = '#0d6efd'; $text = '#fff';
      if ($isUrgent) {
        $bg = '#dc3545';
      } else {
        $stt = $r['status'] ?? '';
        $appt = $r['appointment_status'] ?? '';
        $sched = $r['schedule_status'] ?? '';
        if ($stt === 'repairing') {
          $bg = '#ffc107'; $text = '#000';
        } elseif ($stt === 'done') {
          $bg = '#6c757d'; $text = '#fff';
        } else {
          if (in_array($appt, ['confirmed']) || in_array($sched, ['confirmed'])) {
            $bg = '#198754'; $text = '#fff';
          } elseif (in_array($appt, ['pending']) || in_array($sched, ['proposed','pending'])) {
            $bg = '#ffc107'; $text = '#000';
          } else {
            $bg = '#0d6efd'; $text = '#fff';
          }
        }
      }
      $events[] = [
        'id'    => 'repair-'.($r['id']),
        'title' => $title,
        'start' => $r['s_start'],
        'end'   => $r['s_end'],
        'url'   => 'service_ticket_detail.php?id='.(int)$r['id'],
        'allDay'=> false,
        'backgroundColor' => $bg,
        'borderColor'     => $bg,
        'textColor'       => $text,
        'extendedProps'   => [
          'kind'=>'repair',
          'data_id'=>(int)$r['id'],
          'status'=>$r['status'] ?? null,
          'appointment_status'=>$r['appointment_status'] ?? null,
          'schedule_status'=>$r['schedule_status'] ?? null,
          'urgency'=>$r['urgency'] ?? null
        ]
      ];
    }
  }

  // schedule_proposals (pending)
  if (table_exists('schedule_proposals')) {
    $sqlProp = "
      SELECT id, ticket_type, ticket_id, slot_start, slot_end, status
      FROM schedule_proposals
      WHERE status='pending'
        AND slot_start < ?
        AND (COALESCE(slot_end, DATE_ADD(slot_start, INTERVAL duration_minutes MINUTE))) >= ?
    ";
    $rows = fetch_rows($sqlProp, [$endDt, $startDt]);
    foreach($rows as $r){
      $isRepair = ($r['ticket_type']==='repair');
      $title = $isRepair ? ('ข้อเสนอเวลา (ซ่อม) #'.$r['ticket_id']) : ('ข้อเสนอเวลา (เทิร์น) #'.$r['ticket_id']);
      $events[] = [
        'id'    => 'prop-'.$r['id'],
        'title' => $title,
        'start' => $r['slot_start'],
        'end'   => $r['slot_end'],
        'url'   => $isRepair ? 'service_ticket_detail.php?id='.(int)$r['ticket_id'] : 'tradein_detail.php?id='.(int)$r['ticket_id'],
        'allDay'=> false,
        'backgroundColor' => '#ffc107',
        'borderColor'     => '#ffc107',
        'textColor'       => '#000',
        'extendedProps'   => ['kind'=>'proposal','data_id'=>(int)$r['id'],'status'=>$r['status']]
      ];
    }
  }

  // tradein_requests
  if (table_exists('tradein_requests')) {
    $trStartCol = table_has_column('tradein_requests', 'appointment_start') ? 'appointment_start' : (table_has_column('tradein_requests', 'scheduled_at') ? 'scheduled_at' : null);
    $trEndExpr  = table_has_column('tradein_requests', 'appointment_end') ? 'appointment_end' : ($trStartCol ? "DATE_ADD($trStartCol, INTERVAL 60 MINUTE)" : null);
    if ($trStartCol) {
      $sqlTR = "
        SELECT id, device_type, brand, model, status,
               $trStartCol AS s_start,
               ".($trEndExpr ?: 'NULL')." AS s_end
        FROM tradein_requests
        WHERE $trStartCol IS NOT NULL
          AND $trStartCol < ?
          AND (".($trEndExpr ?: $trStartCol).") >= ?
      ";
      $rows = fetch_rows($sqlTR, [$endDt, $startDt]);
      foreach($rows as $r){
        $title = 'เทิร์น: '.trim(($r['device_type']?:'').' '.$r['brand'].' '.$r['model']);
        $bg = '#0dcaf0'; $text = '#000';
        if (($r['status'] ?? '') === 'accepted') { $bg = '#198754'; $text = '#fff'; }
        if (($r['status'] ?? '') === 'cancelled') { $bg = '#6c757d'; $text = '#fff'; }
        $events[] = [
          'id'    => 'trade-'.$r['id'],
          'title' => $title,
          'start' => $r['s_start'],
          'end'   => $r['s_end'],
          'url'   => 'tradein_detail.php?id='.(int)$r['id'],
          'allDay'=> false,
          'backgroundColor' => $bg,
          'borderColor'     => $bg,
          'textColor'       => $text,
          'extendedProps'   => ['kind'=>'tradein','data_id'=>(int)$r['id'],'status'=>$r['status'] ?? null]
        ];
      }
    }
  }

  echo json_encode($events, JSON_UNESCAPED_UNICODE);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  // ส่งข้อความสั้นๆ เพื่อช่วย debug — ถ้าต้องการซ่อนรายละเอียดเปลี่ยนเป็น generic message
  echo json_encode(['error'=>'exception','message'=>$e->getMessage()]);
  exit;
}
?>
// ...existing code...