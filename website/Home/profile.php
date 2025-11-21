<?php
// Home/profile.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=profile.php");
  exit;
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function show_or_dash($v){
  $v = trim((string)($v ?? ''));
  return $v !== '' ? h($v) : '<span class="text-muted">-</span>';
}

$user_id = (int)$_SESSION['user_id'];

/* ดึงข้อมูลเป็น $profile (กันชนกับ header.php) */
$st = $conn->prepare("
  SELECT
    u.id, u.username, u.email, u.role, u.phone, u.profile_pic, u.created_at, u.updated_at,
    u.full_name, u.avatar,
    u.address_line1, u.address_line2, u.district, u.province, u.postcode
  FROM users u
  WHERE u.id = ?
  LIMIT 1
");
$st->bind_param('i', $user_id);
$st->execute();
$profile = $st->get_result()->fetch_assoc() ?: [];
$st->close();

/* helper อ่านค่าจาก $profile */
$pf = function(string $key) use (&$profile){
  return array_key_exists($key, $profile) ? $profile[$key] : null;
};

/* หา avatar แบบ fallback (คงโฟลเดอร์ย่อยไว้ครบ) */
$avatar = (function() use ($pf){
  // 1) จาก avatar (อาจเก็บ 'avatars/xxx.png' หรือ 'uploads/avatars/xxx.png')
  $a = $pf('avatar');
  if (!empty($a)) {
    $rel = (strpos($a, 'uploads/') === 0) ? $a : ('uploads/'.ltrim($a, '/'));
    if (is_file(__DIR__ . '/'.$rel)) return $rel;
  }
  // 2) จาก profile_pic (เก็บเป็นชื่อไฟล์ใน uploads/)
  $p = $pf('profile_pic');
  if (!empty($p)) {
    $rel = 'uploads/'.ltrim($p, '/');
    if (is_file(__DIR__ . '/'.$rel)) return $rel;
  }
  // 3) default
  $def = 'uploads/Default_pfp.svg.png';
  return is_file(__DIR__.'/'.$def) ? $def : 'https://via.placeholder.com/160?text=Profile';
})();

function role_th($r){ return $r==='admin' ? 'ผู้ดูแลระบบ' : 'ผู้ใช้'; }
function role_badge_class($r){ return $r==='admin' ? 'danger' : 'secondary'; }

$created     = $pf('created_at') ? date('d/m/Y H:i', strtotime($pf('created_at'))) : '-';
$updated     = $pf('updated_at') ? date('d/m/Y H:i', strtotime($pf('updated_at'))) : '-';
$displayName = ($pf('full_name') ?: $pf('username') ?: 'user');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>โปรไฟล์ของฉัน | WEB APP</title>
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
  
    .page-head{
      border-radius:18px;
      padding:16px 18px 14px;
      background:linear-gradient(135deg,var(--pri)0%,var(--pri2)55%,#0ea5e9 100%);
      color:#fff;
      box-shadow:0 14px 36px rgba(37,99,235,.18);
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
    }
    .avatar-xl{
      width:96px;
      height:96px;
      border-radius:50%;
      object-fit:cover;
      border:3px solid rgba(255,255,255,.95);
      box-shadow:0 6px 24px rgba(15,23,42,.35);
      background:#e5e7eb;
    }
    .head-main{
      display:flex;
      align-items:center;
      gap:14px;
      flex:1 1 auto;
      min-width:0;
    }
    .head-text .name{
      font-size:1.25rem;
      font-weight:700;
      letter-spacing:.01em;
    }
    .head-text .email{
      font-size:.9rem;
      opacity:.95;
    }

    /* iOS glass chips */
    .chip-row{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:10px;
    }
    .chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:.45rem;

      background:rgba(255,255,255,.22);
      border:1px solid rgba(255,255,255,.45);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);

      padding:0 16px;
      height:38px;
      border-radius:999px;

      font-weight:600;
      font-size:.9rem;
      color:#ffffff;
      white-space:nowrap;
    }
    .chip i{
      font-size:1rem;
      opacity:.95;
    }

    .page-head .btn-edit{
      border-radius:999px;
      font-weight:600;
      padding:.55rem 1.1rem;
      box-shadow:0 10px 26px rgba(15,23,42,.25);
    }

    .card-rounded{
      border-radius:16px;
      border:1px solid var(--line);
      background:var(--card);
      box-shadow:0 14px 34px rgba(15,23,42,.05);
    }
    .card-rounded .card-header{
      border-bottom:1px solid #edf1fb;
      background:linear-gradient(180deg,#ffffff,#f8f9ff);
      font-weight:600;
    }

    .list-tidy .row{
      padding:.35rem 0;
      border-bottom:1px dashed #eef2f6;
      font-size:.92rem;
    }
    .list-tidy .row:last-child{ border-bottom:0; }
    .label-muted{ color:var(--muted); }

    .btn-soft{
      background:#f0f5ff;
      border:1px solid #cfe0ff;
      color:#1d4ed8;
    }
    .btn-soft:hover{
      background:#e1ecff;
      border-color:#b0c9ff;
    }

    .quick-actions .btn{
      border-radius:999px;
      font-size:.9rem;
    }

    @media (max-width: 767.98px){
      .page-head{
        padding:14px 14px 12px;
      }
      .avatar-xl{
        width:80px;
        height:80px;
      }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="container py-4">

  <!-- head: avatar + chips -->
  <div class="page-head mb-3">
    <div class="head-main">
      <img src="<?= h($avatar) ?>" alt="avatar" class="avatar-xl">
      <div class="head-text">
        <div class="name"><?= show_or_dash($displayName) ?></div>
        <div class="email"><?= show_or_dash($pf('email')) ?></div>
        <div class="chip-row mt-2">
          <span class="chip">
            <i class="bi bi-person-badge"></i>
            <?= h(role_th($pf('role') ?? 'user')) ?>
          </span>
          <span class="chip">
            <i class="bi bi-at"></i>
            <?= show_or_dash($pf('username')) ?>
          </span>
          <span class="chip">
            <i class="bi bi-calendar-check"></i>
            สมัครเมื่อ <?= h($created) ?>
          </span>
        </div>
      </div>
    </div>

    <div class="text-end">
      <a href="profile_edit.php" class="btn btn-light btn-edit">
        <i class="bi bi-pencil-square me-1"></i> แก้ไขโปรไฟล์
      </a>
    </div>
  </div>

  <div class="row g-3">
    <!-- ซ้าย: ข้อมูลหลัก -->
    <div class="col-lg-7">
      <div class="card card-rounded">
        <div class="card-header">ข้อมูลบัญชี</div>
        <div class="card-body list-tidy">
          <div class="row">
            <div class="col-4 label-muted">ชื่อ-นามสกุล</div>
            <div class="col-8"><?= show_or_dash($pf('full_name')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">อีเมล</div>
            <div class="col-8"><?= show_or_dash($pf('email')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">เบอร์โทร</div>
            <div class="col-8"><?= show_or_dash($pf('phone')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">ชื่อผู้ใช้</div>
            <div class="col-8"><?= show_or_dash($pf('username')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">สถานะสิทธิ์</div>
            <div class="col-8"><?= h(role_th($pf('role') ?? 'user')) ?></div>
          </div>
        </div>
      </div>

      <div class="card card-rounded mt-3">
        <div class="card-header">ที่อยู่สำหรับจัดส่ง</div>
        <div class="card-body list-tidy">
          <div class="row">
            <div class="col-4 label-muted">ที่อยู่ (บรรทัดที่ 1)</div>
            <div class="col-8"><?= show_or_dash($pf('address_line1')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">ที่อยู่ (บรรทัดที่ 2)</div>
            <div class="col-8"><?= show_or_dash($pf('address_line2')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">เขต/อำเภอ</div>
            <div class="col-8"><?= show_or_dash($pf('district')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">จังหวัด</div>
            <div class="col-8"><?= show_or_dash($pf('province')) ?></div>
          </div>
          <div class="row">
            <div class="col-4 label-muted">รหัสไปรษณีย์</div>
            <div class="col-8"><?= show_or_dash($pf('postcode')) ?></div>
          </div>

          <div class="mt-3 d-flex flex-wrap gap-2">
            <a href="profile_edit.php#address" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-geo-alt me-1"></i> จัดการที่อยู่
            </a>
            <a href="my_orders.php" class="btn btn-soft btn-sm">
              <i class="bi bi-bag-check me-1"></i> ประวัติคำสั่งซื้อ
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- ขวา: สรุป + Quick actions -->
    <div class="col-lg-5">
      <div class="card card-rounded">
        <div class="card-header">สรุปบัญชี</div>
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="label-muted">สมัครสมาชิกเมื่อ</span>
            <span class="fw-semibold"><?= h($created) ?></span>
          </div>
          <div class="d-flex align-items-center justify-content-between">
            <span class="label-muted">อัปเดตล่าสุด</span>
            <span class="fw-semibold"><?= h($updated) ?></span>
          </div>
          <hr>
          <div class="small text-muted">
            ตรวจสอบข้อมูลให้เป็นปัจจุบัน เพื่อให้การจัดส่งและการติดต่อทำได้สะดวกที่สุด
          </div>
        </div>
      </div>

      <div class="card card-rounded mt-3">
        <div class="card-header">การตั้งค่าเร็ว</div>
        <div class="card-body quick-actions d-grid gap-2">
          <a class="btn btn-outline-primary" href="profile_edit.php#avatar">
            <i class="bi bi-image me-1"></i> เปลี่ยนรูปโปรไฟล์
          </a>
          <a class="btn btn-outline-primary" href="profile_edit.php#info">
            <i class="bi bi-person-lines-fill me-1"></i> แก้ไขข้อมูลส่วนตัว
          </a>
          <a class="btn btn-outline-danger" href="logout.php">
            <i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/assets/html/footer.html'; ?>
</body>
</html>
