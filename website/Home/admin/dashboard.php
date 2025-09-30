<?php
// Home/admin/dashboard.php (beautiful+calendar widgets)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/dashboard.php');
  exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

/* ===== Admin name ===== */
$admin_id = (int)$_SESSION['user_id'];
$admin_name = 'admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i', $admin_id);
  $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

/* ===== Maps ===== */
$PAY_THAI = [
  'unpaid'=>'ยังไม่ชำระ','pending'=>'รอตรวจสอบ','paid'=>'ชำระแล้ว','refunded'=>'คืนเงินแล้ว','expired'=>'หมดเวลาชำระ'
];
$PAY_BADGE = [
  'unpaid'=>'secondary','pending'=>'warning text-dark','paid'=>'success','refunded'=>'info text-dark','expired'=>'danger'
];
$ORDER_THAI = [
  'pending'=>'ใหม่/กำลังตรวจสอบ','processing'=>'กำลังเตรียม/แพ็ค','shipped'=>'ส่งออกจากคลัง',
  'delivered'=>'ถึงปลายทาง','completed'=>'เสร็จสิ้น','cancel_requested'=>'รอยืนยันยกเลิก','cancelled'=>'ยกเลิก'
];
$ORDER_BADGE = [
  'pending'=>'info text-dark','processing'=>'primary','shipped'=>'primary',
  'delivered'=>'success','completed'=>'success','cancel_requested'=>'warning text-dark','cancelled'=>'danger'
];

/* effective order status (ตัดหมดเวลา) */
function effective_status_of(array $o): string {
  $isBank = ($o['payment_method'] ?? '') === 'bank';
  $noSlip = empty($o['slip_image'] ?? '');
  $remain = (int)($o['remaining_sec'] ?? 0);
  $expired_by_rule =
      ($o['payment_status'] ?? '') === 'expired'
      || ($isBank && $noSlip && in_array(($o['payment_status'] ?? ''), ['unpaid','pending'], true) && $remain === 0);
  return $expired_by_rule ? 'cancelled' : (($o['status'] ?? '') ?: 'pending');
}

/* ===== KPIs (users, products, orders) ===== */
$users_total     = (int)$conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$products_active = (int)$conn->query("SELECT COUNT(*) c FROM products WHERE status='active'")->fetch_assoc()['c'];
$orders_open = (int)$conn->query("
  SELECT COUNT(*) c
  FROM orders o
  WHERE (
    CASE
      WHEN o.payment_status='expired' THEN 'cancelled'
      WHEN o.payment_method='bank' AND (o.slip_image IS NULL OR o.slip_image='')
           AND o.payment_status IN ('unpaid','pending')
           AND GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL 15 MINUTE))))=0
           THEN 'cancelled'
      ELSE o.status
    END
  ) IN ('pending','processing','shipped')
")->fetch_assoc()['c'];
$bank_pending = (int)$conn->query("
  SELECT COUNT(*) c
  FROM orders o
  WHERE o.payment_method='bank' AND o.payment_status='pending'
    AND NOT (
      o.payment_status='expired'
      OR (
        (o.slip_image IS NULL OR o.slip_image='')
        AND GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL 15 MINUTE))))=0
      )
    )
")->fetch_assoc()['c'];

/* ===== Sales 7d ===== */
$revenue7 = 0.00;
$res = $conn->query("
  SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) AS rev
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  WHERE o.payment_status='paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
if ($res) { $revenue7 = (float)$res->fetch_assoc()['rev']; }

/* ===== Latest orders ===== */
$sql_latest = "
  SELECT 
    o.id, o.user_id, o.status, o.payment_method, o.payment_status, o.created_at,
    o.slip_image, o.expires_at,
    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL 15 MINUTE)))) AS remaining_sec,
    u.username,
    COALESCE(SUM(oi.quantity*oi.unit_price),0) AS total_amount
  FROM orders o
  LEFT JOIN users u ON u.id = o.user_id
  LEFT JOIN order_items oi ON oi.order_id = o.id
  GROUP BY o.id
  ORDER BY o.created_at DESC
  LIMIT 10
";
$latest = $conn->query($sql_latest)->fetch_all(MYSQLI_ASSOC);

/* ===== Service KPIs (ใช้สถานะล่าสุด + ตัดงานปิดแล้วออกจาก urgent) ===== */
$svcKpiSql = "
  SELECT
    COUNT(*) AS allc,

    /* urgent = เร่งด่วน 'ที่ยังเปิดอยู่' เท่านั้น */
    SUM(
      CASE
        WHEN st.urgency='urgent'
         AND (
           CASE
             WHEN COALESCE(ls.last_status, st.status) IN ('confirm','queue','queued') THEN 'queued'
             ELSE COALESCE(ls.last_status, st.status)
           END
         ) NOT IN ('done','returned','completed','cancelled')
        THEN 1 ELSE 0
      END
    ) AS urgent,

    /* แยกสถานะตาม effective status */
    SUM(
      CASE
        WHEN (
          CASE
            WHEN COALESCE(ls.last_status, st.status) IN ('confirm','queue','queued') THEN 'queued'
            ELSE COALESCE(ls.last_status, st.status)
          END
        ) = 'queued'
        THEN 1 ELSE 0
      END
    ) AS queued,

    SUM(
      CASE
        WHEN (
          CASE
            WHEN COALESCE(ls.last_status, st.status) IN ('confirm','queue','queued') THEN 'queued'
            ELSE COALESCE(ls.last_status, st.status)
          END
        ) = 'repairing'
        THEN 1 ELSE 0
      END
    ) AS repairing,

    SUM(
      CASE
        WHEN (
          CASE
            WHEN COALESCE(ls.last_status, st.status) IN ('confirm','queue','queued') THEN 'queued'
            ELSE COALESCE(ls.last_status, st.status)
          END
        ) IN ('done','returned','completed')
        THEN 1 ELSE 0
      END
    ) AS donec,

    SUM(
      CASE
        WHEN (
          CASE
            WHEN COALESCE(ls.last_status, st.status) IN ('confirm','queue','queued') THEN 'queued'
            ELSE COALESCE(ls.last_status, st.status)
          END
        ) = 'cancelled'
        THEN 1 ELSE 0
      END
    ) AS cancelled

  FROM service_tickets st
  LEFT JOIN (
    /* สถานะล่าสุดจากไทม์ไลน์ */
    SELECT ticket_id,
           SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1) AS last_status
    FROM service_status_logs
    GROUP BY ticket_id
  ) ls ON ls.ticket_id = st.id
";
$svc = $conn->query($svcKpiSql)->fetch_assoc();
$repair_counts = [
  'all'       => (int)($svc['allc'] ?? 0),
  'urgent'    => (int)($svc['urgent'] ?? 0),
  'queued'    => (int)($svc['queued'] ?? 0),
  'repairing' => (int)($svc['repairing'] ?? 0),
  'done'      => (int)($svc['donec'] ?? 0),
  'cancelled' => (int)($svc['cancelled'] ?? 0),
];

/* ===== Trade-in KPIs ===== */
$trade_counts = ['all'=>0,'reviewing'=>0,'offered'=>0,'completed'=>0];
$trade_counts['all'] = (int)$conn->query("SELECT COUNT(*) c FROM tradein_requests")->fetch_assoc()['c'];
if ($conn->query("SHOW TABLES LIKE 'tradein_requests'")->num_rows){
  $trade_counts['reviewing'] = (int)$conn->query("SELECT COUNT(*) c FROM tradein_requests WHERE status='reviewing'")->fetch_assoc()['c'];
  $trade_counts['offered']   = (int)$conn->query("SELECT COUNT(*) c FROM tradein_requests WHERE status='offered'")->fetch_assoc()['c'];
  $trade_counts['completed'] = (int)$conn->query("SELECT COUNT(*) c FROM tradein_requests WHERE status IN ('completed','accepted')")->fetch_assoc()['c'];
}

/* helper: ตรวจว่าตารางมีคอลัมน์นี้ไหม */
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}

$upcoming = [];

/* --- service_tickets --- */
$svcStartCol = has_col($conn,'service_tickets','appointment_start')
  ? 'appointment_start'
  : (has_col($conn,'service_tickets','scheduled_at') ? 'scheduled_at' : null);

$svcEndExpr  = has_col($conn,'service_tickets','appointment_end')
  ? 'appointment_end'
  : ($svcStartCol ? "DATE_ADD($svcStartCol, INTERVAL 60 MINUTE)" : 'NULL');

if ($svcStartCol) {
  $sqlSvc = "
    SELECT id, 'repair' AS ttype, device_type, brand, model,
           $svcStartCol AS s_start,
           $svcEndExpr  AS s_end
    FROM service_tickets
    WHERE $svcStartCol IS NOT NULL
      AND $svcStartCol >= CURDATE()
      AND $svcStartCol < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY $svcStartCol ASC
  ";
  if ($res = $conn->query($sqlSvc)) {
    while($r = $res->fetch_assoc()) $upcoming[] = $r;
  }
}

/* --- tradein_requests (ถ้ามีคอลัมน์นัด) --- */
$hasTR = $conn->query("SHOW TABLES LIKE 'tradein_requests'")->num_rows > 0;
if ($hasTR) {
  $trStartCol = has_col($conn,'tradein_requests','appointment_start')
    ? 'appointment_start'
    : (has_col($conn,'tradein_requests','scheduled_at') ? 'scheduled_at' : null);

  $trEndExpr  = has_col($conn,'tradein_requests','appointment_end')
    ? 'appointment_end'
    : ($trStartCol ? "DATE_ADD($trStartCol, INTERVAL 60 MINUTE)" : 'NULL');

  if ($trStartCol) {
    $sqlTR = "
      SELECT id, 'tradein' AS ttype, device_type, brand, model,
             $trStartCol AS s_start,
             $trEndExpr  AS s_end
      FROM tradein_requests
      WHERE $trStartCol IS NOT NULL
        AND $trStartCol >= CURDATE()
        AND $trStartCol < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      ORDER BY $trStartCol ASC
    ";
    if ($res = $conn->query($sqlTR)) {
      while($r = $res->fetch_assoc()) $upcoming[] = $r;
    }
  }
}

/* notif count */
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
  <title>Admin Dashboard | WEB APP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b;
      --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06);
    }html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.7); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45);}
    body{background:var(--bg); color:var(--text);}
    .topbar{backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border);}
    html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }
    .app{display:grid; grid-template-columns:260px 1fr; gap:24px;}
    @media (max-width:992px){ .app{grid-template-columns:1fr} }
    .sidebar{position:sticky; top:90px; border:1px solid var(--border); border-radius:18px; background:var(--panel); box-shadow:var(--card-shadow); overflow:hidden;}
    .side-a{display:flex; align-items:center; gap:10px; padding:12px 16px; text-decoration:none; color:inherit; border-left:3px solid transparent;}
    .side-a:hover{background:#eef3ff}html[data-theme="dark"] .side-a:hover{background:#0f1a2d}
    .side-a.active{background:#eef3ff; border-left-color:var(--primary)}html[data-theme="dark"] .side-a.active{background:#0e1f3e}
    .glass{border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow);}
    .kpi .icon{width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; background:#4f46e5}
    .kpi-soft{background:linear-gradient(145deg,#ffffff,#f7faff); border:1px solid #eaf0fa; border-radius:16px; box-shadow:0 10px 40px rgba(2,6,23,.06);}
    .table>:not(caption)>*>*{ border-color:var(--border) }
    .hero{background:linear-gradient(135deg,#f8fbff,#f6f9ff); border:1px solid #e7eef7; border-radius:16px;}
    .chip{display:inline-flex; align-items:center; gap:6px; background:#eaf2ff; border:1px solid #cfe1ff; padding:4px 10px; border-radius:999px; font-weight:600; font-size:.85rem;}
     html[data-theme="dark"] .chip{ background:#0f1b33; border-color:#1d2b52; }
     
  </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline">แดชบอร์ด • สวัสดี, <?= h($admin_name) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-primary" href="calendar.php"><i class="bi bi-calendar3"></i> ปฏิทิน</a>
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
      <!-- theme -->
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
      <a class="side-a active" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
      <a class="side-a" href="orders.php"><i class="bi bi-receipt me-2"></i> Orders</a>
      <a class="side-a" href="products.php"><i class="bi bi-box-seam me-2"></i> Products</a>
      <a class="side-a" href="tradein_requests.php"><i class="bi bi-arrow-left-right me-2"></i> Trade-in</a>
      <a class="side-a" href="service_tickets.php"><i class="bi bi-wrench me-2"></i> Service</a>
      <a class="side-a" href="users.php"><i class="bi bi-people me-2"></i> Users</a>
      <a class="side-a" href="coupons_list.php"><i class="bi bi-ticket-detailed me-2"></i> Coupons</a>
      <a class="side-a" href="support.php"><i class="bi bi-chat-dots me-2"></i> กล่องข้อความ</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="d-flex flex-column gap-3">

    <!-- Hero / Quick CTA -->
    <div class="glass p-3 hero">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
          <div class="text-muted small">สรุปเร็ว</div>
          <div class="h4 m-0">ยอดขาย 7 วัน: <?= baht($revenue7) ?> ฿</div>
        </div>
        <a class="btn btn-primary" href="calendar.php"><i class="bi bi-calendar-week"></i> ดูปฏิทินนัด</a>
        <a class="btn btn-outline-primary" href="service_tickets.php"><i class="bi bi-wrench"></i> คิวซ่อม</a>
        <a class="btn btn-outline-success" href="tradein_requests.php"><i class="bi bi-arrow-left-right"></i> เทิร์นสินค้า</a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3">
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100 kpi-soft">
          <div class="d-flex align-items-center gap-3">
            <div class="icon"><i class="bi bi-people"></i></div>
            <div>
              <div class="text-muted small">Users</div>
              <div class="fs-3 fw-bold"><?= number_format($users_total) ?></div>
              <div class="small text-secondary">ทั้งหมด</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100 kpi-soft">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#0ea5e9"><i class="bi bi-box-seam"></i></div>
            <div>
              <div class="text-muted small">Active Products</div>
              <div class="fs-3 fw-bold"><?= number_format($products_active) ?></div>
              <div class="small text-secondary">พร้อมขาย</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100 kpi-soft">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#10b981"><i class="bi bi-receipt"></i></div>
            <div>
              <div class="text-muted small">Open Orders</div>
              <div class="fs-3 fw-bold"><?= number_format($orders_open) ?></div>
              <div class="small text-secondary">ใหม่/กำลังดำเนินการ</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="glass p-3 kpi h-100 kpi-soft">
          <div class="d-flex align-items-center gap-3">
            <div class="icon" style="background:#f59e0b"><i class="bi bi-bank2"></i></div>
            <div>
              <div class="text-muted small">Bank Pending</div>
              <div class="fs-3 fw-bold"><?= number_format($bank_pending) ?></div>
              <div class="small text-secondary">รอตรวจสลิป</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Service & Trade-in mini dashboards -->
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="glass p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="m-0"><i class="bi bi-wrench me-2"></i>งานซ่อม</h5>
            <a class="btn btn-sm btn-outline-primary" href="service_tickets.php">ไปที่คิวซ่อม</a>
          </div>
          <div class="row text-center g-3">
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold"><?= $repair_counts['all'] ?></div>
              <div class="text-muted">ทั้งหมด</div>
            </div>
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold text-danger"><?= $repair_counts['urgent'] ?></div>
              <div class="text-muted">เร่งด่วน</div>
            </div>
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold"><?= $repair_counts['queued'] ?></div>
              <div class="text-muted">เข้าคิว</div>
            </div>
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold"><?= $repair_counts['repairing'] ?></div>
              <div class="text-muted">กำลังซ่อม</div>
            </div>
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold text-success"><?= $repair_counts['done'] ?></div>
              <div class="text-muted">เสร็จ</div>
            </div>
            <div class="col-6 col-md">
              <div class="fs-3 fw-bold text-secondary"><?= $repair_counts['cancelled'] ?></div>
              <div class="text-muted">ยกเลิก</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="glass p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="m-0"><i class="bi bi-arrow-left-right me-2"></i>เทิร์นสินค้า</h5>
            <a class="btn btn-sm btn-outline-success" href="tradein_requests.php">ไปที่เทิร์น</a>
          </div>
          <div class="row text-center g-3">
            <div class="col-6 col-md"><div class="fs-3 fw-bold"><?= $trade_counts['all'] ?></div><div class="text-muted">ทั้งหมด</div></div>
            <div class="col-6 col-md"><div class="fs-4 fw-bold"><?= $trade_counts['reviewing'] ?></div><div class="text-muted">กำลังประเมิน</div></div>
            <div class="col-6 col-md"><div class="fs-4 fw-bold"><?= $trade_counts['offered'] ?></div><div class="text-muted">มีราคาเสนอ</div></div>
            <div class="col-6 col-md"><div class="fs-4 fw-bold text-success"><?= $trade_counts['completed'] ?></div><div class="text-muted">เสร็จสิ้น</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Upcoming 7 days -->
    <div class="glass p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="m-0"><i class="bi bi-calendar-week me-2"></i>นัดหมาย 7 วันถัดไป</h5>
        <a class="btn btn-sm btn-primary" href="calendar.php"><i class="bi bi-calendar3"></i> เปิดปฏิทิน</a>
      </div>
      <?php if(empty($upcoming)): ?>
        <div class="text-muted">ยังไม่มีนัดหมาย</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table mb-0 align-middle">
            <thead class="table-light">
              <tr><th>เวลา</th><th>ประเภท</th><th>รายละเอียด</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
              <?php foreach($upcoming as $ev): ?>
              <tr>
                <td><?= h($ev['s_start']) ?></td>
                <td><?= $ev['ttype']==='repair'?'ซ่อม':'เทิร์น' ?></td>
                <td><?= h(($ev['device_type']?:'')." — ".($ev['brand']?:'')." ".$ev['model']) ?></td>
                <td class="text-end">
                  <?php if($ev['ttype']==='repair'): ?>
                    <a class="btn btn-sm btn-outline-primary" href="service_ticket_detail.php?id=<?= (int)$ev['id'] ?>">เปิดใบงาน</a>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-success" href="tradein_detail.php?id=<?= (int)$ev['id'] ?>">เปิดคำขอ</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Latest orders -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-clock-history me-2"></i>ออเดอร์ล่าสุด</div>
        <form class="d-none d-sm-flex" action="orders.php" method="get">
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input name="q" type="search" class="form-control" placeholder="ค้นหาใน Orders">
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>ลูกค้า</th><th>ยอดรวม</th><th>ชำระ</th><th>สถานะออเดอร์</th><th>วันที่</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($latest)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีคำสั่งซื้อ</td></tr>
          <?php else: foreach($latest as $o): ?>
            <tr id="row-<?= (int)$o['id'] ?>">
              <td><?= (int)$o['id'] ?></td>
              <td><?= h($o['username'] ?? ('UID '.$o['user_id'])) ?></td>
              <td><?= baht($o['total_amount']) ?> ฿</td>
              <td><?php $pbadge = $PAY_BADGE[$o['payment_status']] ?? 'secondary'; ?>
                <span class="badge bg-<?= $pbadge ?>"><?= h($PAY_THAI[$o['payment_status']] ?? $o['payment_status']) ?></span>
              </td>
              <td><?php $effst = effective_status_of($o); $obadge = $ORDER_BADGE[$effst] ?? 'secondary'; ?>
                <span class="badge bg-<?= $obadge ?>"><?= h($ORDER_THAI[$effst] ?? $effst) ?></span>
              </td>
              <td><?= h($o['created_at']) ?></td>
              <td><a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> ดู</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Support widget -->
    <div class="glass">
      <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
        <div class="fw-bold"><i class="bi bi-chat-dots me-2"></i>กล่องข้อความ (ล่าสุด)</div>
        <a href="support.php" class="btn btn-sm btn-outline-primary">เปิดแบบเต็มจอ</a>
      </div>
      <div class="p-3">
        <div class="row g-3" style="height:430px">
          <div class="col-lg-4">
            <div class="h-100 border rounded-3" id="threadList" style="overflow:auto">
              <div class="p-3 text-center text-muted">กำลังโหลด...</div>
            </div>
          </div>
          <div class="col-lg-8">
            <div class="d-flex flex-column h-100 border rounded-3 overflow-hidden">
              <div class="p-2 border-bottom d-flex align-items-center gap-2">
                <div class="fw-semibold" id="roomTitle">เลือกผู้ใช้ทางซ้ายเพื่อเริ่มคุย</div>
                <div class="ms-auto d-flex align-items-center gap-2">
                  <span class="small text-muted" id="chatStatus">—</span>
                  <button class="btn btn-sm btn-outline-danger d-none" id="chatEnd"><i class="bi bi-x-circle"></i> สิ้นสุดแชท</button>
                </div>
              </div>
              <div id="chatMsgs" class="flex-grow-1 p-2" style="overflow:auto; background:linear-gradient(180deg,#f9fbff,#fff)"></div>
              <form id="chatForm" class="p-2 border-top d-flex gap-2">
                <input type="text" class="form-control" id="chatInput" placeholder="พิมพ์ตอบกลับ…" required disabled>
                <button class="btn btn-primary" id="chatSend" disabled><i class="bi bi-send"></i></button>
              </form>
            </div>
          </div>
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

/* ===== Support Chat (ย่อ) ===== */
const ADMIN_NAME = <?= json_encode($admin_name, JSON_UNESCAPED_UNICODE) ?>;
const threadList = document.getElementById('threadList');
const chatMsgs   = document.getElementById('chatMsgs');
const roomTitle  = document.getElementById('roomTitle');
const chatStatus = document.getElementById('chatStatus');
const chatForm   = document.getElementById('chatForm');
const chatInput  = document.getElementById('chatInput');
const chatSend   = document.getElementById('chatSend');
const chatEndBtn = document.getElementById('chatEnd');

let activeUid = 0, lastMsgId = 0, pollTimer = null;
function esc(s){ const d=document.createElement('div'); d.innerText=s??''; return d.innerHTML; }
function normalizeThread(it){ return { id:Number(it.id ?? it.user_id ?? 0), username:it.username ?? it.name ?? ('UID '+(it.id ?? '')), last_time:it.last_time ?? it.updated_at ?? it.created_at ?? '', last_message:it.last_message ?? it.preview ?? it.message ?? '', unread:Number(it.unread ?? it.unread_count ?? 0) }; }
function normalizeMsg(m){ const id=Number(m.id ?? m.msg_id ?? 0); const sender=(m.sender?String(m.sender):(String(m.from_admin??'')==='1'?'admin':(String(m.from_user??'')==='1'?'user':(m.is_admin?'admin':'user')))); const message=m.message ?? m.text ?? ''; const time=m.time ?? m.created_at ?? m.sent_at ?? ''; return {id, sender, message, time}; }
function renderThreads(items){
  const list = (items||[]).map(normalizeThread);
  if(list.length===0){ threadList.innerHTML = `<div class="p-3 text-center text-muted">ยังไม่มีข้อความ</div>`; return; }
  threadList.innerHTML = list.map(it => `
    <div class="d-flex align-items-start gap-2 p-2 border-bottom ${it.id==activeUid?'bg-light':''}" data-uid="${it.id}" role="button">
      <div class="flex-grow-1">
        <div class="d-flex justify-content-between"><div class="fw-semibold">@${esc(it.username)}</div><div class="small text-muted">${esc(it.last_time)}</div></div>
        <div class="small text-muted text-truncate">${esc(it.last_message)}</div>
      </div>
      ${it.unread>0?`<span class="badge bg-danger">${it.unread}</span>`:''}
    </div>`).join('');
  threadList.querySelectorAll('[data-uid]').forEach(el=> el.addEventListener('click', ()=>openRoom(Number(el.dataset.uid))));
}
function appendMsgs(list){
  const arr=(list||[]).map(normalizeMsg);
  for(const m of arr){
    const who=(m.sender==='admin')?('แอดมิน: '+ADMIN_NAME):'ลูกค้า';
    const div=document.createElement('div');
    div.className=`mb-2 ${m.sender==='admin'?'text-end':''}`;
    div.innerHTML = `<div class="d-inline-block px-3 py-2 rounded-3 ${m.sender==='admin'?'bg-primary text-white':'bg-light'}">${esc(m.message)}</div><div class="small text-muted">${esc(who)} • ${esc(m.time)}</div>`;
    chatMsgs.appendChild(div); lastMsgId = Math.max(lastMsgId, m.id||0);
  }
  chatMsgs.scrollTop = chatMsgs.scrollHeight;
}
async function loadThreads(){ try{ const r=await fetch('support_threads_api.php'); const j=await r.json(); renderThreads(j.items||j.data||[]); return (j.items||j.data||[]).map(normalizeThread);}catch(e){ threadList.innerHTML = `<div class="p-3 text-center text-danger">โหลดรายการไม่สำเร็จ</div>`; return [];} }
async function openRoom(uid){
  if(!uid) return; activeUid=uid; lastMsgId=0; chatMsgs.innerHTML=''; chatInput.disabled=chatSend.disabled=false; chatEndBtn.classList.remove('d-none');
  try{ const head=await fetch('support_threads_api.php?single='+uid); const h=await head.json(); const name=h.username||h.user?.username||h.name||('UID '+uid); roomTitle.textContent='คุยกับ @'+name+' • แอดมิน: '+ADMIN_NAME; }catch(_){ roomTitle.textContent='คุยกับ UID '+uid+' • แอดมิน: '+ADMIN_NAME; }
  await loadMsgs(true); if(pollTimer) clearInterval(pollTimer); pollTimer=setInterval(pollMsgs, 2500);
  threadList.querySelectorAll('[data-uid]').forEach(el=> el.classList.toggle('bg-light', Number(el.dataset.uid)===uid));
}
async function loadMsgs(first=false){ try{ const r=await fetch(`support_thread_api.php?uid=${activeUid}&since=0`); const j=await r.json(); appendMsgs(j.items||j.data||[]); chatStatus.textContent='ออนไลน์'; }catch(e){ if(first) chatStatus.textContent='ออฟไลน์'; } }
async function pollMsgs(){ if(!activeUid) return; try{ const r=await fetch(`support_thread_api.php?uid=${activeUid}&since=${lastMsgId}`); const j=await r.json(); appendMsgs(j.items||j.data||[]); chatStatus.textContent='ออนไลน์'; loadThreads(); }catch(e){ chatStatus.textContent='ออฟไลน์'; } }
chatForm.addEventListener('submit', async (e)=>{ e.preventDefault(); if(!activeUid) return; const text=chatInput.value.trim(); if(!text) return; chatInput.value=''; chatInput.focus(); await fetch('support_admin_send.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({ uid:String(activeUid), message:text }) }); });
document.getElementById('chatEnd').addEventListener('click', async ()=>{ if(!activeUid) return; if(!confirm('ยืนยันสิ้นสุดแชทนี้?')) return; try{ const r=await fetch('../support_end_chat.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({uid:String(activeUid)})}); const j=await r.json(); if(j.ok){ clearInterval(pollTimer); chatInput.disabled=chatSend.disabled=true; document.getElementById('chatEnd').disabled=true; chatMsgs.innerHTML='<div class="text-center text-muted mt-5">สิ้นสุดแชทแล้ว</div>'; chatStatus.textContent='ปิดแล้ว'; loadThreads(); } else { alert('ไม่สามารถสิ้นสุดแชทได้'); } }catch(e){ alert('เกิดข้อผิดพลาด'); } });
(async ()=>{ const threads=await loadThreads(); if(threads.length){ openRoom(threads[0].id); }})();
</script>
</body>
</html>
