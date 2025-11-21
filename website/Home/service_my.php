<?php 
// Home/service_my.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }

/* กันแคช: เวลาแอดมินอัปเดตสถานะ/เร่งด่วน ให้หน้านี้เห็นค่าล่าสุดเสมอ */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

/** พาธรูปให้รอดเสมอ */
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
  foreach (['assets/img/no-image.png','assets/img/default.png'] as $cand) {
    if (is_file(__DIR__.'/'.$cand)) return $cand;
  }
  return fallback_data_uri();
}

/** ✅ normalize ค่าความเร่งด่วนให้เหลือ normal/urgent */
function norm_urgency($v): string {
  $s = strtolower(trim((string)$v));
  if ($s === '' || $s === '0' || $s === 'false' || $s === 'normal' || $s === 'ปกติ') return 'normal';
  if (in_array($s, ['urgent','ด่วน','เร่งด่วน','1','true'], true)) return 'urgent';
  return 'normal';
}
$urgencyLabel = ['normal'=>'ปกติ','urgent'=>'เร่งด่วน'];
$urgencyClass = ['normal'=>'secondary','urgent'=>'danger'];

/** แปลง need ของเทิร์นเป็นไทยให้สวย */
function map_need($v): string {
  $v = trim((string)$v);
  $map = ['buy_new'=>'เทิร์นซื้อใหม่', 'cash'=>'ขายรับเงินสด'];
  return $map[$v] ?? $v;
}

$uid = (int)$_SESSION['user_id'];

$repair = $tradein = [];

/* ===== ซ่อมของฉัน (ดึงสถานะจาก log ล่าสุด) ===== */
if ($st = $conn->prepare("
  SELECT
    st.id, st.device_type, st.brand, st.model, st.phone,
    st.desired_date, st.urgency, st.image_path, st.created_at, st.updated_at,
    st.payment_status,
    COALESCE(
      (SELECT l.status FROM service_status_logs l
       WHERE l.ticket_id = st.id
       ORDER BY l.id DESC
       LIMIT 1),
      st.status
    ) AS status_eff
  FROM service_tickets st
  WHERE st.user_id=?
  ORDER BY st.updated_at DESC, st.id DESC
")) {
  $st->bind_param('i', $uid);
  $st->execute();
  $repair = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

/* ===== เทิร์นของฉัน ===== (เติมรูปปกจาก tradein_images ถ้า image_path ว่าง) */
if ($st = $conn->prepare("
  SELECT
    tr.id,
    tr.device_type, tr.brand, tr.model,
    tr.device_condition, tr.need, tr.offer_price, tr.selected_product_id,
    tr.status,
    CASE
      WHEN tr.image_path IS NOT NULL AND tr.image_path <> '' THEN tr.image_path
      ELSE CONCAT('assets/img/', (
        SELECT ti.filename FROM tradein_images ti
        WHERE ti.request_id = tr.id AND ti.is_cover = 1
        ORDER BY ti.id ASC LIMIT 1
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
  'queued'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค',
  'waiting_parts'=>'รออะไหล่','repairing'=>'กำลังซ่อม',
  'done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก',
];
$repairStatusClass = [
  'queued'=>'secondary','confirm'=>'primary','checking'=>'info',
  'waiting_parts'=>'warning text-dark','repairing'=>'primary',
  'done'=>'success','cancelled'=>'danger',
];
/* ===== MAP สถานะการเงิน ===== */
$payStatusMap = [
  'unpaid'  => 'ยังไม่ชำระ',
  'pending' => 'รอตรวจสอบ',
  'paid'    => 'ชำระแล้ว'
];

$payStatusClass = [
  'unpaid'  => 'danger',
  'pending' => 'warning text-dark',
  'paid'    => 'success'
];

$tradeStatusMap = [
  'submitted'=>'ส่งคำขอแล้ว','review'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
  'accepted'=>'ผู้ใช้ยอมรับข้อเสนอ','rejected'=>'ปฏิเสธข้อเสนอ',
  'cancelled'=>'ยกเลิก','completed'=>'เสร็จสิ้น',
];
$tradeStatusClass = [
  'submitted'=>'secondary','review'=>'info','offered'=>'primary',
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
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    :root{
      --bg:#f6f8fb; --card:#fff; --line:#e9eef3; --ink:#0b1a37; --muted:#6b7280;
      --pri:#2563eb; --pri2:#4f46e5;
    }
    .page-head{
      border-radius:20px; color:#fff; padding:18px 18px 12px;
      background:linear-gradient(135deg,var(--pri) 0%, var(--pri2) 55%, #0ea5e9 100%);
      box-shadow:0 8px 24px rgba(37,99,235,.15);
    }
    .tabs{
      margin-top:12px; background:#fff; border:1px solid var(--line); border-radius:14px; padding:6px; display:flex; gap:6px; flex-wrap:wrap;
    }
    .tab-btn{ border:none; background:transparent; color:#e5edff; padding:8px 14px; border-radius:999px; font-weight:700; font-size:.9rem; }
    .tab-btn.active{ color:#0b1a37; background:#eef3ff; }
    .section{background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 18px 48px rgba(2,6,23,.06)}
    .sec-head{display:flex;align-items:center;gap:.6rem;padding:14px 18px;border-bottom:1px solid #eef2f6;background:linear-gradient(180deg,#ffffff,#fafcff)}
    .sec-head .pill{margin-left:auto;background:#f1f5ff;border:1px solid #dbe6ff;border-radius:999px;padding:.25rem .7rem;font-weight:700;font-size:.78rem}
    .toolbar{ padding:10px 16px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .tbl-wrap{overflow:auto}
    .table-modern{margin:0}
    .table-modern thead th{background:linear-gradient(180deg,#fbfdff,#f2f6ff);border-bottom:1px solid #e6edf6;color:#1f2937;font-weight:700}
    .table-modern tbody tr{transition:background .15s}
    .table-modern tbody tr:hover{background:#f9fbff}
    .table-modern td,.table-modern th{vertical-align:middle}
    .thumb{width:46px;height:46px;border-radius:10px;object-fit:cover;border:1px solid #e6edf6;background:#fff}
    .badge{padding:.45rem .6rem;font-weight:800}
    .tag-soft{display:inline-flex;align-items:center;gap:.35rem;background:#eef2f7;border:1px solid #e5ecf6;border-radius:999px;padding:.2rem .55rem;font-weight:700;font-size:.8rem;color:#334155}
    .row-urgent{box-shadow: inset 0 0 0 9999px rgba(255, 0, 0, .02);}

    /* ===== iOS glass legend chips ===== */
    .legend{
      display:flex;
      flex-wrap:wrap;
      gap:.5rem;
    }
    .legend .chip{
      position:relative;
      display:inline-flex;
      align-items:center;
      gap:.45rem;

      padding:.32rem .9rem;
      min-height:32px;
      border-radius:999px;

      font-weight:600;
      font-size:.8rem;
      color:#f9fafb;
      letter-spacing:.01em;
      white-space:nowrap;

      background:linear-gradient(135deg,
        rgba(255,255,255,.26),
        rgba(255,255,255,.10)
      );
      border:1px solid rgba(255,255,255,.65);
      box-shadow:
        0 10px 26px rgba(15,23,42,.35),
        inset 0 0 0 0.5px rgba(255,255,255,.7);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
    }
    .legend .chip i{
      font-size:1rem;
      opacity:.96;
    }
    .legend .dot{
      width:9px;
      height:9px;
      border-radius:999px;
      display:inline-block;
      box-shadow:0 0 0 1px rgba(15,23,42,.3), 0 0 12px rgba(255,255,255,.6);
    }

    /* mobile cards */
    @media (max-width: 992px){
      .table-modern thead{display:none}
      .table-modern tbody tr{display:block;margin:12px;border:1px solid var(--line);border-radius:14px;padding:.75rem;background:#fff}
      .table-modern tbody td{display:flex;justify-content:space-between;border:0;border-bottom:1px dashed #eef2f6;padding:.5rem 0}
      .table-modern tbody td:last-child{border-bottom:0}
      .table-modern tbody td::before{content:attr(data-label);font-weight:700;color:#6b7280;margin-right:1rem}
      .thumb{width:42px;height:42px}
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<main id="servicePage">
  <div class="container py-4">

    <div class="page-head">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="m-0"><i class="bi bi-person-check me-2"></i>สถานะงานซ่อม/เทิร์น</h3>
        <div class="legend">
          <span class="chip">
            <span class="dot" style="background:#6c757d"></span>
            รอดำเนินการ
          </span>
          <span class="chip">
            <span class="dot" style="background:#0d6efd"></span>
            กำลังดำเนินการ
          </span>
          <span class="chip">
            <span class="dot" style="background:#198754"></span>
            เสร็จสิ้น
          </span>
          <span class="chip">
            <span class="dot" style="background:#dc3545"></span>
            ยกเลิก/ปฏิเสธ
          </span>
        </div>
      </div>

      <div class="tabs" role="tablist">
        <button class="tab-btn active" data-target="#tab-repair"  role="tab" aria-selected="true">
          <i class="bi bi-wrench-adjustable me-1"></i> งานซ่อม (<?=count($repair)?>)
        </button>
        <button class="tab-btn"         data-target="#tab-tradein" role="tab" aria-selected="false">
          <i class="bi bi-arrow-left-right me-1"></i> เทิร์น (<?=count($tradein)?>)
        </button>
      </div>
    </div>

    <!-- ===== Toolbar (ค้นหา/รีเฟรช) ===== -->
    <div class="toolbar">
      <div class="input-group" style="max-width:420px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="q" class="form-control" placeholder="ค้นหา: ST-/TR-, ประเภท, ยี่ห้อ, รุ่น, โทร, สถานะ">
      </div>
      <button id="btnRefresh" class="btn btn-outline-primary ms-auto">
        <i class="bi bi-arrow-clockwise"></i> รีเฟรช
      </button>
    </div>

    <!-- ===== ซ่อมของฉัน ===== -->
    <section id="tab-repair" class="section mt-2" role="tabpanel">
      <div class="sec-head">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-wrench-adjustable"></i>
          <span class="fw-semibold">งานซ่อมของฉัน</span>
        </div>
        <span class="pill small"><i class="bi bi-tools me-1"></i> ทั้งหมด <?= count($repair) ?> รายการ</span>
      </div>

      <div class="tbl-wrap">
        <table class="table table-modern align-middle mb-0" id="tblRepair">
          <thead>
            <tr>
              <th style="min-width:110px">เลขงาน</th>
              <th style="min-width:260px">อุปกรณ์</th>
              <th style="min-width:140px">นัดหมาย</th>
              <th style="min-width:110px">เร่งด่วน</th>
              <th style="min-width:160px">สถานะ</th>
              <th style="min-width:150px">การชำระเงิน</th>
              <th style="min-width:160px">อัปเดตล่าสุด</th>
              <th style="min-width:160px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($repair)): ?>
            <tr data-empty="repair"><td colspan="7" class="text-center text-muted py-4">ยังไม่มีงานซ่อม</td></tr>
          <?php else: foreach($repair as $r):
              $img   = thumb_path($r['image_path'] ?? '');
              $u     = norm_urgency($r['urgency'] ?? 'normal');
              $rsKey = $r['status_eff'] ?? ($r['status'] ?? '');
              $rsClass = $repairStatusClass[$rsKey] ?? 'secondary';
              $rsText  = $repairStatusMap[$rsKey] ?? $rsKey;
              $rowCls  = ($u==='urgent') ? 'row-urgent' : '';
              $ticket  = 'ST-'.(int)$r['id'];
              $needle  = strtolower($ticket.' '.$r['device_type'].' '.$r['brand'].' '.$r['model'].' '.$r['phone'].' '.$rsText);
            ?>
            <tr class="<?= $rowCls ?>" data-search="<?= h($needle) ?>">
              <td data-label="เลขงาน">
                <div class="d-flex align-items-center gap-2">
                  <span class="fw-bold"><?=$ticket?></span>
                  <button class="btn btn-sm btn-outline-secondary copy" data-copy="<?=$ticket?>" title="คัดลอก">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </div>
              </td>
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
                <span class="badge bg-<?= $urgencyClass[$u] ?>">
                  <?php if($u==='urgent'): ?><i class="bi bi-lightning-charge"></i> <?php endif; ?>
                  <?= h($urgencyLabel[$u]) ?>
                </span>
              </td>
              <td data-label="สถานะ"><span class="badge bg-<?= $rsClass ?>"><?= h($rsText) ?></span></td>
              <td data-label="การชำระเงิน">
              <?php 
                $ps = strtolower($r['payment_status'] ?? 'unpaid');
                $psText  = $payStatusMap[$ps]   ?? $ps;
                $psClass = $payStatusClass[$ps] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $psClass ?>"><?= h($psText) ?></span>
            </td>
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
    </section>

    <!-- ===== เทิร์นของฉัน ===== -->
    <section id="tab-tradein" class="section mt-3 d-none" role="tabpanel" aria-hidden="true">
      <div class="sec-head">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-arrow-left-right"></i>
          <span class="fw-semibold">คำขอเทิร์นของฉัน</span>
        </div>
        <span class="pill small"><i class="bi bi-repeat me-1"></i> ทั้งหมด <?= count($tradein) ?> รายการ</span>
      </div>

      <div class="tbl-wrap">
        <table class="table table-modern align-middle mb-0" id="tblTrade">
          <thead>
            <tr>
              <th style="min-width:110px">เลขคำขอ</th>
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
            <tr data-empty="trade"><td colspan="7" class="text-center text-muted py-4">ยังไม่มีคำขอเทิร์น</td></tr>
          <?php else: foreach($tradein as $t):
            $img   = thumb_path($t['image_path'] ?? '');
            $tsKey = $t['status'] ?? '';
            $tsClass = $tradeStatusClass[$tsKey] ?? 'secondary';
            $tsText  = $tradeStatusMap[$tsKey] ?? $tsKey;
            $ticket  = 'TR-'.(int)$t['id'];
            $needle  = strtolower($ticket.' '.$t['device_type'].' '.$t['brand'].' '.$t['model'].' '.map_need($t['need']??'').' '.$tsText);
          ?>
            <tr data-search="<?= h($needle) ?>">
              <td data-label="เลขคำขอ">
                <div class="d-flex align-items-center gap-2">
                  <span class="fw-bold"><?=$ticket?></span>
                  <button class="btn btn-sm btn-outline-secondary copy" data-copy="<?=$ticket?>" title="คัดลอก">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </div>
              </td>
              <td data-label="อุปกรณ์">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?= h($img) ?>" class="thumb" alt="">
                  <div class="fw-semibold"><?=h($t['device_type'])?> — <?=h($t['brand'])?> <?=h($t['model'])?></div>
                </div>
              </td>
              <td data-label="ความต้องการ">
                <?php if(!empty($t['need'])): ?>
                  <span class="tag-soft"><i class="bi bi-bag-plus"></i> <?= h(map_need($t['need'])) ?></span>
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
    </section>

    <div class="text-center mt-3">
      <a class="btn btn-outline-primary" href="coupons_my.php">
        <i class="bi bi-ticket-perforated"></i> ดูคูปองของฉัน
      </a>
    </div>

  </div>
</main>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
  // สลับแท็บ
  document.querySelectorAll('.tab-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      const target = btn.getAttribute('data-target');
      document.querySelectorAll('[role="tabpanel"]').forEach(p=>{
        const show = '#'+p.id === target;
        p.classList.toggle('d-none', !show);
        p.setAttribute('aria-hidden', show ? 'false' : 'true');
      });
      const q = document.getElementById('q'); if(q){ q.value=''; filterTable(''); }
    });
  });

  // ค้นหาแบบทันที (ทั้ง 2 ตาราง)
  const q = document.getElementById('q');
  function filterTable(text){
    const val = String(text||'').trim().toLowerCase();
    ['tblRepair','tblTrade'].forEach(id=>{
      const tb = document.getElementById(id)?.querySelector('tbody'); if(!tb) return;
      let any = false;
      tb.querySelectorAll('tr').forEach(tr=>{
        if(tr.hasAttribute('data-empty')) return;
        const needle = tr.getAttribute('data-search')||'';
        const show = val==='' || needle.indexOf(val)>-1;
        tr.style.display = show ? '' : 'none';
        if(show) any = true;
      });
      const empty = tb.querySelector('[data-empty]');
      if(empty){ empty.style.display = any ? 'none' : ''; }
    });
  }
  q?.addEventListener('input', e=> filterTable(e.target.value));

  // ปุ่มรีเฟรช
  document.getElementById('btnRefresh')?.addEventListener('click', ()=>{
    location.replace('service_my.php');
  });

  // คัดลอกเลขงาน/คำขอ
  document.addEventListener('click', e=>{
    const b = e.target.closest('.copy'); if(!b) return;
    const v = b.getAttribute('data-copy')||'';
    navigator.clipboard?.writeText(v).then(()=>{
      const old = b.innerHTML;
      b.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(()=> b.innerHTML = old, 900);
    });
  }, {passive:true});
</script>
</body>
</html>
