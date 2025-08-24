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
    o.cancel_reason,                               -- ✅ เพิ่มมาเพื่อใช้แสดงเหตุผลตอนรอยืนยันยกเลิก
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
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-5">
  <div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0"><i class="bi bi-bag"></i> คำสั่งซื้อของฉัน</h3>
    <div class="text-muted small">ทั้งหมด <?= (int)$total_orders ?> รายการ</div>
  </div>

  <?php if ($total_orders === 0): ?>
    <div class="alert alert-info">คุณยังไม่มีคำสั่งซื้อ</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle table-bordered">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>วันที่</th>
            <th>ยอดรวม</th>
            <th>วิธีชำระ</th>
            <th>สถานะชำระเงิน</th>
            <th>สถานะคำสั่งซื้อ</th>
            <th>สลิป</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($orders as $o):
          $isBank    = ($o['payment_method'] === 'bank');
          $expired   = ((int)$o['remaining_sec'] === 0); // true เมื่อหมดเวลาแล้ว
          // อนุญาตอัปโหลดเฉพาะโอนธนาคาร + ยังไม่หมดเวลา + สถานะยังไม่จ่าย/รอตรวจสอบ
          $canUpload = ($isBank && !$expired && in_array($o['payment_status'], ['unpaid','pending'], true));
        ?>
          <tr>
            <td><?= (int)$o['id'] ?></td>
            <td><?= h(date('Y-m-d H:i:s', strtotime($o['created_at']))) ?></td>
            <td><?= baht($o['total_amount']) ?> ฿</td>
            <td><?= $isBank ? 'โอนธนาคาร' : 'เก็บเงินปลายทาง' ?></td>
            <td>
              <?php if ($o['payment_status'] === 'paid'): ?>
                <span class="badge bg-success">ชำระแล้ว</span>
              <?php elseif ($o['payment_status'] === 'pending'): ?>
                <span class="badge bg-warning text-dark">รอตรวจสอบ</span>
              <?php elseif ($o['payment_status'] === 'refunded'): ?>
                <span class="badge bg-secondary">คืนเงินแล้ว</span>
              <?php elseif ($o['payment_status'] === 'expired' || ($isBank && $expired)): ?>
                <span class="badge bg-danger">หมดเวลาชำระ</span>
              <?php else: ?>
                <span class="badge bg-secondary">ยังไม่ชำระ</span>
              <?php endif; ?>

              <?php if ($isBank): ?>
                <div class="small text-muted mt-1">
                  หมดเวลา: <?= h(date('Y-m-d H:i:s', strtotime($o['expires_at']))) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- ✅ ปรับตรรกะคอลัมน์สถานะคำสั่งซื้อ: ถ้าหมดเวลาชำระสำหรับโอนธนาคาร จะไม่โชว์ฟอร์มยกเลิก -->
            <td>
              <?php
                $isExpiredBank = ($isBank && ($o['payment_status'] === 'expired' || $expired));
              ?>
              <?php if ($isExpiredBank): ?>
                <span class="badge bg-danger">หมดเวลาชำระ</span>
                <div class="small text-muted">ออเดอร์นี้หมดเวลาชำระแล้ว</div>

              <?php elseif ($o['order_status'] === 'completed'): ?>
                <span class="badge bg-success">เสร็จสิ้น</span>

              <?php elseif ($o['order_status'] === 'delivered'): ?>
                <span class="badge bg-success">จัดส่งแล้ว</span>

              <?php elseif (in_array($o['order_status'], ['shipped','processing'], true)): ?>
                <span class="badge bg-primary">กำลังดำเนินการ</span>

              <?php elseif ($o['order_status'] === 'cancel_requested'): ?>
                <span class="badge bg-warning text-dark">รอยืนยันยกเลิก</span>
                <?php if (!empty($o['cancel_reason'])): ?>
                  <div class="small text-muted">เหตุผล: <?= h($o['cancel_reason']) ?></div>
                <?php endif; ?>

              <?php elseif ($o['order_status'] === 'cancelled'): ?>
                <span class="badge bg-danger">ยกเลิก</span>

              <?php else: ?>
                <span class="badge bg-info text-dark">ใหม่</span>
                <!-- ปุ่มส่งคำขอยกเลิก (เฉพาะยังไม่หมดเวลา) -->
                <?php if (!$isExpiredBank): ?>
                  <form method="post" action="request_cancel.php" class="mt-1">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <div class="input-group input-group-sm">
                      <input type="text" name="reason" class="form-control" placeholder="เหตุผลการยกเลิก" required>
                      <button class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('ยืนยันส่งคำขอยกเลิก #<?= (int)$o['id'] ?> ?')">
                        ยกเลิก
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if (!empty($o['slip_image'])): ?>
                <a href="uploads/slips/<?= h($o['slip_image']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">ดูสลิป</a>
              <?php elseif ($canUpload): ?>
                <a href="upload_slip.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-warning">อัปโหลดสลิป</a>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>

            <td>
              <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-eye"></i> รายละเอียด
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- แถบเพจจิเนชัน -->
    <nav aria-label="Page navigation" class="mt-3">
      <ul class="pagination justify-content-center">
        <?php
          $prev = max(1, $page-1);
          $next = min($total_pages, $page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="?page=<?= $prev ?>" tabindex="-1">ก่อนหน้า</a>
        </li>

        <?php
          // แสดงหมายเลขหน้าแบบกระชับ
          $window = 2; // หน้าใกล้เคียงซ้าย/ขวา
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
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
