<?php
// Home/admin/order_detail.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/order_detail.php'); exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }
function has_col(mysqli $c, string $t, string $col): bool {
  $t = preg_replace('/[^a-zA-Z0-9_]/','',$t);
  $col = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $c->query("SHOW COLUMNS FROM `$t` LIKE '$col'");
  return $q && $q->num_rows>0;
}
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $q = $c->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid order id'); }

// ========= ดึงหัวออเดอร์ =========
$sql = "SELECT o.*, u.username, u.email
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = ? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param("i", $id);
$st->execute();
$order = $st->get_result()->fetch_assoc();
$st->close();
if (!$order) { http_response_code(404); exit('ไม่พบคำสั่งซื้อ'); }

// ========= ดึงรายการสินค้า =========
$sql2 = "SELECT oi.*, p.name AS product_name, p.image AS product_image
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?";
$st2 = $conn->prepare($sql2);
$st2->bind_param("i", $id);
$st2->execute();
$items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
$st2->close();

// ========= รวมเงิน (subtotal) =========
$items_subtotal = 0.0;
foreach ($items as $it) { $items_subtotal += ((float)$it['quantity'] * (float)$it['unit_price']); }

// ========= ส่วนลด / คูปอง / ยอดสุทธิ (ยืดหยุ่น) =========
$hasTotalCol  = has_col($conn,'orders','total_price');
$hasDiscCol   = has_col($conn,'orders','discount_total');
$hasCouponCol = has_col($conn,'orders','coupon_code');

$coupon_code     = $hasCouponCol ? trim((string)($order['coupon_code'] ?? '')) : '';
$discount_total  = $hasDiscCol ? (float)($order['discount_total'] ?? 0) : 0.0;

// 2) ถ้า discount ยังเป็น 0 ลองดึงจาก coupon_usages
if ($discount_total <= 0 && table_exists($conn,'coupon_usages')) {
  if ($stx = $conn->prepare("SELECT COALESCE(SUM(amount),0) amt FROM coupon_usages WHERE order_id=?")) {
    $stx->bind_param("i",$id); $stx->execute();
    $discount_total = (float)($stx->get_result()->fetch_assoc()['amt'] ?? 0.0);
    $stx->close();
  }
}

// 3) ถ้ายัง 0 และมี total_price ให้คำนวณจาก subtotal - total_price (กันกรณีไม่มีคอลัมน์ส่วนลด)
$grand_total = $hasTotalCol
  ? (float)($order['total_price'] ?? 0)
  : max(0.0, $items_subtotal - $discount_total);

// กรณีมี total_price และ discount_total ยังเป็น 0 ให้เดา discount จากส่วนต่าง (กันรอบทศนิยม)
if ($hasTotalCol && $discount_total <= 0) {
  $diff = (float)$items_subtotal - (float)$grand_total;
  if ($diff > 0.0001) $discount_total = $diff;
}

// ========= ที่อยู่/ผู้รับ =========
$shipping_name    = $order['shipping_name']    ?? '';
$shipping_address = $order['shipping_address'] ?? '';
$shipping_phone   = $order['shipping_phone']   ?? '';

// ========= รูปสลิป (รองรับ slip_path / slip_image) =========
$slipRel = '';
if (!empty($order['slip_path'])) {
  $slipRel = ltrim($order['slip_path'], '/');
} elseif (!empty($order['slip_image'])) {
  $slipRel = 'uploads/slips/' . ltrim($order['slip_image'], '/');
}
$slipPublic = '';
if ($slipRel) {
  $candidate = __DIR__ . '/../' . $slipRel;
  if (is_file($candidate)) { $slipPublic = '../' . str_replace('\\','/', $slipRel); }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>รายละเอียดออเดอร์ #<?= (int)$order['id'] ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>.coupon-badge{background:#eef2ff;color:#3730a3;border-radius:999px;padding:.2rem .5rem;font-weight:700}</style>
</head>
<body class="bg-light">

<div class="container py-4">
  <h4 class="mb-3"><i class="bi bi-receipt"></i> รายละเอียดออเดอร์ #<?= (int)$order['id'] ?></h4>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header fw-bold">ข้อมูลออเดอร์</div>
        <div class="card-body">
          <div class="mb-2"><small class="text-muted">ลูกค้า</small><br><?= h($order['username'] ?? ('UID '.$order['user_id'])) ?></div>
          <div class="mb-2"><small class="text-muted">วันที่สั่งซื้อ</small><br><?= h($order['created_at']) ?></div>
          <div class="mb-2"><small class="text-muted">การชำระเงิน</small><br><?= ($order['payment_method']==='bank'?'โอนธนาคาร':'ปลายทาง') ?> / <?= h($order['payment_status'] ?? '-') ?></div>
          <div class="mb-2"><small class="text-muted">ชื่อผู้รับ</small><br><?= h($shipping_name ?: '-') ?></div>
          <div class="mb-2"><small class="text-muted">ที่อยู่</small><br><?= nl2br(h($shipping_address ?: '-')) ?></div>
          <div class="mb-2"><small class="text-muted">เบอร์โทร</small><br><?= h($shipping_phone ?: '-') ?></div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header fw-bold">สลิปการโอน</div>
        <div class="card-body">
          <?php if ($order['payment_method']==='bank'): ?>
            <?php if ($slipPublic): ?>
              <a href="<?= h($slipPublic) ?>" target="_blank"><img src="<?= h($slipPublic) ?>" class="img-fluid rounded border" alt="สลิปการโอน"></a>
            <?php else: ?><div class="text-muted">ไม่มีสลิป</div><?php endif; ?>
          <?php else: ?><div class="text-muted">วิธีชำระเงินแบบปลายทาง (ไม่มีสลิป)</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- สินค้า + สรุปราคา -->
  <div class="card mt-3 shadow-sm">
    <div class="card-header fw-bold d-flex align-items-center justify-content-between">
      <span>สินค้าในออเดอร์</span>
      <?php if ($coupon_code !== ''): ?><span class="coupon-badge"><?= h($coupon_code) ?></span><?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>สินค้า</th><th class="text-center">จำนวน</th>
            <th class="text-end">ราคา/ชิ้น</th><th class="text-end">รวม</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it):
            $img = $it['product_image'] ? '../assets/img/'.$it['product_image'] : '../assets/img/default.png';
            $sum = (float)$it['quantity'] * (float)$it['unit_price'];
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-3">
                <img src="<?= h($img) ?>" width="56" height="56" class="rounded border" style="object-fit:cover" alt="">
                <div class="fw-semibold"><?= h($it['product_name'] ?? ('#'.$it['product_id'])) ?></div>
              </div>
            </td>
            <td class="text-center"><?= (int)$it['quantity'] ?></td>
            <td class="text-end"><?= baht($it['unit_price']) ?> ฿</td>
            <td class="text-end"><?= baht($sum) ?> ฿</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="text-end">รวมสินค้า</th>
            <th class="text-end"><?= baht($items_subtotal) ?> ฿</th>
          </tr>
          <tr>
            <th colspan="3" class="text-end">
              ส่วนลดคูปอง <?= $coupon_code!=='' ? '<span class="coupon-badge">'.h($coupon_code).'</span>' : '' ?>
            </th>
            <th class="text-end text-success">- <?= baht($discount_total) ?> ฿</th>
          </tr>
          <tr>
            <th colspan="3" class="text-end">ค่าจัดส่ง</th>
            <th class="text-end">0.00 ฿</th>
          </tr>
          <tr>
            <th colspan="3" class="text-end fs-5">ยอดสุทธิที่ต้องชำระ</th>
            <th class="text-end fs-5"><?= baht($grand_total) ?> ฿</th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
</body>
</html>
