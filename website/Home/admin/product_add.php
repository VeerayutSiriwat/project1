<?php 
/* File: Home/admin/product_add.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.php?redirect=admin/product_add.php"); exit;
}
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/image_helpers.php'; // helper อัปโหลดหลายรูป

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// โหลดหมวดหมู่
$cats = [];
if ($rs = $conn->query("SELECT id,name FROM categories ORDER BY name ASC")) {
  $cats = $rs->fetch_all(MYSQLI_ASSOC);
}

$errors = [];
$success = false;
$new_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $name           = trim($_POST['name'] ?? '');
  $description    = trim($_POST['description'] ?? '');
  $category_id    = strlen($_POST['category_id'] ?? '') ? (int)$_POST['category_id'] : null;
  $price          = isset($_POST['price']) ? (float)$_POST['price'] : 0;
  $discount_price = strlen($_POST['discount_price'] ?? '') ? (float)$_POST['discount_price'] : null;
  $stock          = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;
  $status         = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

  if ($name === '')                 $errors[] = 'กรุณากรอกชื่อสินค้า';
  if ($price <= 0)                  $errors[] = 'ราคาต้องมากกว่า 0';
  if ($stock < 0)                   $errors[] = 'สต็อกต้องไม่ติดลบ';
  if ($discount_price !== null && $discount_price >= $price) {
    $errors[] = 'ราคาลดต้องน้อยกว่าราคาปกติ';
  }

  if (!$errors) {
    $sql = "INSERT INTO products (category_id, seller_id, name, description, price, discount_price, stock, image, status)
            VALUES (?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $seller_id  = null;
    $image_name = null; // ตั้งทีหลังเมื่อรู้รูปปก
    $stmt->bind_param(
      'iissddiss',
      $category_id,
      $seller_id,
      $name,
      $description,
      $price,
      $discount_price,
      $stock,
      $image_name,
      $status
    );

    if ($stmt->execute()) {
      $product_id = $stmt->insert_id;
      $new_id = $product_id;

      // อัปโหลดหลายรูป (ใช้ helper เดิม)
      $uploadDir = __DIR__.'/../assets/img';
      $saved = save_product_images($_FILES['images'] ?? null, $uploadDir, $conn, $product_id, 20);

      // ตั้งภาพปกลง products.image ถ้ามีอัปโหลด
      if (!empty($saved)) {
        $cover = $saved[0]; // helper ตั้ง is_cover ให้รูปแรกแล้ว
        $up = $conn->prepare("UPDATE products SET image=? WHERE id=?");
        $up->bind_param('si', $cover, $product_id);
        $up->execute();
      }

      $success = true;
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // refresh token กัน F5 ซ้ำ
      $_POST = [];
    } else {
      $errors[] = 'บันทึกข้อมูลไม่สำเร็จ: ' . $conn->error;
    }
  }
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มสินค้า (Admin)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#fff; --border:#e9eef5; --muted:#6b7280;
      --brand:#4f46e5; --brand-2:#0ea5e9;
    }
    body{background:var(--bg)}
    .topbar{background:linear-gradient(180deg,#ffffff,#fafcff); border-bottom:1px solid var(--border)}
    .card-elev{border:1px solid var(--border); border-radius:18px; box-shadow:0 18px 48px rgba(2,6,23,.06)}
    .page-head{background:linear-gradient(135deg,#f6f8ff 0%,#ffffff 70%); border:1px solid var(--border); border-radius:14px; padding:12px 16px}
    .hint{color:var(--muted)}
    /* Dropzone สไตล์หรู + พรีวิว/จัดเรียง/ลบ ก่อนส่งฟอร์ม */
    .dz{
      border:1.5px dashed var(--border); border-radius:14px; padding:18px; background:linear-gradient(180deg,#fbfdff,#fff);
      display:flex; gap:14px; align-items:center; transition:.15s ease; cursor:pointer; user-select:none;
    }
    .dz:hover{ border-color:#cfe1ff; background:linear-gradient(180deg,#f8fbff,#fff); }
    .dz.dragover{ border-color: var(--brand); box-shadow:0 0 0 4px rgba(79,70,229,.08) inset; }
    .dz .ico{
      width:56px; height:56px; border-radius:14px; display:grid; place-items:center;
      background: conic-gradient(from 180deg at 50% 50%, rgba(79,70,229,.18), rgba(14,165,233,.18), rgba(79,70,229,.18));
      color:#1e3a8a; font-size:28px;
    }
    .dz .lead{font-weight:700}
    .dz small{color:var(--muted)}

    .grid{display:grid; grid-template-columns:repeat(4,1fr); gap:10px}
    @media (max-width: 992px){ .grid{grid-template-columns:repeat(3,1fr)} }
    @media (max-width: 576px){ .grid{grid-template-columns:repeat(2,1fr)} }

    .tile{position:relative; border:1px solid var(--border); border-radius:12px; overflow:hidden; background:#fff}
    .tile img{width:100%; aspect-ratio:1; object-fit:cover; display:block}
    .tile .mask{position:absolute; inset:auto 6px 6px auto; display:flex; gap:6px}
    .tile .btn-ico{border:1px solid var(--border); background:#fff; width:34px; height:34px; display:grid; place-items:center; border-radius:8px}
    .tile .btn-ico:hover{background:#f8fafc}
    .tile.dragging{opacity:.5}
    .slot{border:2px dashed #cfe1ff; border-radius:12px}
    .cap{position:absolute; left:6px; top:6px; background:linear-gradient(180deg,var(--brand),var(--brand-2)); color:#fff; border-radius:999px; padding:.15rem .5rem; font-size:.75rem; font-weight:700}

    .form-section-title{font-weight:700}
  </style>
</head>
<body>

<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">Add Product</span>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <div class="page-head d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-light border" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> ย้อนกลับ
      </button>
      <a href="products.php" class="btn btn-outline-secondary">
        <i class="bi bi-card-list"></i> รายการสินค้า
      </a>
    </div>
    <div class="text-end">
      <div class="fw-bold text-primary mb-0">เพิ่มสินค้าใหม่</div>
      <div class="small hint">ใส่ข้อมูล และลากรูปหลายไฟล์เพื่ออัปโหลด</div>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success">
      เพิ่มสินค้าเรียบร้อยแล้ว 🎉
      <?php if ($new_id): ?>
        <a class="alert-link" href="product_edit.php?id=<?= (int)$new_id ?>">ไปแก้ไข/จัดการรูปต่อ</a>
      <?php else: ?>
        <a class="alert-link" href="products.php">กลับไปหน้ารายการ</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1">ไม่สามารถบันทึกได้:</div>
      <ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card card-elev">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <div class="row g-4">
          <div class="col-lg-7">
            <div class="form-section-title mb-2">ข้อมูลสินค้า</div>
            <div class="mb-3">
              <label class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">รายละเอียด</label>
              <textarea name="description" class="form-control" rows="6"><?= h($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">ราคา (บาท) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="price" id="price"
                       class="form-control" required value="<?= h($_POST['price'] ?? '') ?>">
                <div class="invalid-feedback">กรุณากรอกราคามากกว่า 0</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">ราคาลด (บาท)</label>
                <input type="number" step="0.01" min="0" name="discount_price" id="discount_price"
                       class="form-control" value="<?= h($_POST['discount_price'] ?? '') ?>">
                <div id="discountFeedback" class="invalid-feedback"></div>
                <div id="discountHint" class="form-text"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">สต็อก</label>
                <input type="number" step="1" min="0" name="stock" class="form-control" value="<?= h($_POST['stock'] ?? 0) ?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">หมวดหมู่</label>
                <select name="category_id" class="form-select">
                  <option value="">- ไม่ระบุ -</option>
                  <?php foreach($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['category_id'] ?? -1)===(int)$c['id'])?'selected':'' ?>>
                      <?= h($c['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">สถานะ</label>
                <select name="status" class="form-select">
                  <option value="active"   <?= (($_POST['status'] ?? 'active')==='active')?'selected':'' ?>>active</option>
                  <option value="inactive" <?= (($_POST['status'] ?? '')==='inactive')?'selected':'' ?>>inactive</option>
                </select>
              </div>
            </div>
          </div>

          <div class="col-lg-5">
            <div class="form-section-title mb-2">รูปสินค้า (หลายรูป)</div>

            <!-- Dropzone (ลากวาง/คลิก/วางจาก Clipboard) -->
            <div id="dz" class="dz mb-2" tabindex="0" role="button" aria-label="อัปโหลดรูป">
              <div class="ico"><i class="bi bi-cloud-arrow-up"></i></div>
              <div>
                <div class="lead">ลากไฟล์รูปมาวางที่นี่ หรือ <u>คลิกเพื่อเลือก</u></div>
                <small>รองรับหลายไฟล์พร้อมกัน (สูงสุดครั้งละ 20 ไฟล์, ≤ 12MB/ไฟล์) • ชนิด: jpg, jpeg, png, gif, webp</small>
              </div>
              <input id="images" type="file" name="images[]" class="d-none" accept="image/*" multiple>
            </div>

            <!-- พรีวิว/จัดเรียง/ลบ ก่อนส่งฟอร์ม -->
            <div id="grid" class="grid"></div>
            <div class="hint small mt-1">* รูปแรกจะถูกตั้งเป็นรูปปกอัตโนมัติ</div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button id="btnSubmit" type="submit" class="btn btn-primary">บันทึกสินค้า</button>
          <a href="products.php" class="btn btn-outline-secondary">ยกเลิก</a>
        </div>
      </form>
    </div>
  </div>

  <div class="text-muted small mt-3">
    * รูปทั้งหมดถูกเก็บใน <code>Home/assets/img/</code> และบันทึกไว้ในตาราง <code>product_images</code><br>
    * ระบบจะตั้งรูปแรกเป็น “รูปปก” และ sync ไปยัง <code>products.image</code> อัตโนมัติ
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ===== Realtime ตรวจราคาลด ===== */
(()=> {
  const priceEl   = document.getElementById('price');
  const discEl    = document.getElementById('discount_price');
  const btn       = document.getElementById('btnSubmit');
  const fbDisc    = document.getElementById('discountFeedback');
  const hintDisc  = document.getElementById('discountHint');
  const toNum = v => (v===''||v==null ? null : (isNaN(parseFloat(v)) ? null : parseFloat(v)));

  function validate(){
    const p = toNum(priceEl.value);
    const d = toNum(discEl.value);
    [priceEl, discEl].forEach(el=>el.classList.remove('is-valid','is-invalid'));
    fbDisc.textContent=''; hintDisc.textContent=''; let ok = true;

    if (p===null || p<=0){ priceEl.classList.add('is-invalid'); ok=false; }
    else priceEl.classList.add('is-valid');

    if (discEl.value.trim()!==''){
      if (d===null || d<0){ discEl.classList.add('is-invalid'); fbDisc.textContent='ราคาลดต้องเป็นตัวเลข และต้องไม่ติดลบ'; ok=false; }
      else if (p!==null && d>=p){ discEl.classList.add('is-invalid'); fbDisc.textContent='ราคาลดต้องน้อยกว่าราคาปกติ'; ok=false; }
      else { discEl.classList.add('is-valid'); if(p){ hintDisc.textContent=`ส่วนลดประมาณ ${((p-d)/p*100).toFixed(1)}%`; } }
    }
    if(btn) btn.disabled = !ok;
  }
  ['input','change','blur'].forEach(ev=>{
    priceEl.addEventListener(ev, validate);
    discEl.addEventListener(ev, validate);
  });
  document.addEventListener('DOMContentLoaded', validate);
})();

/* ===== Drag & Drop / Preview / Remove / Reorder (ก่อนส่งฟอร์ม) ===== */
(function(){
  const dz     = document.getElementById('dz');
  const input  = document.getElementById('images'); // input จริงที่ส่งไป PHP
  const grid   = document.getElementById('grid');

  const MAX_FILES = 20;
  const MAX_SIZE  = 12 * 1024 * 1024; // 12MB
  const ALLOW     = ['image/jpeg','image/png','image/gif','image/webp'];

  // ใช้ DataTransfer เพื่อจัดชุดไฟล์ใน input.files
  let dt = new DataTransfer();

  function render(){
    grid.innerHTML = '';
    const files = Array.from(dt.files);
    files.forEach((f,idx)=>{
      const tile = document.createElement('div');
      tile.className = 'tile';
      tile.draggable = true;
      tile.dataset.idx = idx;

      const img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      img.onload = ()=> URL.revokeObjectURL(img.src);
      tile.appendChild(img);

      if (idx===0){
        const cap = document.createElement('div');
        cap.className = 'cap';
        cap.textContent = 'COVER';
        tile.appendChild(cap);
      }

      const mask = document.createElement('div');
      mask.className = 'mask';
      // ปุ่มลบ
      const del = document.createElement('button');
      del.type='button'; del.className='btn-ico'; del.title='ลบรูปนี้';
      del.innerHTML = '<i class="bi bi-trash"></i>';
      del.addEventListener('click', ()=> removeAt(idx));
      mask.appendChild(del);
      grid.appendChild(tile);
      tile.appendChild(mask);

      // Drag sort
      tile.addEventListener('dragstart', (e)=>{
        tile.classList.add('dragging');
        e.dataTransfer.setData('text/plain', String(idx));
      });
      tile.addEventListener('dragend', ()=> tile.classList.remove('dragging'));
      tile.addEventListener('dragover', (e)=>{ e.preventDefault(); tile.classList.add('slot'); });
      tile.addEventListener('dragleave', ()=> tile.classList.remove('slot'));
      tile.addEventListener('drop', (e)=>{
        e.preventDefault();
        tile.classList.remove('slot');
        const from = parseInt(e.dataTransfer.getData('text/plain'),10);
        const to   = idx;
        if (from===to) return;
        reorder(from,to);
      });
    });

    // sync กลับไปยัง input.files
    input.files = dt.files;
  }

  function addFiles(files){
    const cur = Array.from(dt.files);
    for (const f of files){
      if (!ALLOW.includes(f.type) || f.size<=0 || f.size>MAX_SIZE) continue;
      if (cur.length >= MAX_FILES) break;
      cur.push(f);
    }
    dt = new DataTransfer();
    cur.slice(0, MAX_FILES).forEach(f=> dt.items.add(f));
    render();
  }

  function removeAt(i){
    const cur = Array.from(dt.files);
    cur.splice(i,1);
    dt = new DataTransfer();
    cur.forEach(f=> dt.items.add(f));
    render();
  }

  function reorder(from, to){
    const cur = Array.from(dt.files);
    const [m] = cur.splice(from,1);
    cur.splice(to,0,m);
    dt = new DataTransfer();
    cur.forEach(f=> dt.items.add(f));
    render();
  }

  // Click เพื่อเปิด file picker
  dz.addEventListener('click', ()=> input.click());
  dz.addEventListener('keydown', (e)=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });

  // Drag target
  ['dragenter','dragover'].forEach(ev=>{
    dz.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dz.classList.add('dragover'); });
  });
  ['dragleave','drop'].forEach(ev=>{
    dz.addEventListener(ev, (e)=>{ e.preventDefault(); e.stopPropagation(); dz.classList.remove('dragover'); });
  });
  dz.addEventListener('drop', (e)=>{
    const files = Array.from(e.dataTransfer.files || []);
    const valid = files.filter(f=> ALLOW.includes(f.type) && f.size>0 && f.size<=MAX_SIZE);
    if (valid.length===0){ alert('ชนิดไฟล์ไม่ถูกต้อง หรือไฟล์ใหญ่เกิน 12MB'); return; }
    addFiles(valid);
  });

  // รองรับ paste รูปจาก clipboard
  document.addEventListener('paste', (e)=>{
    if (!dz.matches(':hover')) return; // วางได้เมื่อโฟกัส/โฮเวอร์พื้นที่อัปโหลด
    const items = Array.from(e.clipboardData?.items || []);
    const imgs  = items.map(i=> i.getAsFile()).filter(Boolean);
    if (imgs.length) addFiles(imgs);
  });

  // จาก file picker
  input.addEventListener('change', (e)=>{
    const files = Array.from(input.files || []);
    addFiles(files);
    input.value = ''; // reset picker
  });

  render(); // ครั้งแรก (ว่าง)
})();
</script>
</body>
</html>
