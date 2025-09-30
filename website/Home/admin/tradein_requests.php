<?php
// Home/admin/tradein_requests.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/tradein_requests.php'); exit;
}

/* ===== admin name ===== */
$admin_name = 'admin';
if (!empty($_SESSION['user_id'])) {
  if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
    $st->bind_param('i', $_SESSION['user_id']);
    $st->execute();
    $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
    $st->close();
  }
}

$noti_unread = 0;
$uid = (int)$_SESSION['user_id'];
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $uid); $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

/* ===== helpers ===== */
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function imgsrc($v){
  $v = trim((string)$v);
  if ($v==='') return '../assets/img/no-image.png';
  if (preg_match('~^(https?://|data:image/)~',$v)) return $v;
  // ถ้าเป็นพาธอยู่แล้ว ต้องชี้จากโฟลเดอร์ /admin ออกไป 1 ระดับ
  return (strpos($v,'/')!==false) ? '../'.$v : '../assets/img/'.$v;
}

$statusMap = [
  'submitted'=>'ส่งคำขอแล้ว','reviewing'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
  'accepted'=>'ยอมรับข้อเสนอ','rejected'=>'ปฏิเสธข้อเสนอ',
  'cancelled'=>'ยกเลิก','completed'=>'เสร็จสิ้น'
];
$statusBadge = [
  'submitted'=>'secondary','reviewing'=>'info',
  'offered'=>'primary','accepted'=>'success',
  'rejected'=>'danger','cancelled'=>'danger','completed'=>'success'
];
$needMap = [
  'buy_new' => 'เทิร์นเป็นส่วนลดซื้อใหม่',
  'cash'    => 'ขายรับเงินสด'
];

/* ===== filters ===== */
$q            = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where  = " WHERE 1=1 ";
$params = []; $types = '';

if ($q !== '') {
  // อนุญาตค้นหา id ตรง ๆ หรือ คำในประเภท/ยี่ห้อ/รุ่น
  $where .= " AND (tr.id = ? OR tr.brand LIKE CONCAT('%',?,'%') OR tr.model LIKE CONCAT('%',?,'%') OR tr.device_type LIKE CONCAT('%',?,'%'))";
  $types .= 'isss';
  $params[] = (int)$q; $params[]=$q; $params[]=$q; $params[]=$q;
}
if ($statusFilter !== '') {
  $where .= " AND tr.status = ? ";
  $types .= 's';
  $params[] = $statusFilter;
}

/* ===== list data ===== */
$sql = "
  SELECT tr.*,
         /* ถ้ามี image_path ใช้เลย ไม่งั้นลองหยิบรูปปกจาก tradein_images */
         COALESCE(NULLIF(tr.image_path,''), CONCAT('assets/img/',(
            SELECT ti.filename FROM tradein_images ti
            WHERE ti.request_id=tr.id AND ti.is_cover=1 LIMIT 1
         ))) AS cover_path
  FROM tradein_requests tr
  $where
  ORDER BY tr.updated_at DESC, tr.id DESC
  LIMIT 200
";
$rows=[];
if ($stmt = $conn->prepare($sql)){
  if($types!==''){ $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ===== quick counters (ทั้งหมด) ===== */
$counts = array_fill_keys(array_keys($statusMap), 0);
$res = $conn->query("SELECT status, COUNT(*) c FROM tradein_requests GROUP BY status");
if($res){ while($r=$res->fetch_assoc()){ $counts[$r['status']] = (int)$r['c']; } }
$totalAll = array_sum($counts);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Trade-in Requests | Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root{ --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b; --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06); }
  html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.75); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45); }
  body{background:var(--bg); color:var(--text);}
  .topbar{backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border);}
  html[data-theme="dark"] .topbar{background:linear-gradient(180deg,#0f172acc,#0f172aa6)}
  .app{display:grid; grid-template-columns:260px 1fr; gap:24px}
  @media(max-width:991.98px){ .app{grid-template-columns:1fr} .sidebar{position:static} }
  .sidebar{position:sticky; top:90px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden}
  .side-a{display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent}
  .side-a:hover{background:#f6f9ff} html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
  .side-a.active{background:#eef5ff; border-left-color:var(--primary)} html[data-theme="dark"] .side-a.active{background:#0e1f3e}
  .glass{border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow)}
  .chip{display:inline-flex; align-items:center; gap:6px; background:#eaf2ff; border:1px solid #cfe1ff; padding:4px 10px; border-radius:999px; font-weight:600; font-size:.85rem}
  html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }
  .table> :not(caption)>*>*{border-color:var(--border)}
  .thumb{width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid var(--border);background:#fff}
  /* Dark mode: input ให้กลืนกับพื้นหลัง */
html[data-theme="dark"] .form-control,
html[data-theme="dark"] .form-select {
  background:#0f172a;
  border-color:#1f2a44;
  color:#e5e7eb;
}
html[data-theme="dark"] .form-control::placeholder{
  color:#94a3b8;
}

/* ชิปสถานะให้คลิกได้ + โชว์ active */
.chip-link{ text-decoration:none; color:inherit; }
.chip.active{ background:#dbeafe; border-color:#93c5fd; }
html[data-theme="dark"] .chip.active{ background:#0e1f3e; border-color:#1d4ed8; }

.chip-reset{
  background:#fee2e2; border-color:#fecaca; color:#991b1b;
}
html[data-theme="dark"] .chip-reset{
  background:#2a0f15; border-color:#7f1d1d; color:#fecaca;
}

</style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">Trade-in • สวัสดี, <?= h($admin_name) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <form class="d-none d-md-flex" role="search" action="tradein_requests.php" method="get">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" type="search" class="form-control" placeholder="ค้นหาเลข TR / รุ่น / ยี่ห้อ" value="<?= h($q) ?>">
          <?php if($statusFilter!==''): ?><input type="hidden" name="status" value="<?= h($statusFilter) ?>"><?php endif; ?>
        </div>
      </form>
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
      <a class="side-a" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> Orders</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> Products</a>
      <a class="side-a active" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> Trade-in</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> Service</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> Users</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
    <div class="p-3 border-top">
      <div class="chip"><i class="bi bi-lightning-charge-fill me-1"></i> Quick actions</div>
      <div class="mt-2 d-grid gap-2">
        <a class="btn btn-sm btn-primary" href="product_add.php"><i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้า</a>
        <a class="btn btn-sm btn-outline-primary" href="products.php?status=inactive"><i class="bi bi-eye-slash me-1"></i> สินค้าที่ซ่อน</a>
        <a class="btn btn-sm btn-outline-primary" href="categories.php"><i class="bi bi-tags me-1"></i> จัดการหมวดหมู่</a>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">

        <!-- Filters -->
        <div class="glass p-3">
        <form class="row g-2" action="tradein_requests.php" method="get">
            <div class="col-sm-6 col-lg-4">
            <label class="form-label">ค้นหา (ID / รุ่น / ยี่ห้อ / ประเภท)</label>
            <input class="form-control" name="q" value="<?=h($q)?>" placeholder="เช่น 12 หรือ 'mama'">
            </div>
            <div class="col-sm-4 col-lg-3">
            <label class="form-label">สถานะ</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">— ทั้งหมด —</option>
                <?php foreach($statusMap as $k=>$v): ?>
                <option value="<?=h($k)?>" <?= $k===$statusFilter?'selected':'' ?>><?=h($v)?></option>
                <?php endforeach; ?>
            </select>
            </div>
            <div class="col-sm-2 col-lg-2 d-flex align-items-end">
            <button class="btn btn-primary w-100"><i class="bi bi-search"></i> ค้นหา</button>
            </div>
        </form>

        <!-- Quick chips (กดกรองเร็ว) -->
        <div class="d-flex flex-wrap gap-2 mt-3 small">
            <?php
            // ลิงก์ช่วยสร้าง URL พร้อม q เดิม
            $mk = function($params){ 
                return 'tradein_requests.php?'.http_build_query(array_filter([
                'q'=>$GLOBALS['q'] ?: null,
                'status'=>$params['status'] ?? null,
                ], fn($v)=>$v!==null));
            };
            ?>
            <a class="chip chip-link <?= $statusFilter===''?'active':'' ?>" href="<?= $mk(['status'=>null]) ?>">
            ทั้งหมด: <b><?= number_format($totalAll) ?></b>
            </a>
            <?php foreach($statusMap as $k=>$v): ?>
            <a class="chip chip-link <?= $statusFilter===$k?'active':'' ?>" href="<?= $mk(['status'=>$k]) ?>">
                <?= h($v) ?>: <b><?= number_format($counts[$k] ?? 0) ?></b>
            </a>
            <?php endforeach; ?>

            <?php if($statusFilter!==''): ?>
            <a class="chip chip-link chip-reset ms-auto" href="<?= $mk(['status'=>null]) ?>">
                <i class="bi bi-x-circle me-1"></i> ล้างตัวกรอง
            </a>
            <?php endif; ?>
        </div>
        </div>

    <!-- Table -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-arrow-left-right me-2"></i>รายการเทิร์น</div>
        <div class="text-muted small">อัปเดตล่าสุดก่อน</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>รูป</th>
              <th>อุปกรณ์</th>
              <th>ความต้องการ</th>
              <th>ราคาเสนอ</th>
              <th>สถานะ</th>
              <th>อัปเดตล่าสุด</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($rows)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>
          <?php else: foreach($rows as $r): 
            $thumb = imgsrc($r['cover_path'] ?: $r['image_path']);
          ?>
            <tr id="row-<?= (int)$r['id'] ?>">
              <td>TR-<?= (int)$r['id'] ?></td>
              <td><img src="<?=h($thumb)?>" class="thumb" alt=""></td>
              <td class="fw-semibold"><?=h($r['device_type'])?> — <?=h($r['brand'])?> <?=h($r['model'])?></td>
              <td>
                <span class="badge bg-secondary">
                  <?= h($needMap[$r['need']] ?? $r['need'] ?? '-') ?>
                </span>
              </td>
              <td><?= $r['offer_price']!==null ? number_format((float)$r['offer_price'],2).' ฿' : '-' ?></td>
              <td><span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>"><?=h($statusMap[$r['status']] ?? $r['status'])?></span></td>
              <td class="text-muted small"><?=h($r['updated_at'])?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="tradein_detail.php?id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-pencil-square"></i> จัดการ
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Theme toggle (remember)
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
