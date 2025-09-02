<?php 
// Home/my_orders.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=my_orders.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];

// ให้ PHP/MySQL ใช้เวลาไทย
date_default_timezone_set('Asia/Bangkok');
$conn->query("SET time_zone = '+07:00'");

// helper
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

// ตั้งค่าการแบ่งหน้า
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// ระยะเวลาอนุมัติการโอน (ต้องตรงกับ upload_slip.php)
$minutes_window = 15;

/* นับจำนวนออเดอร์ทั้งหมด (เพื่อทำเพจจิเนชัน) */
$stCount = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE user_id = ?");
$stCount->bind_param("i", $user_id);
$stCount->execute();
$total_orders = (int)($stCount->get_result()->fetch_assoc()['total'] ?? 0);
$stCount->close();
$total_pages = max(1, (int)ceil($total_orders / $per_page));

/*
ดึงออเดอร์เฉพาะหน้าปัจจุบัน:
- total_amount (sum จาก order_items)
- expires_at (ใช้ของจริง ถ้าไม่มีให้คำนวณจาก created_at + 15 นาที)
- remaining_sec = เวลาที่เหลือ (วินาที) เอาไว้เช็คหมดเวลา
*/
$sql = "
  SELECT
    o.id,
    o.status               AS order_status,
    o.cancel_reason,
    o.payment_method,
    o.payment_status,
    o.created_at,
    o.slip_image,
    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_amount,
    COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL ? MINUTE)) AS expires_at,
    GREATEST(
      0,
      TIMESTAMPDIFF(
        SECOND,
        NOW(),
        COALESCE(o.expires_at, DATE_ADD(o.created_at, INTERVAL ? MINUTE))
      )
    ) AS remaining_sec
  FROM orders o
  LEFT JOIN order_items oi ON oi.order_id = o.id
  WHERE o.user_id = ?
  GROUP BY
    o.id, o.status, o.cancel_reason, o.payment_method, o.payment_status, o.created_at, o.slip_image, o.expires_at
  ORDER BY o.created_at DESC
  LIMIT ? OFFSET ?
";
$st = $conn->prepare($sql);
$limit = (int)$per_page;
$ofs   = (int)$offset;
$st->bind_param("iiiii", $minutes_window, $minutes_window, $user_id, $limit, $ofs);
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
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
    body{background:#f6f8fb}
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .stat{display:flex;align-items:center;gap:.5rem;background:#fff;border:1px solid #e9eef3;border-radius:999px;padding:.35rem .75rem}
    .shell{background:#fff;border:1px solid #e9eef3;border-radius:18px;overflow:hidden;box-shadow:0 16px 40px rgba(2,6,23,.06)}
    .tbl-wrap{overflow:auto}
    .table-modern{margin:0}
    .table-modern thead th{background:linear-gradient(180deg,#fbfdff,#f2f6ff);border-bottom:1px solid #e6edf6;color:#1f2937;font-weight:700}
    .table-modern tbody tr{transition:background .15s}
    .table-modern tbody tr:hover{background:#f9fbff}
    .table-modern td,.table-modern th{vertical-align:middle}
    .badge-soft{background:#eef2f7;border:1px solid #e5ecf6;color:#111827}
    .chips{display:flex;gap:.5rem;flex-wrap:wrap}
    .chip{border:1px solid #e5ecf6;background:#fff;border-radius:999px;padding:.25rem .6rem;font-weight:600}
    .chip i{margin-right:.25rem}
    .timer-badge{display:inline-flex;align-items:center;gap:.35rem;background:#0b5ed7;color:#fff;border-radius:999px;padding:.2rem .55rem;font-weight:700}
    .timer-badge i{font-size:1rem}
    .actions .btn{border-radius:10px}
    .sticky-pagination{position:sticky;bottom:0;background:linear-gradient(180deg,rgba(246,248,251,0),#f6f8fb 40%, #f6f8fb);padding-top:.4rem}
    @media (max-width: 992px){
      .page-head{flex-direction:column;align-items:flex-start}
      .table-modern thead{display:none}
      .table-modern tbody tr{display:block;margin:12px; border:1px solid #e9eef3;border-radius:14px;padding:.75rem;background:#fff}
      .table-modern tbody td{display:flex;justify-content:space-between;border:0;border-bottom:1px dashed #eef2f6;padding:.45rem 0}
      .table-modern tbody td:last-child{border-bottom:0}
      .table-modern tbody td::before{content:attr(data-label);font-weight:600;color:#6b7280}
      .badge {
  font-size: 0.85rem;
  padding: 0.45rem 0.75rem;
}

.badge i {
  font-size: 1rem;
  vertical-align: middle;
}

.bg-primary-subtle {
  background: #e7f0ff !important;
}
.bg-success-subtle {
  background: #e8fdf3 !important;
}

    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-5">
  <div class="page-head mb-4">
    <h3 class="mb-0"><i class="bi bi-bag"></i> คำสั่งซื้อของฉัน</h3>
    <div class="chips">
      <span class="chip"><i class="bi bi-list-ul"></i> ทั้งหมด <?= (int)$total_orders ?></span>
      <span class="chip"><i class="bi bi-clock-history"></i> ยืนยันโอนภายใน <?= (int)$minutes_window ?> นาที</span>
    </div>
  </div>

  <?php if ($total_orders === 0): ?>
    <div class="alert alert-info shadow-sm">คุณยังไม่มีคำสั่งซื้อ</div>
  <?php else: ?>
    <div class="shell">
      <div class="tbl-wrap">
        <table class="table table-modern align-middle">
          <thead>
            <tr>
              <th style="min-width:80px">#</th>
              <th style="min-width:160px">วันที่</th>
              <th style="min-width:130px">ยอดรวม</th>
              <th style="min-width:140px">วิธีชำระ</th>
              <th style="min-width:180px">สถานะชำระเงิน</th>
              <th style="min-width:220px">สถานะคำสั่งซื้อ</th>
              <th style="min-width:120px">สลิป</th>
              <th style="min-width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($orders as $o):
            $isBank    = ($o['payment_method'] === 'bank');
            $expired   = ((int)$o['remaining_sec'] === 0);
            $canUpload = ($isBank && !$expired && in_array($o['payment_status'], ['unpaid','pending'], true));
            $expiresAt = h(date('Y-m-d H:i:s', strtotime($o['expires_at'])));
            $createdAt = h(date('Y-m-d H:i:s', strtotime($o['created_at'])));

            // ช่วยเลือก badge จ่ายเงิน
            $payBadge = '<span class="badge bg-secondary">ยังไม่ชำระ</span>';
            if ($o['payment_status'] === 'paid')       $payBadge = '<span class="badge bg-success">ชำระแล้ว</span>';
            elseif ($o['payment_status'] === 'pending')$payBadge = '<span class="badge bg-warning text-dark">รอตรวจสอบ</span>';
            elseif ($o['payment_status'] === 'refunded')$payBadge= '<span class="badge bg-secondary">คืนเงินแล้ว</span>';
            elseif ($o['payment_status'] === 'expired' || ($isBank && $expired)) $payBadge = '<span class="badge bg-danger">หมดเวลาชำระ</span>';

            // สถานะคำสั่งซื้อ
            ob_start();
            $isExpiredBank = ($isBank && ($o['payment_status'] === 'expired' || $expired));
            if ($isExpiredBank){
              echo '<span class="badge bg-danger">หมดเวลาชำระ</span><div class="small text-muted">ออเดอร์นี้หมดเวลาชำระแล้ว</div>';
            } elseif ($o['order_status'] === 'completed'){
              echo '<span class="badge bg-success">เสร็จสิ้น</span>';
            } elseif ($o['order_status'] === 'delivered'){
              echo '<span class="badge bg-success">จัดส่งแล้ว</span>';
            } elseif (in_array($o['order_status'], ['shipped','processing'], true)){
              echo '<span class="badge bg-primary">กำลังดำเนินการ</span>';
            } elseif ($o['order_status'] === 'cancel_requested'){
              echo '<span class="badge bg-warning text-dark">รอยืนยันยกเลิก</span>';
              if (!empty($o['cancel_reason'])){
                echo '<div class="small text-muted">เหตุผล: '.h($o['cancel_reason']).'</div>';
              }
            } elseif ($o['order_status'] === 'cancelled'){
              echo '<span class="badge bg-danger">ยกเลิก</span>';
            } else {
              echo '<span class="badge bg-info text-dark">ใหม่</span>';
              if (!$isExpiredBank){
                echo '
                  <form method="post" action="request_cancel.php" class="mt-2" onsubmit="return confirm(\'ยืนยันส่งคำขอยกเลิก #'.(int)$o['id'].' ?\')">
                    <div class="input-group input-group-sm">
                      <input type="text" name="reason" class="form-control" placeholder="เหตุผลการยกเลิก" required>
                      <input type="hidden" name="id" value="'.(int)$o['id'].'">
                      <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> ยกเลิก</button>
                    </div>
                  </form>
                ';
              }
            }
            $orderStatusHTML = ob_get_clean();
          ?>
            <tr>
              <td data-label="#"><span class="fw-semibold">#<?= (int)$o['id'] ?></span></td>

              <td data-label="วันที่">
                <div class="fw-semibold"><?= $createdAt ?></div>
                <?php if ($isBank): ?>
                  <div class="small text-muted">
                    หมดเวลา: <span class="text-danger"><?= $expiresAt ?></span>
                  </div>
                <?php endif; ?>
              </td>

              <td data-label="ยอดรวม"><span class="fw-semibold"><?= baht($o['total_amount']) ?> ฿</span></td>

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
                          data-order="<?= (int)$o['id'] ?>">
                      <i class="bi bi-clock-history"></i>
                      <span class="mm">--</span>:<span class="ss">--</span>
                    </span>
                  <?php endif; ?>
                </div>
              </td>

              <td data-label="สถานะคำสั่งซื้อ"><?= $orderStatusHTML ?></td>

              <td data-label="สลิป">
                <?php if (!empty($o['slip_image'])): ?>
                  <a href="uploads/slips/<?= h($o['slip_image']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-receipt"></i> ดูสลิป
                  </a>
                <?php elseif ($canUpload): ?>
                  <a href="upload_slip.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-cloud-upload"></i> อัปโหลดสลิป
                  </a>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>

              <td data-label="">
                <div class="actions d-flex gap-2">
                  <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye"></i> รายละเอียด
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- แถบเพจจิเนชัน -->
      <div class="sticky-pagination">
        <nav aria-label="Page navigation" class="mt-3">
          <ul class="pagination justify-content-center mb-0">
            <?php
              $prev = max(1, $page-1);
              $next = min($total_pages, $page+1);
            ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $prev ?>" tabindex="-1">ก่อนหน้า</a>
            </li>

            <?php
              // แสดงหมายเลขหน้าแบบกระชับ
              $window = 2;
              $from = max(1, $page-$window);
              $to   = min($total_pages, $page+$window);

              if ($from > 1) {
                echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                if ($from > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              }
              for ($i=$from; $i<=$to; $i++) {
                $active = $i === $page ? 'active' : '';
                echo '<li class="page-item '.$active.'"><a class="page-link" href="?page='.$i.'">'.$i.'</a></li>';
              }
              if ($to < $total_pages) {
                if ($to < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.'">'.$total_pages.'</a></li>';
              }
            ?>

            <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
              <a class="page-link" href="?page=<?= $next ?>">ถัดไป</a>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
/* Live countdown ให้ badge แต่ละออเดอร์ (เฉพาะโอนธนาคารที่ยังไม่หมดเวลา) */
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
    n.setAttribute('data-remaining', Math.max(0, left-1)); // ลด 1 วินาที
    if (left <= 0) {
      // เปลี่ยน badge เป็นหมดเวลาแบบนุ่มนวล
      n.outerHTML = '<span class="badge bg-danger">หมดเวลาชำระ</span>';
    }
  }

  // แสดงค่าตั้งต้น
  nodes.forEach(renderNode);

  // interval เดินพร้อมกันทั้งหมด
  const t = setInterval(()=>{
    const alive = document.querySelectorAll('.timer-badge');
    if (!alive.length){ clearInterval(t); return; }
    alive.forEach(renderNode);
  }, 1000);
})();
</script>
</body>
</html>
