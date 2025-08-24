<?php
// Home/admin/dashboard.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

// ต้องเป็น admin เท่านั้น
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/dashboard.php');
  exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

// ===== ชื่อแอดมินปัจจุบัน =====
$admin_id = (int)$_SESSION['user_id'];
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

// ====================== สรุปตัวเลข ======================
$users_total = (int)$conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$products_active = (int)$conn->query("SELECT COUNT(*) c FROM products WHERE status='active'")->fetch_assoc()['c'];
$orders_open = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE status IN ('pending','processing','shipped')")->fetch_assoc()['c'];
$bank_pending = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_method='bank' AND payment_status='pending'")->fetch_assoc()['c'];

// ยอดขาย 7 วันล่าสุด
$revenue7 = 0.00;
$res = $conn->query("
  SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) AS rev
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  WHERE o.payment_status='paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
if ($res) { $revenue7 = (float)$res->fetch_assoc()['rev']; }

// ====================== ออเดอร์ล่าสุด ======================
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

// ====================== แจ้งเตือน (ค่าเริ่มต้น) ======================
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
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard | WEB APP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body { background:#f6f8fb; }
    .card-stat{ border:1px solid #eef2f6; border-radius:16px; }
    .sidebar{ position:sticky; top:1rem }
    /* notification dropdown */
    .notif-item.unread { background:#f7fbff; }
    .notif-item .time   { font-size:.8rem; color:#6b7280; }
    .notif-empty { color:#6b7280; }

    /* ===== Support Chat Widget ===== */
    .chat-wrap{height:420px}
    .chat-list{height:420px; overflow:auto; border:1px solid #e9eef5; border-radius:12px}
    .chat-room{height:420px; display:flex; flex-direction:column; border:1px solid #e9eef5; border-radius:12px; overflow:hidden}
    .msgs{flex:1; overflow:auto; padding:12px; background:linear-gradient(180deg,#f9fbff,#fff)}
    .msg{max-width:80%; padding:8px 10px; border-radius:12px; margin-bottom:8px; box-shadow:0 4px 18px rgba(2,6,23,.06)}
    .me{margin-left:auto; background:#0d6efd; color:#fff; border-bottom-right-radius:4px}
    .they{margin-right:auto; background:#eef5ff; color:#0d47a1; border-bottom-left-radius:4px}
    .meta{font-size:12px; color:#6b7280; margin-top:2px}
    .chat-item:hover{background:#f6f9ff}
    .chat-item.active{background:#eef5ff}
    .ellipsis-1{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold text-primary" href="dashboard.php">
      <i class="bi bi-speedometer2"></i> Admin
    </a>

    <ul class="navbar-nav ms-auto align-items-center">
      <!-- ปุ่มแจ้งเตือน -->
      <li class="nav-item dropdown me-2">
        <a class="nav-link position-relative" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-bell fs-5"></i>
          <span id="notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width:360px">
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <div class="fw-semibold">การแจ้งเตือน</div>
            <button class="btn btn-sm btn-link" id="notif-mark-read">อ่านทั้งหมด</button>
          </div>
          <div id="notif-list" style="max-height:360px; overflow:auto">
            <div class="p-3 text-center text-muted">กำลังโหลด...</div>
          </div>
          <div class="text-center small text-muted py-2 border-top">อัปเดตอัตโนมัติ</div>
        </div>
      </li>

      <li class="nav-item">
        <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      </li>
      <li class="nav-item ms-2">
        <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
      </li>
    </ul>
  </div>
</nav>

<div class="container-fluid my-4">
  <div class="row g-3">
    <!-- Sidebar -->
    <aside class="col-lg-2">
      <div class="list-group sidebar shadow-sm">
        <a href="dashboard.php" class="list-group-item list-group-item-action active">
          <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>
        <a href="orders.php" class="list-group-item list-group-item-action">
          <i class="bi bi-receipt me-2"></i> Orders
        </a>
        <a href="products.php" class="list-group-item list-group-item-action">
          <i class="bi bi-box-seam me-2"></i> Products
        </a>
        <a href="users.php" class="list-group-item list-group-item-action">
          <i class="bi bi-people me-2"></i> Users
        </a>
        <a href="../my_orders.php" class="list-group-item list-group-item-action">
          <i class="bi bi-bag me-2"></i> My Orders (as user)
        </a>
        <a href="support.php" class="list-group-item list-group-item-action">
          <i class="bi bi-chat-dots me-2"></i> กล่องข้อความ (เต็มหน้าจอ)
        </a>
      </div>
    </aside>

    <!-- Main -->
    <main class="col-lg-10">
      <div class="row g-3">
        <!-- Stats -->
        <div class="col-md-3">
          <div class="card card-stat shadow-sm">
            <div class="card-body">
              <div class="text-muted small">Users</div>
              <div class="fs-3 fw-bold"><?= number_format($users_total) ?></div>
              <div class="text-secondary"><i class="bi bi-people"></i> ทั้งหมด</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card card-stat shadow-sm">
            <div class="card-body">
              <div class="text-muted small">Active Products</div>
              <div class="fs-3 fw-bold"><?= number_format($products_active) ?></div>
              <div class="text-secondary"><i class="bi bi-box-seam"></i> พร้อมขาย</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card card-stat shadow-sm">
            <div class="card-body">
              <div class="text-muted small">Open Orders</div>
              <div class="fs-3 fw-bold"><?= number_format($orders_open) ?></div>
              <div class="text-secondary"><i class="bi bi-receipt"></i> ใหม่/กำลังดำเนินการ</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card card-stat shadow-sm">
            <div class="card-body">
              <div class="text-muted small">Bank Transfer Pending</div>
              <div class="fs-3 fw-bold"><?= number_format($bank_pending) ?></div>
              <div class="text-secondary"><i class="bi bi-clock"></i> รอตรวจสลิป</div>
            </div>
          </div>
        </div>

        <!-- Revenue -->
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body d-flex justify-content-between align-items-center">
              <div>
                <div class="text-muted small">ยอดขาย (7 วันล่าสุด)</div>
                <div class="h3 mb-0"><?= baht($revenue7) ?> ฿</div>
              </div>
              <a href="orders.php" class="btn btn-primary">
                ไปที่ Orders <i class="bi bi-arrow-right-short"></i>
              </a>
            </div>
          </div>
        </div>

        <!-- Latest Orders -->
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-header fw-bold">
              ออเดอร์ล่าสุด
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>ลูกค้า</th>
                    <th>ยอดรวม</th>
                    <th>ชำระเงิน</th>
                    <th>สถานะชำระ</th>
                    <th>สถานะออเดอร์</th>
                    <th>วันที่</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(empty($latest)): ?>
                    <tr><td colspan="8" class="text-center text-muted">ยังไม่มีคำสั่งซื้อ</td></tr>
                  <?php else: foreach($latest as $o): ?>
                    <tr>
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
                      <td>
                        <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary">
                          <i class="bi bi-eye"></i> ดู
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ===== Support Messages Widget ===== -->
        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="fw-bold"><i class="bi bi-chat-dots"></i> กล่องข้อความ (ล่าสุด)</div>
              <a href="support.php" class="btn btn-sm btn-outline-primary">เปิดแบบเต็มจอ</a>
            </div>
            <div class="card-body">
              <div class="row g-3 chat-wrap">
                <!-- รายชื่อ/ห้อง -->
                <div class="col-lg-4">
                  <div class="chat-list" id="threadList">
                    <div class="p-3 text-center text-muted">กำลังโหลด...</div>
                  </div>
                </div>
                <!-- ห้องสนทนา -->
                <div class="col-lg-8">
                  <div class="chat-room">
                    <div class="p-2 border-bottom bg-white d-flex align-items-center gap-2">
                      <div class="fw-semibold" id="roomTitle">เลือกผู้ใช้ทางซ้ายเพื่อเริ่มคุย</div>
                      <div class="ms-auto d-flex align-items-center gap-2">
                        <span class="small text-muted" id="chatStatus">—</span>
                        <button class="btn btn-sm btn-outline-danger d-none" id="chatEnd">
                          <i class="bi bi-x-circle"></i> สิ้นสุดแชท
                        </button>
                      </div>
                    </div>
                    <div id="chatMsgs" class="msgs"></div>
                    <form id="chatForm" class="p-2 border-top bg-white d-flex gap-2">
                      <input type="text" class="form-control" id="chatInput" placeholder="พิมพ์ตอบกลับ…" required disabled>
                      <button class="btn btn-primary" id="chatSend" disabled><i class="bi bi-send"></i></button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- ===== /Support Messages Widget ===== -->

      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ========= Notifications ========= */
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
    <a class="dropdown-item d-block py-2 px-3 ${it.is_read==0?'bg-light':''}" href="${linkFor(it)}">
      <div class="fw-semibold">${escapeHtml(it.title||'')}</div>
      ${it.message ? `<div class="small">${escapeHtml(it.message)}</div>` : ''}
      <div class="small text-muted">${fmtTime(it.created_at)}</div>
    </a>
  `).join('');
}
async function refreshCount(){
  const r = await fetch('../notify_api.php?action=count');
  const j = await r.json();
  const c = j.count||0;
  if(c>0){ badge.classList.remove('d-none'); badge.textContent=c; }
  else   { badge.classList.add('d-none'); }
}
async function refreshList(){
  const r = await fetch('../notify_api.php?action=list&limit=15');
  const j = await r.json();
  renderItems(j.items||[]);
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

/* ========= Support Chat Widget ========= */
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

/* ---- normalizers ---- */
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

/* ---- renderers ---- */
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

/* ---- data loaders ---- */
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

  // ดึงหัวห้องเพื่อแสดงชื่อ
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

  // set active highlight
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
    // refresh รายชื่อเพื่ออัปเดตตัวเลข unread แบบเบาๆ
    loadThreads();
  }catch(e){
    chatStatus.textContent = 'ออฟไลน์';
  }
}

/* ---- send ---- */
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
  // ข้อความฝั่งแอดมินจะถูกดึงเข้ามารอบ poll ถัดไป
});

/* ---- end chat (ลบประวัติ) ---- */
chatEndBtn.addEventListener('click', async ()=>{
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
      chatEndBtn.disabled = true;
      chatMsgs.innerHTML = `
        <div class="text-center text-muted" style="margin-top:8vh">
          <div class="mb-2"><i class="bi bi-check-circle text-success" style="font-size:2rem"></i></div>
          <div>สิ้นสุดแชทและลบประวัติเรียบร้อย</div>
          <div class="small mt-1">หากลูกค้าส่งข้อความใหม่ ระบบจะเปิดห้องแชทให้อัตโนมัติ</div>
        </div>`;
      chatStatus.textContent = 'ปิดแล้ว';
      // รีเฟรชรายชื่อ
      loadThreads();
    }else{
      alert('ไม่สามารถสิ้นสุดแชทได้: '+(j.error||'unknown'));
    }
  }catch(e){
    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
  }
});

/* ---- boot ---- */
(async ()=>{
  const threads = await loadThreads();
  if(threads.length){
    openRoom(threads[0].id);
  }
})();
</script>

</body>
</html>
