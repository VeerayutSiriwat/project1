<?php
// Home/profile_edit.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=profile_edit.php"); exit;
}

$user_id = (int)$_SESSION['user_id'];
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// โหลดข้อมูลผู้ใช้
$st = $conn->prepare("
  SELECT id, username, email, role,
         COALESCE(full_name,'') full_name,
         COALESCE(phone,'') phone,
         COALESCE(avatar,'') avatar,
         COALESCE(address_line1,'') address_line1,
         COALESCE(address_line2,'') address_line2,
         COALESCE(district,'') district,
         COALESCE(province,'') province,
         COALESCE(postcode,'') postcode,
         created_at, updated_at
  FROM users WHERE id=? LIMIT 1
");
$st->bind_param("i", $user_id);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$st->close();

if (!$me) { http_response_code(404); exit('ไม่พบบัญชีผู้ใช้'); }

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

// Path/ภาพเริ่มต้น
$defaultAvatar = 'uploads/Default_pfp.svg.png';
$avatarFile = trim($me['avatar']) !== '' ? $me['avatar'] : $defaultAvatar;
$avatarWeb  = is_file(__DIR__ . '/' . $avatarFile) ? $avatarFile : $defaultAvatar;

// ผลลัพธ์
$errors = [];
$success = false;

// อัปเดต
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $errors[] = 'โทเค็นหมดอายุ กรุณารีเฟรชหน้า';
  }

  $full_name    = trim($_POST['full_name'] ?? '');
  $phone        = trim($_POST['phone'] ?? '');
  $address1     = trim($_POST['address_line1'] ?? '');
  $address2     = trim($_POST['address_line2'] ?? '');
  $district     = trim($_POST['district'] ?? '');
  $province     = trim($_POST['province'] ?? '');
  $postcode     = trim($_POST['postcode'] ?? '');

  // ตรวจพื้นฐานคร่าวๆ
  if ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
    $errors[] = 'กรุณากรอกเบอร์โทรให้ถูกต้อง';
  }
  if ($postcode !== '' && !preg_match('/^[0-9A-Za-z\-]{3,12}$/', $postcode)) {
    $errors[] = 'รหัสไปรษณีย์ไม่ถูกต้อง';
  }

  // อัปโหลดรูป (ถ้ามี)
  $newAvatarName = null;
  if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
      $maxSize = 3 * 1024 * 1024; // 3MB
      if ($_FILES['avatar']['size'] <= 0 || $_FILES['avatar']['size'] > $maxSize) {
        $errors[] = 'ไฟล์รูปใหญ่เกินกำหนด (≤ 3MB)';
      } else {
        $allowedExt = ['jpg','jpeg','png','webp','gif','svg','svgz'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
          $errors[] = 'อนุญาตเฉพาะ: jpg, jpeg, png, webp, gif, svg';
        } else {
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime  = $finfo->file($_FILES['avatar']['tmp_name']);
          $okMimes = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml','text/xml','application/xml'];
          if (!in_array($mime, $okMimes, true)) {
            $errors[] = 'ชนิดไฟล์รูปไม่ถูกต้อง';
          } else {
            $dir = __DIR__ . '/uploads/avatars';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $newAvatarName = 'uploads/avatars/u'.$user_id.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
            $dest = __DIR__ . '/'.$newAvatarName;
            if (!@move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
              $errors[] = 'อัปโหลดรูปไม่สำเร็จ (สิทธิ์โฟลเดอร์?)';
              $newAvatarName = null;
            }
          }
        }
      }
    } else {
      $errors[] = 'อัปโหลดรูปผิดพลาด (code: '.(int)$_FILES['avatar']['error'].')';
    }
  }

  if (!$errors) {
    // สร้าง SQL ตามว่ามีรูปใหม่ไหม
    if ($newAvatarName) {
      $sql = "UPDATE users
              SET full_name=?, phone=?, address_line1=?, address_line2=?, district=?, province=?, postcode=?, avatar=?, updated_at=NOW()
              WHERE id=?";
      $st = $conn->prepare($sql);
      $st->bind_param("ssssssssi", $full_name,$phone,$address1,$address2,$district,$province,$postcode,$newAvatarName,$user_id);
    } else {
      $sql = "UPDATE users
              SET full_name=?, phone=?, address_line1=?, address_line2=?, district=?, province=?, postcode=?, updated_at=NOW()
              WHERE id=?";
      $st = $conn->prepare($sql);
      $st->bind_param("sssssssi", $full_name,$phone,$address1,$address2,$district,$province,$postcode,$user_id);
    }
    $ok = $st->execute();
    $st->close();

    if ($ok) {
      // ลบรูปเก่า (ถ้าไม่ใช่ default และมีรูปใหม่)
      if ($newAvatarName && trim($me['avatar']) !== '' && $me['avatar'] !== $defaultAvatar) {
        $old = __DIR__ . '/' . $me['avatar'];
        if (is_file($old)) { @chmod($old, 0666); @unlink($old); }
      }

      // PRG → กลับมาหน้าเดิมพร้อม flag แสดงเอฟเฟกต์
      header("Location: profile_edit.php?saved=1");
      exit;
    } else {
      $errors[] = 'บันทึกไม่สำเร็จ: '.$conn->error;
    }
  }

  // refresh csrf + ค่าในฟอร์มเมื่อมี error
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  $csrf = $_SESSION['csrf_token'];
  $me['full_name']     = $full_name;
  $me['phone']         = $phone;
  $me['address_line1'] = $address1;
  $me['address_line2'] = $address2;
  $me['district']      = $district;
  $me['province']      = $province;
  $me['postcode']      = $postcode;
  if ($newAvatarName) { $avatarWeb = $newAvatarName; }
}

$saved = isset($_GET['saved']); // สำหรับโชว์เอฟเฟกต์
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>แก้ไขโปรไฟล์ | WEB APP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --line:#e3e8f5;
      --card:#ffffff;
      --ink:#0b1a37;
      --muted:#6b7280;
      --pri:#2563eb;
      --pri2:#4f46e5;
    }


    .card-rounded{
      border-radius:16px;
      border:1px solid var(--line);
      background:var(--card);
      box-shadow:0 14px 34px rgba(15,23,42,.05);
    }

    .page-head{
      background:linear-gradient(135deg,var(--pri) 0%,var(--pri2) 55%,#0ea5e9 100%);
      border-radius:18px;
      padding:14px 16px;
      color:#fff;
      box-shadow:0 14px 34px rgba(37,99,235,.22);
    }
    .page-head-title{
      font-weight:700;
      letter-spacing:.01em;
    }
    .page-head-sub{
      font-size:.85rem;
      opacity:.9;
    }

    .chip-row{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:8px;
    }
    .chip{
      display:inline-flex;
      align-items:center;
      gap:.4rem;

      background:rgba(255,255,255,.20);
      border:1px solid rgba(255,255,255,.45);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);

      padding:0 14px;
      height:34px;
      border-radius:999px;

      font-weight:600;
      font-size:.85rem;
      color:#ffffff;
      white-space:nowrap;
    }
    .chip i{font-size:1rem;opacity:.95;}

    .btn-back{
      border-radius:999px;
      border:1px solid rgba(255,255,255,.6);
      background:rgba(255,255,255,.12);
      color:#ffffff;
      font-weight:600;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.35rem .9rem;
    }
    .btn-back:hover{
      background:rgba(255,255,255,.22);
      color:#ffffff;
    }

    .avatar-wrap{
      width:112px;
      height:112px;
      border-radius:50%;
      overflow:hidden;
      border:3px solid #ffffff;
      background:#e5e7eb;
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 10px 30px rgba(15,23,42,.25);
    }
    .avatar-wrap img{
      width:100%;
      height:100%;
      object-fit:cover;
    }
    .hint{color:#6b7280;font-size:.85rem;}

    .card-rounded .card-header{
      background:linear-gradient(180deg,#ffffff,#f8f9ff);
      border-bottom:1px solid #edf1fb;
      font-weight:600;
    }

    .success-overlay{
      position:fixed;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      background:rgba(15,23,42,.15);
      z-index:1055;
      animation:fadeOut .9s ease 1.2s forwards;
      pointer-events:none;
    }
    .success-circle{
      width:120px;height:120px;border-radius:50%;
      background:#e6f9ee;border:3px solid #b7f0d3;
      display:flex;align-items:center;justify-content:center;
      box-shadow:0 12px 40px rgba(16,185,129,.25);
      animation:pop .35s ease-out both;
    }
    .success-circle i{
      font-size:56px;color:#16a34a;
      animation:tick .45s ease .1s both;
    }
    @keyframes pop{from{transform:scale(.6)} to{transform:scale(1)}}
    @keyframes tick{from{transform:scale(.2);opacity:.2} to{transform:scale(1);opacity:1}}
    @keyframes fadeOut{to{opacity:0;visibility:hidden}}

    .confetti{
      position:fixed;
      left:0; top:0;
      width:100%; height:100%;
      pointer-events:none;
      z-index:1054;
    }

    .quick-actions button,
    .quick-actions a{
      border-radius:999px;
    }

    @media (max-width: 767.98px){
      .page-head{
        padding:12px 12px;
      }
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">

  <div class="page-head mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn-back" href="profile.php">
          <i class="bi bi-arrow-left"></i><span>กลับไปหน้าโปรไฟล์</span>
        </a>
        <div>
          <div class="page-head-title">แก้ไขโปรไฟล์</div>
          <div class="page-head-sub">อัปเดตข้อมูลส่วนตัว ที่อยู่จัดส่ง และรูปโปรไฟล์ของคุณ</div>
        </div>
      </div>
      <div class="chip-row">
        <span class="chip">
          <i class="bi bi-person"></i><?= h($me['username']) ?>
        </span>
        <span class="chip">
          <i class="bi bi-at"></i><?= h($me['email']) ?>
        </span>
      </div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <b>ไม่สามารถบันทึกได้</b>
      <ul class="mb-0">
        <?php foreach($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

    <!-- ซ้าย: ข้อมูลหลัก -->
    <div class="col-lg-8">
      <div class="card card-rounded shadow-sm mb-3" id="info">
        <div class="card-header bg-white fw-semibold">ข้อมูลบัญชี</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">ชื่อ-นามสกุล</label>
              <input type="text" name="full_name" class="form-control" value="<?= h($me['full_name']) ?>" placeholder="เช่น สมชาย ใจดี">
            </div>
            <div class="col-md-4">
              <label class="form-label">เบอร์โทร</label>
              <input type="text" name="phone" class="form-control" value="<?= h($me['phone']) ?>" placeholder="เช่น 089xxxxxxx">
            </div>

            <div class="col-md-6">
              <label class="form-label">อีเมล (อ่านอย่างเดียว)</label>
              <input type="email" class="form-control" value="<?= h($me['email']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">ชื่อผู้ใช้</label>
              <input type="text" class="form-control" value="<?= h($me['username']) ?>" disabled>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-rounded shadow-sm" id="address">
        <div class="card-header bg-white fw-semibold">ที่อยู่สำหรับจัดส่ง</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 1)</label>
              <input type="text" name="address_line1" class="form-control" value="<?= h($me['address_line1']) ?>" placeholder="บ้านเลขที่, หมู่, ซอย, ถนน">
            </div>
            <div class="col-12">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 2)</label>
              <input type="text" name="address_line2" class="form-control" value="<?= h($me['address_line2']) ?>" placeholder="ตึก/หมู่บ้าน/ชั้น/ห้อง ฯลฯ (ถ้ามี)">
            </div>
            <div class="col-md-4">
              <label class="form-label">เขต/อำเภอ</label>
              <input type="text" name="district" class="form-control" value="<?= h($me['district']) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">จังหวัด</label>
              <input type="text" name="province" class="form-control" value="<?= h($me['province']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">รหัสไปรษณีย์</label>
              <input type="text" name="postcode" class="form-control" value="<?= h($me['postcode']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ขวา: รูปโปรไฟล์ -->
    <div class="col-lg-4">
      <div class="card card-rounded shadow-sm" id="avatar">
        <div class="card-header bg-white fw-semibold">รูปโปรไฟล์</div>
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar-wrap">
              <img id="avatarPreview" src="<?= h($avatarWeb) ?>" alt="avatar">
            </div>
            <div class="hint">
              รองรับ: jpg, png, webp, gif, svg (≤ 3MB)<br>
              เลือกรูปใหม่เพื่อเปลี่ยนโปรไฟล์ของคุณ
            </div>
          </div>
          <input type="file" name="avatar" id="avatarInput" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
        </div>
      </div>

      <div class="d-grid mt-3 gap-2 quick-actions">
        <button id="saveBtn" class="btn btn-primary">
          <i class="bi bi-save"></i> บันทึกการแก้ไข
        </button>
        <a class="btn btn-outline-secondary" href="profile.php">
          <i class="bi bi-x-circle"></i> ยกเลิก
        </a>
      </div>
    </div>
  </form>
</div>

<!-- Toast success -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
  <div id="saveToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        <i class="bi bi-check-circle-fill me-2"></i>บันทึกการแก้ไขเรียบร้อยแล้ว
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Containers for effects -->
<canvas id="confetti" class="confetti" width="0" height="0" style="display:none"></canvas>
<div id="successOverlay" class="success-overlay" style="display:none">
  <div class="success-circle"><i class="bi bi-check-lg"></i></div>
</div>

<script>
// พรีวิวรูป
document.getElementById('avatarInput')?.addEventListener('change', (e)=>{
  const f = e.target.files?.[0]; if(!f) return;
  const reader = new FileReader();
  reader.onload = ev => { document.getElementById('avatarPreview').src = ev.target.result; };
  reader.readAsDataURL(f);
});

// เอฟเฟกต์สำเร็จหลัง redirect ?saved=1
(function(){
  const saved = <?= $saved ? 'true' : 'false' ?>;
  if(!saved) return;

  // Toast
  const toastEl = document.getElementById('saveToast');
  const t = new bootstrap.Toast(toastEl, {delay: 2200});
  t.show();

  // Success overlay + confetti
  const overlay = document.getElementById('successOverlay');
  overlay.style.display = 'flex';

  // Confetti (vanilla)
  const cv = document.getElementById('confetti');
  const ctx = cv.getContext('2d');
  function resize(){ cv.width = innerWidth; cv.height = innerHeight; }
  resize(); cv.style.display = 'block'; window.addEventListener('resize', resize);

  const pieces = [];
  for(let i=0;i<120;i++){
    pieces.push({
      x: Math.random()*cv.width,
      y: -20 - Math.random()*cv.height*0.25,
      w: 6 + Math.random()*6,
      h: 10 + Math.random()*12,
      a: Math.random()*Math.PI,
      s: 2 + Math.random()*3,
      col: `hsl(${Math.random()*360},90%,60%)`
    });
  }
  let t0 = performance.now();
  function anim(t1){
    const dt = (t1 - t0)/16; t0 = t1;
    ctx.clearRect(0,0,cv.width,cv.height);
    pieces.forEach(p=>{
      p.y += p.s*dt; p.a += 0.1*dt; p.x += Math.sin(p.a)*0.6*dt;
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.a);
      ctx.fillStyle = p.col; ctx.fillRect(-p.w/2,-p.h/2,p.w,p.h); ctx.restore();
    });
    if (pieces[0].y < cv.height + 40) requestAnimationFrame(anim);
    else { cv.style.display='none'; }
  }
  requestAnimationFrame(anim);

  // ปรับปุ่มให้บอกสถานะ “บันทึกแล้ว”
  const btn = document.getElementById('saveBtn');
  if(btn){
    const old = btn.innerHTML;
    btn.classList.remove('btn-primary'); btn.classList.add('btn-success');
    btn.innerHTML = '<i class="bi bi-check2-circle"></i> บันทึกแล้ว';
    setTimeout(()=>{
      btn.classList.remove('btn-success'); btn.classList.add('btn-primary');
      btn.innerHTML = old;
    }, 2200);
  }

  // ล้างพารามิเตอร์ saved จาก URL เพื่อกัน Toast โผล่รอบหน้าเมื่อรีเฟรช
  const url = new URL(location.href);
  url.searchParams.delete('saved');
  setTimeout(()=>{ history.replaceState({}, '', url.toString()); }, 2300);
})();
</script>
</body>
</html>
