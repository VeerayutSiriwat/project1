<?php
// Home/admin/support.php (revamped glass UI)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  header('Location: ../login.php?redirect=admin/support.php'); exit;
}
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

// ดึงชื่อแอดมินปัจจุบัน
$admin_id   = (int)($_SESSION['user_id']);
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

// รายชื่อลูกค้า + นับค้างอ่าน
$users = $conn->query("
  SELECT u.id,u.username,
         SUM(CASE WHEN m.sender='user' AND m.is_read_by_admin=0 THEN 1 ELSE 0 END) AS unread
  FROM users u
  LEFT JOIN support_messages m ON m.user_id=u.id
  WHERE u.role='user'
  GROUP BY u.id
  ORDER BY unread DESC, u.username ASC
")->fetch_all(MYSQLI_ASSOC);

$active_uid = (int)($_GET['uid'] ?? ($users[0]['id'] ?? 0));

// ชื่อลูกค้าที่เลือก
$cust_name = '';
if ($active_uid>0) {
  $row = $conn->query("SELECT username FROM users WHERE id={$active_uid}")->fetch_assoc();
  $cust_name = $row['username'] ?? ('UID '.$active_uid);
}
$noti_unread = 0;
$uid = (int)$_SESSION['user_id'];
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $uid); $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}
?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
  <meta charset="utf-8">
  <title>กล่องข้อความลูกค้า | Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b;
      --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06);
    }
    html[data-theme="dark"]{
      --bg:#0b1220; --panel:rgba(17,24,39,.72); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2;
      --card-shadow:0 20px 70px rgba(2,6,23,.45);
    }

    body{
      background:
        radial-gradient(1100px 420px at -10% -10%, #1e3a8a22, transparent 60%),
        radial-gradient(1100px 420px at 110% -10%, #7c3aed22, transparent 60%),
        var(--bg);
      color:var(--text);
    }

    .topbar{
      backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
      background:linear-gradient(180deg,#ffffffcc,#ffffffaa);
      border-bottom:1px solid var(--border);
    }
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }

    .app{ display:grid; grid-template-columns: 300px 1fr; gap:24px; }
    @media(max-width:991.98px){ .app{ grid-template-columns:1fr } .sidebar{ position:static } }

    .glass{ border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow); }

    /* Sidebar */
    .sidebar{ position:sticky; top:90px; overflow:hidden; }
    .side-a{ display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent; }
    .side-a:hover{ background:#f6f9ff } html[data-theme="dark"] .side-a:hover{ background:#0f1a2d }
    .side-a.active{ background:#eef5ff; border-left-color:var(--primary) } html[data-theme="dark"] .side-a.active{ background:#0e1f3e }

    /* People list */
    .list{ height:72vh; overflow:auto; }
    .user-item{ padding:.65rem .75rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:.6rem; text-decoration:none; color:inherit; }
    .user-item:hover{ background:#f6f9ff } html[data-theme="dark"] .user-item:hover{ background:#0f1a2d }
    .ellipsis-1{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* Chat */
    .chat{ height:72vh; display:flex; flex-direction:column; overflow:hidden; }
    .msgs{ flex:1; overflow:auto; padding:16px; background:linear-gradient(180deg,#f9fbff,#fff) }
    html[data-theme="dark"] .msgs{ background:linear-gradient(180deg,#0f172a,#0b1322) }
    .msg{ max-width:80%; padding:10px 12px; border-radius:14px; margin-bottom:10px; box-shadow:0 4px 20px rgba(2,6,23,.06) }
    .me{ margin-left:auto; background:#4f46e5; color:#fff; border-bottom-right-radius:4px }
    .they{ margin-right:auto; background:#eef5ff; color:#0b3b9c; border-bottom-left-radius:4px }
    html[data-theme="dark"] .they{ background:#0f1b33; color:#dbeafe; }
    .meta{ font-size:12px; opacity:.75; margin-top:2px }

    .table> :not(caption)>*>*{ border-color:var(--border); }
  </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-chat-dots me-1"></i> Support</span>
      <span class="fw-semibold d-none d-md-inline">กล่องข้อความ • แอดมิน: <?= h($admin_name) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-secondary" href="../index.php" title="หน้าร้าน"><i class="bi bi-house"></i></a>
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
      <a class="btn btn-outline-secondary" href="dashboard.php" title="แดชบอร์ด"><i class="bi bi-speedometer2"></i></a>
      <button class="btn btn-outline-secondary" id="themeToggle" title="สลับโหมด"><i class="bi bi-moon-stars"></i></button>
      <a class="btn btn-outline-danger" href="../logout.php" title="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</nav>

<div class="container-fluid my-4 app">
  <!-- Sidebar -->
  <aside class="glass sidebar">
    <div class="p-2">
      <a class="side-a" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> Orders</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> Products</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> Trade-in</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> Service</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> Users</a>
      <a class="side-a" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> Coupons</a>
      <a class="side-a active" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
    <div class="p-3 border-top">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="userFilter" type="search" class="form-control" placeholder="ค้นหาลูกค้า...">
      </div>
    </div>
  </aside>

  <!-- People + Chat -->
  <main class="d-grid gap-3">
    <div class="row g-3">
      <!-- รายชื่อผู้ใช้ -->
      <div class="col-lg-4">
        <div class="glass list" id="userList">
          <div class="px-3 py-2 border-bottom fw-semibold d-flex align-items-center justify-content-between">
            <span>ลูกค้าทั้งหมด</span>
            <span class="small text-muted"><?= count($users) ?> คน</span>
          </div>
          <?php foreach($users as $u): ?>
            <a class="user-item" href="?uid=<?= (int)$u['id'] ?>">
              <div class="flex-grow-1 ellipsis-1">@<?= h($u['username']??('UID '.$u['id'])) ?></div>
              <?php if(($u['unread']??0)>0): ?>
                <span class="badge bg-danger"><?= (int)$u['unread'] ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
          <?php if(empty($users)): ?>
            <div class="p-3 text-center text-muted">ยังไม่มีผู้ใช้</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ห้องแชท -->
      <div class="col-lg-8">
        <div class="glass chat">
          <div class="p-2 border-bottom d-flex align-items-center gap-2">
            <div class="fw-semibold">
              <?php if($active_uid>0): ?>
                คุยกับ @<?= h($cust_name) ?>
              <?php else: ?>
                เลือกผู้ใช้ทางซ้ายเพื่อเริ่มคุย
              <?php endif; ?>
              <span class="badge bg-secondary ms-2"><i class="bi bi-person-badge"></i> แอดมิน: <?= h($admin_name) ?></span>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
              <span class="small text-muted" id="status">—</span>
              <?php if($active_uid>0): ?>
                <button class="btn btn-sm btn-outline-danger" id="btnEnd">
                  <i class="bi bi-x-circle"></i> สิ้นสุดแชท
                </button>
              <?php endif; ?>
            </div>
          </div>

          <div id="msgs" class="msgs"></div>

          <form id="form" class="p-2 border-top d-flex gap-2 <?= $active_uid>0?'':'d-none' ?>">
            <input type="hidden" name="uid" value="<?=$active_uid?>">
            <input class="form-control" name="message" id="message" placeholder="พิมพ์ตอบกลับ…" required <?= $active_uid>0?'':'disabled' ?>>
            <button class="btn btn-primary" id="btnSend"><i class="bi bi-send"></i></button>
          </form>
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
    const cur = html.getAttribute('data-theme')==='dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', cur);
    localStorage.setItem('admin-theme', cur);
  });
})();

/* ===== filter users (client-side) ===== */
(function(){
  const inp = document.getElementById('userFilter');
  const list = document.getElementById('userList');
  if(!inp || !list) return;
  inp.addEventListener('input', ()=>{
    const q = (inp.value||'').toLowerCase();
    list.querySelectorAll('.user-item').forEach(a=>{
      const t = a.textContent.toLowerCase();
      a.style.display = t.includes(q) ? '' : 'none';
    });
  });
})();

/* ===== Chat logic (unchanged core, just UI) ===== */
const uid        = <?= (int)$active_uid ?>;
const adminName  = <?= json_encode($admin_name, JSON_UNESCAPED_UNICODE) ?>;
const box        = document.getElementById('msgs');
const form       = document.getElementById('form');
const inp        = document.getElementById('message');
const stat       = document.getElementById('status');
const btnEnd     = document.getElementById('btnEnd');
const btnSend    = document.getElementById('btnSend');

let lastId = 0;
let pollItv = null;

function scrollBottom(){ box.scrollTop = box.scrollHeight; }
function esc(s){ const d=document.createElement('div'); d.innerText=s??''; return d.innerHTML; }

function normalizeMsg(m){
  return {
    id: Number(m.id ?? m.msg_id ?? 0),
    sender: String(m.sender ?? (String(m.from_admin??'')==='1' ? 'admin' : 'user')),
    message: m.message ?? m.text ?? '',
    time: m.time ?? m.created_at ?? m.sent_at ?? ''
  };
}
function render(items){
  for(const raw of (items||[])){
    const m = normalizeMsg(raw);
    const wrap = document.createElement('div');
    const who  = (m.sender==='admin') ? ('แอดมิน: '+adminName) : 'ลูกค้า';
    wrap.innerHTML = `
      <div class="msg ${m.sender==='admin'?'me':'they'}">
        ${esc(m.message)}
        <div class="meta">${esc(who)} • ${esc(m.time)}</div>
      </div>`;
    box.appendChild(wrap.firstElementChild);
    lastId = Math.max(lastId, Number(m.id)||0);
  }
  if(items && items.length) scrollBottom();
}

async function poll(){
  if(!uid) return;
  try{
    const r = await fetch('support_admin_poll.php?uid='+uid+'&since='+lastId, {cache:'no-store'});
    const j = await r.json();
    render(j.items||[]);
    stat.textContent = 'ออนไลน์';
  }catch(_){
    stat.textContent = 'ออฟไลน์';
  }
}

form?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  if(!uid) return;
  const text = (inp.value||'').trim();
  if(!text) return;
  inp.value=''; inp.focus();
  await fetch('support_admin_send.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ uid:String(uid), message:text })
  });
  // ข้อความใหม่จะเข้ามารอบ poll ถัดไป
});

btnEnd?.addEventListener('click', async ()=>{
  if(!uid) return;
  if(!confirm('ยืนยันสิ้นสุดแชทนี้? ระบบจะลบประวัติแชททั้งหมดของผู้ใช้นี้')) return;
  try{
    const r = await fetch('../support_end_chat.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ uid:String(uid) })
    });
    const j = await r.json();
    if(j.ok){
      clearInterval(pollItv);
      if(inp) inp.disabled = true;
      if(btnSend) btnSend.disabled  = true;
      if(form) form.classList.add('d-none');
      box.innerHTML = `
        <div class="text-center text-muted" style="margin-top:8vh">
          <div class="mb-2"><i class="bi bi-check-circle text-success" style="font-size:2rem"></i></div>
          <div>สิ้นสุดแชทและลบประวัติเรียบร้อย</div>
          <div class="small mt-1">หากลูกค้าส่งข้อความใหม่ ระบบจะเปิดห้องแชทให้อัตโนมัติ</div>
        </div>`;
      stat.textContent = 'ปิดแล้ว';
    }else{
      alert('ไม่สามารถสิ้นสุดแชทได้: '+(j.error||'unknown'));
    }
  }catch(e){
    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
  }
});

// boot
if(uid){
  box.innerHTML = '<div class="p-3 text-center text-muted"></div>';
  poll();
  pollItv = setInterval(poll, 2500);
}else{
  box.innerHTML = `<div class="p-3 text-center text-muted">เลือกผู้ใช้จากด้านซ้ายเพื่อเริ่มคุย</div>`;
  stat.textContent = '—';
}

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
