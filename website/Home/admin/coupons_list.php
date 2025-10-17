<?php
// Home/admin/coupons_list.php (match dashboard style)
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  header("Location: ../login.php?redirect=admin/coupons_list.php"); exit;
}

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }
/* ===== helpers สำหรับเช็ค schema ===== */
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows > 0;
}



/* ===== Admin name (เหมือน dashboard) ===== */
$admin_id = (int)$_SESSION['user_id'];
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

/* ===== โหลดรายการคูปอง (ทนสคีมา) ===== */
$rows = [];
$hasUsages = table_exists($conn, 'coupon_usages');
$hasUsedCountCol = has_col($conn, 'coupons', 'used_count');
$usedFallback = $hasUsedCountCol ? "COALESCE(c.used_count,0)" : "0";

if ($hasUsages) {
  $sql = "
    SELECT 
      c.*,
      COALESCE(u.used_total, $usedFallback) AS used_total
    FROM coupons c
    LEFT JOIN (
      SELECT coupon_id, COUNT(*) AS used_total
      FROM coupon_usages
      GROUP BY coupon_id
    ) u ON u.coupon_id = c.id
    ORDER BY c.id DESC
  ";
} else {
  // ไม่มี coupon_usages → ใช้คอลัมน์ใน coupons ถ้ามี ไม่มีก็เป็น 0
  $sql = "
    SELECT 
      c.*,
      $usedFallback AS used_total
    FROM coupons c
    ORDER BY c.id DESC
  ";
}
if ($res = $conn->query($sql)) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

/* ===== KPI สรุป ===== */
$total = count($rows);
$now = new DateTime();
$active = 0; $inactive = 0; $expired = 0; $expSoon = 0; // ภายใน 7 วัน
foreach ($rows as $c){
  $isActive = ($c['status']??'')==='active';
  $end = empty($c['ends_at']) ? null : new DateTime($c['ends_at']);
  $isExpired = $end && $end < $now;
  if ($isExpired) $expired++;
  if ($isActive && !$isExpired) $active++;
  if (($c['status']??'')==='inactive') $inactive++;
  if ($end && !$isExpired) {
    $diff = $now->diff($end)->days;
    if ($diff !== false && $diff <= 7) $expSoon++;
  }
}

/* notif count (เหมือน dashboard) */
$noti_unread = 0;
$uid = (int)$_SESSION['user_id'];
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $uid); $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

/* helper แสดง badge สถานะคูปอง */
function coupon_status_badge($c){
  $now = new DateTime();
  $end = empty($c['ends_at']) ? null : new DateTime($c['ends_at']);
  $isExpired = $end && $end < $now;
  if ($isExpired) return '<span class="badge bg-danger">หมดอายุ</span>';
  if (($c['status']??'')==='active') return '<span class="badge bg-success">ใช้งาน</span>';
  return '<span class="badge bg-secondary">ปิด</span>';
}

/* ชนิดคูปอง + มูลค่า */
function coupon_value_str($c){
  $type = $c['type'] ?? 'fixed';
  $v = (float)($c['value'] ?? 0);
  if ($type === 'percent') return baht($v).'%';
  return baht($v).'฿';
}
?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
  <meta charset="utf-8">
  <title>Coupons | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b;
      --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06);
    }html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.7); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45);}
    body{background:var(--bg); color:var(--text);}
    .topbar{backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border);}
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }
    .app{display:grid; grid-template-columns:260px 1fr; gap:24px;}
    @media (max-width:992px){ .app{grid-template-columns:1fr} }
    .sidebar{position:sticky; top:90px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden;}
    .side-a{display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent;}
    .side-a:hover{background:#eef3ff}html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
    .side-a.active{background:#eef3ff; border-left-color:var(--primary)}html[data-theme="dark"] .side-a.active{background:#0e1f3e}
    .glass{border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow);}
    .kpi .icon{width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; background:#4f46e5}
    .kpi-soft{background:linear-gradient(145deg,#ffffff,#f7faff); border:1px solid #eaf0fa; border-radius:16px; box-shadow:0 10px 40px rgba(2,6,23,.06);}
    .table>:not(caption)>*>*{ border-color:var(--border) }
    .hero{background:linear-gradient(135deg,#f8fbff,#f6f9ff); border:1px solid #e7eef7; border-radius:16px;}
    .chip{display:inline-flex; align-items:center; gap:6px; background:#eaf2ff; border:1px solid #cfe1ff; padding:4px 10px; border-radius:999px; font-weight:600; font-size:.85rem;}
     html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }
     
    
  </style>
</head>
<body>

<!-- Topbar (เหมือน dashboard) -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-ticket-perforated me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">จัดการคูปอง • สวัสดี, <?= h($admin_name) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-primary" href="calendar.php"><i class="bi bi-calendar3"></i> ปฏิทิน</a>
      <div class="dropdown">
        <a class="btn btn-light border position-relative" data-bs-toggle="dropdown">
          <i class="bi bi-bell"></i>
          <span id="notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $noti_unread? '' : 'd-none' ?>"><?= (int)$noti_unread ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end glass p-0" style="min-width:360px">
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <div class="fw-semibold">การแจ้งเตือน</div>
            <button class="btn btn-sm btn-link" id="notif-mark-read">อ่านทั้งหมด</button>
          </div>
          <div id="notif-list" style="max-height:360px; overflow:auto">
            <div class="p-3 text-center text-muted">กำลังโหลด...</div>
          </div>
          <div class="text-center small text-muted py-2 border-top">อัปเดตอัตโนมัติ</div>
        </div>
      </div>
      <!-- theme -->
      <button class="btn btn-outline-secondary" id="themeToggle" title="สลับโหมด">
        <i class="bi bi-moon-stars"></i>
      </button>
      <a class="btn btn-outline-secondary" href="../index.php"><i class="bi bi-house"></i></a>
      <a class="btn btn-outline-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</nav>

<div class="container-fluid my-4 app">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="p-2">
      <a class="side-a" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> แดชบอร์ด</a>
      <a class="side-a" href="sales_summary.php"><i class="bi bi-graph-up-arrow me-2"></i> สรุปยอดขาย</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> ออเดอร์</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> สินค้า</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> เทิร์นสินค้า</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> งานซ่อม</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> ผู้ใช้</a>
      <a class="side-a active" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> คูปอง</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">

    <!-- Hero / Quick CTA -->
    <div class="glass p-3 hero">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
          <div class="text-muted small">เครื่องมือคูปอง</div>
          <div class="h4 m-0">สร้างและจัดการโค้ดส่วนลดสำหรับร้าน</div>
        </div>
        <a class="btn btn-primary" href="coupon_form.php"><i class="bi bi-plus-lg"></i> สร้างคูปอง</a>
        <a class="btn btn-outline-secondary" href="coupons_list.php"><i class="bi bi-arrow-clockwise"></i> รีเฟรช</a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3">
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi-soft h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#4f46e5;color:#fff"><i class="bi bi-ticket-perforated"></i></div>
            <div>
              <div class="text-muted small">ทั้งหมด</div>
              <div class="fs-3 fw-bold"><?= number_format($total) ?></div>
              <div class="small text-secondary">คูปอง</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi-soft h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#10b981;color:#fff"><i class="bi bi-check2-circle"></i></div>
            <div>
              <div class="text-muted small">ใช้งานอยู่</div>
              <div class="fs-3 fw-bold"><?= number_format($active) ?></div>
              <div class="small text-secondary">สถานะ active</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi-soft h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f59e0b;color:#fff"><i class="bi bi-hourglass-split"></i></div>
            <div>
              <div class="text-muted small">ใกล้หมดอายุ</div>
              <div class="fs-3 fw-bold"><?= number_format($expSoon) ?></div>
              <div class="small text-secondary">ภายใน 7 วัน</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi-soft h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#ef4444;color:#fff"><i class="bi bi-x-octagon"></i></div>
            <div>
              <div class="text-muted small">หมดอายุแล้ว</div>
              <div class="fs-3 fw-bold"><?= number_format($expired) ?></div>
              <div class="small text-secondary">ไม่สามารถใช้งาน</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="glass p-3">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <form class="d-flex flex-grow-1" method="get" action="coupons_list.php">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" class="form-control" placeholder="ค้นหาโค้ด / ประเภท" value="<?= h($_GET['q'] ?? '') ?>">
          </div>
        </form>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-primary" href="coupon_form.php"><i class="bi bi-plus"></i> สร้างคูปอง</a>
          <a class="btn btn-outline-secondary" href="coupons_list.php"><i class="bi bi-arrow-clockwise"></i></a>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-ticket-detailed me-2"></i>รายการคูปอง</div>
        <div class="text-muted small">
          <?php if(!empty($_SESSION['flash'])): ?>
            <span class="badge bg-success me-2"><i class="bi bi-check2"></i> <?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
          <?php else: ?>
            รวม <?= number_format($total) ?> รายการ
          <?php endif; ?>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>โค้ด</th>
              <th>ประเภท</th>
              <th>มูลค่า</th>
              <th>ช่วงเวลา</th>
              <th>ใช้ไป/จำกัด</th>
              <th>สถานะ</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($rows)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีคูปอง</td></tr>
          <?php else:
            $q = strtolower(trim($_GET['q'] ?? ''));
            foreach($rows as $c):
              if ($q) {
                $hay = strtolower(($c['code'] ?? '').' '.($c['type'] ?? ''));
                if (strpos($hay, $q) === false) continue;
              }
              $startTxt = $c['starts_at'] ?? '';
              $endTxt   = $c['ends_at'] ?? '';
              $rangeTxt = ($startTxt || $endTxt) ? (h($startTxt ?: '—').' — '.h($endTxt ?: '—')) : '—';
          ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td class="fw-semibold"><span class="tag"><i class="bi bi-hash"></i> <?= h($c['code']) ?></span></td>
              <td><?= h($c['type'] ?? '-') ?></td>
              <td><?= coupon_value_str($c) ?></td>
              <td><?= $rangeTxt ?></td>
              <?php
                $used  = (int)($c['used_total'] ?? 0);    // มาจาก coupon_usages หรือ used_count
                $limit = (int)($c['uses_limit'] ?? 0);    // 0 = ไม่จำกัด
              ?>
              <td>
                <?= $used ?> / <?= $limit>0 ? $limit : 'ไม่จำกัด' ?>
                <?php if ($limit>0): ?>
                  <div class="small text-muted">เหลืออีก <?= max(0, $limit - $used) ?> ครั้ง</div>
                <?php endif; ?>
              </td>

              <td><?= coupon_status_badge($c) ?></td>
              <td class="text-end">
                <a href="coupon_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square"></i> แก้ไข</a>
                <a href="coupon_delete.php?id=<?= (int)$c['id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('ลบคูปองนี้?')"><i class="bi bi-trash"></i> ลบ</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>

/* ===== theme toggle (remember) ===== */
(function(){
  const html = document.documentElement;
  const saved = localStorage.getItem('admin-theme') || 'light';
  html.setAttribute('data-theme', saved);
  document.getElementById('themeToggle')?.addEventListener('click', ()=>{
    const cur = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', cur);
    localStorage.setItem('admin-theme', cur);
  });
})();
/* ===== Notifications ===== */
const badge   = document.getElementById('notif-badge');
const listEl  = document.getElementById('notif-list');
const markBtn = document.getElementById('notif-mark-read');
function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }
function fmtTime(iso){ try{ const d=new Date(iso.replace(' ','T')); return d.toLocaleString(); }catch(e){ return iso; } }
function linkFor(it){
  if (it.type === 'cancel_request' && it.ref_id) return `orders.php?status=cancel_requested#row-${it.ref_id}`;
  if (it.type === 'order_status'   && it.ref_id) return `order_detail.php?id=${it.ref_id}`;
  if (it.type === 'payment_status' && it.ref_id) return `order_detail.php?id=${it.ref_id}`;
  if (it.type === 'support_msg') return `support.php`;
  if (it.type === 'calendar') return `calendar.php`;
  return 'orders.php';
}
function renderItems(items){
  if(!items || items.length===0){
    listEl.innerHTML = `<div class="p-3 text-center text-muted">ยังไม่มีการแจ้งเตือน</div>`;
    return;
  }
  listEl.innerHTML = items.map(it=>`
    <a class="dropdown-item d-block ${it.is_read==0?'bg-light':''}" href="${linkFor(it)}">
      <div class="fw-semibold">${escapeHtml(it.title||'')}</div>
      ${it.message ? `<div class="small">${escapeHtml(it.message)}</div>` : ''}
      <div class="small text-muted">${fmtTime(it.created_at)}</div>
    </a>
  `).join('');
}
async function refreshCount(){
  try{ const r = await fetch('../notify_api.php?action=count'); const j = await r.json();
       const c = j.count||0; if(c>0){ badge.classList.remove('d-none'); badge.textContent=c; } else { badge.classList.add('d-none'); }
  }catch(_){}
}
async function refreshList(){
  try{ const r = await fetch('../notify_api.php?action=list&limit=15'); const j = await r.json(); renderItems(j.items||[]); }catch(_){}
}
markBtn?.addEventListener('click', async ()=>{
  await fetch('../notify_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=mark_all_read' });
  refreshCount(); refreshList();
});
refreshCount(); refreshList(); setInterval(refreshCount, 30000);
</script>
</body>
</html>
