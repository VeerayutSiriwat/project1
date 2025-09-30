<?php 
// Home/service.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
$loggedIn = isset($_SESSION['user_id']);
$userId   = $loggedIn ? (int)$_SESSION['user_id'] : null;

// preload ชื่อผู้ใช้ (ไว้ทักทาย)
$username = '';
if ($loggedIn) {
  if ($st=$conn->prepare("SELECT username FROM users WHERE id=?")) {
    $st->bind_param("i",$userId); $st->execute();
    $username = $st->get_result()->fetch_assoc()['username'] ?? '';
    $st->close();
  }
}
// สถานะงานซ่อม
$gradeChoices = [
  'used'     => ['label'=>'มือสอง',   'desc'=>'อะไหล่มือสองแท้ สภาพดี ตรวจสภาพก่อนติดตั้ง',          'add'=>0],
  'standard' => ['label'=>'ปานกลาง',  'desc'=>'อะไหล่เทียบคุณภาพดี (รับประกันร้าน)',                   'add'=>400],
  'premium'  => ['label'=>'ดีมาก',    'desc'=>'อะไหล่แท้ใหม่/เทียบเกรดพรีเมียม อายุใช้งานยาว',          'add'=>900],
];
$warrantyChoices = [
   0 => ['label'=>'ไม่เพิ่ม (ฟรี 1 เดือนอยู่แล้ว)', 'months'=>0,  'add'=>0],
   3 => ['label'=>'+3 เดือน',                         'months'=>3,  'add'=>300],
   6 => ['label'=>'+6 เดือน',                         'months'=>6,  'add'=>500],
  12 => ['label'=>'+12 เดือน',                        'months'=>12, 'add'=>800],
];

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>บริการซ่อม / เทิร์น | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#ffffff; --border:#e9eef3; --ink:#0f172a; --muted:#6b7280;
      --brand:#0d6efd; --brand2:#4f46e5; --soft:#eef2f7; --chip:#eaf2ff; --chip-b:#cfe1ff;
    }
    body{background:var(--bg); color:var(--ink)}
    .hero{border:1px solid var(--border); border-radius:18px; background:linear-gradient(180deg,#fff,#f9fbff); padding:18px 20px; box-shadow:0 20px 60px rgba(2,6,23,.06)}
    .section{border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:0 18px 48px rgba(2,6,23,.06)}
    .sec-head{display:flex; align-items:center; gap:.6rem; padding:14px 18px; border-bottom:1px solid #eef2f6; background:linear-gradient(180deg,#ffffff,#fafcff)}
    .pill{margin-left:auto; background:#f1f5ff; border:1px solid #dbe6ff; border-radius:999px; padding:.25rem .6rem; font-weight:600}
    .muted{color:var(--muted)}
    .form-hint{font-size:.85rem; color:var(--muted)}
    .drop{border:1px dashed #b6c2d1; border-radius:14px; background:#f8fafc; padding:18px; text-align:center; transition:.15s}
    .drop.drag{background:#eef6ff; border-color:#93b7ff}
    .drop .big{font-size:34px}
    .ti-dropzone{border:1px dashed #b6c2d1; border-radius:14px; background:#f8fafc; padding:18px; text-align:center; transition:.15s}
    .ti-dropzone.drag{background:#eef6ff; border-color:#93b7ff}
    .ti-thumb img{width:100%; height:110px; object-fit:cover; border-radius:10px; border:1px solid rgba(197, 217, 221, 0.12)}
    .preview{display:grid; grid-template-columns:repeat(3,1fr); gap:.6rem; margin-top:.75rem}
    .preview img{width:100%; height:110px; object-fit:cover; border-radius:10px; border:1px solid var(--border); background:#fff}
    .chip{display:inline-flex; align-items:center; gap:.4rem; background:var(--chip); border:1px solid var(--chip-b); padding:.3rem .7rem; border-radius:999px; font-weight:600}
    .note{background:var(--soft); border:1px solid #e5ecf6; border-radius:12px; padding:10px 12px}
  </style>
</head>
<body>

<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">

  <!-- HERO -->
  <div class="hero mb-4">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <h3 class="fw-bold mb-1"><i class="bi bi-wrench-adjustable"></i> บริการซ่อมมาตรฐาน</h3>
        <p class="muted mb-2">
          กรอกข้อมูลอุปกรณ์และอาการเบื้องต้น ทีมช่างจะติดต่อยืนยันคิวและประเมินเบื้องต้นให้คุณ
          แนบรูปประกอบอาการได้ ช่วยให้วินิจฉัยได้เร็วขึ้น
        </p>
        <div class="d-flex flex-wrap gap-2">
          <span class="chip"><i class="bi bi-bell"></i> แจ้งเตือนสถานะอัตโนมัติ</span>
          <span class="chip"><i class="bi bi-shield-check"></i> งานซ่อมตามมาตรฐาน</span>
          <span class="chip"><i class="bi bi-truck"></i> นัดรับ–ส่งได้</span>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="note">
          <?php if($loggedIn): ?>
            <div class="fw-semibold">สวัสดี, @<?=h($username)?></div>
            <div class="small muted">คุณสามารถส่งคำขอซ่อมและเทิร์นสินค้าได้ทันที</div>
          <?php else: ?>
            <div class="fw-semibold">ยังไม่ได้เข้าสู่ระบบ</div>
            <div class="small muted">เข้าสู่ระบบเพื่อใช้งานฟอร์มด้านล่างให้เต็มรูปแบบ</div>
            <a class="btn btn-primary btn-sm mt-2" href="login.php?redirect=service.php"><i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== ส่งคำขอซ่อม ===== -->
  <div class="section mb-4">
    <div class="sec-head">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-tools"></i>
        <span class="fw-semibold">ส่งคำขอซ่อม</span>
      </div>
      <?php if($loggedIn): ?>
        <span class="pill small">เข้าสู่ระบบแล้ว</span>
      <?php endif; ?>
    </div>

    <div class="p-3 p-md-4">
      <form action="service_create.php" method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">ประเภทอุปกรณ์</label>
          <input type="text" name="device_type" class="form-control" placeholder="เช่น เครื่องตัดหญ้า / เลื่อย" required <?= $loggedIn?'':'disabled' ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">ยี่ห้อ</label>
          <input type="text" name="brand" class="form-control" placeholder="เช่น MAXMA" required <?= $loggedIn?'':'disabled' ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">รุ่น</label>
          <input type="text" name="model" class="form-control" placeholder="เช่น MXR-411" required <?= $loggedIn?'':'disabled' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">เบอร์โทร</label>
          <input type="tel" name="phone" class="form-control" placeholder="เช่น 08x-xxx-xxxx" required <?= $loggedIn?'':'disabled' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label">LINE ID (ถ้ามี)</label>
          <input type="text" name="line_id" class="form-control" placeholder="ไลน์ไอดี" <?= $loggedIn?'':'disabled' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">ต้องการนัดวันที่</label>
          <input type="date" name="desired_date" class="form-control" <?= $loggedIn?'':'disabled' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label">ความเร่งด่วน</label>
          <select name="urgency" class="form-select" <?= $loggedIn?'':'disabled' ?>>
            <option value="normal">ปกติ</option>
            <option value="urgent">ด่วน</option>
          </select>
        </div>
        <!-- เกรดวัสดุที่ต้องการ -->
<div class="col-12">
  <label class="form-label">เลือกเกรดวัสดุ</label>
  <div class="row g-2">
    <?php foreach($gradeChoices as $key=>$g): ?>
      <div class="col-md-4">
        <label class="border rounded-3 p-3 d-block h-100">
          <div class="form-check">
            <input class="form-check-input grade-opt" type="radio"
                   name="parts_grade" value="<?= $key ?>"
                   data-add="<?= (float)$g['add'] ?>"
                   <?= $key==='standard'?'checked':'' ?> <?= $loggedIn?'':'disabled' ?>>
            <span class="fw-semibold ms-2"><?= h($g['label']) ?></span>
          </div>
          <div class="small text-muted mt-1"><?= h($g['desc']) ?></div>
          <div class="mt-2 fw-semibold">+<?= number_format((float)$g['add'],2) ?> ฿</div>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ประกันหลังซ่อม (เพิ่มได้) -->
<div class="col-12">
  <label class="form-label">ประกันหลังซ่อม (เพิ่มได้)</label>
  <div class="row g-2">
    <?php foreach($warrantyChoices as $m=>$w): ?>
      <div class="col-md-3">
        <label class="border rounded-3 p-3 d-block h-100">
          <div class="form-check">
            <input class="form-check-input warranty-opt" type="radio"
                   name="ext_warranty_months" value="<?= (int)$m ?>"
                   data-add="<?= (float)$w['add'] ?>"
                   <?= $m===0?'checked':'' ?> <?= $loggedIn?'':'disabled' ?>>
            <span class="ms-2"><?= h($w['label']) ?></span>
          </div>
          <div class="mt-2 fw-semibold">+<?= number_format((float)$w['add'],2) ?> ฿</div>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="form-text">ร้านมีประกันมาตรฐานฟรี 1 เดือนอยู่แล้ว เลือกเพิ่มได้ถ้าต้องการความอุ่นใจ</div>
</div>

<!-- สรุปค่าส่วนเพิ่มจากตัวเลือก -->
<div class="col-12">
  <div class="alert alert-secondary d-flex justify-content-between align-items-center">
    <div><i class="bi bi-calculator"></i> ค่าส่วนเพิ่มจากตัวเลือก</div>
    <div class="fw-bold" id="addonsTotal">+0.00 ฿</div>
  </div>
</div>

<!-- ค่าที่ต้องส่งไปบันทึก -->
<input type="hidden" name="parts_grade_surcharge" id="parts_grade_surcharge" value="0">
<input type="hidden" name="ext_warranty_price"    id="ext_warranty_price"    value="0">
<input type="hidden" name="estimate_total"         id="estimate_total"         value="0">

        <div class="col-12">
          <label class="form-label">อธิบายอาการ/ปัญหา</label>
          <textarea name="issue" rows="4" class="form-control" placeholder="อธิบายอาการ / มีเสียงดัง / สตาร์ตติดยาก ฯลฯ" required <?= $loggedIn?'':'disabled' ?>></textarea>
        </div>

        <div class="col-12">
          <label class="form-label">แนบรูปอุปกรณ์/ปัญหา (ไม่บังคับ)</label>
          <div id="drop-repair" class="drop <?= $loggedIn?'':'disabled' ?>" tabindex="0">
            <i class="bi bi-cloud-arrow-up big d-block mb-1"></i>
            ลากไฟล์มาวาง หรือคลิกเพื่อเลือกไฟล์
            <div class="form-hint">รองรับ JPG/PNG ~ ไม่เกิน ~5MB</div>
          </div>
          <input id="file-repair" type="file" name="image" accept="image/*" class="d-none" <?= $loggedIn?'':'disabled' ?>>
          <div id="preview-repair" class="preview"></div>
        </div>

        <div class="col-12 d-grid">
          <button class="btn btn-primary btn-lg" <?= $loggedIn?'':'disabled' ?>>
            <i class="bi bi-send"></i> ส่งคำขอซ่อม
          </button>
        </div>

        <?php if(!$loggedIn): ?>
          <div class="col-12">
            <div class="alert alert-info mb-0">
              ต้องเข้าสู่ระบบก่อนจึงจะส่งคำขอได้
              <a href="login.php?redirect=service.php" class="alert-link">ไปหน้าเข้าสู่ระบบ</a>
            </div>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ===== บริการเทิร์น (Trade-in) ===== -->
  <div class="section">
    <div class="sec-head">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-arrow-left-right"></i>
        <span class="fw-semibold">เทิร์นอุปกรณ์เก่าเป็นส่วนลด / ขายคืน</span>
      </div>
      <span class="pill small"><i class="bi bi-ticket-perforated me-1"></i> ประเมินราคาเบื้องต้นฟรี</span>
    </div>

    <div class="p-3 p-md-4">
      <div class="row g-4">
        <div class="col-lg-7">
          <form action="tradein_create.php" method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">ประเภทอุปกรณ์</label>
              <input type="text" name="device_type" class="form-control" placeholder="เช่น เครื่องตัดหญ้า / เลื่อย" required <?= $loggedIn?'':'disabled' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label">ยี่ห้อ</label>
              <input type="text" name="brand" class="form-control" placeholder="เช่น MAXMA" required <?= $loggedIn?'':'disabled' ?>>
            </div>
            <div class="col-md-3">
              <label class="form-label">รุ่น</label>
              <input type="text" name="model" class="form-control" placeholder="รุ่น" required <?= $loggedIn?'':'disabled' ?>>
            </div>

            <div class="col-md-6">
              <label class="form-label">สภาพอุปกรณ์</label>
              <select name="device_condition" class="form-select" <?= $loggedIn?'':'disabled' ?>>
                <option value="working">ใช้งานปกติ</option>
                <option value="minor_issue">มีปัญหาเล็กน้อย</option>
                <option value="broken">เสีย/ต้องซ่อม</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ความต้องการ</label>
              <select name="need" id="needSelect" class="form-select" <?= $loggedIn?'':'disabled' ?>>
                <option value="buy_new">เทิร์นเป็นส่วนลดซื้อใหม่</option>
                <option value="cash">ขายรับเงินสด</option>
              </select>
            </div>

            <!-- แสดงเมื่อเลือก เทิร์นเป็นส่วนลดซื้อใหม่ -->
            <div id="buyNewWrap" class="row g-3 d-none">
              <div class="col-md-6">
                <label class="form-label">ราคาเสนอ (บาท)</label>
                <input type="number" step="0.01" min="0" name="offer_price" id="offerPrice" class="form-control" placeholder="เช่น 1500" <?= $loggedIn?'':'disabled' ?>>
              </div>
              <div class="col-md-6">
                <label class="form-label">รหัสสินค้าที่เลือก</label>
                <input type="number" step="1" min="1" name="selected_product_id" id="selectedProductId" class="form-control" placeholder="เช่น PRD123" <?= $loggedIn?'':'disabled' ?>>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">แนบรูปอุปกรณ์/ปัญหา (ได้หลายรูป)</label>
              <div id="tiDrop" class="ti-dropzone text-center p-4 rounded-3 border border-2 border-dashed">
                <i class="bi bi-cloud-arrow-up fs-1 d-block mb-2"></i>
                <div class="fw-semibold">ลากไฟล์มาวาง หรือคลิกเพื่อเลือก</div>
                <div class="text-muted small">รองรับ JPG/PNG/WebP/GIF (≤ 5MB ต่อไฟล์)</div>
              </div>
              <input id="tiImages" type="file" name="images[]" class="d-none" accept="image/*" multiple>
              <div id="tiPreview" class="row g-2 mt-2"></div>
            </div>

            <div class="col-12 d-grid">
              <button class="btn btn-outline-primary btn-lg" <?= $loggedIn?'':'disabled' ?>>
                <i class="bi bi-send"></i> ส่งคำขอเทิร์น
              </button>
            </div>

            <?php if(!$loggedIn): ?>
              <div class="col-12">
                <div class="alert alert-info mb-0">
                  เข้าสู่ระบบเพื่อส่งคำขอเทิร์น
                </div>
              </div>
            <?php endif; ?>
          </form>
        </div>

        <div class="col-lg-5">
          <div class="note mb-3">
            <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle"></i> เคล็ดลับได้ราคาดี</h6>
            <ul class="mb-0 muted">
              <li>ถ่ายรูปให้เห็นสภาพชัดเจนหลายมุม</li>
              <li>แจ้งอาการ/ร่องรอย/ของแถมให้ครบ</li>
              <li>นำใบเสร็จ/กล่อง/อุปกรณ์เสริม (ถ้ามี)</li>
            </ul>
          </div>
          <div class="note">
            <h6 class="fw-semibold mb-2"><i class="bi bi-shield-check"></i> เงื่อนไขเบื้องต้น</h6>
            <div class="muted small">
              การประเมินออนไลน์เป็นเพียงราคาคร่าว ๆ ราคาจริงขึ้นอยู่กับการตรวจสภาพที่หน้าร้าน
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>

(function(){
  const $ = (s,all=false)=> all?document.querySelectorAll(s):document.querySelector(s);
  const fmt = n => (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' ฿';
  function recalc(){
    const gSel = $('.grade-opt:checked');
    const wSel = $('.warranty-opt:checked');
    const gAdd = gSel ? parseFloat(gSel.dataset.add||'0') : 0;
    const wAdd = wSel ? parseFloat(wSel.dataset.add||'0') : 0;
    const total = gAdd + wAdd;
    $('#addonsTotal').textContent = '+'+fmt(total);
    $('#parts_grade_surcharge').value = gAdd.toFixed(2);
    $('#ext_warranty_price').value  = wAdd.toFixed(2);
    $('#estimate_total').value      = total.toFixed(2);
  }
  document.addEventListener('change', (e)=>{
    if(e.target.classList.contains('grade-opt') || e.target.classList.contains('warranty-opt')) recalc();
  });
  recalc();
})();

/* ---------- Dropzone (งานซ่อม) : แก้ id ให้ตรง + สร้าง <img> พรีวิวให้อัตโนมัติ ---------- */
function wireSingleDrop(dropId, inputId, previewId){
  const dz = document.getElementById(dropId);
  const inp = document.getElementById(inputId);
  const pv  = document.getElementById(previewId);

  // ถ้ายังไม่ได้ล็อกอินหรือไม่มี element ก็ไม่ต้องทำอะไร
  if(!dz || !inp || dz.classList.contains('disabled') || inp.disabled) return;

  let imgEl = null;
  const showPreview = (file)=>{
    if(!file || !file.type?.startsWith('image/')) return;
    const url = URL.createObjectURL(file);
    if(!imgEl){
      imgEl = document.createElement('img');
      imgEl.className = 'img-fluid rounded border';
      pv.innerHTML = '';
      pv.appendChild(imgEl);
    }
    imgEl.src = url;
  };

  dz.addEventListener('click', ()=> inp.click());
  dz.addEventListener('keydown', (e)=>{ if(e.key==='Enter' || e.key===' ') { e.preventDefault(); inp.click(); } });

  ['dragenter','dragover'].forEach(ev=>{
    dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.add('drag'); });
  });
  ['dragleave','drop'].forEach(ev=>{
    dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.remove('drag'); });
  });
  dz.addEventListener('drop', e=>{
    const f = e.dataTransfer?.files?.[0];
    if(f){ showPreview(f); inp.files = e.dataTransfer.files; }
  });
  inp.addEventListener('change', e=>{
    const f = e.target.files?.[0];
    showPreview(f);
  });
}
wireSingleDrop('drop-repair','file-repair','preview-repair');

/* ---------- Dropzone หลายรูป (เทิร์น) ---------- */
(function(){
  const dz   = document.getElementById('tiDrop');
  const inp  = document.getElementById('tiImages');
  const prev = document.getElementById('tiPreview');

  const openPicker = ()=> inp.click();
  dz.addEventListener('click', openPicker);

  ['dragenter','dragover'].forEach(ev=>{
    dz.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.add('drag'); });
  });
  ['dragleave','drop'].forEach(ev=>{
    dz.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.remove('drag'); });
  });
  dz.addEventListener('drop', e=>{
    const files = e.dataTransfer.files;
    if (files && files.length) { inp.files = files; render(); }
  });
  inp.addEventListener('change', render);

  function render(){
    prev.innerHTML = '';
    const files = Array.from(inp.files || []);
    files.slice(0, 12).forEach(f=>{
      const col = document.createElement('div'); col.className='col-4 col-md-3';
      const img = document.createElement('img'); img.src = URL.createObjectURL(f);
      const box = document.createElement('div'); box.className='ti-thumb'; box.appendChild(img);
      col.appendChild(box); prev.appendChild(col);
    });
  }
})();

/* ---------- แสดงฟิลด์ "ราคาเสนอ" + "รหัสสินค้าที่เลือก" เฉพาะตอนเลือก buy_new ---------- */
(function(){
  const logged = <?= $loggedIn ? 'true' : 'false' ?>;
  const needSel = document.getElementById('needSelect');
  const wrap = document.getElementById('buyNewWrap');
  const offer = document.getElementById('offerPrice');
  const selProd = document.getElementById('selectedProductId');

  function sync(){
    const isBN = needSel?.value === 'buy_new';
    wrap?.classList.toggle('d-none', !isBN);
    if(offer && selProd){
      offer.disabled = !logged || !isBN;
      selProd.disabled = !logged || !isBN;
      if(!isBN){ offer.value=''; selProd.value=''; }
    }
  }
  needSel?.addEventListener('change', sync);
  document.addEventListener('DOMContentLoaded', sync);
})();
</script>
</body>
</html>
