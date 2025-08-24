<?php
/* File: Home/admin/product_edit.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.php?redirect=admin/product_edit.php"); exit;
}
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/image_helpers.php'; // << สำคัญสำหรับหลายรูป

/* รับ id */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "รหัสสินค้าไม่ถูกต้อง"; exit; }

/* โหลดสินค้าเดิม */
$stmt = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$product) { echo "ไม่พบสินค้า"; exit; }

/* โหลดหมวดหมู่ */
$cats = [];
if ($rs = $conn->query("SELECT id,name FROM categories ORDER BY name ASC")) {
  $cats = $rs->fetch_all(MYSQLI_ASSOC);
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

/* ฟังก์ชั่นภาพพรีวิวเดี่ยว (fallback) */
function admin_product_image_src(?string $filename): string {
  $filename = $filename ?: '';
  $p = __DIR__ . '/../assets/img/' . $filename;
  if ($filename && is_file($p)) return '../assets/img/' . $filename;
  if (is_file(__DIR__ . '/../assets/img/no-image.png')) return '../assets/img/no-image.png';
  return 'https://via.placeholder.com/300x300?text=No+Image';
}

/* โหลดแกลเลอรี่ภาพของสินค้านี้ */
function load_gallery(mysqli $conn, int $pid): array {
  $st = $conn->prepare("SELECT id, filename, is_cover FROM product_images WHERE product_id=? ORDER BY is_cover DESC, id ASC");
  $st->bind_param('i', $pid);
  $st->execute();
  $imgs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return $imgs ?: [];
}

/* แอคชันย่อย: ตั้งเป็นปก / ลบรูป (เรียกมาด้วย POST จากปุ่มบนการ์ด) */
$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['img_action'])) {
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $flash = 'CSRF token ไม่ถูกต้อง';
  } else {
    $imgId = (int)($_POST['image_id'] ?? 0);
    if ($_POST['img_action'] === 'set_cover' && $imgId>0) {
      if (set_cover_image($conn, $id, $imgId)) $flash = 'ตั้งรูปปกเรียบร้อย';
    }
    if ($_POST['img_action'] === 'delete' && ($imgId>0 || isset($_POST['delete_all']))) {
      // ลบรูปเดียว หรือถ้า tick delete_all มาจากปุ่ม “ลบทั้งหมด”
      delete_product_images($conn, $id, isset($_POST['delete_all']) ? null : $imgId, true);
      $flash = isset($_POST['delete_all']) ? 'ลบรูปทั้งหมดแล้ว' : 'ลบรูปแล้ว';
    }
  }
}

/* บันทึกฟอร์มหลัก (ข้อมูล + อัปโหลดหลายรูปเพิ่ม) */
$errors=[]; $success=false;
if ($_SERVER['REQUEST_METHOD']==='POST' && empty($_POST['img_action'])) {
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $name           = trim($_POST['name'] ?? '');
  $description    = trim($_POST['description'] ?? '');
  $category_id    = strlen($_POST['category_id'] ?? '') ? (int)$_POST['category_id'] : null;
  $price          = isset($_POST['price']) ? (float)$_POST['price'] : 0;
  $discount_price = strlen($_POST['discount_price'] ?? '') ? (float)$_POST['discount_price'] : null;
  $stock          = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;
  $status         = (($_POST['status'] ?? 'active')==='inactive') ? 'inactive' : 'active';

  if ($name==='')                 $errors[]='กรุณากรอกชื่อสินค้า';
  if ($price<=0)                  $errors[]='ราคาต้องมากกว่า 0';
  if ($stock<0)                   $errors[]='สต็อกต้องไม่ติดลบ';
  if (!is_null($discount_price) && $discount_price >= $price)
                                  $errors[]='ราคาลดต้องน้อยกว่าราคาปกติ';

  if (!$errors) {
    $seller_id = null;
    $sql="UPDATE products
          SET category_id=?, seller_id=?, name=?, description=?, price=?, discount_price=?, stock=?, status=?
          WHERE id=?";
    $st=$conn->prepare($sql);
    $st->bind_param('iissddisi', $category_id,$seller_id,$name,$description,$price,$discount_price,$stock,$status,$id);
    $ok=$st->execute(); $st->close();
    if ($ok) { $success=true; } else { $errors[]='บันทึกข้อมูลไม่สำเร็จ: '.$conn->error; }

    // อัปโหลด “หลายรูป” เพิ่มเข้าแกลเลอรี่
    if ($success && !empty($_FILES['images']) && !empty($_FILES['images']['name'])) {
      $uploadDir = __DIR__.'/../assets/img';
      $added = save_product_images($_FILES['images'], $uploadDir, $conn, $id, 10);
      if ($added) { $flash = 'บันทึกข้อมูลและเพิ่มรูปใหม่แล้ว'; }
    }

    if ($success) {
      // sync ค่าโชว์ในฟอร์ม
      $product['category_id']=$category_id; $product['name']=$name;
      $product['description']=$description; $product['price']=$price;
      $product['discount_price']=$discount_price; $product['stock']=$stock;
      $product['status']=$status;
      $_SESSION['csrf_token']=bin2hex(random_bytes(32)); // refresh token
      $csrf_token=$_SESSION['csrf_token'];
    }
  }
}

/* โหลดแกลเลอรี่อีกครั้งหลังทุกแอคชัน */
$gallery = load_gallery($conn, $id);
$preview = admin_product_image_src($product['image'] ?? '');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แก้ไขสินค้า (หลายรูป) | Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .page-head{background:linear-gradient(135deg,#f6f8ff 0%,#ffffff 70%);border:1px solid #eef2f6;border-radius:14px;padding:14px 16px}
    .card-rounded{border-radius:16px}
    .img-preview{max-height:180px;border:1px solid #e9ecef;border-radius:12px;padding:6px;background:#fff}
    .thumb{position:relative;border:1px solid #e9ecef;border-radius:12px;overflow:hidden}
    .thumb img{width:100%;height:180px;object-fit:cover;display:block}
    .badge-cover{position:absolute;top:.5rem;left:.5rem}
    .thumb-actions{position:absolute;right:.5rem;bottom:.5rem;display:flex;gap:.35rem}
  </style>
</head>
<body class="bg-light">
<div class="container py-4">

  <!-- Header -->
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
      <div class="fw-bold text-primary mb-0">แก้ไขสินค้า #<?= (int)$product['id'] ?></div>
      <div class="small text-muted">ปรับข้อมูลและจัดการรูปหลายไฟล์</div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-info"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i>บันทึกเรียบร้อย</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1">ไม่สามารถบันทึกได้:</div>
      <ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- ฟอร์มข้อมูล + อัปโหลดหลายรูป -->
    <div class="col-lg-8">
      <div class="card shadow-sm card-rounded">
        <div class="card-header bg-white fw-semibold">รายละเอียดสินค้า</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?= h($product['name']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">หมวดหมู่</label>
                <select name="category_id" class="form-select">
                  <option value="">- ไม่ระบุ -</option>
                  <?php foreach($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$product['category_id']===(int)$c['id'])?'selected':'' ?>>
                      <?= h($c['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">รายละเอียด</label>
                <textarea name="description" class="form-control" rows="4"><?= h($product['description'] ?? '') ?></textarea>
              </div>

              <div class="col-md-4">
                <label class="form-label">ราคา (บาท) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="price" id="price" class="form-control" required
                       value="<?= h($product['price']) ?>">
                <div class="invalid-feedback">กรุณากรอกราคาให้ถูกต้อง (> 0)</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">ราคาลด (บาท)</label>
                <input type="number" step="0.01" min="0" name="discount_price" id="discount_price" class="form-control"
                       value="<?= h($product['discount_price']) ?>">
                <div id="discountFeedback" class="invalid-feedback"></div>
                <div id="discountHint" class="form-text"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">สต็อก</label>
                <input type="number" step="1" min="0" name="stock" class="form-control" value="<?= h($product['stock']) ?>">
              </div>

              <div class="col-12">
                <label class="form-label">เพิ่มรูปสินค้า (เลือกได้หลายรูป)</label>
                <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
                <div class="form-text">รองรับ: jpg, jpeg, png, gif, webp — จะถูกเพิ่มเข้าแกลเลอรี่</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">สถานะ</label>
                <select name="status" class="form-select">
                  <option value="active"   <?= ($product['status']==='active')?'selected':'' ?>>active</option>
                  <option value="inactive" <?= ($product['status']==='inactive')?'selected':'' ?>>inactive</option>
                </select>
              </div>
            </div>

            <div class="mt-4 d-flex gap-2">
              <button id="btnSubmit" type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> บันทึกการแก้ไข
              </button>
              <a href="products.php" class="btn btn-outline-secondary">ยกเลิก</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- แกลเลอรี่ภาพ -->
    <div class="col-lg-4">
      <div class="card shadow-sm card-rounded h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>รูปภาพสินค้า</span>
          <?php if ($gallery): ?>
          <form method="post" class="m-0" onsubmit="return confirm('ลบรูปทั้งหมดของสินค้านี้?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="img_action" value="delete">
            <input type="hidden" name="delete_all" value="1">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> ลบทั้งหมด</button>
          </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$gallery): ?>
            <div class="text-muted small">ยังไม่มีรูปในแกลเลอรี่</div>
            <div class="mt-2"><img src="<?= h($preview) ?>" class="img-preview w-100" alt="preview"></div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach($gallery as $g): ?>
                <div class="col-12">
                  <div class="thumb">
                    <img src="<?= h('../assets/img/'.$g['filename']) ?>" alt="">
                    <?php if ((int)$g['is_cover']===1): ?>
                      <span class="badge text-bg-success badge-cover"><i class="bi bi-star-fill me-1"></i>Cover</span>
                    <?php endif; ?>
                    <div class="thumb-actions">
                      <?php if ((int)$g['is_cover']!==1): ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                          <input type="hidden" name="img_action" value="set_cover">
                          <input type="hidden" name="image_id" value="<?= (int)$g['id'] ?>">
                          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-star"></i></button>
                        </form>
                      <?php endif; ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('ลบรูปนี้?')">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="img_action" value="delete">
                        <input type="hidden" name="image_id" value="<?= (int)$g['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- realtime validate ราคาลด -->
<script>
(()=> {
  const priceEl   = document.getElementById('price');
  const discEl    = document.getElementById('discount_price');
  const submitBtn = document.getElementById('btnSubmit');
  const fbDisc    = document.getElementById('discountFeedback');
  const hintDisc  = document.getElementById('discountHint');

  function toNum(v){ if(v===''||v==null) return null; const n=parseFloat(v); return isNaN(n)?null:n; }
  function format2(el){ const n=toNum(el.value); if(n!==null) el.value=n.toFixed(2); }

  function validate(){
    const p = toNum(priceEl.value);
    const d = toNum(discEl.value);
    [priceEl, discEl].forEach(el=>el.classList.remove('is-valid','is-invalid'));
    fbDisc.textContent=''; hintDisc.textContent='';
    let ok=true;
    if (p===null || p<=0){ priceEl.classList.add('is-invalid'); ok=false; }
    else { priceEl.classList.add('is-valid'); }
    if (discEl.value.trim()!==''){
      if (d===null || d<0){ discEl.classList.add('is-invalid'); fbDisc.textContent='ราคาลดต้องเป็นตัวเลข และไม่ติดลบ'; ok=false; }
      else if (p!==null && d>=p){ discEl.classList.add('is-invalid'); fbDisc.textContent='ราคาลดต้องน้อยกว่าราคาปกติ'; ok=false; }
      else { discEl.classList.add('is-valid'); if(p && d!==null){ const pct=((p-d)/p)*100; hintDisc.textContent=`ส่วนลดประมาณ ${pct.toFixed(1)}%`; } }
    }
    if (submitBtn) submitBtn.disabled = !ok;
  }
  ['input','change'].forEach(evt=>{ priceEl.addEventListener(evt, validate); discEl.addEventListener(evt, validate); });
  priceEl.addEventListener('blur', ()=>{ format2(priceEl); validate(); });
  discEl.addEventListener('blur',  ()=>{ format2(discEl);  validate(); });
  document.addEventListener('DOMContentLoaded', validate);
})();
</script>
</body>
</html>
