<?php
/* File: Home/admin/products.php (with theme toggle + topbar quick search + thumbnails) */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.php?redirect=admin/products.php"); exit;
}
require __DIR__ . '/../includes/db.php';

/* admin name (greeting) */
$admin_name = 'admin';
if (!empty($_SESSION['user_id'])) {
  $uid = (int)$_SESSION['user_id'];
  if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
    $st->bind_param('i',$uid); $st->execute();
    $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
    $st->close();
  }
}

/* CSRF token (delete btn) */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

/* ===== Filters (same logic) ===== */
$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? 'all';
$allowed_status = ['all','active','inactive'];
if (!in_array($status, $allowed_status, true)) $status = 'all';

$cats_rs = $conn->query("SELECT id,name FROM categories ORDER BY name ASC");
$cats = $cats_rs ? $cats_rs->fetch_all(MYSQLI_ASSOC) : [];
$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

/* Pagination */
$per_page = 10; $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $per_page;

$noti_unread = 0;
$uid = (int)$_SESSION['user_id'];
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $uid); $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

/* Count */
$count_sql = "SELECT COUNT(*) AS c FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE 1=1";
$c_params=[]; $c_types="";
if ($status !== 'all'){ $count_sql.=" AND p.status=?";       $c_params[]=$status; $c_types.="s"; }
if ($cat_id > 0)      { $count_sql.=" AND p.category_id=?";  $c_params[]=$cat_id; $c_types.="i"; }
if ($q !== '') {
  if (ctype_digit($q)) {
    // ถ้ากรอกเป็นตัวเลขล้วน ให้ค้นหาด้วย id
    $count_sql .= " AND p.id = ?";
    $c_params[] = (int)$q;
    $c_types .= "i";
  } else {
    // ถ้าเป็นข้อความ ค้นหาชื่อสินค้า
    $count_sql .= " AND (p.name LIKE ?)";
    $c_params[] = "%{$q}%";
    $c_types .= "s";
  }
}
$stc=$conn->prepare($count_sql); if($c_params){$stc->bind_param($c_types,...$c_params);} $stc->execute();
$total_rows=(int)($stc->get_result()->fetch_assoc()['c'] ?? 0); $stc->close();
$total_pages=max(1,(int)ceil($total_rows/$per_page)); if($page>$total_pages){$page=$total_pages;$offset=($page-1)*$per_page;}

/* Query rows: ✅ เพิ่ม p.image */
$sql="SELECT p.id,p.name,p.price,p.discount_price,p.stock,p.status,p.image, c.name AS category_name
      FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE 1=1";
$params=[]; $types="";
if ($status !== 'all'){ $sql.=" AND p.status=?";      $params[]=$status; $types.="s"; }
if ($cat_id > 0)      { $sql.=" AND p.category_id=?"; $params[]=$cat_id;  $types.="i"; }
if ($q !== '') {
  if (ctype_digit($q)) {
    $sql .= " AND p.id = ?";
    $params[] = (int)$q;
    $types .= "i";
  } else {
    $sql .= " AND (p.name LIKE ?)";
    $params[] = "%{$q}%";
    $types .= "s";
  }
}

$sql.=" ORDER BY p.id DESC LIMIT ? OFFSET ?"; $params[]=$per_page; $types.="i"; $params[]=$offset; $types.="i";
$stmt=$conn->prepare($sql); if($params){$stmt->bind_param($types,...$params);} $stmt->execute(); $res=$stmt->get_result();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }
function page_link($p){ $qs=$_GET; $qs['page']=$p; return 'products.php?'.http_build_query($qs); }

?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Products | Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b; --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06);}
    html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.7); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45);}
    body{background:var(--bg); color:var(--text);}
    .topbar{backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border);}
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }
    .app{display:grid; grid-template-columns:260px 1fr; gap:24px;} @media(max-width:991.98px){ .app{grid-template-columns:1fr} .sidebar{position:static} }
    .sidebar{position:sticky; top:90px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden;}
    .side-a{display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent;}
    .side-a:hover{background:#f6f9ff} html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
    .side-a.active{background:#eef5ff; border-left-color:var(--primary)} html[data-theme="dark"] .side-a.active{background:#0e1f3e}
    .glass{border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow);}
    .chip{display:inline-flex; align-items:center; gap:6px; background:#eaf2ff; border:1px solid #cfe1ff; padding:4px 10px; border-radius:999px; font-weight:600; font-size:.85rem;}
    html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }
    .table> :not(caption)>*>*{border-color:var(--border)}
    .badge-status{font-weight:600}

    /* ✅ thumbnail */
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

<!-- Topbar with quick search + theme toggle -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">Products • สวัสดี, <?= h($admin_name) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <form class="d-none d-md-flex" role="search" action="products.php" method="get">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" type="search" class="form-control" placeholder="ค้นหา ID หรือชื่อสินค้า…" value="<?= h($q) ?>">
          <input type="hidden" name="status" value="<?= h($status) ?>">
          <input type="hidden" name="cat" value="<?= (int)$cat_id ?>">
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
      <a class="side-a" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> แดชบอร์ด</a>
      <a class="side-a" href="sales_summary.php"><i class="bi bi-graph-up-arrow me-2"></i> สรุปยอดขาย</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> ออเดอร์</a>
      <a class="side-a active" href="products.php"><i class="bi bi-box-seam me-2"></i> สินค้า</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> เทิร์นสินค้า</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> งานซ่อม</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> ผู้ใช้</a>
      <a class="side-a" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> คูปอง</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
    <div class="p-3 border-top">
      <div class="chip"><i class="bi bi-lightning-charge-fill me-1"></i> Quick actions</div>
      <div class="mt-2 d-grid gap-2">
        <a class="btn btn-sm btn-primary" href="product_add.php"><i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้า</a>
        <a class="btn btn-sm btn-outline-primary" href="products.php?status=inactive"><i class="bi bi-eye-slash me-1"></i> ที่ซ่อนไว้</a>
        <a class="btn btn-sm btn-outline-primary" href="categories.php"><i class="bi bi-tags me-1"></i> จัดการหมวดหมู่</a>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-light border" onclick="history.back()">
          <i class="bi bi-arrow-left"></i> ย้อนกลับ
        </button>
        <h3 class="mb-0"><i class="bi bi-box-seam"></i> รายการสินค้า</h3>
      </div>
      <a href="product_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> เพิ่มสินค้า</a>
    </div>

    <!-- Flash -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="glass p-3">
      <form class="row g-2 align-items-center" method="get">
        <div class="col-12 col-md-3">
          <input type="text" class="form-control" name="q" placeholder="ค้นหา ID หรือชื่อสินค้า…" value="<?= h($q) ?>">
        </div>
        <div class="col-6 col-md-3">
          <select name="status" class="form-select">
            <option value="all"      <?= $status==='all'?'selected':'' ?>>สถานะทั้งหมด</option>
            <option value="active"   <?= $status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <select name="cat" class="form-select">
            <option value="0">ทุกหมวดหมู่</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $cat_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3 d-grid d-md-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-funnel"></i> กรอง</button>
          <a class="btn btn-outline-secondary" href="products.php"><i class="bi bi-x-circle"></i> ล้างตัวกรอง</a>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="glass">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>เลขสินค้า</th>
              <th>ชื่อสินค้า</th>
              <th>หมวดหมู่</th>
              <th class="text-end">ราคา</th>
              <th class="text-end">สต็อก</th>
              <th>สถานะ</th>
              <th style="width:210px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($res && $res->num_rows > 0): while($row=$res->fetch_assoc()): ?>
            <?php
              // ✅ กำหนด path รูป (รองรับทั้งเก็บเป็นชื่อไฟล์ หรือเก็บเป็น path)
              $img = trim((string)($row['image'] ?? ''));
              if ($img === '') {
                $src = '../assets/img/default.png';
              } else {
                // ถ้าเป็น URL เต็มหรือระบุโฟลเดอร์มาแล้ว ใช้ต่อหน้า ../
                if (preg_match('~^https?://~i', $img) || preg_match('~^(assets/|uploads/)~', $img)) {
                  $src = '../' . ltrim($img, '/');
                } else {
                  // ชื่อไฟล์อย่างเดียว -> ชี้ไปโฟลเดอร์สินค้าทั่วไป
                  $src = '../assets/img/' . $img;
                }
              }
            ?>
            <tr>
              <td>PRD<?=(int)$row['id'] ?></td>

              <!-- ✅ แสดง thumbnail ในคอลัมน์ชื่อสินค้า -->
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img src="<?= h($src) ?>" alt="thumb" class="thumb">
                  <div class="fw-semibold"><?= h($row['name']) ?></div>
                </div>
              </td>

              <td><?= h($row['category_name'] ?? '-') ?></td>

              <td class="text-end">
                <?php if (!empty($row['discount_price']) && $row['discount_price'] < $row['price']): ?>
                  <span class="text-muted text-decoration-line-through me-1"><?= baht($row['price']) ?></span>
                  <span class="fw-bold text-danger"><?= baht($row['discount_price']) ?></span>
                <?php else: ?><span class="fw-bold"><?= baht($row['price']) ?></span><?php endif; ?> ฿
              </td>

              <td class="text-end"><?= (int)$row['stock'] ?></td>

              <td>
                <?php $isActive = ($row['status']==='active'); ?>
                <span class="badge badge-status bg-<?= $isActive?'success':'secondary' ?>"><?= $isActive?'active':'inactive' ?></span>
              </td>

              <td>
                <div class="d-flex gap-2 flex-wrap">
                  <a class="btn btn-sm btn-outline-primary" href="../product.php?id=<?= (int)$row['id'] ?>" target="_blank">
                    <i class="bi bi-eye"></i> ดูหน้าเว็บ
                  </a>
                  <a class="btn btn-sm btn-outline-secondary" href="product_edit.php?id=<?= (int)$row['id'] ?>">
                    <i class="bi bi-pencil-square"></i> แก้ไข
                  </a>
                  <form method="post" action="product_delete.php" onsubmit="return confirm('ยืนยันลบสินค้า #<?= (int)$row['id'] ?> ?');" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> ลบ</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีสินค้า</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <nav>
        <ul class="pagination justify-content-center flex-wrap">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $page<=1?'#':h(page_link($page-1)) ?>">ก่อนหน้า</a>
          </li>
          <?php
            $window=2; $start_p=max(1,$page-$window); $end_p=min($total_pages,$page+$window);
            if ($start_p>1){ echo '<li class="page-item"><a class="page-link" href="'.h(page_link(1)).'">1</a></li>'; if ($start_p>2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            for($p=$start_p;$p<=$end_p;$p++){ echo '<li class="page-item '.($p==$page?'active':'').'"><a class="page-link" href="'.h(page_link($p)).'">'.$p.'</a></li>'; }
            if ($end_p<$total_pages){ if ($end_p<$total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="'.h(page_link($total_pages)).'">'.$total_pages.'</a></li>'; }
          ?>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
            <a class="page-link" href="<?= $page>=$total_pages?'#':h(page_link($page+1)) ?>">ถัดไป</a>
          </li>
        </ul>
        <div class="text-center text-muted small">หน้า <?= $page ?> / <?= $total_pages ?> • ทั้งหมด <?= $total_rows ?> รายการ</div>
      </nav>
    <?php endif; ?>

  </main>
</div>

<!-- Back-to-top -->
<button type="button" class="btn btn-primary position-fixed" style="right:16px; bottom:16px; display:none;" id="btnTop">
  <i class="bi bi-arrow-up"></i>
</button>

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

  // Back to top
  const btnTop = document.getElementById('btnTop');
  window.addEventListener('scroll', ()=>{ btnTop.style.display = (window.scrollY > 300) ? 'block' : 'none'; });
  btnTop.addEventListener('click', ()=> window.scrollTo({top:0, behavior:'smooth'}));
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
