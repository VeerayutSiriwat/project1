<?php
// Home/admin/service_tickets.php (glass UI + status/urgency + payment column)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';

// admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/service_tickets.php'); exit;
}
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

/* ---- admin name (greeting) ---- */
$admin_name='admin';
if(!empty($_SESSION['user_id'])){
  $uid=(int)$_SESSION['user_id'];
  if($st=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
    $st->bind_param('i',$uid); $st->execute();
    $admin_name=$st->get_result()->fetch_assoc()['username'] ?? 'admin';
    $st->close();
  }
}

/* ---- filters ---- */
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? 'all');     // all | queued | checking | waiting_parts | repairing | done | cancelled | confirm
$urgency = trim($_GET['urgency'] ?? 'all');    // all | normal | urgent
$pay     = trim($_GET['pay'] ?? 'all');        // all | unpaid | pending | paid
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to']   ?? '');

/* ---- pagination ---- */
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page-1)*$per_page;

$noti_unread = 0;
$uid = (int)$_SESSION['user_id'];
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $uid); $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

/* ===== base derived table (คำนวณสถานะจาก log และ normalize เร่งด่วน) =====
   - status_eff  : สถานะจาก log ล่าสุด (fallback เป็น service_tickets.status)
   - urgency_eff : แปลงค่าเร่งด่วนให้เหลือ normal/urgent เพื่อให้ filter ตรง
*/
$BASE_FROM = "
  FROM (
    SELECT
      st.*,
      COALESCE(
        (SELECT l.status FROM service_status_logs l
         WHERE l.ticket_id = st.id
         ORDER BY l.id DESC
         LIMIT 1),
        st.status
      ) AS status_eff,
      CASE
        WHEN LOWER(TRIM(st.urgency)) IN ('urgent','ด่วน','เร่งด่วน','1','true') THEN 'urgent'
        ELSE 'normal'
      END AS urgency_eff
    FROM service_tickets st
  ) S
  WHERE 1=1
";

/* ---- count ---- */
$count_sql = "SELECT COUNT(*) c ".$BASE_FROM;
$types=''; $params=[];
if($q!==''){
  $count_sql.=" AND (S.id=? OR S.phone LIKE ? OR S.device_type LIKE ? OR S.brand LIKE ? OR S.model LIKE ?)";
  $idq=(int)preg_replace('/\D/','',$q); $like="%$q%";
  $params[]=$idq;  $types.='i';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
}
if($status!=='all'){   $count_sql.=" AND S.status_eff=?";   $params[]=$status;  $types.='s'; }
if($urgency!=='all'){  $count_sql.=" AND S.urgency_eff=?";  $params[]=$urgency; $types.='s'; }
if($pay!=='all'){      $count_sql.=" AND COALESCE(S.payment_status,'unpaid')=?"; $params[]=$pay; $types.='s'; }
if($from!==''){        $count_sql.=" AND DATE(S.created_at)>=?"; $params[]=$from; $types.='s'; }
if($to!==''){          $count_sql.=" AND DATE(S.created_at)<=?"; $params[]=$to;   $types.='s'; }
$st=$conn->prepare($count_sql); if($params){$st->bind_param($types, ...$params);} $st->execute();
$total_rows=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
$total_pages=max(1,(int)ceil($total_rows/$per_page));
if($page>$total_pages){ $page=$total_pages; $offset=($page-1)*$per_page; }

/* ---- fetch ---- */
$sql = "SELECT * ".$BASE_FROM;
$types=''; $params=[];
if($q!==''){
  $sql.=" AND (S.id=? OR S.phone LIKE ? OR S.device_type LIKE ? OR S.brand LIKE ? OR S.model LIKE ?)";
  $idq=(int)preg_replace('/\D/','',$q); $like="%$q%";
  $params[]=$idq;  $types.='i';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
  $params[]=$like; $types.='s';
}
if($status!=='all'){   $sql.=" AND S.status_eff=?";   $params[]=$status;  $types.='s'; }
if($urgency!=='all'){  $sql.=" AND S.urgency_eff=?";  $params[]=$urgency; $types.='s'; }
if($pay!=='all'){      $sql.=" AND COALESCE(S.payment_status,'unpaid')=?"; $params[]=$pay; $types.='s'; }
if($from!==''){        $sql.=" AND DATE(S.created_at)>=?"; $params[]=$from; $types.='s'; }
if($to!==''){          $sql.=" AND DATE(S.created_at)<=?"; $params[]=$to;   $types.='s'; }
$sql.=" ORDER BY S.updated_at DESC, S.id DESC LIMIT ? OFFSET ?";
$params[]=$per_page; $types.='i';
$params[]=$offset;   $types.='i';
$st=$conn->prepare($sql); if($params){$st->bind_param($types, ...$params);} $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

/* ---- labels ---- */
$statuses = [
  'queued'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค',
  'waiting_parts'=>'รออะไหล่','repairing'=>'กำลังซ่อม','done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก'
];
$badge_map = [
  'queued' => 'secondary', 'confirm'=>'info text-dark', 'checking'=>'primary',
  'waiting_parts'=>'warning text-dark', 'repairing'=>'primary',
  'done'=>'success', 'cancelled'=>'danger'
];

/* ---- payment labels ---- */
$PAY_LABEL = [
  'unpaid'  => 'ยังไม่ชำระ',
  'pending' => 'รอตรวจสอบ',
  'paid'    => 'ชำระแล้ว',
];
$PAY_BADGE = [
  'unpaid'  => 'danger',
  'pending' => 'warning text-dark',
  'paid'    => 'success',
];
$PAY_METHOD = [
  'bank'   => 'โอนธนาคาร / พร้อมเพย์',
  'cash'   => 'เงินสดหน้าร้าน',
  'wallet' => 'วอลเล็ท',
  'cod'    => 'เก็บเงินปลายทาง',
];
?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
  <meta charset="utf-8">
  <title>คิวซ่อม | Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b; --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06); }
    html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.72); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45); }

    body{ background:var(--bg); color:var(--text); }
    .topbar{ backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border); }
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }

    .app{ display:grid; grid-template-columns:260px 1fr; gap:24px; }
    @media(max-width:991.98px){ .app{grid-template-columns:1fr} .sidebar{position:static} }

    .sidebar{ position:sticky; top:90px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden; }
    .side-a{ display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent; }
    .side-a:hover{ background:#f6f9ff } html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
    .side-a.active{ background:#eef5ff; border-left-color:var(--primary) } html[data-theme="dark"] .side-a.active{background:#0e1f3e}

    .glass{ border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow); }
    .chip{ display:inline-flex; align-items:center; gap:6px; background:#eaf2ff; border:1px solid #cfe1ff; padding:4px 10px; border-radius:999px; font-weight:600; font-size:.85rem; }
    html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }

    .table> :not(caption)>*>*{ border-color:var(--border); }
    .ellipsis-1{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-tools me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">คิวซ่อม • สวัสดี, <?= h($admin_name) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <!-- Quick search (id/phone/model/brand) -->
      <form class="d-none d-md-flex" action="service_tickets.php" method="get">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" type="search" class="form-control" placeholder="ค้นหา: หมายเลข/เบอร์/รุ่น/ยี่ห้อ" value="<?= h($q) ?>">
          <!-- keep current filters -->
          <input type="hidden" name="status" value="<?= h($status) ?>">
          <input type="hidden" name="urgency" value="<?= h($urgency) ?>">
          <input type="hidden" name="pay" value="<?= h($pay) ?>">
          <input type="hidden" name="from" value="<?= h($from) ?>">
          <input type="hidden" name="to" value="<?= h($to) ?>">
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
      </div>
      <!-- Theme toggle -->
      <button class="btn btn-outline-secondary" id="themeToggle" title="สลับโหมด">
        <i class="bi bi-moon-stars"></i>
      </button>

      <a class="btn btn-outline-secondary" href="../index.php" title="หน้าร้าน"><i class="bi bi-house"></i></a>
      <a class="btn btn-outline-danger" href="../logout.php" title="ออกจากระบบ"><i class="bi bi-box-arrow-right"></i></a>
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
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> สินค้า</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> เทิร์นสินค้า</a>
      <a class="side-a active" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> งานซ่อม</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> ผู้ใช้</a>
      <a class="side-a" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> คูปอง</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
    <div class="p-3 border-top">
      <div class="chip"><i class="bi bi-lightning-charge-fill me-1"></i> Quick actions</div>
      <div class="mt-2 d-grid gap-2">
        <a class="btn btn-sm btn-primary" href="service_ticket_new.php"><i class="bi bi-plus-circle me-1"></i> เปิดใบคิวใหม่</a>
        <!-- ใช้ queued ให้ตรงกับ status จริง -->
        <a class="btn btn-sm btn-outline-primary" href="service_tickets.php?status=queued"><i class="bi bi-list-check me-1"></i> งานเข้าคิว</a>
        <a class="btn btn-sm btn-outline-primary" href="service_tickets.php?status=waiting_parts"><i class="bi bi-gear-wide-connected me-1"></i> รออะไหล่</a>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">

    <!-- Filter toolbar -->
    <div class="glass p-3">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
          <label class="form-label">ค้นหา</label>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="หมายเลข/เบอร์/รุ่น/ยี่ห้อ">
        </div>
        <div class="col-md-2">
          <label class="form-label">สถานะใบงาน</label>
          <select class="form-select" name="status">
            <option value="all" <?= $status==='all'?'selected':'' ?>>ทั้งหมด</option>
            <?php foreach($statuses as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">ความเร่งด่วน</label>
          <select class="form-select" name="urgency">
            <option value="all"    <?= $urgency==='all'?'selected':'' ?>>ทั้งหมด</option>
            <option value="normal" <?= $urgency==='normal'?'selected':'' ?>>ปกติ</option>
            <option value="urgent" <?= $urgency==='urgent'?'selected':'' ?>>ด่วน</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">สถานะการชำระเงิน</label>
          <select class="form-select" name="pay">
            <option value="all"    <?= $pay==='all'?'selected':'' ?>>ทั้งหมด</option>
            <option value="unpaid" <?= $pay==='unpaid'?'selected':'' ?>>ยังไม่ชำระ</option>
            <option value="pending"<?= $pay==='pending'?'selected':'' ?>>รอตรวจสอบ</option>
            <option value="paid"   <?= $pay==='paid'?'selected':'' ?>>ชำระแล้ว</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">จากวันที่</label>
          <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">ถึงวันที่</label>
          <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary"><i class="bi bi-funnel"></i></button>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="glass">
      <div class="table-responsive">
        <table class="table align-middle mb-0 table-hover">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th style="min-width:260px">ประเภท/รุ่น</th>
              <th>ลูกค้า</th>
              <th>นัดหมาย</th>
              <th>ความเร่งด่วน</th>
              <th>สถานะใบงาน</th>
              <th>การชำระเงิน</th>
              <th>อัปเดต</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($rows)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
          <?php else: foreach($rows as $r): ?>
            <?php
              $stKey = $r['status_eff'] ?? $r['status'];
              $payStatus = $r['payment_status'] ?? 'unpaid';
              if ($payStatus === '' || $payStatus === null) $payStatus = 'unpaid';

              // ยอดแสดงให้ดูคร่าว ๆ (ใช้ final_total ถ้ามี ไม่งั้น fallback estimate_total)
              $amount = 0.0;
              if (isset($r['final_total']) && $r['final_total'] !== null) {
                $amount = (float)$r['final_total'];
              } elseif (isset($r['estimate_total']) && $r['estimate_total'] !== null) {
                $amount = (float)$r['estimate_total'];
              }

              $isDoneNotPaid = ($stKey === 'done' && $payStatus !== 'paid');

              $payMethod = $r['pay_method'] ?? '';
              $payMethodLabel = $payMethod ? ($PAY_METHOD[$payMethod] ?? $payMethod) : '';
            ?>
            <tr id="row-<?= (int)$r['id'] ?>" <?= $isDoneNotPaid? 'style="background:rgba(254,242,242,.7);"' : '' ?>>
              <td>ST-<?= (int)$r['id'] ?></td>
              <td>
                <div class="fw-semibold"><?= h($r['device_type']) ?> — <?= h($r['brand']) ?> <?= h($r['model']) ?></div>
                <?php if (!empty($r['issue'])): ?>
                  <div class="small text-muted ellipsis-1">
                    <i class="bi bi-chat-quote"></i> <?= h(mb_strimwidth($r['issue'],0,110,'…','UTF-8')) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="small text-muted">โทร: <?= h($r['phone']) ?></div>
                <?php if(!empty($r['line_id'])): ?>
                  <div class="small text-muted">LINE: <?= h($r['line_id']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= h($r['desired_date'] ?: '-') ?></td>
              <td>
                <?php if(($r['urgency_eff'] ?? 'normal')==='urgent'): ?>
                  <span class="badge bg-danger">เร่งด่วน</span>
                <?php else: ?>
                  <span class="badge bg-secondary">ปกติ</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $badge_map[$stKey] ?? 'secondary' ?>">
                  <?= h($statuses[$stKey] ?? $stKey) ?>
                </span>
                <?php if($isDoneNotPaid): ?>
                  <div class="small text-danger">
                    <i class="bi bi-exclamation-circle"></i> เสร็จแล้ว รอชำระเงิน
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $PAY_BADGE[$payStatus] ?? 'secondary' ?>">
                  <?= h($PAY_LABEL[$payStatus] ?? $payStatus) ?>
                </span>
                 

                  <?php if($payMethodLabel): ?>
                    <div class="small text-muted">
                      ประเภท: <?= h($payMethodLabel) ?>
                    </div>
                  <?php endif; ?>
<td>
                <?php if($amount>0): ?>
                  <div class="small text-muted">ยอด: <?= number_format($amount,2) ?> ฿</div>
                <?php endif; ?>
                <?php if(!empty($r['paid_at']) && $payStatus==='paid'): ?>
                  <div class="small text-muted">จ่ายเมื่อ: <?= h($r['paid_at']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= h($r['updated_at']) ?></td>
              <td class="text-end">
                <a href="service_ticket_detail.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i> เปิด
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
              
    <!-- Pagination -->
    <?php if($total_pages>1): ?>
      <nav>
        <ul class="pagination justify-content-center flex-wrap">
          <?php
            $qs=$_GET; $prev=max(1,$page-1); $next=min($total_pages,$page+1);
            $qs['page']=$prev; $prev_url='?'.http_build_query($qs);
            $qs['page']=$next; $next_url='?'.http_build_query($qs);
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $page<=1?'#':$prev_url ?>">ก่อนหน้า</a></li>
          <li class="page-item disabled"><span class="page-link">หน้า <?= $page ?> / <?= $total_pages ?> • ทั้งหมด <?= $total_rows ?></span></li>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?= $page>=$total_pages?'#':$next_url ?>">ถัดไป</a></li>
        </ul>
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
    const html=document.documentElement;
    const saved=localStorage.getItem('admin-theme') || 'light';
    html.setAttribute('data-theme', saved);
    document.getElementById('themeToggle')?.addEventListener('click', ()=>{
      const cur=html.getAttribute('data-theme')==='dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', cur);
      localStorage.setItem('admin-theme', cur);
    });
  })();

  // Back to top
  const btnTop=document.getElementById('btnTop');
  window.addEventListener('scroll', ()=>{ btnTop.style.display=(window.scrollY>300)?'block':'none'; });
  btnTop.addEventListener('click', ()=> window.scrollTo({top:0,behavior:'smooth'}));

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
  refreshCount(); refreshList(); setInterval(refreshCount, 30000);
</script>
</body>
</html>
