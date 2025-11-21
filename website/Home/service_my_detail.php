<?php   
// Home/service_my_detail.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service_my.php'); exit; }
require_once __DIR__.'/includes/image_helpers.php';

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

/* ---------- helper แสดงรูปให้รอดเสมอ ---------- */
function fallback_data_uri(): string {
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80">'
       . '<rect width="100%" height="100%" fill="#eef2f7"/>'
       . '<text x="50%" y="55%" text-anchor="middle" font-family="Arial" font-size="12" fill="#99a3b1">no image</text>'
       . '</svg>';
  return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
}
function thumb_path(?string $val): string {
  $v = trim((string)$val);
  if ($v === '') {
    foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
      if (is_file(__DIR__.'/'.$cand)) return $cand;
    }
    return fallback_data_uri();
  }
  if (preg_match('~^(https?://|data:image/)~i', $v)) return $v;
  $rel = (strpos($v,'/')!==false) ? $v : ('assets/img/'.$v);
  if (is_file(__DIR__.'/'.$rel)) return $rel;
  foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
    if (is_file(__DIR__.'/'.$cand)) return $cand;
  }
  return fallback_data_uri();
}
/* ตรวจคอลัมน์แบบยืดหยุ่น */
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows>0;
}
/* ---------------------------------------------- */

$uid  = (int)$_SESSION['user_id'];
$type = ($_GET['type'] ?? 'repair'); // repair | tradein
$id   = (int)($_GET['id'] ?? 0);

if(!in_array($type,['repair','tradein'],true) || $id<=0){
  header('Location: service_my.php'); exit;
}

$ticket = null;
$req    = null;
$logs   = [];
$gallery= [];
$proposals = [];     // ข้อเสนอเวลานัดจากแอดมิน
$appt = [            // สรุปนัดหมาย
  'start'=>null,'end'=>null,'status'=>'none'
];

/* ตัวแปรเกี่ยวกับการชำระเงิน + รายการซ่อม (เฉพาะงานซ่อม) */
$paymentStatus = 'unpaid';
$paymentMethod = null;
$paidAt        = null;
$servicePrice  = 0.00;
$items         = [];
$itemsSubtotal = 0.00;

/* ---- โหลดข้อมูลตามประเภท ---- */
if($type==='repair'){
  if($st=$conn->prepare("SELECT * FROM service_tickets WHERE id=? AND user_id=?")){
    $st->bind_param('ii',$id,$uid); $st->execute();
    $ticket=$st->get_result()->fetch_assoc(); $st->close();
  }
  if(!$ticket){ header('Location: service_my.php'); exit; }

  if($st=$conn->prepare("SELECT * FROM service_status_logs WHERE ticket_id=? ORDER BY id DESC")){
    $st->bind_param('i',$id); $st->execute();
    $logs=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  }
  $map=[
    'queued'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค',
    'waiting_parts'=>'รออะไหล่','repairing'=>'กำลังซ่อม','done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก'
  ];

  // เร่งด่วน
  $urgencyMap   = ['normal'=>'ปกติ','urgent'=>'เร่งด่วน'];
  $urgencyBadge = ['normal'=>'secondary','urgent'=>'danger'];
  $urgency      = $ticket['urgency'] ?? 'normal';

  // นัดหมาย (อ่านตามคอลัมน์ที่มีจริง)
  $startCol  = has_col($conn,'service_tickets','appointment_start') ? 'appointment_start'
            : (has_col($conn,'service_tickets','scheduled_at') ? 'scheduled_at' : null);
  $endCol    = has_col($conn,'service_tickets','appointment_end')   ? 'appointment_end'   : null;
  $statusCol = has_col($conn,'service_tickets','appointment_status')? 'appointment_status'
            : (has_col($conn,'service_tickets','schedule_status') ? 'schedule_status' : null);
  if($startCol)  $appt['start']  = $ticket[$startCol]??null;
  if($endCol)    $appt['end']    = $ticket[$endCol]??null;
  if($statusCol) $appt['status'] = $ticket[$statusCol]??'none';

  // ข้อเสนอเวลานัดจากแอดมิน
  if($st=$conn->prepare("SELECT * FROM schedule_proposals WHERE ticket_type='repair' AND ticket_id=? ORDER BY id DESC")){
    $st->bind_param('i',$id); $st->execute();
    $proposals=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  }

  // ---- รายการซ่อมทีละรายการ (service_ticket_items) ----
  $hasItemsTable = $conn->query("SHOW TABLES LIKE 'service_ticket_items'")->num_rows>0;
  if($hasItemsTable){
    if($st=$conn->prepare("SELECT * FROM service_ticket_items WHERE ticket_id=? ORDER BY id ASC")){
      $st->bind_param('i',$id); $st->execute();
      $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
      $st->close();
    }

    $itemsSubtotal = 0.00;
    foreach($items as &$it){
      $qty  = (int)($it['qty'] ?? 0);
      $unit = (float)($it['unit_price'] ?? 0);
      $line = isset($it['line_total']) ? (float)$it['line_total'] : 0.0;

      // ถ้า line_total ใน DB เป็น 0 หรือว่าง ให้คำนวณจาก qty * unit_price แทน
      if ($line <= 0 && $qty > 0) {
        $line = $qty * $unit;
      }

      $it['__total'] = $line;          // เก็บไว้ใช้ตอนแสดงผล
      $itemsSubtotal += $line;         // เอาไปคิด "รวมรายการ"
    }
    unset($it);
  }

  // ---- อ่านข้อมูลการชำระเงินตาม schema ใหม่ ----
  $baseEstimate = (float)($ticket['estimate_total'] ?? 0);

  $hasFinalTotalCol  = has_col($conn,'service_tickets','final_total');
  $hasPayStatusCol   = has_col($conn,'service_tickets','payment_status');
  $hasPayMethodCol   = has_col($conn,'service_tickets','pay_method');
  $hasPaidAtCol      = has_col($conn,'service_tickets','paid_at');

  // ยอดสุดท้ายที่ร้านสรุป
  if ($hasFinalTotalCol && array_key_exists('final_total',$ticket) && $ticket['final_total'] !== null) {
    $servicePrice = (float)$ticket['final_total'];
  } elseif ($itemsSubtotal > 0) {
    $servicePrice = $itemsSubtotal;
  } else {
    $servicePrice = $baseEstimate;
  }

  // สถานะการชำระเงิน
  if ($hasPayStatusCol && !empty($ticket['payment_status'])) {
    $paymentStatus = $ticket['payment_status'];
  } else {
    $paymentStatus = 'unpaid';
  }

  if ($hasPayMethodCol && !empty($ticket['pay_method'])) {
    $paymentMethod = $ticket['pay_method'];
  }
  if ($hasPaidAtCol && !empty($ticket['paid_at'])) {
    $paidAt = $ticket['paid_at'];
  }

}else{
  // เทิร์น
  if($st=$conn->prepare("
    SELECT tr.*,
           CASE
             WHEN tr.image_path IS NOT NULL AND tr.image_path <> '' THEN tr.image_path
             ELSE (
               SELECT CONCAT('assets/img/', ti.filename)
               FROM tradein_images ti
               WHERE ti.request_id = tr.id AND ti.is_cover = 1
               ORDER BY ti.id ASC LIMIT 1
             )
           END AS cover_path
    FROM tradein_requests tr
    WHERE tr.id=? AND tr.user_id=? LIMIT 1
  ")){
    $st->bind_param('ii',$id,$uid); $st->execute();
    $req=$st->get_result()->fetch_assoc(); $st->close();
  }
  if(!$req){ header('Location: service_my.php'); exit; }

  if($st=$conn->prepare("SELECT * FROM tradein_status_logs WHERE request_id=? ORDER BY id DESC")){
    $st->bind_param('i',$id); $st->execute();
    $logs=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  }
  $map=[
    'submitted'=>'ส่งคำขอแล้ว','review'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
    'accepted'=>'ยอมรับข้อเสนอ','rejected'=>'ปฏิเสธข้อเสนอ','cancelled'=>'ยกเลิก','completed'=>'เสร็จสิ้น'
  ];

  $gallery = load_tradein_gallery($conn, (int)$req['id']);
}

/* ---- ตั้ง <title> ---- */
$docTitle = 'รายละเอียดงาน | WEB APP';
if ($type==='repair'  && $ticket) $docTitle = 'ใบงานซ่อม ST-'.(int)$ticket['id'].' | WEB APP';
if ($type==='tradein' && $req)    $docTitle = 'คำขอเทิร์น TR-'.(int)$req['id'].' | WEB APP';

$gradeMap = ['used'=>'มือสอง','standard'=>'ปานกลาง','premium'=>'ดีมาก'];

// map แสดง badge นัดหมาย
$APPT_BADGE = ['none'=>'secondary','pending'=>'warning text-dark','confirmed'=>'success','declined'=>'danger','proposed'=>'info text-dark'];
$APPT_TEXT  = ['none'=>'—','pending'=>'รอยยืนยัน','confirmed'=>'ยืนยันแล้ว','declined'=>'ปฏิเสธแล้ว','proposed'=>'แอดมินเสนอเวลาแล้ว'];

$SP_BADGE = [
  'pending'   => 'warning text-dark',
  'accepted'  => 'success',
  'declined'  => 'danger',
  'cancelled' => 'secondary',
];
$SP_TEXT = [
  'pending'   => 'รอคุณยืนยัน',
  'accepted'  => 'คุณยืนยันแล้ว',
  'declined'  => 'คุณปฏิเสธเวลานัดนี้',
  'cancelled' => 'นัดนี้ถูกยกเลิกแล้ว',
];

// map แสดงสถานะชำระเงิน
$PAY_BADGE = [
  'unpaid'  => 'danger',
  'pending' => 'warning text-dark',
  'paid'    => 'success',
];
$PAY_TEXT = [
  'unpaid'  => 'ยังไม่ชำระ',
  'pending' => 'รอตรวจสอบการชำระ',
  'paid'    => 'ชำระแล้ว',
];
// map วิธีชำระ
$METHOD_TEXT = [
  'cash'   => 'เงินสดหน้าร้าน',
  'bank'   => 'โอนผ่านธนาคาร',
  'wallet' => 'วอลเล็ท/ช่องทางออนไลน์',
];

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?= h($docTitle) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --pri:#2563eb;
      --pri2:#4f46e5;
      --line:#e5e7eb;
      --ink:#0f172a;
      --muted:#6b7280;
    }
    body{
      background:
        radial-gradient(circle at top left,#e0f2ff 0,#f5f9ff 45%,#ffffff 80%);
      color:var(--ink);
    }
    #serviceDetail{ background:transparent; }

    .page-head{
      border-radius:20px;
      padding:16px 18px 14px;
      background:linear-gradient(135deg,var(--pri)0%,var(--pri2)55%,#0ea5e9 100%);
      color:#fff;
      box-shadow:0 14px 36px rgba(37,99,235,.2);
    }
    .page-head h3{margin:0;font-weight:700;letter-spacing:.01em;}
    .page-head-sub{font-size:.9rem;opacity:.9;margin-top:4px;}
    .summary-pills{display:flex;flex-wrap:wrap;gap:10px;}
    .summary-pill{
      min-width:180px;
      padding:8px 14px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.55);
      background:rgba(15,23,42,.05);
      display:flex;align-items:center;gap:8px;
      font-size:.85rem;
      font-weight:600;
      backdrop-filter:blur(4px);
    }
    .summary-pill i{font-size:1rem;}

    #serviceDetail .card{
      border:1px solid #e3e8f5;
      border-radius:18px;
      overflow:hidden;
      box-shadow:0 14px 40px rgba(15,23,42,.06);
      background:#fff;
    }
    #serviceDetail .card-header{
      background:linear-gradient(180deg,#ffffff,#f6f9ff);
      border-bottom:1px solid #eef2f6;
      font-weight:600;
    }
    #serviceDetail .kv .k{ color:var(--muted); font-size:.85rem; }
    #serviceDetail .kv .v{ font-weight:500; }

    .ti-thumb{width:100%; height:110px; object-fit:cover; border-radius:10px; border:1px solid #e6edf6}
    .thumb-lg{max-height:360px; object-fit:contain; background:#fff; border:1px solid #e6edf6; border-radius:12px}

    /* Timeline */
    #serviceDetail .timeline{ position:relative; padding-left:1rem; }
    #serviceDetail .timeline::before{
      content:""; position:absolute; left:.6rem; top:4px; bottom:4px;
      width:2px; background:#e6edf6;
    }
    #serviceDetail .tl-item{ position:relative; padding-left:1.5rem; margin-bottom:.75rem; }
    #serviceDetail .tl-item:last-child{ margin-bottom:0; }
    #serviceDetail .tl-point{
      position:absolute; left:-.05rem; top:.3rem;
      width:12px;height:12px;border-radius:999px;
      background:#2563eb; box-shadow:0 0 0 3px #e7f0ff;
    }
    #serviceDetail .tl-title{ font-weight:700; }
    #serviceDetail .tl-note{ color:var(--muted); font-size:.9rem; }
    #serviceDetail .tl-time{ color:var(--muted); font-size:.8rem; white-space:nowrap; }

    @media(max-width: 768px){
      .page-head{padding:14px 14px;}
      .summary-pill{min-width:0;}
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<main id="serviceDetail">
  <div class="container py-4">

    <?php if($type==='repair' && $ticket): ?>
      <?php
        $createdAt  = $ticket['created_at'] ?? '';
        $createdTxt = $createdAt ? date('d/m/Y H:i', strtotime($createdAt)) : '-';
        $headerStatus = $map[$ticket['status']] ?? $ticket['status'];
        if ($ticket['status'] === 'done' && $paymentStatus !== 'paid') {
          $headerStatus = 'เสร็จพร้อมรับ (รอชำระค่าบริการ)';
        }
      ?>
      <div class="page-head mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3 align-items-start">
          <div>
            <div class="small text-white-50">ใบงานซ่อม</div>
            <h3 class="mb-0">ST-<?= (int)$ticket['id'] ?></h3>
            <div class="page-head-sub">สร้างเมื่อ <?= h($createdTxt) ?></div>
          </div>
          <div class="summary-pills">
            <div class="summary-pill">
              <i class="bi bi-clipboard-check"></i>
              <span><?= h($headerStatus) ?></span>
            </div>
            <div class="summary-pill">
              <i class="bi bi-cash-coin"></i>
              <span>ยอดบริการ <?= number_format((float)$servicePrice,2) ?> ฿</span>
            </div>
          </div>
        </div>
      </div>
    <?php elseif($type==='tradein' && $req): ?>
      <?php
        $createdAt  = $req['created_at'] ?? '';
        $createdTxt = $createdAt ? date('d/m/Y H:i', strtotime($createdAt)) : '-';
        $headerStatus = $map[$req['status']] ?? $req['status'];
      ?>
      <div class="page-head mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3 align-items-start">
          <div>
            <div class="small text-white-50">คำขอเทิร์น</div>
            <h3 class="mb-0">TR-<?= (int)$req['id'] ?></h3>
            <div class="page-head-sub">สร้างเมื่อ <?= h($createdTxt) ?></div>
          </div>
          <div class="summary-pills">
            <div class="summary-pill">
              <i class="bi bi-arrow-left-right"></i>
              <span><?= h($headerStatus) ?></span>
            </div>
            <?php if($req['offer_price']!==null && $req['offer_price']!==''): ?>
              <div class="summary-pill">
                <i class="bi bi-cash-stack"></i>
                <span>ราคาเสนอ <?= number_format((float)$req['offer_price'],2) ?> ฿</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <a href="service_my.php" class="btn btn-outline-secondary mb-3">
      <i class="bi bi-arrow-left"></i> กลับ
    </a>

    <?php
      // flash สำหรับแจ้งผล accept/reject เทิร์น ฯลฯ
      $flash = $_SESSION['flash'] ?? '';
      unset($_SESSION['flash']);
      if ($flash):
    ?>
      <div class="alert alert-info alert-dismissible fade show">
        <?= h($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if($type==='repair'): ?>
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div>ใบงานซ่อม <span class="badge text-bg-dark">ST-<?= (int)$ticket['id'] ?></span></div>
              <div>
                <span class="badge bg-<?= $urgencyBadge[$urgency] ?? 'secondary' ?>">
                  <?php if(($urgency??'')==='urgent'): ?><i class="bi bi-lightning-charge-fill me-1"></i><?php endif; ?>
                  <?= h($urgencyMap[$urgency] ?? '-') ?>
                </span>
              </div>
            </div>
            <div class="card-body">
              <div class="row g-3 kv">
                <div class="col-md-4"><div class="k">ประเภท</div><div class="v"><?=h($ticket['device_type'])?></div></div>
                <div class="col-md-4"><div class="k">ยี่ห้อ</div><div class="v"><?=h($ticket['brand'])?></div></div>
                <div class="col-md-4"><div class="k">รุ่น</div><div class="v"><?=h($ticket['model'])?></div></div>
                <div class="col-md-6"><div class="k">โทร</div><div class="v"><?=h($ticket['phone'])?></div></div>
                <div class="col-md-6"><div class="k">LINE</div><div class="v"><?=h($ticket['line_id'])?></div></div>
                <div class="col-md-6"><div class="k">นัดหมาย (ลูกค้าเสนอ)</div><div class="v"><?=h($ticket['desired_date'] ?: '-')?></div></div>
                <div class="col-md-6">
                  <div class="k">ความเร่งด่วน</div>
                  <div class="v">
                    <span class="badge bg-<?= $urgencyBadge[$urgency] ?? 'secondary' ?>">
                      <?php if(($urgency??'')==='urgent'): ?><i class="bi bi-lightning-charge-fill me-1"></i><?php endif; ?>
                      <?= h($urgencyMap[$urgency] ?? '-') ?>
                    </span>
                  </div>
                </div>

                <!-- แสดงนัดหมายที่ยืนยัน -->
                <div class="col-12">
                  <div class="k">นัดหมายของคุณ (ล่าสุด)</div>
                  <div class="v">
                    <span class="badge bg-<?= $APPT_BADGE[$appt['status']] ?? 'secondary' ?>">
                      <?= h($APPT_TEXT[$appt['status']] ?? $appt['status']) ?>
                    </span>
                    <?php if($appt['start']): ?>
                      <span class="ms-2"><?= h($appt['start']) ?><?= $appt['end'] ? ' — '.h($appt['end']) : '' ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="col-12"><div class="k">อาการที่แจ้ง</div><div class="v"><?=nl2br(h($ticket['issue']))?></div></div>
              </div>

              <?php
                $img = thumb_path($ticket['image_path'] ?? '');
                if($img){ echo '<hr><img class="img-fluid thumb-lg" src="'.h($img).'" alt="">'; }
              ?>

              <!-- ตัวเลือกเบื้องต้น -->
              <hr>
              <div class="row g-3 kv">
                <div class="col-md-6">
                  <div class="k">เกรดวัสดุ</div>
                  <div class="v">
                    <?= h($gradeMap[$ticket['parts_grade'] ?? 'standard'] ?? '-') ?>
                    <?php if (($ticket['parts_grade_surcharge'] ?? 0) > 0): ?>
                      <span class="badge text-bg-secondary ms-2">
                        +<?= number_format((float)$ticket['parts_grade_surcharge'], 2) ?> ฿
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="k">ประกันหลังซ่อม</div>
                  <div class="v">
                    ฟรี 1 เดือน
                    <?php if (($ticket['ext_warranty_months'] ?? 0) > 0): ?>
                      +<?= (int)$ticket['ext_warranty_months'] ?> เดือน
                      <span class="badge text-bg-secondary ms-2">
                        +<?= number_format((float)$ticket['ext_warranty_price'], 2) ?> ฿
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="k">รวมค่าบริการโดยประมาณ (ตอนเปิดงาน)</div>
                  <div class="v">
                    <?= number_format((float)($ticket['estimate_total'] ?? 0), 2) ?> ฿
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- รายละเอียดค่าใช้จ่ายจริงที่แอดมินสรุป -->
          <div class="card mt-3">
            <div class="card-header">
              <i class="bi bi-receipt-cutoff me-1"></i> รายการซ่อมและค่าใช้จ่าย
            </div>
            <div class="card-body">
              <?php
                $TYPE_TEXT = [
                  'part'    => 'อะไหล่',
                  'labor'   => 'ค่าแรง',
                  'service' => 'บริการ',
                  'other'   => 'อื่น ๆ'
                ];
              ?>
              <?php if(empty($items)): ?>
                <div class="text-muted">ยังไม่มีรายละเอียดค่าใช้จ่ายจากร้าน หากมีการสรุปบิลจะแสดงที่นี่</div>
              <?php else: ?>
                <div class="table-responsive mb-2">
                  <table class="table table-sm align-middle">
                    <thead>
                      <tr class="table-light">
                        <th style="width:20%">ประเภท</th>
                        <th>รายการ</th>
                        <th class="text-center" style="width:10%">จำนวน</th>
                        <th class="text-end" style="width:15%">ราคา/หน่วย</th>
                        <th class="text-end" style="width:15%">รวม</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($items as $it): ?>
                        <tr>
                          <td><?= h($TYPE_TEXT[$it['item_type'] ?? ''] ?? $it['item_type'] ?? '-') ?></td>
                          <td><?= h($it['description'] ?? '-') ?></td>
                          <td class="text-center"><?= (int)($it['qty'] ?? 1) ?></td>
                          <td class="text-end"><?= number_format((float)($it['unit_price'] ?? 0), 2) ?></td>
                          <td class="text-end"><?= number_format((float)($it['__total'] ?? 0), 2) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr>
                        <th colspan="4" class="text-end">รวมรายการ</th>
                        <th class="text-end"><?= number_format($itemsSubtotal, 2) ?> ฿</th>
                      </tr>
                      <?php
                        // ถ้ายอดสุดท้ายไม่เท่ากับผลรวมรายการ แสดงส่วนต่างเป็นส่วนลด/ปรับราคา
                        $diff = round($servicePrice - $itemsSubtotal, 2);
                        if($itemsSubtotal > 0 && abs($diff) >= 0.01):
                      ?>
                        <tr>
                          <th colspan="4" class="text-end">
                            <?= $diff < 0 ? 'ส่วนลด/ปรับลด' : 'ปรับราคาเพิ่ม' ?>
                          </th>
                          <th class="text-end">
                            <?= $diff < 0 ? '-' : '+' ?><?= number_format(abs($diff), 2) ?> ฿
                          </th>
                        </tr>
                      <?php endif; ?>
                      <tr>
                        <th colspan="4" class="text-end">ยอดสุทธิที่ต้องชำระ</th>
                        <th class="text-end text-primary fs-5">
                          <?= number_format($servicePrice, 2) ?> ฿
                        </th>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($proposals)): ?>
          <!-- ข้อเสนอเวลานัดจากแอดมิน -->
          <div class="card mt-3">
            <div class="card-header"><i class="bi bi-calendar-week me-1"></i> ข้อเสนอเวลานัดจากแอดมิน</div>
            <div class="card-body">
              <div class="list-group list-group-flush">
                <?php foreach($proposals as $p): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold">
                        <?= h($p['slot_start']) ?><?php if(!empty($p['slot_end'])): ?> — <?= h($p['slot_end']) ?><?php endif; ?>
                      </div>
                      <?php if(!empty($p['note'])): ?>
                        <div class="small text-muted"><?= h($p['note']) ?></div>
                      <?php endif; ?>
                      <div class="small text-muted">
                        สถานะ: <?= h($SP_TEXT[$p['status']] ?? $p['status']) ?>
                      </div>
                    </div>
                    <div class="ms-2 d-flex gap-2">
                      <?php if(($p['status'] ?? '')==='pending'): ?>
                        <button class="btn btn-sm btn-success" data-act="accept" data-prop="<?= (int)$p['id'] ?>">
                          <i class="bi bi-check2"></i> ยืนยัน
                        </button>
                        <button class="btn btn-sm btn-outline-danger" data-act="decline" data-prop="<?= (int)$p['id'] ?>">
                          ปฏิเสธ
                        </button>
                      <?php else: ?>
                        <span class="badge bg-<?= $SP_BADGE[$p['status']] ?? 'secondary' ?>">
                          <?= h($SP_TEXT[$p['status']] ?? $p['status']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <div class="col-lg-5">
          <div class="card">
            <?php
              // ปรับข้อความสถานะปัจจุบันให้สะท้อน "รอชำระค่าบริการ" ถ้างานเสร็จแต่ยังไม่ได้ชำระ
              $statusLabel = $map[$ticket['status']] ?? $ticket['status'];
              if ($ticket['status'] === 'done' && $paymentStatus !== 'paid') {
                $statusLabel = 'เสร็จพร้อมรับ (รอชำระค่าบริการ)';
              }
            ?>
            <div class="card-header">
              สถานะปัจจุบัน: <span class="badge text-bg-primary"><?=h($statusLabel)?></span>
            </div>
            <div class="card-body">
              <?php $ps = $paymentStatus; ?>

              <!-- บล็อกสถานะการชำระค่าบริการ -->
              <div class="mb-3 kv">
                <div class="k">สถานะการชำระค่าบริการ</div>
                <div class="v">
                  <span class="badge bg-<?= $PAY_BADGE[$ps] ?? 'secondary' ?>">
                    <?= h($PAY_TEXT[$ps] ?? $ps) ?>
                  </span>

                  <?php if($ticket['status']==='done' && $ps==='unpaid'): ?>
                    <a href="service_pay.php?id=<?= (int)$ticket['id'] ?>" class="btn btn-sm btn-primary ms-2">
                      <i class="bi bi-credit-card"></i> ชำระค่าบริการ
                    </a>
                  <?php endif; ?>
                </div>

                <div class="k mt-2">ยอดที่ต้องชำระ</div>
                <div class="v">
                  <?= number_format((float)$servicePrice, 2) ?> ฿
                </div>

                <?php if($paymentMethod): ?>
                  <div class="k mt-2">วิธีชำระ</div>
                  <div class="v">
                    <?= h($METHOD_TEXT[$paymentMethod] ?? $paymentMethod) ?>
                  </div>
                <?php endif; ?>

                <?php if($paidAt): ?>
                  <div class="k mt-2">ชำระเมื่อ</div>
                  <div class="v"><?= h($paidAt) ?></div>
                <?php endif; ?>
              </div>

              <hr>

              <?php if(empty($logs)): ?>
                <div class="text-muted">ยังไม่มีบันทึกสถานะ</div>
              <?php else: ?>
                <div class="timeline">
                  <?php foreach($logs as $lg): ?>
                    <div class="tl-item">
                      <span class="tl-point"></span>
                      <div class="d-flex justify-content-between align-items-start tl-wrap">
                        <div>
                          <div class="tl-title"><?=h($map[$lg['status']] ?? $lg['status'])?></div>
                          <?php if(!empty($lg['note'])): ?>
                            <div class="tl-note"><?=nl2br(h($lg['note']))?></div>
                          <?php endif; ?>
                        </div>
                        <div class="tl-time"><?=h($lg['created_at'])?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    <?php else: /* tradein */ ?>
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header">
              คำขอเทิร์น <span class="badge text-bg-dark">TR-<?= (int)$req['id'] ?></span>
            </div>
            <div class="card-body">
              <div class="row g-3 kv">
                <div class="col-md-4"><div class="k">ประเภท</div><div class="v"><?=h($req['device_type'])?></div></div>
                <div class="col-md-4"><div class="k">ยี่ห้อ</div><div class="v"><?=h($req['brand'])?></div></div>
                <div class="col-md-4"><div class="k">รุ่น</div><div class="v"><?=h($req['model'])?></div></div>
                <div class="col-md-6"><div class="k">สภาพ</div><div class="v"><?=h($req['device_condition'])?></div></div>
                <div class="col-md-6"><div class="k">ความต้องการ</div><div class="v"><?=h($req['need'])?></div></div>
                <div class="col-md-6"><div class="k">ราคาเสนอ</div><div class="v"><?= $req['offer_price']!==null ? number_format((float)$req['offer_price'],2).' ฿' : '-' ?></div></div>
              </div>

              <?php
                if($gallery){
                  echo '<hr><div class="row g-2">';
                  foreach($gallery as $g){
                    $src = thumb_path($g['filename'] ?? '');
                    echo '<div class="col-4 col-md-3"><img class="ti-thumb" src="'.h($src).'" alt=""></div>';
                  }
                  echo '</div>';
                }  else {
                  $cover = thumb_path($req['cover_path'] ?? $req['image_path'] ?? '');
                  if($cover){
                    echo '<hr><img class="img-fluid rounded border thumb-lg" src="'.h($cover).'" alt="">';
                  }
                }
              ?>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card">
            <div class="card-header">
              สถานะปัจจุบัน: <span class="badge text-bg-primary"><?=h($map[$req['status']] ?? $req['status'])?></span>
            </div>
            <?php
            // ==== บล็อกคูปองเทิร์น ====

            // ดึงคูปองเทิร์นของคำขอนี้ (ถ้ามี) + นับการใช้งานจาก coupon_usages
            $tradeinCoupon = null;
            if ($st=$conn->prepare("
              SELECT c.id, c.code, c.value, c.ends_at, c.uses_limit, c.per_user_limit, c.applies_to,
                     COALESCE(COUNT(cu.id),0) AS used_total
              FROM coupons c
              LEFT JOIN coupon_usages cu ON cu.coupon_id = c.id
              WHERE c.tradein_id=? AND c.user_id=? AND c.status='active'
              GROUP BY c.id
              ORDER BY c.id DESC
              LIMIT 1
            ")){
              $st->bind_param('ii', $req['id'], $uid); $st->execute();
              $tradeinCoupon = $st->get_result()->fetch_assoc();
              $st->close();
            }
            if ($tradeinCoupon):
              $limit = (int)($tradeinCoupon['uses_limit'] ?? 0);   // 0 หรือ NULL = ไม่จำกัด
              $used  = (int)($tradeinCoupon['used_total'] ?? 0);
              $left  = ($limit<=0) ? 'ไม่จำกัด' : max(0, $limit - $used);
            ?>
              <div class="alert alert-success mb-3">
                <div><i class="bi bi-ticket-perforated me-1"></i> คูปองเครดิตเทิร์นของคุณ</div>
                <div class="mt-1">
                  โค้ด: <b><?= h($tradeinCoupon['code']) ?></b> — 
                  มูลค่า: <b><?= number_format((float)$tradeinCoupon['value'],2) ?> ฿</b><br>
                  ใช้ได้อีก: <b><?= h($left) ?></b> ครั้ง
                  <?php if(!empty($tradeinCoupon['ends_at'])): ?>
                    , หมดอายุ: <b><?= h($tradeinCoupon['ends_at']) ?></b>
                  <?php endif; ?>
                </div>
                <a href="checkout.php" class="btn btn-outline-primary btn-sm mt-2">ไปหน้า Checkout เพื่อใช้คูปอง</a>
              </div>
            <?php endif; ?>


            <?php if($req['status']==='offered'): ?>
              <div class="alert alert-primary">
                <?php if($req['offer_price']!==null && $req['offer_price']!==''): ?>
                  ข้อเสนอจากร้าน: <b><?= number_format((float)$req['offer_price'],2) ?> ฿</b><br>
                <?php else: ?>
                  มีข้อเสนอให้พิจารณา
                <?php endif; ?>
                <?php if(!empty($req['selected_product_id'])): ?>
                  <div class="mt-1">สินค้าแนะนำ: <span class="badge text-bg-secondary">ID #<?= (int)$req['selected_product_id'] ?></span></div>
                <?php endif; ?>
              </div>

              <form action="tradein_action.php" method="post" class="d-flex gap-2 mb-3">
                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                <button class="btn btn-success" name="action" value="accept">
                  <i class="bi bi-check2-circle"></i> ยอมรับข้อเสนอ
                </button>
                <button class="btn btn-outline-danger" name="action" value="reject">
                  <i class="bi bi-x-circle"></i> ปฏิเสธ
                </button>
              </form>
            <?php elseif($req['status']==='accepted'): ?>
              <div class="alert alert-success mb-3"><i class="bi bi-check2-circle"></i> คุณยอมรับข้อเสนอแล้ว กำลังรอร้านดำเนินการ</div>
            <?php elseif($req['status']==='rejected'): ?>
              <div class="alert alert-secondary mb-3"><i class="bi bi-x-circle"></i> คุณปฏิเสธข้อเสนอแล้ว</div>
            <?php endif; ?>

            <div class="card-body">
              <?php if(empty($logs)): ?>
                <div class="text-muted">ยังไม่มีบันทึกสถานะ</div>
              <?php else: ?>
                <div class="timeline">
                  <?php foreach($logs as $lg): ?>
                    <div class="tl-item">
                      <span class="tl-point"></span>
                      <div class="d-flex justify-content-between align-items-start tl-wrap">
                        <div>
                          <div class="tl-title"><?=h($map[$lg['status']] ?? $lg['status'])?></div>
                          <?php if(!empty($lg['note'])): ?>
                            <div class="tl-note"><?=nl2br(h($lg['note']))?></div>
                          <?php endif; ?>
                        </div>
                        <div class="tl-time"><?=h($lg['created_at'])?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<?php if($type==='repair'): ?>
<script>
document.addEventListener('click', async (e)=>{
  const b = e.target.closest('[data-act]');
  if(!b) return;
  const act = b.dataset.act;
  const pid = b.dataset.prop;
  if(act==='accept' && !confirm('ยืนยันนัดหมายตามเวลาที่เลือกนี้?')) return;
  if(act==='decline' && !confirm('ปฏิเสธเวลานัดนี้?')) return;
  b.disabled = true;
  try{
    const r = await fetch('schedule_reply.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ action:act, prop_id:pid, ticket_id:'<?= (int)$id ?>' })
    });
    const j = await r.json();
    if(j.ok){ location.reload(); }
    else{ alert('ทำรายการไม่สำเร็จ: '+(j.error || 'unknown')); b.disabled=false; }
  }catch(_){ alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); b.disabled=false; }
});
</script>
<?php endif; ?>
</body>
</html>
