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
function show_or_dash($v){ $v = trim((string)($v ?? '')); return $v !== '' ? h($v) : '<span class="text-muted">-</span>'; }

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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb}
    .page-head{background:linear-gradient(135deg,#f7faff 0%,#ffffff 60%);border:1px solid #e9eef3;border-radius:16px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .avatar-xl{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid #fff;box-shadow:0 6px 24px rgba(2,6,23,.08)}
    .card-rounded{border-radius:16px;border:1px solid #e9eef3}
    .list-tidy .row{padding:.35rem 0;border-bottom:1px dashed #eef2f6}
    .list-tidy .row:last-child{border-bottom:0}
    .label-muted{color:#6b7280}
    .btn-soft{background:#f0f5ff;border:1px solid #cfe0ff}
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="container py-4">
  <div class="page-head mb-3">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= h($avatar) ?>" alt="avatar" class="avatar-xl">
      <div>
        <div class="fs-5 fw-bold"><?= show_or_dash($displayName) ?></div>
        <div class="small text-muted"><?= show_or_dash($pf('email')) ?></div>
        <span class="badge bg-<?= role_badge_class($pf('role') ?? '') ?> mt-1"><?= h(role_th($pf('role') ?? 'user')) ?></span>
      </div>
    </div>
    <div class="text-end">
      <a href="profile_edit.php" class="btn btn-primary"><i class="bi bi-pencil-square"></i> แก้ไขโปรไฟล์</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card card-rounded shadow-sm">
        <div class="card-header bg-white fw-semibold">ข้อมูลบัญชี</div>
        <div class="card-body list-tidy">
          <div class="row"><div class="col-4 label-muted">ชื่อ-นามสกุล</div><div class="col-8"><?= show_or_dash($pf('full_name')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">อีเมล</div><div class="col-8"><?= show_or_dash($pf('email')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">เบอร์โทร</div><div class="col-8"><?= show_or_dash($pf('phone')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">ชื่อผู้ใช้</div><div class="col-8"><?= show_or_dash($pf('username')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">สถานะสิทธิ์</div><div class="col-8"><?= h(role_th($pf('role') ?? 'user')) ?></div></div>
        </div>
      </div>

      <div class="card card-rounded shadow-sm mt-3">
        <div class="card-header bg-white fw-semibold">ที่อยู่สำหรับจัดส่ง</div>
        <div class="card-body list-tidy">
          <div class="row"><div class="col-4 label-muted">ที่อยู่ (บรรทัดที่ 1)</div><div class="col-8"><?= show_or_dash($pf('address_line1')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">ที่อยู่ (บรรทัดที่ 2)</div><div class="col-8"><?= show_or_dash($pf('address_line2')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">เขต/อำเภอ</div><div class="col-8"><?= show_or_dash($pf('district')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">จังหวัด</div><div class="col-8"><?= show_or_dash($pf('province')) ?></div></div>
          <div class="row"><div class="col-4 label-muted">รหัสไปรษณีย์</div><div class="col-8"><?= show_or_dash($pf('postcode')) ?></div></div>

          <div class="mt-3 d-flex gap-2">
            <a href="profile_edit.php#address" class="btn btn-outline-secondary btn-sm">จัดการที่อยู่</a>
            <a href="my_orders.php" class="btn btn-soft btn-sm">ประวัติคำสั่งซื้อ</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card card-rounded shadow-sm">
        <div class="card-header bg-white fw-semibold">สรุปบัญชี</div>
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <div class="label-muted">สมัครสมาชิกเมื่อ</div><div class="fw-semibold"><?= h($created) ?></div>
          </div>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <div class="label-muted">อัปเดตล่าสุด</div><div class="fw-semibold"><?= h($updated) ?></div>
          </div>
          <hr>
          <div class="small text-muted"></div>
        </div>
      </div>

      <div class="card card-rounded shadow-sm mt-3">
        <div class="card-header bg-white fw-semibold">การตั้งค่าเร็ว</div>
        <div class="card-body d-grid gap-2">
          <a class="btn btn-outline-primary" href="profile_edit.php#avatar">เปลี่ยนรูปโปรไฟล์</a>
          <a class="btn btn-outline-primary" href="profile_edit.php#info">แก้ไขข้อมูลส่วนตัว</a>
          <a class="btn btn-outline-danger" href="logout.php">ออกจากระบบ</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/assets/html/footer.html'; ?>
</body>
</html>
