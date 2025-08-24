<?php
/* File: Home/admin/product_add.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.php?redirect=admin/product_add.php"); exit;
}
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/image_helpers.php'; // << สำคัญ: helper อัปโหลดหลายรูป

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
    // ใส่ image เป็น NULL ไปก่อน เดี๋ยวตั้งค่าจากรูปปกหลังอัปโหลด
    $sql = "INSERT INTO products (category_id, seller_id, name, description, price, discount_price, stock, image, status)
            VALUES (?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $seller_id  = null;
    $image_name = null; // จะอัปเดตทีหลัง
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

      // อัปโหลดหลายรูป (ข้ามเองถ้าไม่เลือกไฟล์)
      $uploadDir = __DIR__.'/../assets/img';
      $saved = save_product_images($_FILES['images'] ?? null, $uploadDir, $conn, $product_id, 10);
      // $saved เป็น array รายชื่อไฟล์ที่บันทึกแล้ว ตาม helper ที่ให้ไว้

      // ตั้งรูปปกลง products.image ถ้าอัปโหลดได้อย่างน้อย 1 ไฟล์
      if (!empty($saved)) {
        $cover = $saved[0]; // ใน helper ผมตั้งรูปแรกเป็นปกอยู่แล้ว
        $up = $conn->prepare("UPDATE products SET image=? WHERE id=?");
        $up->bind_param('si', $cover, $product_id);
        $up->execute();
      }

      $success = true;
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // refresh กัน F5 ซ้ำ
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
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .toolbar {display:flex;gap:.5rem;align-items:center}
    .card-elev {border:1px solid #e9eef5;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    .dropzone {border:1px dashed #b6c2d1;border-radius:12px;padding:1rem;text-align:center;background:#f8fafc;cursor:pointer}
    .img-thumb{width:100%;height:110px;object-fit:cover;border-radius:10px;border:1px solid #e9eef5}
    .grid-preview{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}
  </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold text-primary" href="dashboard.php"><i class="bi bi-speedometer2"></i> Admin</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <div class="toolbar mb-3">
    <button type="button" class="btn btn-light border" onclick="history.back()">
      <i class="bi bi-arrow-left"></i> ย้อนกลับ
    </button>
    <h1 class="h4 mb-0 ms-1">เพิ่มสินค้า</h1>
    <div class="ms-auto">
      <a href="products.php" class="btn btn-outline-secondary"><i class="bi bi-list-ul"></i> รายการสินค้า</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success">
      เพิ่มสินค้าเรียบร้อยแล้ว 🎉
      <a href="products.php" class="alert-link">กลับไปหน้ารายการ</a>
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
            <label class="form-label">อัปโหลดรูปสินค้า (เลือกได้หลายรูป)</label>
            <div class="dropzone" onclick="document.getElementById('images').click()">
              <i class="bi bi-cloud-arrow-up fs-3 d-block mb-2"></i>
              ลากไฟล์มาวาง หรือคลิกเพื่อเลือกไฟล์
              <div class="small mt-1">รองรับ: jpg, jpeg, png, gif, webp</div>
            </div>
            <!-- อัปได้หลายรูป -->
            <input id="images" type="file" name="images[]" class="d-none" accept="image/*" multiple>
            <div id="previewGrid" class="grid-preview mt-3"></div>
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
// พรีวิวหลายรูป
const inputMulti = document.getElementById('images');
const grid       = document.getElementById('previewGrid');
inputMulti?.addEventListener('change', (e)=>{
  grid.innerHTML = '';
  const files = Array.from(e.target.files || []);
  files.slice(0, 12).forEach(f=>{
    const url = URL.createObjectURL(f);
    const img = document.createElement('img');
    img.src = url;
    img.className = 'img-thumb';
    grid.appendChild(img);
  });
});

// Realtime ตรวจราคาลด
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
</script>
</body>
</html>
