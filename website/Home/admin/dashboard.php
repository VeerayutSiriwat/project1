<?php
// Home/admin/dashboard.php (revamped)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/dashboard.php');
  exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

/* ===== Admin name ===== */
$admin_id = (int)$_SESSION['user_id'];
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

/* ===== KPIs ===== */
$users_total     = (int)$conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$products_active = (int)$conn->query("SELECT COUNT(*) c FROM products WHERE status='active'")->fetch_assoc()['c'];
$orders_open     = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE status IN ('pending','processing','shipped')")->fetch_assoc()['c'];
$bank_pending    = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_method='bank' AND payment_status='pending'")->fetch_assoc()['c'];

/* sales 7d */
$revenue7 = 0.00;
$res = $conn->query("
  SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) AS rev
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  WHERE o.payment_status='paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
if ($res) { $revenue7 = (float)$res->fetch_assoc()['rev']; }

/* latest orders */
$sql_latest = "
  SELECT 
    o.id, o.user_id, o.status, o.payment_method, o.payment_status, o.created_at,
    u.username,
    COALESCE(SUM(oi.quantity*oi.unit_price),0) AS total_amount
  FROM orders o
  LEFT JOIN users u ON u.id = o.user_id
  LEFT JOIN order_items oi ON oi.order_id = o.id
  GROUP BY o.id
  ORDER BY o.created_at DESC
  LIMIT 10
";
$latest = $conn->query($sql_latest)->fetch_all(MYSQLI_ASSOC);

/* notif count (initial) */
$noti_unread = 0;
if (!empty($_SESSION['user_id'])) {
  $uid = (int)$_SESSION['user_id'];
  if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
    $st->bind_param('i', $uid);
    $st->execute();
    $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
  }
}
?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard | WEB APP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="">
  <style>
    :root{
      --bg: #0b1220;
      --panel: rgba(255,255,255,.08);
      --border: rgba(255,255,255,.16);
      --text: #e5e7eb;
      --muted: #a1a1aa;
      --primary:#4f46e5;
      --primary-2:#6366f1;
      --chip:#0ea5e9;
      --card-shadow: 0 20px 70px rgba(2,6,23,.25);
    }
    html[data-theme="light"]{
      --bg: #f6f8fb;
      --panel: #ffffffcc;
      --border: #e9eef5;
      --text: #0f172a;
      --muted: #64748b;
      --card-shadow: 0 18px 60px rgba(2,6,23,.06);
    }

    body{
      background:
        radial-gradient(1100px 420px at -10% -10%, #1e3a8a22, transparent 60%),
        radial-gradient(1100px 420px at 110% -10%, #7c3aed22, transparent 60%),
        var(--bg);
      color:var(--text);
    }

    /* ===== shell ===== */
    .topbar{
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      background: linear-gradient(180deg, #ffffffcc, #ffffffaa);
      border-bottom: 1px solid var(--border);
    }
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }

    .app{
      display:grid;
      grid-template-columns: 260px 1fr;
      gap:24px;
    }
    @media (max-width: 991.98px){
      .app{grid-template-columns: 1fr}
      .sidebar{position:static}
    }

    /* sidebar */
    .sidebar{
      position: sticky; top: 90px;
      border:1px solid var(--border);
      border-radius:18px;
      background: var(--panel);
      box-shadow: var(--card-shadow);
      overflow:hidden;
    }
    .side-a{
      display:flex; align-items:center; gap:10px;
      padding:12px 16px; text-decoration:none; color:inherit;
      border-left:3px solid transparent;
    }
    .side-a:hover{ background: rgba(99,102,241,.08); }
    .side-a.active{ background: rgba(99,102,241,.12); border-left-color: var(--primary); }
html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
html[data-theme="dark"] .side-a.active{background:#0e1f3e}
    /* cards */
    .glass{
      border:1px solid var(--border);
      background: var(--panel);
      border-radius:18px;
      box-shadow: var(--card-shadow);
    }
    .kpi{
      position:relative; overflow:hidden;
      transition: transform .1s ease;
    }
    .kpi:hover{ transform: translateY(-2px); }
    .kpi .icon{
      width:42px; height:42px; border-radius:10px;
      display:flex; align-items:center; justify-content:center;
      color:#fff; background: var(--primary);
    }
    .chip{
      display:inline-flex; align-items:center; gap:6px;
      background:#0ea5e933; color:#075985; border:1px solid #0ea5e955;
      font-weight:600; padding:4px 10px; border-radius:999px; font-size:.85rem;
    }
    html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }

    .table> :not(caption)>*>*{ border-color: var(--border); }
    .table thead th{ color: var(--muted); font-weight:600; }

    /* notif dropdown */
    .dropdown-menu.glass{
      background: var(--panel);
      border:1px solid var(--border);
      box-shadow: var(--card-shadow);
      border-radius:14px; overflow:hidden;
    }
    .dropdown-menu.glass .dropdown-item{ padding:.6rem .9rem; }

    /* chat widget */
    .chat-wrap{height:430px}
    .chat-list{height:430px; overflow:auto; border:1px solid var(--border); border-radius:12px}
    .chat-room{height:430px; display:flex; flex-direction:column; border:1px solid var(--border); border-radius:12px; overflow:hidden}
    .msgs{flex:1; overflow:auto; padding:12px; background:linear-gradient(180deg,#f9fbff,#fff)}
    html[data-theme="dark"] .msgs{ background:linear-gradient(180deg,#0f172a,#0b1322) }
    .msg{max-width:80%; padding:8px 10px; border-radius:12px; margin-bottom:8px; box-shadow:0 4px 18px rgba(2,6,23,.06)}
    .me{margin-left:auto; background:#4f46e5; color:#fff; border-bottom-right-radius:4px}
    .they{margin-right:auto; background:#eef5ff; color:#0b3b9c; border-bottom-left-radius:4px}
    html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.7); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45);}
    .meta{font-size:12px; opacity:.7; margin-top:2px}
    .chat-item:hover{background:#f6f9ff}
    html[data-theme="dark"] .chat-item:hover{background:#0f1a2d}
    .chat-item.active{background:#eef5ff}
    html[data-theme="dark"] .chat-item.active{background:#0e1f3e}
    .ellipsis-1{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">แดชบอร์ด • สวัสดี, <?= h($admin_name) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <!-- quick search orders -->
      <form class="d-none d-md-flex" role="search" action="orders.php" method="get">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" type="search" class="form-control" placeholder="ค้นหาเลขออเดอร์ / ชื่อลูกค้า">
        </div>
      </form>

      <!-- notifications -->
      <div class="dropdown">
        <a class="btn btn-light border position-relative" data-bs-toggle="dropdown" aria-expanded="false" id="notifDropdown">
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
      <a class="side-a active" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> Orders</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> Products</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> Trade-in</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> service</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> Users</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>

    <div class="p-3 border-top">
      <div class="chip"><i class="bi bi-lightning-charge-fill"></i> Quick actions</div>
      <div class="mt-2 d-grid gap-2">
        <a class="btn btn-sm btn-primary" href="product_add.php"><i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้า</a>
        <a class="btn btn-sm btn-outline-primary" href="orders.php?status=pending"><i class="bi bi-hourglass-split me-1"></i> ออเดอร์รอตรวจ</a>
        <a class="btn btn-sm btn-outline-primary" href="users.php?role=user"><i class="bi bi-person-plus me-1"></i> จัดการผู้ใช้</a>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">
    <!-- KPIs -->
    <div class="row g-3">
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="icon"><i class="bi bi-people"></i></div>
            <div>
              <div class="text-muted small">Users</div>
              <div class="fs-3 fw-bold"><?= number_format($users_total) ?></div>
              <div class="small text-secondary">ทั้งหมด</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#0ea5e9"><i class="bi bi-box-seam"></i></div>
            <div>
              <div class="text-muted small">Active Products</div>
              <div class="fs-3 fw-bold"><?= number_format($products_active) ?></div>
              <div class="small text-secondary">พร้อมขาย</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#10b981"><i class="bi bi-receipt"></i></div>
            <div>
              <div class="text-muted small">Open Orders</div>
              <div class="fs-3 fw-bold"><?= number_format($orders_open) ?></div>
              <div class="small text-secondary">ใหม่/กำลังดำเนินการ</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#f59e0b"><i class="bi bi-bank2"></i></div>
            <div>
              <div class="text-muted small">Bank Transfer Pending</div>
              <div class="fs-3 fw-bold"><?= number_format($bank_pending) ?></div>
              <div class="small text-secondary">รอตรวจสลิป</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Revenue quick -->
    <div class="glass p-3 d-flex justify-content-between align-items-center">
      <div>
        <div class="text-muted small">ยอดขาย (7 วันล่าสุด)</div>
        <div class="h3 mb-0"><?= baht($revenue7) ?> ฿</div>
      </div>
      <a href="orders.php" class="btn btn-primary">
        ไปที่ Orders <i class="bi bi-arrow-right-short"></i>
      </a>
    </div>

    <!-- Latest orders -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-clock-history me-2"></i>ออเดอร์ล่าสุด</div>
        <form class="d-none d-sm-flex" action="orders.php" method="get">
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input name="q" type="search" class="form-control" placeholder="ค้นหาใน Orders">
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>ลูกค้า</th><th>ยอดรวม</th><th>ชำระเงิน</th><th>สถานะชำระ</th><th>สถานะออเดอร์</th><th>วันที่</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($latest)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีคำสั่งซื้อ</td></tr>
          <?php else: foreach($latest as $o): ?>
            <tr id="row-<?= (int)$o['id'] ?>">
              <td><?= (int)$o['id'] ?></td>
              <td><?= h($o['username'] ?? ('UID '.$o['user_id'])) ?></td>
              <td><?= baht($o['total_amount']) ?> ฿</td>
              <td><?= $o['payment_method']==='bank' ? 'โอนธนาคาร' : 'ปลายทาง' ?></td>
              <td>
                <?php if($o['payment_status']==='paid'): ?>
                  <span class="badge bg-success">ชำระแล้ว</span>
                <?php elseif($o['payment_status']==='pending'): ?>
                  <span class="badge bg-warning text-dark">รอตรวจสอบ</span>
                <?php else: ?>
                  <span class="badge bg-secondary">ยังไม่ชำระ</span>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $map = [
                    'pending'=>'info text-dark',
                    'processing'=>'primary',
                    'shipped'=>'primary',
                    'delivered'=>'success',
                    'completed'=>'success',
                    'cancel_requested'=>'warning text-dark',
                    'cancelled'=>'danger'
                  ];
                  $badge = $map[$o['status']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= h($o['status']) ?></span>
              </td>
              <td><?= h($o['created_at']) ?></td>
              <td><a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-eye"></i> ดู</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Support widget -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-chat-dots me-2"></i>กล่องข้อความ (ล่าสุด)</div>
        <a href="support.php" class="btn btn-sm btn-outline-primary">เปิดแบบเต็มจอ</a>
      </div>
      <div class="p-3">
        <div class="row g-3 chat-wrap">
          <div class="col-lg-4">
            <div class="chat-list" id="threadList">
              <div class="p-3 text-center text-muted">กำลังโหลด...</div>
            </div>
          </div>
          <div class="col-lg-8">
            <div class="chat-room">
              <div class="p-2 border-bottom d-flex align-items-center gap-2">
                <div class="fw-semibold" id="roomTitle">เลือกผู้ใช้ทางซ้ายเพื่อเริ่มคุย</div>
                <div class="ms-auto d-flex align-items-center gap-2">
                  <span class="small text-muted" id="chatStatus">—</span>
                  <button class="btn btn-sm btn-outline-danger d-none" id="chatEnd">
                    <i class="bi bi-x-circle"></i> สิ้นสุดแชท
                  </button>
                </div>
              </div>
              <div id="chatMsgs" class="msgs"></div>
              <form id="chatForm" class="p-2 border-top d-flex gap-2">
                <input type="text" class="form-control" id="chatInput" placeholder="พิมพ์ตอบกลับ…" required disabled>
                <button class="btn btn-primary" id="chatSend" disabled><i class="bi bi-send"></i></button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

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
  try{
    const r = await fetch('../notify_api.php?action=count');
    const j = await r.json();
    const c = j.count||0;
    if(c>0){ badge.classList.remove('d-none'); badge.textContent=c; }
    else   { badge.classList.add('d-none'); }
  }catch(_){}
}
async function refreshList(){
  try{
    const r = await fetch('../notify_api.php?action=list&limit=15');
    const j = await r.json();
    renderItems(j.items||[]);
  }catch(_){}
}
markBtn?.addEventListener('click', async ()=>{
  await fetch('../notify_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=mark_all_read'
  });
  refreshCount(); refreshList();
});
refreshCount(); refreshList();
setInterval(refreshCount, 30000);

/* ===== Support Chat Widget ===== */
const ADMIN_NAME = <?= json_encode($admin_name, JSON_UNESCAPED_UNICODE) ?>;

const threadList = document.getElementById('threadList');
const chatMsgs   = document.getElementById('chatMsgs');
const roomTitle  = document.getElementById('roomTitle');
const chatStatus = document.getElementById('chatStatus');
const chatForm   = document.getElementById('chatForm');
const chatInput  = document.getElementById('chatInput');
const chatSend   = document.getElementById('chatSend');
const chatEndBtn = document.getElementById('chatEnd');

let activeUid = 0;
let lastMsgId = 0;
let pollTimer = null;

function scrollBottom(){ chatMsgs.scrollTop = chatMsgs.scrollHeight; }
function esc(s){ const d=document.createElement('div'); d.innerText=s??''; return d.innerHTML; }

function normalizeThread(it){
  return {
    id: Number(it.id ?? it.user_id ?? 0),
    username: it.username ?? it.name ?? ('UID ' + (it.id ?? '')),
    last_time: it.last_time ?? it.updated_at ?? it.created_at ?? '',
    last_message: it.last_message ?? it.preview ?? it.message ?? '',
    unread: Number(it.unread ?? it.unread_count ?? 0)
  };
}
function normalizeMsg(m){
  const id = Number(m.id ?? m.msg_id ?? 0);
  const sender =
    m.sender ? String(m.sender) :
    (String(m.from_admin ?? '') === '1' ? 'admin' :
     (String(m.from_user ?? '') === '1' ? 'user' :
      (m.is_admin ? 'admin' : (m.is_user ? 'user' : 'user'))));
  const message = m.message ?? m.text ?? '';
  const time = m.time ?? m.created_at ?? m.sent_at ?? '';
  return { id, sender, message, time };
}

function renderThreads(items){
  const list = (items||[]).map(normalizeThread);
  if(list.length===0){
    threadList.innerHTML = `<div class="p-3 text-center text-muted">ยังไม่มีข้อความ</div>`;
    return;
  }
  threadList.innerHTML = list.map(it => `
    <div class="d-flex align-items-start gap-2 p-2 border-bottom chat-item ${it.id==activeUid?'active':''}" data-uid="${it.id}" role="button">
      <div class="flex-grow-1">
        <div class="d-flex justify-content-between">
          <div class="fw-semibold">@${esc(it.username)}</div>
          <div class="small text-muted">${esc(it.last_time)}</div>
        </div>
        <div class="small text-muted ellipsis-1">${esc(it.last_message)}</div>
      </div>
      ${it.unread>0?`<span class="badge bg-danger">${it.unread}</span>`:''}
    </div>
  `).join('');
  threadList.querySelectorAll('.chat-item').forEach(el=>{
    el.addEventListener('click', ()=>openRoom(Number(el.dataset.uid)));
  });
}
function appendMsgs(list){
  const arr = (list||[]).map(normalizeMsg);
  for(const m of arr){
    const who = (m.sender==='admin') ? ('แอดมิน: '+ADMIN_NAME) : 'ลูกค้า';
    const bubble = document.createElement('div');
    bubble.className = `msg ${m.sender==='admin'?'me':'they'}`;
    bubble.innerHTML = `${esc(m.message)}<div class="meta">${esc(who)} • ${esc(m.time)}</div>`;
    chatMsgs.appendChild(bubble);
    lastMsgId = Math.max(lastMsgId, m.id||0);
  }
  if(arr.length) scrollBottom();
}

async function loadThreads(){
  try{
    const r = await fetch('support_threads_api.php');
    const j = await r.json();
    renderThreads(j.items||j.data||[]);
    return (j.items||j.data||[]).map(normalizeThread);
  }catch(e){
    threadList.innerHTML = `<div class="p-3 text-center text-danger">โหลดรายการไม่สำเร็จ</div>`;
    return [];
  }
}
async function openRoom(uid){
  if(!uid) return;
  activeUid = uid;
  lastMsgId = 0;
  chatMsgs.innerHTML = '';
  chatInput.disabled = chatSend.disabled = false;
  chatEndBtn.classList.remove('d-none');

  try{
    const head = await fetch('support_threads_api.php?single='+uid);
    const hjson = await head.json();
    const name = hjson.username || hjson.user?.username || hjson.name || ('UID '+uid);
    roomTitle.textContent = 'คุยกับ @' + name + '  •  แอดมิน: ' + ADMIN_NAME;
  }catch(_){
    roomTitle.textContent = 'คุยกับ UID ' + uid + '  •  แอดมิน: ' + ADMIN_NAME;
  }

  await loadMsgs(true);
  if(pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(pollMsgs, 2500);

  threadList.querySelectorAll('.chat-item').forEach(el => el.classList.toggle('active', Number(el.dataset.uid)===uid));
}
async function loadMsgs(first=false){
  try{
    const r = await fetch(`support_thread_api.php?uid=${activeUid}&since=0`);
    const j = await r.json();
    appendMsgs(j.items||j.data||[]);
    chatStatus.textContent = 'ออนไลน์';
  }catch(e){
    if(first) chatStatus.textContent = 'ออฟไลน์';
  }
}
async function pollMsgs(){
  if(!activeUid) return;
  try{
    const r = await fetch(`support_thread_api.php?uid=${activeUid}&since=${lastMsgId}`);
    const j = await r.json();
    appendMsgs(j.items||j.data||[]);
    chatStatus.textContent = 'ออนไลน์';
    loadThreads(); // refresh unread
  }catch(e){
    chatStatus.textContent = 'ออฟไลน์';
  }
}
chatForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  if(!activeUid) return;
  const text = chatInput.value.trim();
  if(!text) return;
  chatInput.value=''; chatInput.focus();
  await fetch('support_admin_send.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ uid:String(activeUid), message:text })
  });
});
document.getElementById('chatEnd').addEventListener('click', async ()=>{
  if(!activeUid) return;
  if(!confirm('ยืนยันสิ้นสุดแชทนี้? ระบบจะลบประวัติแชททั้งหมดของผู้ใช้นี้')) return;
  try{
    const r = await fetch('../support_end_chat.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ uid:String(activeUid) })
    });
    const j = await r.json();
    if(j.ok){
      clearInterval(pollTimer);
      chatInput.disabled = true;
      chatSend.disabled  = true;
      document.getElementById('chatEnd').disabled = true;
      chatMsgs.innerHTML = `
        <div class="text-center text-muted" style="margin-top:8vh">
          <div class="mb-2"><i class="bi bi-check-circle text-success" style="font-size:2rem"></i></div>
          <div>สิ้นสุดแชทและลบประวัติเรียบร้อย</div>
          <div class="small mt-1">หากลูกค้าส่งข้อความใหม่ ระบบจะเปิดห้องแชทให้อัตโนมัติ</div>
        </div>`;
      chatStatus.textContent = 'ปิดแล้ว';
      loadThreads();
    }else{
      alert('ไม่สามารถสิ้นสุดแชทได้: '+(j.error||'unknown'));
    }
  }catch(e){ alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
});

(async ()=>{ const threads = await loadThreads(); if(threads.length){ openRoom(threads[0].id); }})();
</script>

</body>
</html>
