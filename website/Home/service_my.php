<?php
// Home/service_my.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service_my.php'); exit; }

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

/** data-uri fallback (โลคัล ไม่ง้อเน็ต) */
function fallback_data_uri(): string {
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80">'
       . '<rect width="100%" height="100%" fill="#eef2f7"/>'
       . '<text x="50%" y="55%" text-anchor="middle" font-family="Arial" font-size="10" fill="#99a3b1">no image</text>'
       . '</svg>';
  return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
}

/**
 * คืนพาธรูปที่ใช้ได้จริง:
 * - url/data-uri => คืนเลย
 * - ชื่อไฟล์ล้วน  => เติม assets/img/
 * - ถ้าไม่พบไฟล์ => ใช้รูป default ในโปรเจกต์ ถ้าไม่มี ใช้ data-uri
 */
function thumb_path(?string $val): string {
  $v = trim((string)$val);
  if ($v === '') {
    foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
      if (is_file(__DIR__.'/'.$cand)) return $cand;
    }
    return fallback_data_uri();
  }
  if (preg_match('~^(https?://|data:image/)~i', $v)) return $v;

  $rel = (strpos($v, '/') !== false) ? $v : ('assets/img/'.$v);
  if (is_file(__DIR__.'/'.$rel)) return $rel;

  // ถ้าไม่เจอไฟล์จริง ให้ลอง fallback โลคัลก่อน
  foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
    if (is_file(__DIR__.'/'.$cand)) return $cand;
  }
  return fallback_data_uri();
}

$uid = (int)$_SESSION['user_id'];

$repair = $tradein = [];

/* ===== ซ่อมของฉัน ===== */
if ($st = $conn->prepare("
  SELECT id, device_type, brand, model, phone, desired_date, urgency, status,
         image_path, created_at, updated_at
  FROM service_tickets
  WHERE user_id=?
  ORDER BY updated_at DESC, id DESC
")) {
  $st->bind_param('i', $uid);
  $st->execute();
  $repair = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

/* ===== เทิร์นของฉัน =====
   ถ้า tr.image_path ว่าง ให้ประกอบพาธรูปปกจาก tradein_images ตั้งแต่ใน SQL */
if ($st = $conn->prepare("
  SELECT
    tr.id,
    tr.device_type, tr.brand, tr.model,
    tr.device_condition, tr.need, tr.offer_price, tr.selected_product_id,
    tr.status,
    CASE
      WHEN tr.image_path IS NOT NULL AND tr.image_path <> '' THEN tr.image_path
      ELSE CONCAT('assets/img/', (
        SELECT ti.filename
        FROM tradein_images ti
        WHERE ti.request_id = tr.id AND ti.is_cover = 1
        ORDER BY ti.id ASC
        LIMIT 1
      ))
    END AS image_path,
    tr.created_at, tr.updated_at
  FROM tradein_requests tr
  WHERE tr.user_id=?
  ORDER BY tr.updated_at DESC, tr.id DESC
")) {
  $st->bind_param('i', $uid);
  $st->execute();
  $tradein = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

/* mapping สถานะ */
$repairStatusMap = [
  'queue'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค',
  'waiting_parts'=>'รออะไหล่','repairing'=>'กำลังซ่อม',
  'done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก',
];
$repairStatusClass = [
  'queue'=>'secondary','confirm'=>'primary','checking'=>'info',
  'waiting_parts'=>'warning text-dark','repairing'=>'primary',
  'done'=>'success','cancelled'=>'danger',
];

$tradeStatusMap = [
  'submitted'=>'ส่งคำขอแล้ว','reviewing'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
  'accepted'=>'ผู้ใช้ยอมรับข้อเสนอ','rejected'=>'ปฏิเสธข้อเสนอ',
  'cancelled'=>'ยกเลิก','completed'=>'เสร็จสิ้น',
];
$tradeStatusClass = [
  'submitted'=>'secondary','reviewing'=>'info','offered'=>'primary',
  'accepted'=>'success','rejected'=>'danger','cancelled'=>'danger','completed'=>'success',
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>สถานะงานซ่อม/เทิร์น | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    #servicePage{background:#f6f8fb}
    #servicePage .section{background:#fff;border:1px solid #e9eef3;border-radius:18px;overflow:hidden;box-shadow:0 18px 48px rgba(2,6,23,.06)}
    #servicePage .sec-head{display:flex;align-items:center;gap:.6rem;padding:14px 18px;border-bottom:1px solid #eef2f6;background:linear-gradient(180deg,#ffffff,#fafcff)}
    #servicePage .sec-head .pill{margin-left:auto;background:#f1f5ff;border:1px solid #dbe6ff;border-radius:999px;padding:.25rem .6rem;font-weight:600}
    #servicePage .tbl-wrap{overflow:auto}
    #servicePage .table-modern{margin:0}
    #servicePage .table-modern thead th{background:linear-gradient(180deg,#fbfdff,#f2f6ff);border-bottom:1px solid #e6edf6;color:#1f2937;font-weight:700}
    #servicePage .table-modern tbody tr{transition:background .15s}
    #servicePage .table-modern tbody tr:hover{background:#f9fbff}
    #servicePage .table-modern td,#servicePage .table-modern th{vertical-align:middle}
    #servicePage .thumb{width:46px;height:46px;border-radius:10px;object-fit:cover;border:1px solid #e6edf6;background:#fff}
    #servicePage .badge{padding:.45rem .6rem;font-weight:700}
    #servicePage .tag-soft{display:inline-flex;align-items:center;gap:.35rem;background:#eef2f7;border:1px solid #e5ecf6;border-radius:999px;padding:.2rem .55rem;font-weight:600}
    @media (max-width: 992px){
      #servicePage .table-modern thead{display:none}
      #servicePage .table-modern tbody tr{display:block;margin:12px;border:1px solid #e9eef3;border-radius:14px;padding:.75rem;background:#fff}
      #servicePage .table-modern tbody td{display:flex;justify-content:space-between;border:0;border-bottom:1px dashed #eef2f6;padding:.5rem 0}
      #servicePage .table-modern tbody td:last-child{border-bottom:0}
      #servicePage .table-modern tbody td::before{content:attr(data-label);font-weight:600;color:#6b7280;margin-right:1rem}
      #servicePage .thumb{width:42px;height:42px}
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<main id="servicePage">
  <div class="container py-4">
    <h3 class="fw-bold mb-3"><i class="bi bi-person-check"></i> สถานะงานซ่อม/เทิร์น</h3>

    <!-- ===== ซ่อมของฉัน ===== -->
    <div class="section mb-4">
      <div class="sec-head">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-wrench-adjustable"></i>
          <span class="fw-semibold">งานซ่อมของฉัน</span>
        </div>
        <span class="pill small"><i class="bi bi-tools me-1"></i> ทั้งหมด <?= count($repair) ?> รายการ</span>
      </div>

      <div class="tbl-wrap">
        <table class="table table-modern align-middle mb-0">
          <thead>
            <tr>
              <th style="min-width:90px">#</th>
              <th style="min-width:260px">อุปกรณ์</th>
              <th style="min-width:140px">นัดหมาย</th>
              <th style="min-width:110px">เร่งด่วน</th>
              <th style="min-width:160px">สถานะ</th>
              <th style="min-width:160px">อัปเดตล่าสุด</th>
              <th style="min-width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($repair)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีงานซ่อม</td></tr>
          <?php else: foreach($repair as $r):
            $img = thumb_path($r['image_path'] ?? '');
            $urgent = ($r['urgency'] ?? 'normal')==='urgent';
            $rsKey = $r['status'] ?? '';
            $rsClass = $repairStatusClass[$rsKey] ?? 'secondary';
            $rsText  = $repairStatusMap[$rsKey] ?? $rsKey;
          ?>
            <tr>
              <td data-label="#"><span class="fw-semibold">ST-<?= (int)$r['id'] ?></span></td>
              <td data-label="อุปกรณ์">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?= h($img) ?>" class="thumb" alt="">
                  <div>
                    <div class="fw-semibold"><?=h($r['device_type'])?> — <?=h($r['brand'])?> <?=h($r['model'])?></div>
                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i> <?=h($r['phone'])?></div>
                  </div>
                </div>
              </td>
              <td data-label="นัดหมาย">
                <?php if(!empty($r['desired_date'])): ?>
                  <span class="tag-soft"><i class="bi bi-calendar2-week"></i> <?= h($r['desired_date']) ?></span>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
              </td>
              <td data-label="เร่งด่วน">
                <?php if($urgent): ?>
                  <span class="badge bg-danger"><i class="bi bi-lightning-charge"></i> ด่วน</span>
                <?php else: ?>
                  <span class="badge bg-secondary">ปกติ</span>
                <?php endif; ?>
              </td>
              <td data-label="สถานะ"><span class="badge bg-<?= $rsClass ?>"><?= h($rsText) ?></span></td>
              <td data-label="อัปเดตล่าสุด" class="small text-muted"><?= h($r['updated_at']) ?></td>
              <td data-label="" class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="service_my_detail.php?type=repair&id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-eye"></i> รายละเอียด
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ===== เทิร์นของฉัน ===== -->
    <div class="section">
      <div class="sec-head">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-arrow-left-right"></i>
          <span class="fw-semibold">คำขอเทิร์นของฉัน</span>
        </div>
        <span class="pill small"><i class="bi bi-repeat me-1"></i> ทั้งหมด <?= count($tradein) ?> รายการ</span>
      </div>

      <div class="tbl-wrap">
        <table class="table table-modern align-middle mb-0">
          <thead>
            <tr>
              <th style="min-width:90px">#</th>
              <th style="min-width:260px">อุปกรณ์</th>
              <th style="min-width:160px">ความต้องการ</th>
              <th style="min-width:140px">ราคาเสนอ</th>
              <th style="min-width:160px">สถานะ</th>
              <th style="min-width:160px">อัปเดตล่าสุด</th>
              <th style="min-width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($tradein)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีคำขอเทิร์น</td></tr>
          <?php else: foreach($tradein as $t):
            $img   = thumb_path($t['image_path'] ?? '');
            $tsKey = $t['status'] ?? '';
            $tsClass = $tradeStatusClass[$tsKey] ?? 'secondary';
            $tsText  = $tradeStatusMap[$tsKey] ?? $tsKey;
          ?>
            <tr>
              <td data-label="#"><span class="fw-semibold">TR-<?= (int)$t['id'] ?></span></td>
              <td data-label="อุปกรณ์">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?= h($img) ?>" class="thumb" alt="">
                  <div class="fw-semibold"><?=h($t['device_type'])?> — <?=h($t['brand'])?> <?=h($t['model'])?></div>
                </div>
              </td>
              <td data-label="ความต้องการ">
                <?php if(!empty($t['need'])): ?>
                  <span class="tag-soft"><i class="bi bi-bag-plus"></i> <?= h($t['need']) ?></span>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
              </td>
              <td data-label="ราคาเสนอ">
                <?= $t['offer_price']!==null ? number_format((float)$t['offer_price'],2) . ' ฿' : '<span class="text-muted">-</span>' ?>
              </td>
              <td data-label="สถานะ"><span class="badge bg-<?= $tsClass ?>"><?= h($tsText) ?></span></td>
              <td data-label="อัปเดตล่าสุด" class="small text-muted"><?= h($t['updated_at']) ?></td>
              <td data-label="" class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="service_my_detail.php?type=tradein&id=<?= (int)$t['id'] ?>">
                  <i class="bi bi-eye"></i> รายละเอียด
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__.'/assets/html/footer.html'; ?>
</body>
</html>
