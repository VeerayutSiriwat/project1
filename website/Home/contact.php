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
    .msg{max-width:80%;padding:10px 12px;border-radius:14px;margin-bottom:12px;box-shadow:0 4px 20px rgba(2,6,23,.06)}
    .me{margin-left:auto;background:#0d6efd;color:#fff;border-bottom-right-radius:4px}
    .they{margin-right:auto;background:#eef5ff;color:#0d47a1;border-bottom-left-radius:4px}
    .meta{font-size:12px;color:#6b7280;margin-top:4px}
    .name{font-weight:600;margin-bottom:2px}
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
          <div>
            <div class="fw-semibold">สนทนากับแอดมิน</div>
            <div class="small text-muted">@<?=h($username)?></div>
          </div>
          <div class="ms-auto d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-danger" id="btnEndChat" title="สิ้นสุดการแชทและลบประวัติ">
              <i class="bi bi-x-circle"></i> สิ้นสุดการแชท
            </button>
            <div class="small text-muted" id="status">กำลังเชื่อมต่อ…</div>
          </div>
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
/* ====== Chat (user side) with admin-name + end chat ====== */
const box   = document.getElementById('msgs');
const form  = document.getElementById('form');
const inp   = document.getElementById('message');
const stat  = document.getElementById('status');
const endBt = document.getElementById('btnEndChat');

const UID = <?= (int)$user_id ?>;

// endpoint แบบใหม่ (จะลองใช้ก่อน)
const API_NEW_POLL = 'support_thread_api.php?uid='+UID;
const API_NEW_SEND = 'support_user_send.php';
const API_END      = 'support_end_chat.php';

// endpoint แบบเดิม (fallback)
const API_OLD_POLL = 'support_poll.php';
const API_OLD_SEND = 'support_send.php';

let useNewApi = true;     // จะพยายามใช้แบบใหม่ก่อน
let lastId = 0;

function esc(s){ const d=document.createElement('div'); d.innerText=s??''; return d.innerHTML; }
function scrollBottom(){ box.scrollTop = box.scrollHeight; }

// แปลงรูปแบบข้อความจาก API ให้เหลือ key มาตรฐาน
function normalizeItems(items){
  return (items||[]).map(m=>{
    const id = Number(m.id ?? m.msg_id ?? 0);
    const from_admin = (m.from_admin!=null) ? String(m.from_admin)==='1' : (m.sender==='admin');
    const name = from_admin ? (m.admin_name || m.admin || 'ผู้ดูแล') : 'คุณ';
    const msg  = m.message ?? m.text ?? '';
    const time = m.created_at ?? m.time ?? m.sent_at ?? '';
    return { id, from_admin, name, msg, time };
  });
}

function render(items){
  const arr = normalizeItems(items);
  for(const m of arr){
    const bubble = document.createElement('div');
    bubble.className = 'msg ' + (m.from_admin ? 'they' : 'me'); // แอดมิน = ซ้าย, ผู้ใช้ = ขวา
    bubble.innerHTML = `
      <div class="name">${esc(m.name)}</div>
      ${esc(m.msg)}
      <div class="meta">${esc(m.time)}</div>
    `;
    box.appendChild(bubble);
    lastId = Math.max(lastId, m.id||0);
  }
  if(arr.length) scrollBottom();
}

async function poll(){
  try{
    let url = useNewApi ? `${API_NEW_POLL}&since=${lastId}` : `${API_OLD_POLL}?since=${lastId}`;
    const res = await fetch(url, {cache:'no-store'});
    if(!res.ok) throw new Error('poll fail');
    const data = await res.json();
    render(data.items || data.data || []);
    stat.textContent = 'ออนไลน์';
  }catch(e){
    // ถ้าแบบใหม่ล้มเหลว ลองถอยไปใช้ API เดิมอัตโนมัติ
    if(useNewApi){ useNewApi = false; setTimeout(poll, 300); return; }
    stat.textContent = 'ออฟไลน์ (กำลังพยายามเชื่อมต่อใหม่)';
  }
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = inp.value.trim();
  if(!text) return;
  inp.value=''; inp.focus();

  // ยิงส่งข้อความ: แบบใหม่ก่อน, ถ้าไม่สำเร็จถอยไปแบบเดิม
  const bodyNew = new URLSearchParams({ message: text });
  const bodyOld = new URLSearchParams({ message: text });

  try{
    let ok = false;
    if(useNewApi){
      const r = await fetch(API_NEW_SEND, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyNew });
      ok = r.ok;
      if(!ok) useNewApi=false; // ถ้าไม่โอเค สลับไปใช้เดิมครั้งต่อไป
    }
    if(!useNewApi){
      await fetch(API_OLD_SEND, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyOld });
    }
  }catch(_){ /* เงียบไว้ ให้รอรอบ poll ดึง */ }
});

// ปุ่มสิ้นสุดการแชท (ลบประวัติ + ปิดเธรด)
endBt.addEventListener('click', async ()=>{
  if(!confirm('ยืนยันสิ้นสุดการแชทและลบข้อความเก่าทั้งหมด?')) return;
  try{
    const r = await fetch(API_END, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'x=1' // user ฝั่งนี้ไม่ต้องส่ง uid
    });
    const j = await r.json().catch(()=>({}));
    if(j && j.ok){
      box.innerHTML = '<div class="text-center text-muted my-3">แชทถูกสิ้นสุดแล้ว คุณสามารถเริ่มใหม่ได้ทุกเมื่อ</div>';
      lastId = 0;
    }else{
      alert('ไม่สามารถสิ้นสุดการแชทได้');
    }
  }catch(_){
    alert('ไม่สามารถสิ้นสุดการแชทได้');
  }
});

// boot
poll(); setInterval(poll, 3000);
</script>
</body>
</html>
