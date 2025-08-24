<?php
// Home/admin/support.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  header('Location: ../login.php?redirect=admin/support.php'); exit;
}
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

// รายชื่อลูกค้าที่เคยคุย + นับค้างอ่าน
$users = $conn->query("
  SELECT u.id,u.username,
         SUM(CASE WHEN m.sender='user' AND m.is_read_by_admin=0 THEN 1 ELSE 0 END) AS unread
  FROM users u
  LEFT JOIN support_messages m ON m.user_id=u.id
  WHERE u.role='user'
  GROUP BY u.id
  HAVING COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END),0) > 0 OR 1=1
  ORDER BY unread DESC, u.username ASC
")->fetch_all(MYSQLI_ASSOC);

$active_uid = (int)($_GET['uid'] ?? ($users[0]['id'] ?? 0));
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
  </style>
</head>
<body class="container-fluid py-4">
  <div class="row g-3">
    <div class="col-lg-3">
      <div class="list p-2">
        <div class="px-2 py-2 fw-semibold border-bottom">ลูกค้าทั้งหมด</div>
        <?php foreach($users as $u): ?>
          <a class="d-flex align-items-center justify-content-between px-2 py-2 text-decoration-none border-bottom" href="?uid=<?=$u['id']?>">
            <span>@<?=h($u['username']??('UID '.$u['id']))?></span>
            <?php if(($u['unread']??0)>0): ?><span class="badge bg-danger"><?=$u['unread']?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-9">
      <div class="chat">
        <div class="p-2 border-bottom bg-white d-flex align-items-center gap-2">
          <div class="fw-semibold">คุยกับ @<?=h($conn->query("SELECT username FROM users WHERE id={$active_uid}")->fetch_assoc()['username'] ?? ('UID '.$active_uid))?></div>
          <div class="small text-muted ms-auto" id="status">กำลังเชื่อมต่อ…</div>
        </div>
        <div id="msgs" class="msgs"></div>
        <form id="form" class="p-2 border-top bg-white d-flex gap-2">
          <input type="hidden" name="uid" value="<?=$active_uid?>">
          <input class="form-control" name="message" id="message" placeholder="พิมพ์ตอบกลับ…" required>
          <button class="btn btn-primary" id="btnSend"><i class="bi bi-send"></i></button>
        </form>
      </div>
    </div>
  </div>

<script>
const uid  = <?=$active_uid?>;
const box  = document.getElementById('msgs');
const form = document.getElementById('form');
const inp  = document.getElementById('message');
const stat = document.getElementById('status');
let lastId = 0;

function scrollBottom(){ box.scrollTop = box.scrollHeight; }

function render(items){
  for(const m of items){
    const el = document.createElement('div');
    el.innerHTML = `
      <div class="msg ${m.sender==='admin'?'me':'they'}">
        ${m.message.replaceAll('<','&lt;').replaceAll('>','&gt;')}
        <div class="meta">${m.time}</div>
      </div>`;
    box.appendChild(el.firstElementChild);
    lastId = Math.max(lastId, Number(m.id));
  }
  if(items.length) scrollBottom();
}

async function poll(){
  try{
    const res = await fetch('support_admin_poll.php?uid='+uid+'&since='+lastId, {cache:'no-store'});
    const data = await res.json();
    render(data.items||[]);
    stat.textContent = 'ออนไลน์';
  }catch{ stat.textContent = 'ออฟไลน์'; }
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = inp.value.trim();
  if(!text) return;
  const body = new URLSearchParams({ uid:String(uid), message:text });
  inp.value=''; inp.focus();
  await fetch('support_admin_send.php',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
});

poll(); setInterval(poll, 2500);
</script>
</body>
</html>
