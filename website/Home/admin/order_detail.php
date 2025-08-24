<?php
// Home/admin/order_detail.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/order_detail.php'); exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }

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

// รวมเงิน
$total = 0;
foreach ($items as $it) $total += $it['quantity'] * $it['unit_price'];

// ========= ฟิลด์ที่อยู่/ผู้รับ (แก้จาก fullname/address/phone -> shipping_*) =========
$shipping_name    = $order['shipping_name']    ?? '';
$shipping_address = $order['shipping_address'] ?? '';
$shipping_phone   = $order['shipping_phone']   ?? '';

// ========= รูปสลิป: รองรับทั้ง slip_path หรือ slip_image =========
$slipRel = '';
if (!empty($order['slip_path'])) {
  $slipRel = ltrim($order['slip_path'], '/');
} elseif (!empty($order['slip_image'])) {
  $slipRel = 'uploads/slips/' . ltrim($order['slip_image'], '/');
}
$slipPublic = '';
if ($slipRel) {
  // หน้า admin อยู่ใน /Home/admin -> รูปอยู่ใต้ /Home/... ต้องถอยขึ้น 1 ระดับ
  $candidate = __DIR__ . '/../' . $slipRel;     // path จริงในเครื่อง
  if (is_file($candidate)) {
    $slipPublic = '../' . str_replace('\\','/', $slipRel); // path สำหรับ <img src>
  }
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
</head>
<body class="bg-light">

<!-- ... header navbar เหมือนเดิม ... -->

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
              <a href="<?= h($slipPublic) ?>" target="_blank">
                <img src="<?= h($slipPublic) ?>" class="img-fluid rounded border" alt="สลิปการโอน">
              </a>
            <?php else: ?>
              <div class="text-muted">ไม่มีสลิป</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">วิธีชำระเงินแบบปลายทาง (ไม่มีสลิป)</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ตารางสินค้าเหมือนเดิม (คำนวณ $total ด้านบนแล้ว) -->
  <div class="card mt-3 shadow-sm">
    <div class="card-header fw-bold">สินค้าในออเดอร์</div>
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
            $sum = $it['quantity'] * $it['unit_price'];
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-3">
                <img src="<?= h($img) ?>" width="56" height="56" class="rounded border" style="object-fit:cover">
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
          <tr><th colspan="3" class="text-end">รวมทั้งหมด</th><th class="text-end"><?= baht($total) ?> ฿</th></tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
</body>
</html>
