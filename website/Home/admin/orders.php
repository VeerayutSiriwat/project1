<?php
// Home/admin/orders.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

// auth: admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/orders.php'); exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }

// ===== Map ภาษาไทย =====
$PAY_THAI = [
  'unpaid'   => 'ยังไม่ชำระ',
  'pending'  => 'รอตรวจสอบ',
  'paid'     => 'ชำระแล้ว',
  'refunded' => 'คืนเงินแล้ว',
  'expired'  => 'หมดเวลาชำระ',
];
$PAY_BADGE = [
  'unpaid'   => 'secondary',
  'pending'  => 'warning text-dark',
  'paid'     => 'success',
  'refunded' => 'info text-dark',
  'expired'  => 'danger',
];
$ORDER_THAI = [
  'pending'          => 'ใหม่/กำลังตรวจสอบ',
  'processing'       => 'กำลังเตรียม/แพ็ค',
  'shipped'          => 'ส่งออกจากคลัง',
  'delivered'        => 'ถึงปลายทาง',
  'completed'        => 'เสร็จสิ้น',
  'cancel_requested' => 'รอยืนยันยกเลิก',
  'cancelled'        => 'ยกเลิก',
];
$ORDER_BADGE = [
  'pending'          => 'info text-dark',
  'processing'       => 'primary',
  'shipped'          => 'primary',
  'delivered'        => 'success',
  'completed'        => 'success',
  'cancel_requested' => 'warning text-dark',
  'cancelled'        => 'danger',
];

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];

/* =======================
   รับตัวกรอง
======================= */
$status  = $_GET['status'] ?? 'all';
$allowed = ['pending','processing','shipped','delivered','completed','cancel_requested','cancelled','all'];
if (!in_array($status, $allowed, true)) $status = 'all';

$q_user = trim($_GET['q_user'] ?? '');
$start  = trim($_GET['start']  ?? '');
$end    = trim($_GET['end']    ?? '');
$end_dt = $end ? ($end.' 23:59:59') : '';

/* =======================
   Pagination
======================= */
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* =======================
   COUNT ทั้งหมดตามตัวกรอง
======================= */
$count_sql = "
  SELECT COUNT(DISTINCT o.id) AS c
  FROM orders o
  LEFT JOIN users u ON u.id=o.user_id
  WHERE 1=1
";
$c_params=[]; $c_types="";
if ($status !== 'all'){ $count_sql.=" AND o.status=? ";          $c_params[]=$status;              $c_types.="s"; }
if ($q_user !== '')   { $count_sql.=" AND (u.username LIKE ?) "; $c_params[]='%'.$q_user.'%';      $c_types.="s"; }
if ($start !== '')    { $count_sql.=" AND o.created_at >= ? ";   $c_params[]=$start.' 00:00:00';   $c_types.="s"; }
if ($end_dt !== '')   { $count_sql.=" AND o.created_at <= ? ";   $c_params[]=$end_dt;              $c_types.="s"; }

$stc = $conn->prepare($count_sql);
if ($c_params) { $stc->bind_param($c_types, ...$c_params); }
$stc->execute();
$total_rows = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
$stc->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

/* =======================
   Query รายการตามตัวกรอง + แบ่งหน้า
======================= */
$sql = "
  SELECT 
    o.id, o.user_id, o.status, o.payment_method, o.payment_status, o.created_at,
    o.cancel_reason, o.cancel_requested_at,
    o.slip_image, o.expires_at,
    u.username,
    COALESCE(SUM(oi.quantity*oi.unit_price),0) AS total_amount,
    -- เวลาที่เหลือ (วินาที) ถึงหมดเวลา
    GREATEST(
      0,
      TIMESTAMPDIFF(
        SECOND,
        NOW(),
        COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL 15 MINUTE))
      )
    ) AS remaining_sec
  FROM orders o
  LEFT JOIN users u ON u.id=o.user_id
  LEFT JOIN order_items oi ON oi.order_id=o.id
  WHERE 1=1
";
$params=[]; $types="";
if ($status !== 'all'){ $sql.=" AND o.status=? ";          $params[]=$status;            $types.="s"; }
if ($q_user !== '')   { $sql.=" AND (u.username LIKE ?) "; $params[]='%'.$q_user.'%';    $types.="s"; }
if ($start !== '')    { $sql.=" AND o.created_at >= ? ";   $params[]=$start.' 00:00:00'; $types.="s"; }
if ($end_dt !== '')   { $sql.=" AND o.created_at <= ? ";   $params[]=$end_dt;            $types.="s"; }

$sql .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT ? OFFSET ? ";
$params[] = $per_page; $types .= "i";
$params[] = $offset;   $types .= "i";

$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function page_link($p){
  $qs = $_GET; $qs['page']=$p;
  return 'orders.php?'.http_build_query($qs);
}
$returnQS = 'orders.php?'.http_build_query(['status'=>$status,'q_user'=>$q_user,'start'=>$start,'end'=>$end,'page'=>$page]);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>คำสั่งซื้อ | แอดมิน</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body{background:#f7fafc}
    .toolbar-card{border:1px solid #e9eef5;border-radius:14px;padding:12px;background:#fff}
    .card{border-radius:16px;border:1px solid #e9eef5}
    th,td{vertical-align:middle}
    .ellipsis-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .mini-form input[type="text"]{max-width:160px}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold text-primary" href="dashboard.php"><i class="bi bi-speedometer2"></i> Admin</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container-fluid my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-light border" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> ย้อนกลับ
      </button>
      <h3 class="mb-0"><i class="bi bi-receipt"></i> คำสั่งซื้อ</h3>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= h($_SESSION['flash']); unset($_SESSION['flash']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- ตัวกรอง -->
  <div class="toolbar-card mb-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label">สถานะออเดอร์</label>
        <select name="status" class="form-select">
          <option value="all"               <?= $status==='all'?'selected':''?>>ทั้งหมด</option>
          <option value="pending"           <?= $status==='pending'?'selected':''?>>ใหม่/กำลังตรวจสอบ</option>
          <option value="processing"        <?= $status==='processing'?'selected':''?>>กำลังเตรียม/แพ็ค</option>
          <option value="shipped"           <?= $status==='shipped'?'selected':''?>>ส่งออกจากคลัง</option>
          <option value="delivered"         <?= $status==='delivered'?'selected':''?>>ถึงปลายทาง</option>
          <option value="completed"         <?= $status==='completed'?'selected':''?>>เสร็จสิ้น</option>
          <option value="cancel_requested"  <?= $status==='cancel_requested'?'selected':''?>>รอยืนยันยกเลิก</option>
          <option value="cancelled"         <?= $status==='cancelled'?'selected':''?>>ยกเลิก</option>
        </select>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">ชื่อลูกค้า (username)</label>
        <input type="text" name="q_user" class="form-control" value="<?= h($q_user) ?>" placeholder="พิมพ์ชื่อลูกค้า...">
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">วันที่เริ่ม</label>
        <input type="date" name="start" class="form-control" value="<?= h($start) ?>">
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">วันที่สิ้นสุด</label>
        <input type="date" name="end" class="form-control" value="<?= h($end) ?>">
      </div>

      <div class="col-12 d-grid d-md-flex gap-2 mt-2">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> กรอง</button>
        <a class="btn btn-outline-secondary" href="orders.php"><i class="bi bi-x-circle"></i> ล้างตัวกรอง</a>
      </div>
    </form>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>เลขคำสั่งซื้อ</th>
            <th>ลูกค้า</th>
            <th>ยอดรวม</th>
            <th>วิธีชำระ</th>
            <th>สถานะชำระ</th>
            <th>สถานะออเดอร์</th>
            <th style="min-width:260px">คำขอยกเลิก</th>
            <th>วันที่สร้าง</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($orders)): ?>
            <tr><td colspan="9" class="text-center text-muted">ไม่มีออเดอร์</td></tr>
          <?php else: foreach($orders as $o):

            // ===== คำนวณ “ยกเลิกโดยอัตโนมัติ” เมื่อหมดเวลา =====
            $isBank  = ($o['payment_method'] === 'bank');
            $noSlip  = empty($o['slip_image']);
            $expired_now = ((int)($o['remaining_sec'] ?? 0) === 0);

            $expired_by_rule = (
              $o['payment_status'] === 'expired'
              || ($isBank && $noSlip && in_array($o['payment_status'], ['unpaid','pending'], true) && $expired_now)
            );

            $effective_status = $expired_by_rule ? 'cancelled' : ($o['status'] ?: 'pending');

            $payBadge = $PAY_BADGE[$o['payment_status']] ?? 'secondary';
            $ordBadge = $ORDER_BADGE[$effective_status] ?? 'secondary';
          ?>
            <tr>
              <td><?= (int)$o['id'] ?></td>
              <td><?= h($o['username'] ?? ('UID '.$o['user_id'])) ?></td>
              <td><?= baht($o['total_amount']) ?> ฿</td>
              <td><?= $o['payment_method']==='bank' ? 'โอนธนาคาร' : 'เก็บเงินปลายทาง' ?></td>

              <!-- Payment Status -->
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-<?= $payBadge ?>"><?= h($PAY_THAI[$o['payment_status']] ?? $o['payment_status']) ?></span>
                  <form action="order_update.php" method="post" class="d-inline">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="type" value="payment_status">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="return" value="<?= h($returnQS) ?>">
                    <select name="value" class="form-select form-select-sm" onchange="this.form.submit()">
                      <?php foreach (['unpaid','pending','paid','refunded','expired'] as $val): ?>
                        <option value="<?= $val ?>" <?= $o['payment_status']===$val?'selected':'' ?>>
                          <?= h($PAY_THAI[$val]) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </div>
              </td>

              <!-- Order Status (แสดงผลตาม effective_status และปิดถ้ายกเลิก) -->
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-<?= $ordBadge ?>"><?= h($ORDER_THAI[$effective_status] ?? $effective_status) ?></span>
                  <form action="order_update.php" method="post" class="d-inline">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="type" value="order_status">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="return" value="<?= h($returnQS) ?>">
                    <select name="value" class="form-select form-select-sm" onchange="this.form.submit()" <?= $effective_status==='cancelled'?'disabled':'' ?>>
                      <?php foreach (['pending','processing','shipped','delivered','completed','cancel_requested','cancelled'] as $val): ?>
                        <option value="<?= $val ?>" <?= $effective_status===$val?'selected':'' ?>>
                          <?= h($ORDER_THAI[$val]) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </div>
              </td>

              <!-- Cancel request info -->
              <td>
                <?php if (!empty($o['cancel_requested_at'])): ?>
                  <div>
                    <span class="badge bg-warning text-dark">ร้องขอยกเลิก</span>
                    <span class="text-muted small ms-2"><?= h($o['cancel_requested_at']) ?></span>
                  </div>
                  <?php if (!empty($o['cancel_reason'])): ?>
                    <div class="small ellipsis-2 mt-1"><i class="bi bi-chat-quote"></i> <?= h($o['cancel_reason']) ?></div>
                  <?php endif; ?>

                  <?php if ($effective_status==='cancel_requested'): ?>
                    <div class="mt-2 d-flex flex-wrap align-items-center gap-1">
                      <form action="order_update.php" method="post" class="d-inline mini-form">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <input type="hidden" name="type" value="cancel_decision">
                        <input type="hidden" name="value" value="approve">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="return" value="<?= h($returnQS) ?>">
                        <input type="text" name="note" class="form-control form-control-sm d-inline-block" placeholder="เหตุผล (ถ้ามี)">
                        <button class="btn btn-sm btn-outline-danger mt-1 mt-sm-0">อนุมัติยกเลิก</button>
                      </form>

                      <form action="order_update.php" method="post" class="d-inline mini-form">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <input type="hidden" name="type" value="cancel_decision">
                        <input type="hidden" name="value" value="reject">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="return" value="<?= h($returnQS) ?>">
                        <input type="text" name="note" class="form-control form-control-sm d-inline-block" placeholder="เหตุผล (ถ้ามี)">
                        <button class="btn btn-sm btn-outline-secondary mt-1 mt-sm-0">ปฏิเสธคำขอ</button>
                      </form>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>

              <td><?= h($o['created_at']) ?></td>
              <td class="text-nowrap">
                <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i> รายละเอียด
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
    <nav class="mt-3">
      <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $page<=1?'#':h(page_link($page-1)) ?>">ก่อนหน้า</a>
        </li>
        <?php
          $window = 2;
          $start_p = max(1, $page-$window);
          $end_p   = min($total_pages, $page+$window);

          if ($start_p > 1){
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link(1)).'">1</a></li>';
            if ($start_p > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for($p=$start_p; $p<=$end_p; $p++){
            echo '<li class="page-item '.($p==$page?'active':'').'"><a class="page-link" href="'.h(page_link($p)).'">'.$p.'</a></li>';
          }
          if ($end_p < $total_pages){
            if ($end_p < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link($total_pages)).'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $page>=$total_pages?'#':h(page_link($page+1)) ?>">ถัดไป</a>
        </li>
      </ul>
      <div class="text-center text-muted small">หน้า <?=$page?> / <?=$total_pages?> • ทั้งหมด <?=$total_rows?> ออเดอร์</div>
    </nav>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
