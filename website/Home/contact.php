<?php
// Home/contact.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php?redirect=contact.php"); exit; }

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
$user_id = (int)$_SESSION['user_id'];

// แสดงชื่อผู้ใช้
$st = $conn->prepare("SELECT username FROM users WHERE id=?");
$st->bind_param("i",$user_id); $st->execute();
$username = $st->get_result()->fetch_assoc()['username'] ?? ('UID '.$user_id);
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ติดต่อเรา | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7fafc}
    .chat{border:1px solid #e9eef5;border-radius:16px;background:#fff;overflow:hidden}
    .head{padding:12px 16px;border-bottom:1px solid #eef2f6;background:#fbfdff}
    .msgs{height:58vh;overflow:auto;padding:16px;background:linear-gradient(180deg,#f9fbff,#fff)}
    .msg{max-width:80%;padding:10px 12px;border-radius:14px;margin-bottom:10px;box-shadow:0 4px 20px rgba(2,6,23,.06)}
    .me{margin-left:auto;background:#0d6efd;color:#fff;border-bottom-right-radius:4px}
    .they{margin-right:auto;background:#eef5ff;color:#0d47a1;border-bottom-left-radius:4px}
    .meta{font-size:12px;color:#6b7280;margin-top:2px}
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="p-4 bg-white rounded-4 border">
        <h3 class="mb-2">ติดต่อเรา</h3>
        <p class="text-muted mb-1"><i class="bi bi-envelope"></i> siriwat4932@gmail.com</p>
        <p class="text-muted mb-1"><i class="bi bi-telephone"></i> 09-349-44932 (ทุกวัน 09:00–18:00)</p>
        <p class="text-muted"><i class="bi bi-geo-alt"></i> Bangkok, Thailand</p>
        <hr>
        <p class="text-muted">พิมพ์ข้อความทางขวาเพื่อคุยกับแอดมินได้ทันที เมื่อแอดมินตอบกลับจะขึ้นในห้องนี้</p>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="chat">
        <div class="head d-flex align-items-center gap-2">
          <i class="bi bi-chat-dots text-primary"></i>
          <div><div class="fw-semibold">สนทนากับแอดมิน</div><div class="small text-muted">@<?=h($username)?></div></div>
          <div class="ms-auto small text-muted" id="status">กำลังเชื่อมต่อ…</div>
        </div>
        <div id="msgs" class="msgs"></div>
        <form id="form" class="p-2 border-top bg-white d-flex gap-2">
          <input class="form-control" name="message" id="message" placeholder="พิมพ์ข้อความ…" required>
          <button class="btn btn-primary" id="btnSend"><i class="bi bi-send"></i></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const box  = document.getElementById('msgs');
const form = document.getElementById('form');
const inp  = document.getElementById('message');
const stat = document.getElementById('status');

let lastId = 0;

function scrollBottom(){ box.scrollTop = box.scrollHeight; }

function render(items){
  for(const m of items){
    const wrap = document.createElement('div');
    wrap.innerHTML = `
      <div class="msg ${m.sender==='user'?'me':'they'}">
        ${m.message.replaceAll('<','&lt;').replaceAll('>','&gt;')}
        <div class="meta">${m.time}</div>
      </div>`;
    box.appendChild(wrap.firstElementChild);
    lastId = Math.max(lastId, Number(m.id));
  }
  if(items.length) scrollBottom();
}

async function poll(){
  try{
    const res = await fetch('support_poll.php?since='+lastId, {cache:'no-store'});
    const data = await res.json();
    render(data.items||[]);
    stat.textContent = 'ออนไลน์';
  }catch{ stat.textContent = 'ออฟไลน์ (จะพยายามต่อใหม่)'; }
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = inp.value.trim();
  if(!text) return;
  const body = new URLSearchParams({ message: text });
  inp.value=''; inp.focus();
  await fetch('support_send.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body
  });
  // ดันทันทีรอรอบ poll ต่อไป
});

poll(); setInterval(poll, 3000);
</script>
</body>
</html>
