<?php
// Home/admin/sales_summary.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__.'/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/sales_summary.php'); exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

/* ===== Date range ===== */
date_default_timezone_set('Asia/Bangkok');
$today        = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$from = $_GET['from'] ?? $firstOfMonth;
$to   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $firstOfMonth;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;

$fromTS = $from.' 00:00:00';
$toTS   = $to.' 23:59:59';

/* ===== small helpers (no type hints for max compatibility) ===== */
function table_exists($conn, $table){
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows > 0;
}

/* ===== Admin name ===== */
$admin_id = (int)$_SESSION['user_id'];
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

/* -----------------------------------------------------------------------------
   CORE DATA MODEL (ยอดขายสุทธิต่อออเดอร์)
   - gross:     ยอดรวมจาก order_items (qty*unit_price)
   - disc:      ยอดส่วนลดจาก coupon_usages (sum amount ต่อ order)
   - net:       gross - disc
   - pm:        payment_method
   - od:        วันที่สั่งซื้อ (DATE(created_at))
   เงื่อนไข:    เฉพาะ orders.payment_status = 'paid' และอยู่ในช่วงวันที่
------------------------------------------------------------------------------*/
$hasCouponUsages = table_exists($conn, 'coupon_usages');

$baseSql = "
  WITH t_orders AS (
    SELECT 
      o.id,
      o.payment_method AS pm,
      DATE(o.created_at) AS od,
      o.created_at,
      COALESCE(SUM(oi.quantity*oi.unit_price),0) AS gross
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.payment_status='paid'
      AND o.created_at BETWEEN ? AND ?
    GROUP BY o.id
  )
  SELECT 
    t.id, t.pm, t.od, t.created_at,
    t.gross,
    COALESCE(d.disc,0) AS disc,
    (t.gross - COALESCE(d.disc,0)) AS net,
    COALESCE(d.code,'') AS coupon_code
  FROM t_orders t
  LEFT JOIN (
    ".($hasCouponUsages ? "
      SELECT 
        cu.order_id,
        COALESCE(SUM(cu.amount),0) AS disc,
        MIN(c.code) AS code
      FROM coupon_usages cu
      LEFT JOIN coupons c ON c.id = cu.coupon_id
      GROUP BY cu.order_id
    " : "
      SELECT 0 AS order_id, 0 AS disc, '' AS code LIMIT 0
    ")."
  ) d ON d.order_id = t.id
";

/* ===== KPI รวม ===== */
$kpi = [
  'revenue_net'   => 0.0,
  'orders_paid'   => 0,
  'avg_order'     => 0.0,
  'discount_total'=> 0.0,
  'coupon_orders' => 0,
];

if ($st = $conn->prepare("
  SELECT 
    COALESCE(SUM(net),0)                AS revenue_net,
    COALESCE(SUM(disc),0)               AS discount_total,
    COUNT(*)                            AS orders_paid,
    SUM(CASE WHEN COALESCE(disc,0)>0 THEN 1 ELSE 0 END) AS coupon_orders
  FROM ( $baseSql ) x
")){
  $st->bind_param('ss', $fromTS, $toTS);
  $st->execute();
  $kpi = array_map(fn($v)=>$v ?? 0, $st->get_result()->fetch_assoc() ?: $kpi);
  $st->close();
}
$revenue    = (float)$kpi['revenue_net'];
$ordersPaid = (int)$kpi['orders_paid'];
$avgOrder   = $ordersPaid>0 ? ($revenue/$ordersPaid) : 0.0;
$discTotal  = (float)$kpi['discount_total'];
$couponCnt  = (int)$kpi['coupon_orders'];

/* ===== Donut: ยอดขายสุทธิแยกตามวิธีชำระ ===== */
$byPay = []; // [pm => rev_net]
if ($st = $conn->prepare("
  SELECT pm, COALESCE(SUM(net),0) AS rev_net
  FROM ( $baseSql ) x
  GROUP BY pm
  ORDER BY rev_net DESC
")){
  $st->bind_param('ss', $fromTS, $toTS);
  $st->execute();
  $r = $st->get_result();
  while($a = $r->fetch_assoc()){
    $byPay[$a['pm'] ?: 'other'] = (float)$a['rev_net'];
  }
  $st->close();
}

/* ===== Donut: สัดส่วนยอดขายสุทธิจากออเดอร์ที่ใช้คูปอง / ไม่ใช้คูปอง ===== */
$couponShare = ['ไม่มีคูปอง'=>0.0, 'มีคูปอง'=>0.0];
if ($st = $conn->prepare("
  SELECT 
    COALESCE(SUM(CASE WHEN disc>0 THEN 0 ELSE net END),0) AS no_coupon_net,
    COALESCE(SUM(CASE WHEN disc>0 THEN net ELSE 0 END),0) AS with_coupon_net
  FROM ( $baseSql ) x
")){
  $st->bind_param('ss', $fromTS, $toTS);
  $st->execute();
  $a = $st->get_result()->fetch_assoc() ?: ['no_coupon_net'=>0,'with_coupon_net'=>0];
  $couponShare['ไม่มีคูปอง'] = (float)$a['no_coupon_net'];
  $couponShare['มีคูปอง']    = (float)$a['with_coupon_net'];
  $st->close();
}

/* ===== Line: รายวัน (ยอดสุทธิ + จำนวนออเดอร์) ===== */
$daily = []; // [{d, rev, orders_cnt}]
if ($st = $conn->prepare("
  SELECT od AS d, COALESCE(SUM(net),0) AS rev, COUNT(*) AS orders_cnt
  FROM ( $baseSql ) x
  GROUP BY od
  ORDER BY od ASC
")){
  $st->bind_param('ss', $fromTS, $toTS);
  $st->execute();
  $r = $st->get_result();
  while($a = $r->fetch_assoc()){ $daily[] = $a; }
  $st->close();
}

/* ===== Bar: Top 10 สินค้าขายดี (อิงยอดขายรวมของสินค้า-จากออเดอร์ที่จ่ายแล้ว) ===== */
$top = [];
if ($st = $conn->prepare("
  SELECT 
    oi.product_id,
    COALESCE(p.name, CONCAT('PID ', oi.product_id)) AS name,
    SUM(oi.quantity) AS qty,
    SUM(oi.quantity * oi.unit_price) AS rev
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE o.payment_status='paid'
    AND o.created_at BETWEEN ? AND ?
  GROUP BY oi.product_id, p.name
  ORDER BY rev DESC
  LIMIT 10
")){
  $st->bind_param('ss', $fromTS, $toTS);
  $st->execute();
  $r = $st->get_result();
  while($a = $r->fetch_assoc()) $top[] = $a;
  $st->close();
}

/* ===== Bar: Top คูปอง (ตามยอดส่วนลดรวม) – ใช้ได้เมื่อมี coupon_usages ===== */
$couponTop = []; // [{code, disc_sum, uses}]
if ($hasCouponUsages) {
  if ($st = $conn->prepare("
    SELECT 
      COALESCE(c.code, '(ไม่ระบุ)') AS code,
      COALESCE(SUM(cu.amount),0)   AS disc_sum,
      COUNT(*)                     AS uses
    FROM coupon_usages cu
    LEFT JOIN coupons c ON c.id = cu.coupon_id
    INNER JOIN orders o ON o.id = cu.order_id
    WHERE o.payment_status='paid'
      AND o.created_at BETWEEN ? AND ?
    GROUP BY c.id, c.code
    ORDER BY disc_sum DESC
    LIMIT 10
  ")){
    $st->bind_param('ss', $fromTS, $toTS);
    $st->execute();
    $r = $st->get_result();
    while($a = $r->fetch_assoc()) $couponTop[] = $a;
    $st->close();
  }
}

/* ===== Notifications badge (นับเฉยๆ) ===== */
$noti_unread = 0;
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0")) {
  $st->bind_param('i', $_SESSION['user_id']);
  $st->execute();
  $noti_unread = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Sales Summary | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a;
      --card-shadow:0 14px 40px rgba(2,6,23,.06);
      --muted:#64748b;
    }
    body{background:var(--bg); color:var(--text);}
    .topbar{backdrop-filter:blur(8px); background:linear-gradient(180deg,#ffffffcc,#ffffffa6); border-bottom:1px solid var(--border);}
    .app{display:grid; grid-template-columns:260px 1fr; gap:24px; padding:16px;}
    @media (max-width:992px){ .app{grid-template-columns:1fr} }
    .sidebar{position:sticky; top:76px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden;}
    .side-a{display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent;}
    .side-a:hover{background:#eef3ff}
    .side-a.active{background:#eef3ff; border-left-color:#4f46e5}
    .glass{ background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:var(--card-shadow); }
    .glass canvas{ width:100% !important; height:auto; display:block; }
    .kpi{ border-radius:14px; background:linear-gradient(145deg,#ffffff,#f7faff); border:1px solid #eef2fb; }
    .kpi .small{ color:var(--muted); }
  </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-graph-up-arrow me-2"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">
        Sales Summary • ช่วง: <?= h($from) ?> – <?= h($to) ?>
      </span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <div class="btn-group d-none d-md-inline-flex">
        <a class="btn btn-outline-secondary" href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>">วันนี้</a>
        <a class="btn btn-outline-primary"   href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">เดือนนี้</a>
        <a class="btn btn-outline-success"   href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">ปีนี้</a>
      </div>

      <div class="dropdown">
        <a class="btn btn-light border position-relative" data-bs-toggle="dropdown" aria-expanded="false">
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

      <a class="btn btn-outline-secondary" href="../index.php"><i class="bi bi-house"></i></a>
      <a class="btn btn-outline-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</nav>

<div class="container-fluid app">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="p-2">
      <a class="side-a" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> แดชบอร์ด</a>
      <a class="side-a active" href="sales_summary.php"><i class="bi bi-graph-up-arrow me-2"></i> สรุปยอดขาย</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> ออเดอร์</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> สินค้า</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> เทิร์นสินค้า</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> งานซ่อม</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> ผู้ใช้</a>
      <a class="side-a" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> คูปอง</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">

    <!-- ฟิลเตอร์ -->
    <form class="glass p-3">
      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label class="form-label">จากวันที่</label>
          <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-sm-3">
          <label class="form-label">ถึงวันที่</label>
          <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-sm-3">
          <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> ดูสรุป</button>
        </div>
        <div class="col-sm-3 d-flex gap-2">
          <a class="btn btn-outline-secondary flex-fill" href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>">วันนี้</a>
          <a class="btn btn-outline-primary flex-fill" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">เดือนนี้</a>
          <a class="btn btn-outline-success flex-fill" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">ปีนี้</a>
        </div>
      </div>
    </form>

    <!-- KPI -->
    <div class="row g-3">
      <div class="col-sm-6 col-lg-3"><div class="kpi p-3">
        <div class="small">ยอดขายสุทธิ (หลังหักคูปอง)</div>
        <div class="fs-3 fw-bold"><?= baht($revenue) ?> ฿</div>
      </div></div>
      <div class="col-sm-6 col-lg-3"><div class="kpi p-3">
        <div class="small">จำนวนออเดอร์ที่จ่ายแล้ว</div>
        <div class="fs-3 fw-bold"><?= number_format($ordersPaid) ?></div>
      </div></div>
      <div class="col-sm-6 col-lg-3"><div class="kpi p-3">
        <div class="small">ค่าเฉลี่ย/ออเดอร์ (net)</div>
        <div class="fs-3 fw-bold"><?= baht($avgOrder) ?> ฿</div>
      </div></div>
      <div class="col-sm-6 col-lg-3"><div class="kpi p-3">
        <div class="small">ส่วนลดคูปองรวม</div>
        <div class="fs-3 fw-bold text-success">- <?= baht($discTotal) ?> ฿</div>
        <div class="small">ออเดอร์ใช้คูปอง: <?= number_format($couponCnt) ?> บิล</div>
      </div></div>
    </div>

    <!-- Charts row 1 -->
    <div class="row g-3">
      <div class="col-xl-4">
        <div class="glass p-3 h-100">
          <h6 class="mb-3"><i class="bi bi-pie-chart me-2"></i>ยอดขายตามวิธีชำระ (net)</h6>
          <canvas id="chartPay" height="240"></canvas>
        </div>
      </div>
      <div class="col-xl-4">
        <div class="glass p-3 h-100">
          <h6 class="mb-3"><i class="bi bi-pie-chart-fill me-2"></i>สัดส่วนยอดขายจากคูปอง (net)</h6>
          <canvas id="chartCouponShare" height="240"></canvas>
        </div>
      </div>
      <div class="col-xl-4">
        <div class="glass p-3 h-100">
          <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>ยอดขายรายวัน (net)</h6>
          <canvas id="chartDaily" height="240"></canvas>
        </div>
      </div>
    </div>

    <!-- Charts row 2 -->
    <div class="row g-3">
      <div class="col-xl-6">
        <div class="glass p-3 h-100">
          <h6 class="mb-3"><i class="bi bi-bar-chart-line me-2"></i>Top 10 สินค้าขายดี (paid)</h6>
          <canvas id="chartTop" height="260"></canvas>
        </div>
      </div>
      <div class="col-xl-6">
        <div class="glass p-3 h-100">
          <h6 class="mb-3"><i class="bi bi-ticket-detailed me-2"></i>Top คูปองตามส่วนลดรวม</h6>
          <canvas id="chartCouponTop" height="260"></canvas>
        </div>
      </div>
    </div>

    <!-- ตาราง Top สินค้า -->
    <div class="glass p-3">
      <h6 class="mb-2"><i class="bi bi-trophy me-2"></i>รายละเอียด Top 10 สินค้าขายดี</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>#</th><th>สินค้า</th><th class="text-end">จำนวน</th><th class="text-end">ยอดขาย</th></tr>
          </thead>
          <tbody>
          <?php if(empty($top)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">— ไม่มีข้อมูล —</td></tr>
          <?php else: $i=1; foreach($top as $t): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($t['name']) ?></td>
              <td class="text-end"><?= number_format($t['qty']) ?></td>
              <td class="text-end"><?= baht($t['rev']) ?> ฿</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const pay       = <?= json_encode($byPay, JSON_UNESCAPED_UNICODE) ?>;
  const couponShr = <?= json_encode($couponShare, JSON_UNESCAPED_UNICODE) ?>;
  const daily     = <?= json_encode($daily, JSON_UNESCAPED_UNICODE) ?>;
  const top       = <?= json_encode($top,   JSON_UNESCAPED_UNICODE) ?>;
  const couponTop = <?= json_encode($couponTop, JSON_UNESCAPED_UNICODE) ?>;

  // Donut: by payment method (net)
  (function(){
    const el = document.getElementById('chartPay'); if(!el) return;
    const labels = Object.keys(pay).length ? Object.keys(pay) : ['ไม่มีข้อมูล'];
    const data   = Object.keys(pay).length ? Object.values(pay) : [0];
    new Chart(el, {
      type: 'doughnut',
      data: { labels, datasets: [{ data }] },
      options: { plugins:{ legend:{ position:'bottom' } }, cutout:'65%' }
    });
  })();

  // Donut: coupon share (net)
  (function(){
    const el = document.getElementById('chartCouponShare'); if(!el) return;
    const labels = Object.keys(couponShr).length ? Object.keys(couponShr) : ['ไม่มีข้อมูล'];
    const data   = Object.keys(couponShr).length ? Object.values(couponShr) : [0];
    new Chart(el, {
      type:'doughnut',
      data:{ labels, datasets:[{ data }] },
      options:{ plugins:{ legend:{ position:'bottom' } }, cutout:'65%' }
    });
  })();

  // Line: daily (net + count)
  (function(){
    const el = document.getElementById('chartDaily'); if(!el) return;
    const labels = daily.length ? daily.map(x=>x.d) : ['ไม่มีข้อมูล'];
    const rev    = daily.length ? daily.map(x=>Number(x.rev||0)) : [0];
    const cnt    = daily.length ? daily.map(x=>Number(x.orders_cnt||0)) : [0];
    new Chart(el, {
      type:'line',
      data:{
        labels,
        datasets:[
          { label:'ยอดขาย (บาท, net)', data:rev, tension:.3, fill:false },
          { label:'จำนวนออเดอร์', data:cnt, yAxisID:'y2', tension:.3, fill:false }
        ]
      },
      options:{
        interaction:{ mode:'index', intersect:false },
        scales:{ y:{ beginAtZero:true }, y2:{ beginAtZero:true, position:'right', grid:{drawOnChartArea:false} } },
        plugins:{ legend:{ position:'bottom' } }
      }
    });
  })();

  // Bar: top products
  (function(){
    const el = document.getElementById('chartTop'); if(!el) return;
    const labels = top.length ? top.map(x=>x.name) : ['ไม่มีข้อมูล'];
    const data   = top.length ? top.map(x=>Number(x.rev||0)) : [0];
    new Chart(el, {
      type:'bar',
      data:{ labels, datasets:[{ label:'ยอดขาย (บาท)', data }] },
      options:{ indexAxis:'y', plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true } } }
    });
  })();

  // Bar: top coupons by discount sum
  (function(){
    const el = document.getElementById('chartCouponTop'); if(!el) return;
    const labels = couponTop.length ? couponTop.map(x=>x.code) : ['ไม่มีข้อมูล'];
    const data   = couponTop.length ? couponTop.map(x=>Number(x.disc_sum||0)) : [0];
    new Chart(el, {
      type:'bar',
      data:{ labels, datasets:[{ label:'ส่วนลดรวม (บาท)', data }] },
      options:{ indexAxis:'y', plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true } } }
    });
  })();
});

/* ===== Notifications (เหมือนหน้าอื่น ๆ) ===== */
const badge   = document.getElementById('notif-badge');
const listEl  = document.getElementById('notif-list');
const markBtn = document.getElementById('notif-mark-read');

function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }
function fmtTime(iso){ try{ const d=new Date(iso.replace(' ','T')); return d.toLocaleString(); }catch(e){ return iso; } }
function linkFor(it){
  if (it.type === 'order_status'   && it.ref_id) return `order_detail.php?id=${it.ref_id}`;
  if (it.type === 'payment_status' && it.ref_id) return `order_detail.php?id=${it.ref_id}`;
  if (it.type === 'cancel_request' && it.ref_id) return `orders.php?status=cancel_requested#row-${it.ref_id}`;
  if (it.type === 'support_msg') return `support.php`;
  if (it.type === 'calendar')    return `calendar.php`;
  return 'orders.php';
}
function renderItems(items){
  if(!items || items.length===0){
    listEl.innerHTML = `<div class="p-3 text-center text-muted">ยังไม่มีการแจ้งเตือน</div>`;
    return;
  }
  listEl.innerHTML = items.map(it=>`
    <a class="dropdown-item d-block ${Number(it.is_read)===0?'bg-light':''}" href="${linkFor(it)}">
      <div class="fw-semibold">${escapeHtml(it.title||'')}</div>
      ${it.message ? `<div class="small">${escapeHtml(it.message)}</div>` : ''}
      <div class="small text-muted">${fmtTime(it.created_at)}</div>
    </a>
  `).join('');
}
async function refreshCount(){
  try{
    const r = await fetch('../notify_api.php?action=count', {cache:'no-store'});
    const j = await r.json(); const c = j.count||0;
    if(c>0){ badge.classList.remove('d-none'); badge.textContent = c; }
    else   { badge.classList.add('d-none'); }
  }catch(_){}
}
async function refreshList(){
  try{
    const r = await fetch('../notify_api.php?action=list&limit=15', {cache:'no-store'});
    const j = await r.json(); renderItems(j.items||[]);
  }catch(_){
    listEl.innerHTML = `<div class="p-3 text-center text-muted">โหลดไม่สำเร็จ</div>`;
  }
}
markBtn?.addEventListener('click', async ()=>{
  await fetch('../notify_api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=mark_all_read'});
  refreshCount(); refreshList();
});
refreshCount(); refreshList(); setInterval(refreshCount, 30000);
</script>
</body>
</html>
