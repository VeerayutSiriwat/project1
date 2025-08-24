<?php
// Home/includes/header.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/db.php';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$isLoggedIn = isset($_SESSION['user_id']);
$cart_count = 0;
$avatar_web = 'uploads/Default_pfp.svg.png';
$navUser = ['username' => 'user'];

if ($isLoggedIn) {
  $uid = (int)$_SESSION['user_id'];

  $st = $conn->prepare("SELECT username, avatar, profile_pic FROM users WHERE id=? LIMIT 1");
  $st->bind_param("i", $uid);
  $st->execute();
  $navUser = $st->get_result()->fetch_assoc() ?: $navUser;
  $st->close();

  // สร้าง path รูปโปรไฟล์ (กันแคช)
  $avatar_web = (function($u){
    $cands = [];
    if (!empty($u['avatar'])) {
      $a = $u['avatar'];
      if (strpos($a, 'uploads/') !== 0) { $a = 'uploads/' . ltrim($a, '/'); }
      $cands[] = $a;
    }
    if (!empty($u['profile_pic'])) {
      $p = 'uploads/' . ltrim($u['profile_pic'], '/');
      $cands[] = $p;
    }
    $cands[] = 'uploads/Default_pfp.svg.png';
    foreach ($cands as $rel) {
      if (is_file(__DIR__ . '/../' . $rel)) return $rel . '?v=' . time();
    }
    return 'uploads/Default_pfp.svg.png';
  })($navUser);

  if ($res = $conn->query("SHOW TABLES LIKE 'cart_items'")) {
    if ($res->num_rows > 0) {
      $st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM cart_items WHERE user_id=?");
      $st->bind_param("i", $uid);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $cart_count = (int)($row['total'] ?? 0);
      $st->close();
    }
    $res->free();
  }

  // นับแจ้งเตือนที่ยังไม่อ่าน (แสดงค่าเริ่มต้นตอนโหลด)
  $noti_unread = 0;
  if ($res = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
    $res->bind_param('i', $uid);
    $res->execute();
    $noti_unread = (int)($res->get_result()->fetch_assoc()['c'] ?? 0);
    $res->close();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Header</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .notif-item.unread { background:#f7fbff; }
    .notif-item .time   { font-size:.8rem; color:#6b7280; }
    .notif-empty { color:#6b7280; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top" data-bs-theme="light">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <span class="fw-bold text-primary">WEB APP</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="index.php">หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link" href="products.php">สินค้า</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="serviceDropdown" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">บริการซ่อม</a>
          <ul class="dropdown-menu" aria-labelledby="serviceDropdown">
            <li><a class="dropdown-item" href="#">ลงทะเบียนส่งซ่อม</a></li>
            <li><a class="dropdown-item" href="#">เทิร์นสินค้าเก่า</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="#">สถานะงานซ่อม</a></li>
            <li><a class="dropdown-item" href="#">สถานะงานเทิร์น</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="about.php">เกี่ยวกับเรา</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.php">ติดต่อ</a></li>
      </ul>

      <ul class="navbar-nav ms-auto align-items-center">
        <?php if ($isLoggedIn): ?>

          <!-- ปุ่มแจ้งเตือน -->
          <li class="nav-item dropdown me-2">
            <a class="nav-link position-relative" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-bell fs-5"></i>
              <span id="notif-badge"
                    class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= ($noti_unread>0?'':'d-none') ?>">
                <?= (int)$noti_unread ?>
              </span>
            </a>
            <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width:340px">
              <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <div class="fw-semibold">การแจ้งเตือน</div>
                <button class="btn btn-sm btn-link" id="notif-mark-read">อ่านทั้งหมด</button>
              </div>
              <div id="notif-list" style="max-height:360px; overflow:auto">
                <div class="p-3 text-center notif-empty">กำลังโหลด...</div>
              </div>
              <div class="text-center small text-muted py-2 border-top">อัปเดตอัตโนมัติ</div>
            </div>
          </li>

          <!-- โปรไฟล์ -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center" id="userDropdown" role="button"
               data-bs-toggle="dropdown" aria-expanded="false">
              <img src="<?= h($avatar_web) ?>" class="rounded-circle" width="36" height="36" style="object-fit:cover;">
              <span class="ms-2"><?= h($navUser['username'] ?? 'user') ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
              <li><a class="dropdown-item" href="profile.php">โปรไฟล์</a></li>
              <li><a class="dropdown-item" href="my_orders.php">ประวัติการสั่งซื้อ</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php">ออกจากระบบ</a></li>
            </ul>
          </li>

          <!-- ตะกร้า -->
          <li class="nav-item ms-3 position-relative">
            <a href="cart_view.php" class="nav-link">
              <i class="bi bi-cart3 fs-4"></i>
              <?php if ($cart_count > 0): ?>
                <span id="cart-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                  <?= (int)$cart_count ?>
                </span>
              <?php endif; ?>
            </a>
          </li>

        <?php else: ?>
          <li class="nav-item ms-3">
            <a class="btn btn-outline-primary me-2" href="login.php">เข้าสู่ระบบ</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($isLoggedIn): ?>
<script>
const badge   = document.getElementById('notif-badge');
const listEl  = document.getElementById('notif-list');
const markBtn = document.getElementById('notif-mark-read');

function fmtTime(iso){
  try { const d = new Date(iso.replace(' ','T')); return d.toLocaleString(); } catch(e){ return iso; }
}
function renderItems(items){
  if (!items || items.length === 0){
    listEl.innerHTML = `<div class="p-3 text-center notif-empty">ยังไม่มีการแจ้งเตือน</div>`;
    return;
  }
  listEl.innerHTML = items.map(it => `
    <a class="dropdown-item d-block py-2 px-3 notif-item ${it.is_read==0?'unread':''}" href="${linkFor(it)}">
      <div class="fw-semibold">${escapeHtml(it.title || '')}</div>
      ${it.message ? `<div class="small">${escapeHtml(it.message)}</div>` : ''}
      <div class="time">${fmtTime(it.created_at)}</div>
    </a>
  `).join('');
}
function escapeHtml(s){ const d = document.createElement('div'); d.innerText = s || ''; return d.innerHTML; }
function linkFor(it){
  if (it.type === 'order_status' && it.ref_id) return `my_orders.php?id=${it.ref_id}`;
  if (it.type === 'cancel_request' && it.ref_id) return `admin/orders.php?status=cancel_requested`;
  if (it.type === 'review_reply' && it.ref_id) return `product.php?id=${it.ref_id}#reviews`;
  if (it.type === 'support_msg') {return `contact.php${it.ref_id ? `?uid=${it.ref_id}#chat` : '#chat'}`;
  }

  return '#';
}


async function refreshCount(){
  const r = await fetch('notify_api.php?action=count');
  const j = await r.json();
  const c = j.count || 0;
  if (c>0){ badge.classList.remove('d-none'); badge.textContent = c; }
  else   { badge.classList.add('d-none'); }
}
async function refreshList(){
  const r = await fetch('notify_api.php?action=list&limit=15');
  const j = await r.json();
  renderItems(j.items || []);
}
markBtn?.addEventListener('click', async ()=>{
  await fetch('notify_api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=mark_all_read'});
  refreshCount(); refreshList();
});
// โหลดครั้งแรก + อัปเดตทุก 30 วินาที
refreshCount(); refreshList();
setInterval(()=>{ refreshCount(); }, 30000);
</script>
<?php endif; ?>
</body>
</html>
