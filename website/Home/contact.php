<?php
// Home/contact.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$user_id    = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

// แสดงชื่อผู้ใช้ (ถ้าล็อกอิน)
$username = 'ผู้เยี่ยมชม';
if ($isLoggedIn) {
  $st = $conn->prepare("SELECT username FROM users WHERE id=?");
  $st->bind_param("i",$user_id); $st->execute();
  $username = $st->get_result()->fetch_assoc()['username'] ?? ('UID '.$user_id);
  $st->close();
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ติดต่อเรา | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body{background:#f7fafc}
    .chat{border:1px solid #e9eef5;border-radius:16px;background:#fff;overflow:hidden}
    .head{padding:12px 16px;border-bottom:1px solid #eef2f6;background:#fbfdff}
    .msgs{height:58vh;overflow:auto;padding:16px;background:linear-gradient(180deg,#f9fbff,#fff)}
    .msg{max-width:80%;padding:10px 12px;border-radius:14px;margin-bottom:12px;box-shadow:0 4px 20px rgba(2,6,23,.06)}
    .me{margin-left:auto;background:#4f46e5; color:#fff;border-bottom-right-radius:4px}
    .they{margin-right:auto;background:#eef5ff;color:#0d47a1;border-bottom-left-radius:4px}
    .meta{font-size:12px;color:#6b7280;margin-top:4px}
    .name{font-weight:600;margin-bottom:2px}
    .locked{position:relative}
    .locked::after{
      content:"";
      position:absolute; inset:0; background:rgba(255,255,255,.7); pointer-events:none;
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <div class="row g-4">
    <!-- กล่องข้อมูลติดต่อ -->
    <div class="col-lg-5">
      <div class="p-4 bg-white rounded-4 border">
        <h3 class="mb-2">ติดต่อเรา</h3>
        <p class="text-muted mb-1"><i class="bi bi-envelope"></i> 6521207026@bsru.ac.th</p>
        <p class="text-muted mb-1"><i class="bi bi-telephone"></i> 09-349-44932 ( จันทร์–เสาร์ 09:00–18:00 )</p>
        <p class="text-muted"><i class="bi bi-geo-alt"></i> 99/12 ศิริสุข ซอย 1 แขวงหลักสอง บางแค กรุงเทพมหานคร 10160</p>
        <hr>
        <p class="text-muted mb-0">
          <br>
        </p>
        <!-- Google Map -->
        <div class="ratio ratio-16x9">
      <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d288.1230921335063!2d100.40585904904144!3d13.687726656701468!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x30e2bd7b4cf4013d%3A0xd3a550f1832ebf97!2zOTkvMTIg4Lio4Li04Lij4Li04Liq4Li44LiCIOC4i-C4reC4oiAxIOC5geC4guC4p-C4h-C4q-C4peC4seC4geC4quC4reC4hyDguJrguLLguIfguYHguIQg4LiB4Lij4Li44LiH4LmA4LiX4Lie4Lih4Lir4Liy4LiZ4LiE4LijIDEwMTYw!5e0!3m2!1sth!2sth!4v1756816775150!5m2!1sth!2sth" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
      </div>
    </div>
    
    <!-- กล่องแชท -->
    <div class="col-lg-7">
      <div class="chat">
        <div class="head d-flex align-items-center gap-2">
          <i class="bi bi-chat-dots text-primary"></i>
          <div>
            <div class="fw-semibold">สนทนากับแอดมิน</div>
            <div class="small text-muted">@<?=h($username)?></div>
          </div>

          <div class="ms-auto d-flex align-items-center gap-2">
            <?php if ($isLoggedIn): ?>
              <button class="btn btn-sm btn-outline-danger" id="btnEndChat" title="สิ้นสุดการแชทและลบประวัติ">
                <i class="bi bi-x-circle"></i> สิ้นสุดการแชท
              </button>
            <?php endif; ?>
            <div class="small text-muted" id="status"><?= $isLoggedIn ? 'กำลังเชื่อมต่อ…' : 'เข้าสู่ระบบเพื่อส่งข้อความ' ?></div>
          </div>
        </div>

        <div id="msgs" class="msgs">
          <?php if (!$isLoggedIn): ?>
            <div class="text-center text-muted my-3">
              เข้าสู่ระบบเพื่อเริ่มต้นสนทนา — คุณยังสามารถดูหน้านี้ได้ตามปกติ
            </div>
          <?php endif; ?>
        </div>

        <div class="p-2 border-top bg-white">
          <?php if ($isLoggedIn): ?>
            <form id="form" class="d-flex gap-2">
              <input class="form-control" name="message" id="message" placeholder="พิมพ์ข้อความ…" required>
              <button class="btn btn-primary" id="btnSend"><i class="bi bi-send"></i></button>
            </form>
          <?php else: ?>
            <div class="d-grid d-md-flex gap-2 align-items-center">
              <input class="form-control locked" value="ต้องเข้าสู่ระบบก่อนจึงจะส่งข้อความได้" disabled>
              <a class="btn btn-primary" href="login.php?redirect=contact.php"><i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<?php if ($isLoggedIn): ?>
<script>
/* ====== Chat (เฉพาะเมื่อเข้าสู่ระบบ) ====== */
const box   = document.getElementById('msgs');
const form  = document.getElementById('form');
const inp   = document.getElementById('message');
const stat  = document.getElementById('status');
const endBt = document.getElementById('btnEndChat');

const UID = <?= (int)$user_id ?>;

// endpoint (ใหม่ก่อน, มี fallback)
const API_NEW_POLL = 'support_thread_api.php?uid='+UID;
const API_NEW_SEND = 'support_user_send.php';
const API_END      = 'support_end_chat.php';
const API_OLD_POLL = 'support_poll.php';
const API_OLD_SEND = 'support_send.php';

let useNewApi = true;
let lastId = 0;

function esc(s){ const d=document.createElement('div'); d.innerText=s??''; return d.innerHTML; }
function scrollBottom(){ box.scrollTop = box.scrollHeight; }

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
    bubble.className = 'msg ' + (m.from_admin ? 'they' : 'me');
    bubble.innerHTML = `<div class="name">${esc(m.name)}</div>${esc(m.msg)}<div class="meta">${esc(m.time)}</div>`;
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
    if(useNewApi){ useNewApi = false; setTimeout(poll, 300); return; }
    stat.textContent = 'ออฟไลน์ (กำลังพยายามเชื่อมต่อใหม่)';
  }
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = inp.value.trim();
  if(!text) return;
  inp.value=''; inp.focus();

  const bodyNew = new URLSearchParams({ message: text });
  const bodyOld = new URLSearchParams({ message: text });

  try{
    let ok = false;
    if(useNewApi){
      const r = await fetch(API_NEW_SEND, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyNew });
      ok = r.ok;
      if(!ok) useNewApi=false;
    }
    if(!useNewApi){
      await fetch(API_OLD_SEND, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyOld });
    }
  }catch(_){}
});

endBt?.addEventListener('click', async ()=>{
  if(!confirm('ยืนยันสิ้นสุดการแชทและลบข้อความเก่าทั้งหมด?')) return;
  try{
    const r = await fetch(API_END,{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'x=1' });
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

poll(); setInterval(poll, 3000);
</script>
<?php endif; ?>
</body>
</html>
