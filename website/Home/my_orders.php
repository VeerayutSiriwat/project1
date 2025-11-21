<?php 
// Home/my_orders.php — premium UI, softer & easier to read
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=my_orders.php"); exit;
}

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function has_col(mysqli $c, string $t, string $col): bool {
  $t = preg_replace('/[^a-zA-Z0-9_]/','',$t);
  $col = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $c->query("SHOW COLUMNS FROM `$t` LIKE '$col'");
  return $q && $q->num_rows>0;
}

/* ---------- รับพารามิเตอร์ ---------- */
$allowed_status = ['all','new','processing','shipped','delivered','completed','cancelled','cancel_requested'];
$statusParam = strtolower(trim($_GET['status'] ?? 'all'));
if (!in_array($statusParam, $allowed_status, true)) $statusParam = 'all';

$user_id = (int)$_SESSION['user_id'];

// TH time
date_default_timezone_set('Asia/Bangkok');
$conn->query("SET time_zone = '+07:00'");

// paging
$per_page = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$prev   = max(1, $page - 1);
$next   = $page + 1;

// payment window (match upload_slip.php)
$minutes_window = 15;

/* ---------- นับจำนวนรายการสำหรับเพจจิเนชัน ---------- */
if ($statusParam === 'all') {
  $stCount = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE user_id=?");
  $stCount->bind_param("i", $user_id);
} else {
  $stCount = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE user_id=? AND LOWER(status)=?");
  $stCount->bind_param("is", $user_id, $statusParam);
}
$stCount->execute();
$total_orders = (int)($stCount->get_result()->fetch_assoc()['total'] ?? 0);
$stCount->close();
$total_pages = max(1, (int)ceil($total_orders / $per_page));
$next = min($total_pages, $next);

/* ---------- ดึงรายการคำสั่งซื้อ (หน้า current) ---------- */
$hasTotalCol = has_col($conn,'orders','total_price');
$hasDiscCol  = has_col($conn,'orders','discount_total');

$limit = (int)$per_page;
$ofs   = (int)$offset;

$selectDiscount = $hasDiscCol ? "COALESCE(o.discount_total,0)" : "0";
$selectPayable  = $hasTotalCol 
  ? "COALESCE(o.total_price, 0)"
  : "(COALESCE(SUM(oi.quantity * oi.unit_price),0) - {$selectDiscount})";

$baseSelect = "
    SELECT
      o.id,
      o.status               AS order_status,
      o.cancel_reason,
      o.payment_method,
      o.payment_status,
      o.created_at,
      o.slip_image,
      COALESCE(SUM(oi.quantity * oi.unit_price), 0)            AS total_amount,
      {$selectDiscount}                                        AS discount_total,
      {$selectPayable}                                         AS payable_total,
      COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL ? MINUTE)) AS expires_at,
      GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL ? MINUTE)))) AS remaining_sec
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
";

if ($statusParam === 'all') {
  $sql = $baseSelect."
    WHERE o.user_id = ?
    GROUP BY o.id, o.status, o.cancel_reason, o.payment_method, o.payment_status, o.created_at, o.slip_image, o.expires_at
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("iiiii", $minutes_window, $minutes_window, $user_id, $limit, $ofs);
} else {
  $sql = $baseSelect."
    WHERE o.user_id = ? AND LOWER(o.status) = ?
    GROUP BY o.id, o.status, o.cancel_reason, o.payment_method, o.payment_status, o.created_at, o.slip_image, o.expires_at
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("iiisii", $minutes_window, $minutes_window, $user_id, $statusParam, $limit, $ofs);
}
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ---------- ตัวเลขสรุป tab ทั้งหมด ---------- */
$statusKeys = ['new','processing','shipped','delivered','completed','cancelled','cancel_requested'];
$cnt = array_fill_keys(array_merge(['all'], $statusKeys), 0);

// ทั้งหมด
$stCountAll = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE user_id=?");
$stCountAll->bind_param("i", $user_id);
$stCountAll->execute();
$cnt['all'] = (int)($stCountAll->get_result()->fetch_assoc()['total'] ?? 0);
$stCountAll->close();

// ตามสถานะ
$stAgg = $conn->prepare("SELECT LOWER(status) s, COUNT(*) c FROM orders WHERE user_id=? GROUP BY s");
$stAgg->bind_param("i", $user_id);
$stAgg->execute();
$rAgg = $stAgg->get_result();
while($row = $rAgg->fetch_assoc()){
  $s = (string)$row['s']; $c = (int)$row['c'];
  if (isset($cnt[$s])) $cnt[$s] = $c;
}
$stAgg->close();

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>คำสั่งซื้อของฉัน | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --card:#ffffff;
      --line:#e1ebf7;
      --ink:#0b1a37;
      --muted:#6b7280;
      --pri:#2563eb;
      --pri2:#4f46e5;
      --good:#16a34a;
      --warn:#f59e0b;
      --bad:#ef4444;
    }
    .page-head{
      border-radius:20px;
      color:#fff;
      padding:16px 18px 12px;
      background:linear-gradient(135deg,var(--pri) 0%, var(--pri2) 55%, #0ea5e9 100%);
      box-shadow:0 14px 36px rgba(37,99,235,.18);
    }
    .page-head h3{
      font-weight:700;
      letter-spacing:.01em;
    }
    .chips{
      display:flex;
      gap:.5rem;
      flex-wrap:wrap;
      font-size:.85rem;
    }
    .chip{
      border:1px solid rgba(255,255,255,.45);
      background:rgba(255,255,255,.10);
      color:#e5edff;
      border-radius:999px;
      padding:.15rem .6rem;
      font-weight:600;
    }
    .tabs{
      margin-top:10px;
      background:#f8fbff;
      border-radius:999px;
      padding:4px;
      display:flex;
      gap:4px;
      flex-wrap:wrap;
    }
    .tab-btn{
      border:none;
      background:transparent;
      color:#64748b;
      padding:6px 12px;
      border-radius:999px;
      font-weight:600;
      font-size:.85rem;
      text-decoration:none;
      white-space:nowrap;
    }
    .tab-btn span.badge{
      font-weight:600;
      font-size:.7rem;
    }
    .tab-btn.active{
      color:#0b1a37;
      background:#ffffff;
      box-shadow:0 6px 16px rgba(15,23,42,.08);
    }

    .shell{
      background:var(--card);
      border-radius:18px;
      border:1px solid var(--line);
      box-shadow:0 16px 40px rgba(2,6,23,.06);
      overflow:hidden;
    }

    .toolbar{
      padding:10px 14px;
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
      border-bottom:1px solid #edf1f8;
      background:#fafbff;
    }
    .toolbar .form-control{
      font-size:.9rem;
    }
    .toolbar .btn-icon{
      border-radius:999px;
      padding:.35rem .7rem;
      font-size:.9rem;
    }

    .tbl-wrap{overflow:auto;}

    .table-modern{
      margin:0;
      font-size:.9rem;
    }
    .table-modern thead th{
      background:#f5f7fc;
      border-bottom:1px solid #e3e8f5;
      color:#111827;
      font-weight:600;
    }
    .table-modern tbody tr{
      transition:background .12s;
    }
    .table-modern tbody tr:hover{
      background:#f9fbff;
    }
    .table-modern td,.table-modern th{
      vertical-align:middle;
    }

    .badge-soft{
      background:#eef2f7;
      border:1px solid #e5ecf6;
      color:#111827;
    }
    .timer-badge{
      display:inline-flex;
      align-items:center;
      gap:.3rem;
      background:#0b5ed7;
      color:#fff;
      border-radius:999px;
      padding:.15rem .5rem;
      font-weight:700;
      font-size:.8rem;
    }
    .timer-badge i{font-size:.95rem}

    .actions .btn{
      border-radius:10px;
      font-size:.85rem;
    }

    .small-muted{
      font-size:.8rem;
      color:#94a3b8;
    }

    .sticky-pagination{
      position:sticky;
      bottom:0;
      background:linear-gradient(180deg,rgba(246,248,251,0),#f6f8fb 40%, #f6f8fb);
      padding:.4rem .6rem .6rem;
    }

    .badge.rounded-pill{ font-weight:700; }
    .bg-primary-subtle{ background:#e7f0ff!important; }
    .bg-success-subtle{ background:#e8fdf3!important; }

    /* mobile: แปลงเป็นการ์ด อ่านง่ายขึ้น */
    @media (max-width: 992px){
      .toolbar{
        padding:8px 10px;
      }
      .table-modern thead{display:none;}
      .table-modern tbody tr{
        display:block;
        margin:10px;
        border:1px solid #e5ecf6;
        border-radius:14px;
        padding:.6rem .7rem;
        background:#ffffff;
        box-shadow:0 8px 24px rgba(15,23,42,.04);
      }
      .table-modern tbody td{
        display:flex;
        justify-content:space-between;
        border:0;
        border-bottom:1px dashed #edf1f8;
        padding:.35rem 0;
      }
      .table-modern tbody td:last-child{
        border-bottom:0;
        padding-top:.45rem;
      }
      .table-modern tbody td::before{
        content:attr(data-label);
        font-weight:600;
        color:#6b7280;
        max-width:45%;
        padding-right:.75rem;
      }
      .actions{
        justify-content:flex-end;
        width:100%;
      }
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <div class="page-head">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h3 class="m-0">
          <i class="bi bi-bag me-2"></i>คำสั่งซื้อของฉัน
        </h3>
        <div class="small" style="opacity:.9;margin-top:2px;">
          ดูสถานะออเดอร์ทั้งหมดของคุณได้จากหน้ารวมเดียว
        </div>
      </div>
      <div class="chips">
        <span class="chip">
          <i class="bi bi-list-ul me-1"></i> ทั้งหมด <?= (int)$total_orders ?>
        </span>
        <span class="chip">
          <i class="bi bi-clock-history me-1"></i> ยืนยันโอนภายใน <?= (int)$minutes_window ?> นาที
        </span>
      </div>
    </div>

    <div class="tabs" role="tablist" aria-label="Filters">
      <a class="tab-btn <?= $statusParam==='all'?'active':'' ?>" href="?status=all">
        ทั้งหมด
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['all'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='new'?'active':'' ?>" href="?status=new">
        ใหม่
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['new'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='processing'?'active':'' ?>" href="?status=processing">
        กำลังดำเนินการ
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['processing'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='shipped'?'active':'' ?>" href="?status=shipped">
        ส่งออก
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['shipped'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='delivered'?'active':'' ?>" href="?status=delivered">
        จัดส่งแล้ว
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['delivered'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='completed'?'active':'' ?>" href="?status=completed">
        เสร็จสิ้น
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['completed'] ?></span>
      </a>
      <a class="tab-btn <?= $statusParam==='cancelled'?'active':'' ?>" href="?status=cancelled">
        ยกเลิก
        <span class="badge text-bg-light ms-1"><?= (int)$cnt['cancelled'] ?></span>
      </a>
    </div>
  </div>

  <?php if ($total_orders === 0): ?>
    <div class="alert alert-info shadow-sm mt-3">
      <i class="bi bi-info-circle me-1"></i> คุณยังไม่มีคำสั่งซื้อ
    </div>
  <?php else: ?>
    <div class="shell mt-3">
      <!-- toolbar -->
      <div class="toolbar">
        <div class="input-group" style="max-width:420px">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" id="q" class="form-control" placeholder="ค้นหา #ออเดอร์ / วันที่ / วิธีชำระ / ยอดรวม">
        </div>
        <button id="btnRefresh" class="btn btn-outline-primary btn-icon ms-auto" title="รีเฟรช">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>

      <div class="tbl-wrap">
        <table class="table table-modern align-middle" id="ordersTable">
          <thead>
            <tr>
              <th style="min-width:90px">หมายเลข</th>
              <th style="min-width:160px">วันที่</th>
              <th style="min-width:130px">ยอดชำระ</th>
              <th style="min-width:140px">วิธีชำระ</th>
              <th style="min-width:170px">สถานะชำระเงิน</th>
              <th style="min-width:190px">สถานะคำสั่งซื้อ</th>
              <th style="min-width:110px">สลิป</th>
              <th style="min-width:120px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($orders as $o):
            $isBank    = ($o['payment_method'] === 'bank');
            $expired   = ((int)$o['remaining_sec'] === 0);
            $canUpload = ($isBank && !$expired && in_array($o['payment_status'], ['unpaid','pending'], true));
            $expiresAt = h(date('Y-m-d H:i:s', strtotime($o['expires_at'])));
            $createdAt = h(date('Y-m-d H:i:s', strtotime($o['created_at'])));
            $oid       = (int)$o['id'];

            $subtotal  = (float)$o['total_amount'];
            $discount  = max(0.0, (float)($o['discount_total'] ?? 0));
            $payable   = max(0.0, (float)($o['payable_total'] ?? ($subtotal - $discount)));

            // payment badge
            $payBadge = '<span class="badge bg-secondary">ยังไม่ชำระ</span>';
            if ($o['payment_status'] === 'paid')          $payBadge = '<span class="badge bg-success">ชำระแล้ว</span>';
            elseif ($o['payment_status'] === 'pending')   $payBadge = '<span class="badge bg-warning text-dark">รอตรวจสอบ</span>';
            elseif ($o['payment_status'] === 'refunded')  $payBadge = '<span class="badge bg-secondary">คืนเงินแล้ว</span>';
            elseif ($o['payment_status'] === 'expired' || ($isBank && $expired)) $payBadge = '<span class="badge bg-danger">หมดเวลาชำระ</span>';

            // order status block
            ob_start();
            $isExpiredBank = ($isBank && ($o['payment_status'] === 'expired' || $expired));
            if ($isExpiredBank){
              echo '<span class="badge bg-danger">หมดเวลาชำระ</span><div class="small-muted">ออเดอร์นี้หมดเวลาชำระแล้ว</div>';
            } elseif ($o['order_status'] === 'completed'){
              echo '<span class="badge bg-success">เสร็จสิ้น</span>';
            } elseif ($o['order_status'] === 'delivered'){
              echo '<span class="badge bg-success">จัดส่งแล้ว</span>';
            } elseif (in_array($o['order_status'], ['shipped','processing'], true)){
              echo '<span class="badge bg-primary">กำลังดำเนินการ</span>';
            } elseif ($o['order_status'] === 'cancel_requested'){
              echo '<span class="badge bg-warning text-dark">รอยืนยันยกเลิก</span>';
              if (!empty($o['cancel_reason'])){
                echo '<div class="small-muted">เหตุผล: '.h($o['cancel_reason']).'</div>';
              }
            } elseif ($o['order_status'] === 'cancelled'){
              echo '<span class="badge bg-danger">ยกเลิก</span>';
              if (!empty($o['cancel_reason'])){
                echo '<div class="small-muted">เหตุผล: '.h($o['cancel_reason']).'</div>';
              }
            } else {
              echo '<span class="badge bg-info text-dark">ใหม่</span>';
              if (!$isExpiredBank){
                echo '
                  <form method="post" action="request_cancel.php" class="mt-2" onsubmit="return confirm(\'ยืนยันส่งคำขอยกเลิก #'.$oid.' ?\')">
                    <div class="input-group input-group-sm">
                      <input type="text" name="reason" class="form-control" placeholder="เหตุผลการยกเลิก" required>
                      <input type="hidden" name="id" value="'.$oid.'">
                      <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> ยกเลิก</button>
                    </div>
                  </form>
                ';
              }
            }
            $orderStatusHTML = ob_get_clean();

            // สตริงสำหรับค้นหา
            $searchText = strtolower(
              '#' . $oid . ' ' .
              $createdAt . ' ' .
              baht($payable) . ' ' .
              ($isBank ? 'bank' : 'cod') . ' ' .
              ($o['payment_status'] ?? '') . ' ' .
              ($o['order_status'] ?? '')
            );
          ?>
            <tr data-status="<?= h(strtolower($o['order_status'])) ?>" data-search="<?= h($searchText) ?>">
              <td data-label="#ออเดอร์">
                <div class="d-flex align-items-center gap-2">
                  <span class="fw-semibold">#<?= $oid ?></span>
                  <button class="btn btn-sm btn-outline-secondary copy" data-copy="#<?= $oid ?>" title="คัดลอก">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </div>
              </td>

              <td data-label="วันที่">
                <div class="fw-semibold"><?= $createdAt ?></div>
                <?php if ($isBank): ?>
                  <div class="small-muted">
                    หมดเวลา: <span class="text-danger"><?= $expiresAt ?></span>
                  </div>
                <?php endif; ?>
              </td>

              <td data-label="ยอดชำระ">
                <div class="fw-semibold"><?= baht($payable) ?> ฿</div>
                <?php if ($discount > 0.0): ?>
                  <div class="small-muted">
                    <del><?= baht($subtotal) ?> ฿</del> − ส่วนลด <?= baht($discount) ?> ฿
                  </div>
                <?php endif; ?>
              </td>

              <td data-label="วิธีชำระ">
                <?php if ($isBank): ?>
                  <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary">
                    <i class="bi bi-bank me-1"></i> โอนธนาคาร
                  </span>
                <?php else: ?>
                  <span class="badge rounded-pill bg-success-subtle text-success border border-success">
                    <i class="bi bi-truck me-1"></i> เก็บเงินปลายทาง
                  </span>
                <?php endif; ?>
              </td>

              <td data-label="สถานะชำระเงิน">
                <div class="d-flex flex-column gap-1">
                  <?= $payBadge ?>
                  <?php if ($isBank && !$expired && in_array($o['payment_status'], ['unpaid','pending'], true)): ?>
                    <span class="timer-badge" 
                          title="เวลาที่เหลือสำหรับการโอน"
                          data-remaining="<?= (int)$o['remaining_sec'] ?>"
                          data-order="<?= $oid ?>">
                      <i class="bi bi-clock-history"></i>
                      <span class="mm">--</span>:<span class="ss">--</span>
                    </span>
                  <?php endif; ?>
                </div>
              </td>

              <td data-label="สถานะคำสั่งซื้อ">
                <?= $orderStatusHTML ?>
              </td>

              <td data-label="สลิป">
                <?php if (!empty($o['slip_image'])): ?>
                  <a href="uploads/slips/<?= h($o['slip_image']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-receipt"></i> ดูสลิป
                  </a>
                <?php elseif ($canUpload): ?>
                  <a href="upload_slip.php?id=<?= $oid ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-cloud-upload"></i> อัปโหลดสลิป
                  </a>
                <?php else: ?>
                  <span class="small-muted">-</span>
                <?php endif; ?>
              </td>

              <td data-label="">
                <div class="actions d-flex gap-2">
                  <a href="order_detail.php?id=<?= $oid ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> รายละเอียด
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- pagination -->
      <div class="sticky-pagination">
        <nav aria-label="Page navigation" class="mt-2">
          <ul class="pagination justify-content-center mb-0">
            <?php $qStatus = '&status='.urlencode($statusParam); ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $prev . $qStatus ?>" tabindex="-1">ก่อนหน้า</a>
            </li>
            <?php
              $window = 2;
              $from = max(1, $page-$window);
              $to   = min($total_pages, $page+$window);

              if ($from > 1) {
                echo '<li class="page-item"><a class="page-link" href="?page=1'.$qStatus.'">1</a></li>';
                if ($from > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              }
              for ($i=$from; $i<=$to; $i++) {
                $active = $i === $page ? 'active' : '';
                echo '<li class="page-item '.$active.'"><a class="page-link" href="?page='.$i.$qStatus.'">'.$i.'</a></li>';
              }
              if ($to < $total_pages) {
                if ($to < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.$qStatus.'">'.$total_pages.'</a></li>';
              }
            ?>
            <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $next . $qStatus ?>">ถัดไป</a>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
/* live countdown for bank transfers */
(function(){
  const nodes = Array.from(document.querySelectorAll('.timer-badge'));
  if (!nodes.length) return;

  function renderNode(n){
    let left = parseInt(n.getAttribute('data-remaining')||'0',10);
    if (left < 0) left = 0;
    const mm = n.querySelector('.mm'); 
    const ss = n.querySelector('.ss');
    const m = Math.floor(left/60);
    const s = left%60;
    if (mm && ss){
      mm.textContent = String(m).padStart(2,'0');
      ss.textContent = String(s).padStart(2,'0');
    }
    n.setAttribute('data-remaining', Math.max(0, left-1));
    if (left <= 0) n.outerHTML = '<span class="badge bg-danger">หมดเวลาชำระ</span>';
  }
  nodes.forEach(renderNode);
  const t = setInterval(()=>{
    const alive = document.querySelectorAll('.timer-badge');
    if (!alive.length){ clearInterval(t); return; }
    alive.forEach(renderNode);
  }, 1000);
})();

/* copy order id */
document.addEventListener('click', e=>{
  const b = e.target.closest('.copy'); if(!b) return;
  const v = b.getAttribute('data-copy')||'';
  navigator.clipboard?.writeText(v).then(()=>{
    const old = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(()=> b.innerHTML = old, 900);
  });
}, {passive:true});

/* refresh button */
document.getElementById('btnRefresh')?.addEventListener('click', ()=> location.replace('my_orders.php'));

/* client-side search filter (เบา ๆ) */
(function(){
  const q = document.getElementById('q');
  if (!q) return;
  function applyFilter(){
    const text = (q.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#ordersTable tbody tr');
    rows.forEach(tr=>{
      const searchable = tr.getAttribute('data-search') || '';
      tr.style.display = (!text || searchable.indexOf(text) > -1) ? '' : 'none';
    });
  }
  q.addEventListener('input', applyFilter);
})();
</script>
</body>
</html>
