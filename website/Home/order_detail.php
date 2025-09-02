<?php
// Home/order_detail.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=order_detail.php");
  exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

/* ---------- แปลสถานะเป็นภาษาไทย ---------- */
function tPaymentStatus(string $s): string {
  switch($s){
    case 'unpaid':   return 'ยังไม่ชำระ';
    case 'pending':  return 'รอตรวจสอบ';
    case 'paid':     return 'ชำระแล้ว';
    case 'expired':  return 'หมดเวลาชำระ';
    case 'refunded': return 'คืนเงินแล้ว';
    default:         return $s;
  }
}
function tOrderStatus(string $s): string {
  switch($s){
    case 'pending':    return 'ใหม่/กำลังตรวจสอบ';
    case 'processing': return 'กำลังดำเนินการ';
    case 'shipped':    return 'จัดส่งแล้ว';
    case 'delivered':  return 'ส่งสำเร็จ';
    case 'completed':  return 'เสร็จสิ้น';
    case 'cancelled':  return 'ยกเลิก';
    default:           return $s;
  }
}
function tPayMethod(string $s): string {
  return $s === 'bank' ? 'โอนผ่านธนาคาร' : 'เก็บเงินปลายทาง';
}

$user_id  = (int)$_SESSION['user_id'];
$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) { http_response_code(400); exit('ไม่พบเลขที่คำสั่งซื้อ'); }

/* ---------- 1) ดึงหัวออเดอร์ ---------- */
$sql = "
  SELECT 
    o.id, o.user_id,
    o.status          AS order_status,
    o.payment_method, o.payment_status,
    o.created_at,     o.updated_at,
    o.shipping_name,  o.shipping_phone, o.shipping_address,
    o.slip_image
  FROM orders o
  WHERE o.id = ? AND o.user_id = ?
  LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param("ii", $order_id, $user_id);
$st->execute();
$order = $st->get_result()->fetch_assoc();
$st->close();

if (!$order) { http_response_code(404); exit('ไม่พบคำสั่งซื้อ หรือคุณไม่มีสิทธิ์เข้าถึง'); }

/* ---------- 2) รายการสินค้า ---------- */
$sql2 = "
  SELECT 
    oi.id, oi.product_id, oi.quantity, oi.unit_price,
    p.name AS product_name, p.image AS product_image
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = ?
  ORDER BY oi.id ASC
";
$st2 = $conn->prepare($sql2);
$st2->bind_param("i", $order_id);
$st2->execute();
$items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
$st2->close();

/* คำนวณยอดรวม */
$total = 0;
foreach ($items as $it) { $total += $it['quantity'] * $it['unit_price']; }

/* badge map */
$orderBadge = [
  'pending'    => 'info text-dark',
  'processing' => 'primary',
  'shipped'    => 'primary',
  'delivered'  => 'success',
  'completed'  => 'success',
  'cancelled'  => 'danger',
];
$payBadge = [
  'unpaid'   => 'secondary',
  'pending'  => 'warning text-dark',
  'paid'     => 'success',
  'expired'  => 'danger',
  'refunded' => 'info text-dark',
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>รายละเอียดคำสั่งซื้อ #<?= (int)$order['id'] ?> | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">
      <i class="bi bi-receipt"></i> คำสั่งซื้อ #<?= (int)$order['id'] ?>
    </h3>
    <a href="my_orders.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> กลับรายการของฉัน
    </a>
  </div>

  <div class="row g-3">
    <!-- สรุปสถานะ -->
    <div class="col-lg-4">
      <div class="card h-100 shadow-sm">
        <div class="card-header fw-bold">สถานะคำสั่งซื้อ</div>
        <div class="card-body">
          <p class="mb-1"><small class="text-muted">วันที่สั่งซื้อ</small><br><?= h($order['created_at']) ?></p>
          <?php if (!empty($order['updated_at'])): ?>
            <p class="mb-1"><small class="text-muted">อัปเดตล่าสุด</small><br><?= h($order['updated_at']) ?></p>
          <?php endif; ?>

          <p class="mb-1">
            <small class="text-muted">สถานะออเดอร์</small><br>
            <?php $ob = $orderBadge[$order['order_status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $ob ?>"><?= h(tOrderStatus((string)$order['order_status'])) ?></span>
          </p>

          <p class="mb-1">
            <small class="text-muted">การชำระเงิน</small><br>
            <?= h(tPayMethod((string)$order['payment_method'])) ?>
          </p>

          <p class="mb-1">
            <small class="text-muted">สถานะชำระเงิน</small><br>
            <?php $pb = $payBadge[$order['payment_status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $pb ?>"><?= h(tPaymentStatus((string)$order['payment_status'])) ?></span>
          </p>

          <?php if ($order['payment_method']==='bank' && !in_array($order['payment_status'], ['paid','expired','refunded'])): ?>
            <div class="mt-3 d-grid">
              <a class="btn btn-warning" href="upload_slip.php?id=<?= (int)$order['id'] ?>">
                อัปโหลดสลิปโอนเงิน
              </a>
            </div>
          <?php endif; ?>

          <?php if (!empty($order['slip_image'])): ?>
            <div class="mt-3">
              <small class="text-muted d-block mb-1">สลิปที่อัปโหลด</small>
              <a href="uploads/slips/<?= h($order['slip_image']) ?>" target="_blank">
                <img src="uploads/slips/<?= h($order['slip_image']) ?>" class="border rounded" style="max-width: 100%; height: auto;" alt="สลิปโอนเงิน">
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- รายการสินค้า + ที่อยู่จัดส่ง -->
    <div class="col-lg-8">
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-bold">รายการสินค้า</div>
        <?php if (empty($items)): ?>
          <div class="card-body">
            <div class="alert alert-info mb-0">ไม่มีรายการสินค้าในออเดอร์นี้</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>สินค้า</th>
                  <th class="text-center" style="width:120px;">จำนวน</th>
                  <th class="text-end" style="width:150px;">ราคาต่อหน่วย</th>
                  <th class="text-end" style="width:150px;">รวม</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($items as $it):
                $img = $it['product_image'] ? 'assets/img/'.$it['product_image'] : 'assets/img/default.png';
                $sum = $it['quantity'] * $it['unit_price'];
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
                  <th colspan="3" class="text-end">รวมทั้งหมด</th>
                  <th class="text-end"><?= baht($total) ?> ฿</th>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">ที่อยู่จัดส่ง</div>
        <div class="card-body">
          <div class="mb-1"><strong>ชื่อผู้รับ:</strong> <?= h($order['shipping_name'] ?: '-') ?></div>
          <div class="mb-1"><strong>โทร:</strong> <?= h($order['shipping_phone'] ?: '-') ?></div>
          <div><strong>ที่อยู่:</strong> <div><?= nl2br(h($order['shipping_address'] ?: '-')) ?></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
</body>
</html>
