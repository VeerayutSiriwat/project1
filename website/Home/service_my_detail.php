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
$proposals = [];     // << เพิ่ม
$appt = [            // << เพิ่ม: สรุปนัดหมาย
  'start'=>null,'end'=>null,'status'=>'none'
];

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
    'queue'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค',
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
    'submitted'=>'ส่งคำขอแล้ว','reviewing'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
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
$APPT_TEXT  = ['none'=>'—','pending'=>'รอยืนยัน','confirmed'=>'ยืนยันแล้ว','declined'=>'ปฏิเสธแล้ว','proposed'=>'แอดมินเสนอเวลาแล้ว'];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?= h($docTitle) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    #serviceDetail{ background:#f6f8fb; }
    #serviceDetail .card{ border:1px solid #e9eef3; border-radius:18px; overflow:hidden; }
    #serviceDetail .card-header{
      background:linear-gradient(180deg,#ffffff,#f6f9ff);
      border-bottom:1px solid #eef2f6;
      font-weight:600;
    }
    #serviceDetail .kv .k{ color:#6b7280; font-size:.85rem; }
    #serviceDetail .kv .v{ font-weight:500; }
    .ti-thumb{width:100%; height:110px; object-fit:cover; border-radius:10px; border:1px solid #e6edf6}
    .thumb-lg{max-height:360px; object-fit:contain; background:#fff; border:1px solid #e6edf6; border-radius:12px}
    /* Timeline */
    #serviceDetail .timeline{ position:relative; padding-left:1rem; }
    #serviceDetail .timeline::before{ content:""; position:absolute; left:.6rem; top:4px; bottom:4px; width:2px; background:#e6edf6; }
    #serviceDetail .tl-item{ position:relative; padding-left:1.5rem; margin-bottom:.75rem; }
    #serviceDetail .tl-item:last-child{ margin-bottom:0; }
    #serviceDetail .tl-point{ position:absolute; left:-.05rem; top:.3rem; width:12px;height:12px;border-radius:999px; background:#0d6efd; box-shadow:0 0 0 3px #e7f0ff; }
    #serviceDetail .tl-title{ font-weight:700; }
    #serviceDetail .tl-note{ color:#6b7280; font-size:.9rem; }
    #serviceDetail .tl-time{ color:#6b7280; font-size:.8rem; white-space:nowrap; }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<main id="serviceDetail">
  <div class="container py-4">
    <a href="service_my.php" class="btn btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> กลับ</a>

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

              <!-- ตัวเลือก -->
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
                  <div class="k">รวมค่าบริการโดยประมาณ</div>
                  <div class="v">
                    <?= number_format((float)($ticket['estimate_total'] ?? 0), 2) ?> ฿
                  </div>
                </div>
              </div>
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
                      <div class="small text-muted">สถานะ: <?= h($p['status']) ?></div>
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
                        <span class="badge bg-secondary"><?= h($p['status']) ?></span>
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
            <div class="card-header">
              สถานะปัจจุบัน: <span class="badge text-bg-primary"><?=h($map[$ticket['status']] ?? $ticket['status'])?></span>
            </div>
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
                <div class="col-md-6"><div class="k">รหัสสินค้าที่เลือก</div><div class="v"><?=h($req['selected_product_id'] ?: '-')?></div></div>
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
// ==== บล็อกคูปองเทิร์น (เวอร์ชันทนสคีมา) ====

// ดึงคูปองเทิร์นของคำขอนี้ (ถ้ามี) — map ชื่อคอลัมน์ให้เข้ากับ schema จริง
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
