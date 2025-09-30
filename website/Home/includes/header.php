<?php
// Home/includes/header.php  (คง PHP/โครงเดิมทุกอย่าง)
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
    /* ================== โทน/พื้นหลัง/เงา ================== */
    .nav-glass {
      background:
        radial-gradient(900px 240px at 5% -30%, rgba(99,102,241,.14), transparent 60%),
        radial-gradient(900px 240px at 105% -30%, rgba(14,165,233,.12), transparent 60%),
        linear-gradient(180deg, #ffffffcc, #ffffffaa);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(15,23,42,.06);
      box-shadow: 0 10px 30px rgba(2,6,23,.06);
    }

    /* ================== ลิงก์เมนูแบบ Pill ================== */
    .navbar .nav-link {
      font-weight: 600;
      color: #0f172a;
      border-radius: 999px;
      padding: .5rem .9rem;
      transition: all .15s ease;
    }
    .navbar .nav-link:hover,
    .navbar .nav-link:focus {
      background: #f1f5f9;
      color: #0f172a;
    }
    .navbar .nav-link.active{ color:#111827; }

    /* ================== ปุ่ม/ไอคอนวงกลม ================== */
    .icon-btn, .btn-outline-primary, .btn-primary {
      border-radius: 999px !important;
    }
    .icon-btn{
      position:relative; width:42px; height:42px;
      display:inline-flex; align-items:center; justify-content:center;
      background:#ffffff; border:1px solid #e5e7eb; color:#0f172a;
      transition:.15s ease;
    }
    .icon-btn:hover{ background:#f8fafc; }
    .badge-dot{
      position:absolute; top:-3px; right:-3px;
      background:#ef4444; color:#fff; font-size:.7rem; border-radius:999px;
      padding:.2rem .35rem; line-height:1; border: 2px solid #fff;
    }

    /* ================== Dropdown แบบ Glass ================== */
    .dropdown-menu.glass{
      background: rgba(255,255,255,.96);
      border: 1px solid rgba(15,23,42,.08);
      box-shadow: 0 16px 50px rgba(2,6,23,.12);
      border-radius: 16px;
      overflow:hidden;
    }
    .dropdown-menu.glass .dropdown-item{
      padding:.6rem .85rem; border-radius:10px; margin:2px 6px;
    }
    .dropdown-menu.glass .dropdown-item:hover{ background:#f1f5f9; }

    /* ================== โปรไฟล์/ภาพ ================== */
    .avatar-36{ width:36px; height:36px; border-radius:999px; object-fit:cover; border:1px solid #e5e7eb; }

    /* ================== แจ้งเตือน ================== */
    .notif-item.unread { background:#f7fbff; }
    .notif-item .time   { font-size:.8rem; color:#6b7280; }
    .notif-empty { color:#6b7280; }

    /* ================== Toggler ให้เห็นชัดบนพื้นสว่าง ================== */
    .navbar-toggler { border:0; }
    .navbar-toggler .navbar-toggler-icon{
      filter: invert(0) brightness(0.3);
    }

    /* ระยะห่างรวม */
    .navbar .navbar-brand span { letter-spacing:.2px }
    .navbar .btn { font-weight:700; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg nav-glass sticky-top" data-bs-theme="light">
  <div class="container-fluid">
    <!-- โลโก้ -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <span class="fw-bold text-primary">WEB APP</span>
    </a>

    <!-- ปุ่มเมนูมือถือ -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- เมนูหลัก -->
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php">หน้าแรก</a></li>
        <li class="nav-item"><a class="nav-link" href="products.php">สินค้า</a></li>
        <li class="nav-item"><a class="nav-link" href="service.php">บริการซ่อม</a></li>
        <li class="nav-item"><a class="nav-link" href="about.php">เกี่ยวกับเรา</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.php">ติดต่อ</a></li>
      </ul>

      <!-- ขวาสุด -->
      <ul class="navbar-nav ms-auto align-items-center gap-2">
        <?php if ($isLoggedIn): ?>

          <!-- แจ้งเตือน -->
          <li class="nav-item dropdown">
            <a class="icon-btn nav-link border-0" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="การแจ้งเตือน">
              <i class="bi bi-bell"></i>
              <span id="notif-badge" class="badge-dot <?= ($noti_unread>0?'':'d-none') ?>"><?= (int)$noti_unread ?></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end glass p-0" aria-labelledby="notifDropdown" style="min-width:340px">
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
              <img src="<?= h($avatar_web) ?>" class="avatar-36" alt="avatar">
              <span class="ms-2 fw-semibold"><?= h($navUser['username'] ?? 'user') ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end glass" aria-labelledby="userDropdown">
              <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
              <li><a class="dropdown-item" href="my_orders.php"><i class="bi bi-receipt me-2"></i>ประวัติการสั่งซื้อ</a></li>
              <li><a class="dropdown-item" href="service_my.php"><i class="bi bi-clipboard-check"></i> สถานะงานซ่อม/เทิร์น</a></li>
              <li><a class="dropdown-item" href="coupons_my.php"><i class="bi bi-ticket-perforated me-2"></i> คูปองของฉัน</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
            </ul>
          </li>

          <!-- ตะกร้า -->
          <li class="nav-item position-relative">
            <a href="cart_view.php" class="icon-btn nav-link border-0" title="ตะกร้า">
              <i class="bi bi-cart3"></i>
              <?php if ($cart_count > 0): ?>
                <span id="cart-count" class="badge-dot"><?= (int)$cart_count ?></span>
              <?php endif; ?>
            </a>
          </li>

        <?php else: ?>
          <li class="nav-item">
            <a class="btn btn-outline-primary px-3" href="login.php">เข้าสู่ระบบ</a>
          </li>
          <li class="nav-item d-none d-lg-block">
            <a class="btn btn-primary px-3" href="register.php">สมัครสมาชิก</a>
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
const dd      = document.getElementById('notifDropdown');

function fmtTime(iso){
  try { const d = new Date(iso.replace(' ','T')); return d.toLocaleString(); } catch(e){ return iso; }
}
function escapeHtml(s){ const d = document.createElement('div'); d.innerText = s || ''; return d.innerHTML; }

/* ปลายทางของลูกค้า */
function linkFor(it){
  if (it.type === 'schedule_proposed' && it.ref_id) return `service_my_detail.php?type=repair&id=${it.ref_id}#schedule`;
  if (it.type === 'schedule_booked'   && it.ref_id) return `service_my_detail.php?type=repair&id=${it.ref_id}#schedule`;
  if (it.type === 'service_status'    && it.ref_id) return `service_my_detail.php?type=repair&id=${it.ref_id}`;
  if (it.type === 'tradein_status'    && it.ref_id) return `service_my_detail.php?type=tradein&id=${it.ref_id}`;
  if (it.type === 'order_status' && it.ref_id)       return `my_orders.php?id=${it.ref_id}`;
  if (it.type === 'review_reply' && it.ref_id)       return `product.php?id=${it.ref_id}#reviews`;
  if (it.type === 'support_msg')                    return `contact.php${it.ref_id ? `?uid=${it.ref_id}#chat` : '#chat'}`;
  return '#';
}

/* ===== render รายการ + data-id ===== */
function renderItems(items){
  if (!items || items.length === 0){
    listEl.innerHTML = `<div class="p-3 text-center notif-empty">ยังไม่มีการแจ้งเตือน</div>`;
    return;
  }
  listEl.innerHTML = items.map(it => `
    <a class="dropdown-item d-block py-2 px-3 notif-item ${Number(it.is_read)===0?'unread':''}"
       href="${linkFor(it)}" data-id="${it.id||''}">
      <div class="fw-semibold">${escapeHtml(it.title || '')}</div>
      ${it.message ? `<div class="small">${escapeHtml(it.message)}</div>` : ''}
      <div class="time">${fmtTime(it.created_at)}</div>
    </a>
  `).join('');
}

/* ===== API calls ===== */
async function refreshCount(){
  try{
    const r = await fetch('notify_api.php?action=count', {cache:'no-store'});
    const j = await r.json();
    const c = j.count || 0;
    if (c>0){ badge?.classList.remove('d-none'); badge.textContent = c; }
    else   { badge?.classList.add('d-none'); }
  }catch(e){}
}
async function refreshList(){
  try{
    const r = await fetch('notify_api.php?action=list&limit=15', {cache:'no-store'});
    const j = await r.json();
    renderItems(j.items || []);
  }catch(e){
    listEl.innerHTML = `<div class="p-3 text-center notif-empty">โหลดไม่สำเร็จ ลองใหม่อีกครั้ง</div>`;
  }
}

/* ===== ทำเครื่องหมายอ่านทั้งหมด ===== */
markBtn?.addEventListener('click', async ()=>{
  await fetch('notify_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=mark_all_read'
  });
  refreshCount(); refreshList();
});

/* ===== ทำเครื่องหมายอ่านทีละรายการเมื่อคลิก ===== */
listEl?.addEventListener('click', async (e)=>{
  const a = e.target.closest('a[data-id]');
  if(!a) return;
  const id = a.getAttribute('data-id');
  if(!id) return;
  // ส่งแบบ keepalive ให้ทันแม้กำลังเปลี่ยนหน้า
  navigator.sendBeacon?.(
    'notify_api.php',
    new URLSearchParams({ action:'mark_read', id }).toString()
  ) || fetch('notify_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action:'mark_read', id }).toString(),
    keepalive: true
  });
  a.classList.remove('unread');
  refreshCount(); // ลดตัวเลขทันที
});

/* เปิดดรอปดาวน์ = รีเฟรชรายการล่าสุด */
dd?.addEventListener('show.bs.dropdown', refreshList);

/* เริ่มต้น + โพลล์ทุก 30 วิ */
refreshCount(); refreshList();
setInterval(refreshCount, 30000);

</script>

<?php endif; ?>

</body>
</html>
