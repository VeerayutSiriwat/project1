<?php
// Home/service_my_detail.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service_my.php'); exit; }
require_once __DIR__.'/includes/image_helpers.php'; // ใช้โหลดแกลเลอรีเทิร์น

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
  if (preg_match('~^(https?://|data:image/)~i', $v)) return $v; // url หรือ data-uri
  $rel = (strpos($v,'/')!==false) ? $v : ('assets/img/'.$v);     // ชื่อไฟล์ล้วน → เติมโฟลเดอร์ให้
  if (is_file(__DIR__.'/'.$rel)) return $rel;
  foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
    if (is_file(__DIR__.'/'.$cand)) return $cand;
  }
  return fallback_data_uri();
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
}else{
  // ถ้า image_path ว่าง ให้ดึงรูปปกจาก tradein_images ตั้งแต่ใน SQL (ประกอบเป็น assets/img/filename)
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

  // แกลเลอรีหลายรูปของเทิร์น
  $gallery = load_tradein_gallery($conn, (int)$req['id']);
}

/* ---- ตั้ง <title> ---- */
$docTitle = 'รายละเอียดงาน | WEB APP';
if ($type==='repair'  && $ticket) $docTitle = 'ใบงานซ่อม ST-'.(int)$ticket['id'].' | WEB APP';
if ($type==='tradein' && $req)    $docTitle = 'คำขอเทิร์น TR-'.(int)$req['id'].' | WEB APP';
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
    #serviceDetail .hero{
      background:linear-gradient(135deg,#f8fbff,#f6f9ff);
      border:1px solid #e7eef7; border-radius:16px; padding:12px 14px;
    }
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

    @media (max-width: 992px){
      #serviceDetail .tl-wrap{ display:block !important; }
      #serviceDetail .tl-time{ margin-top:.25rem; }
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<main id="serviceDetail">
  <div class="container py-4">
    <a href="service_my.php" class="btn btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> กลับ</a>

    <?php if($type==='repair'): ?>
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header">
              ใบงานซ่อม <span class="badge text-bg-dark">ST-<?= (int)$ticket['id'] ?></span>
            </div>
            <div class="card-body">
              <div class="row g-3 kv">
                <div class="col-md-4"><div class="k">ประเภท</div><div class="v"><?=h($ticket['device_type'])?></div></div>
                <div class="col-md-4"><div class="k">ยี่ห้อ</div><div class="v"><?=h($ticket['brand'])?></div></div>
                <div class="col-md-4"><div class="k">รุ่น</div><div class="v"><?=h($ticket['model'])?></div></div>
                <div class="col-md-6"><div class="k">โทร</div><div class="v"><?=h($ticket['phone'])?></div></div>
                <div class="col-md-6"><div class="k">LINE</div><div class="v"><?=h($ticket['line_id'])?></div></div>
                <div class="col-md-6"><div class="k">นัดหมาย</div><div class="v"><?=h($ticket['desired_date'] ?: '-')?><?= $ticket['urgency']==='urgent' ? ' <span class="badge text-bg-danger ms-1">ด่วน</span>' : '' ?></div></div>
                <div class="col-md-6"><div class="k">เร่งด่วน</div><div class="v"><?=h($ticket['urgency'])?></div></div>
                <div class="col-12"><div class="k">อาการที่แจ้ง</div><div class="v"><?=nl2br(h($ticket['issue']))?></div></div>
              </div>
              <?php
                $img = thumb_path($ticket['image_path'] ?? '');
                if($img){ echo '<hr><img class="img-fluid thumb-lg" src="'.h($img).'" alt="">'; }
              ?>
            </div>
          </div>
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
                // แสดงรูป: ถ้ามีแกลเลอรีใช้หลายรูป, ไม่งั้นใช้รูปปก/รูปเดี่ยว
                if($gallery){
                  echo '<hr><div class="row g-2">';
                  foreach($gallery as $g){
                    $src = thumb_path($g['filename'] ?? '');
                    echo '<div class="col-4 col-md-3"><img class="ti-thumb" src="'.h($src).'" alt=""></div>';
                  }
                  echo '</div>';
                } else {
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
</body>
</html>
