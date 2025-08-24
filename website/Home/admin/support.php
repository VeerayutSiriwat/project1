<?php
// Home/admin/support.php
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
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>กล่องข้อความลูกค้า | Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7fafc}
    .list{border:1px solid #e9eef5;border-radius:14px;background:#fff;overflow:auto;height:70vh}
    .chat{border:1px solid #e9eef5;border-radius:14px;background:#fff;overflow:hidden;height:70vh;display:flex;flex-direction:column}
    .msgs{flex:1;overflow:auto;padding:16px;background:linear-gradient(180deg,#f9fbff,#fff)}
    .msg{max-width:80%;padding:10px 12px;border-radius:14px;margin-bottom:10px;box-shadow:0 4px 20px rgba(2,6,23,.06)}
    .me{margin-left:auto;background:#0d6efd;color:#fff;border-bottom-right-radius:4px}
    .they{margin-right:auto;background:#eef5ff;color:#0d47a1;border-bottom-left-radius:4px}
    .meta{font-size:12px;color:#6b7280;margin-top:2px}
    .ellipsis-1{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  </style>
</head>
<body class="container-fluid py-4">
  <div class="row g-3">
    <!-- รายชื่อผู้ใช้ -->
    <div class="col-lg-3">
      <div class="list p-2">
        <div class="px-2 py-2 fw-semibold border-bottom">ลูกค้าทั้งหมด</div>
        <?php foreach($users as $u): ?>
          <a class="d-flex align-items-center justify-content-between px-2 py-2 text-decoration-none border-bottom" href="?uid=<?=$u['id']?>">
            <span class="ellipsis-1">@<?=h($u['username']??('UID '.$u['id']))?></span>
            <?php if(($u['unread']??0)>0): ?><span class="badge bg-danger"><?=$u['unread']?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ห้องแชท -->
    <div class="col-lg-9">
      <div class="chat">
        <div class="p-2 border-bottom bg-white d-flex align-items-center gap-2">
          <div class="fw-semibold">
            คุยกับ @<?=h($cust_name)?>
            <span class="badge bg-secondary ms-2"><i class="bi bi-person-badge"></i> แอดมิน: <?=h($admin_name)?></span>
          </div>
          <div class="ms-auto d-flex align-items-center gap-2">
            <span class="small text-muted" id="status">กำลังเชื่อมต่อ…</span>
            <?php if($active_uid>0): ?>
              <button class="btn btn-sm btn-outline-danger" id="btnEnd">
                <i class="bi bi-x-circle"></i> สิ้นสุดแชท
              </button>
            <?php endif; ?>
          </div>
        </div>

        <div id="msgs" class="msgs"></div>

        <form id="form" class="p-2 border-top bg-white d-flex gap-2 <?= $active_uid>0?'':'d-none' ?>">
          <input type="hidden" name="uid" value="<?=$active_uid?>">
          <input class="form-control" name="message" id="message" placeholder="พิมพ์ตอบกลับ…" required <?= $active_uid>0?'':'disabled' ?>>
          <button class="btn btn-primary" id="btnSend"><i class="bi bi-send"></i></button>
        </form>
      </div>
    </div>
  </div>

<script>
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

function normalizeMsg(m){
  return {
    id: Number(m.id ?? m.msg_id ?? 0),
    sender: String(m.sender ?? (String(m.from_admin??'')==='1' ? 'admin' : 'user')),
    message: m.message ?? m.text ?? '',
    time: m.time ?? m.created_at ?? m.sent_at ?? ''
  };
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
  if(!confirm('ยืนยันสิ้นสุดการแชทของผู้ใช้รายนี้? ระบบจะลบประวัติแชทเก่าทั้งหมด')) return;
  try{
    const r = await fetch('../support_end_chat.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ uid:String(uid) })
    });
    const j = await r.json();
    if(j.ok){
      // ปิดการใช้งานอินพุต / หยุด poll / เคลียร์หน้าต่าง
      clearInterval(pollItv);
      if(inp) inp.disabled = true;
      if(btnSend) btnSend.disabled = true;
      if(form) form.classList.add('d-none');
      box.innerHTML = `
        <div class="text-center text-muted" style="margin-top:20vh">
          <div class="mb-2"><i class="bi bi-check-circle text-success" style="font-size:2rem"></i></div>
          <div>สิ้นสุดการแชทและลบประวัติเรียบร้อย</div>
          <div class="small mt-1">หากลูกค้าส่งข้อความใหม่ ระบบจะเปิดห้องแชทให้อัตโนมัติ</div>
        </div>`;
      stat.textContent = 'ปิดการสนทนาแล้ว';
    }else{
      alert('ไม่สามารถสิ้นสุดการแชทได้: '+(j.error||'unknown'));
    }
  }catch(e){
    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
  }
});

// boot
if(uid){
  poll();
  pollItv = setInterval(poll, 2500);
}else{
  box.innerHTML = `<div class="p-3 text-center text-muted">เลือกผู้ใช้จากด้านซ้ายเพื่อเริ่มคุย</div>`;
  stat.textContent = '—';
}
</script>
</body>
</html>
