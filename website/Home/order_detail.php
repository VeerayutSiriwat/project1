<?php
// Home/order_detail.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=order_detail.php");
  exit;
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
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $q = $c->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}

/* ---------- map สถานะ ---------- */
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
$extras = [];
$extras[] = has_col($conn,'orders','discount_total') ? "COALESCE(o.discount_total,0) AS discount_total" : "0 AS discount_total";
$extras[] = has_col($conn,'orders','coupon_code')     ? "COALESCE(o.coupon_code,'') AS coupon_code"    : "'' AS coupon_code";
$extras[] = has_col($conn,'orders','total_price')     ? "COALESCE(o.total_price,0)  AS total_price"    : "0 AS total_price";

$sql = "
  SELECT 
    o.id, o.user_id,
    o.status          AS order_status,
    o.payment_method, o.payment_status,
    o.created_at,     o.updated_at,
    o.shipping_name,  o.shipping_phone, o.shipping_address,
    o.slip_image,
    ".implode(',', $extras)."
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

/* ---------- 3) คำนวณยอดรวม + คูปอง ---------- */
$items_subtotal = 0.0;
foreach ($items as $it) { $items_subtotal += ((float)$it['quantity'] * (float)$it['unit_price']); }

$discount_total = (float)($order['discount_total'] ?? 0);
$coupon_code    = trim((string)($order['coupon_code'] ?? ''));
$grand_total    = (float)($order['total_price'] ?? max(0.0, $items_subtotal - $discount_total));

if ($discount_total <= 0) {
  if ($coupon_code !== '' && table_exists($conn,'coupon_usages') && has_col($conn,'coupon_usages','amount')) {
    if ($stAmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS amt FROM coupon_usages WHERE order_id=?")) {
      $stAmt->bind_param("i", $order_id);
      $stAmt->execute();
      $amt = (float)($stAmt->get_result()->fetch_assoc()['amt'] ?? 0);
      $stAmt->close();
      if ($amt > 0) { $discount_total = $amt; }
    }
  }
  if ($discount_total <= 0 && $grand_total > 0) {
    $derived = $items_subtotal - $grand_total;
    if ($derived > 0) { $discount_total = $derived; }
  }
}
$grand_total = max(0.0, $items_subtotal - $discount_total);

/* ---------- ดึงรายละเอียดคูปอง ---------- */
$coupon_info = null;
if ($coupon_code !== '' && table_exists($conn,'coupons')) {
  $cols = [
    "c.type","c.value",
    (has_col($conn,'coupons','uses_limit')     ? "COALESCE(c.uses_limit,0) AS uses_limit"     : "0 AS uses_limit"),
    (has_col($conn,'coupons','per_user_limit') ? "COALESCE(c.per_user_limit,0) AS per_user_limit" : "0 AS per_user_limit"),
    (has_col($conn,'coupons','max_discount')   ? "COALESCE(c.max_discount,0) AS max_discount" : "0 AS max_discount"),
    (has_col($conn,'coupons','applies_to')     ? "COALESCE(c.applies_to,'all') AS applies_to" : "'all' AS applies_to")
  ];
  $stc = $conn->prepare("SELECT ".implode(',',$cols)." FROM coupons c WHERE c.code=? LIMIT 1");
  $stc->bind_param("s",$coupon_code);
  $stc->execute();
  $coupon_info = $stc->get_result()->fetch_assoc() ?: null;
  $stc->close();
}

/* ---------- 4) badge ---------- */
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

$createdFmt = $order['created_at'] ? date('d/m/Y H:i', strtotime($order['created_at'])) : '-';
$updatedFmt = $order['updated_at'] ? date('d/m/Y H:i', strtotime($order['updated_at'])) : '-';
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
  <style>
    :root{
      --bg:#f6f8fb;
      --pri:#2563eb;
      --pri2:#4f46e5;
      --line:#e5e7eb;
      --ink:#0f172a;
      --muted:#6b7280;
    }
  
    .page-head{
      border-radius:20px;
      padding:16px 18px 14px;
      background:linear-gradient(135deg,var(--pri)0%,var(--pri2)55%,#0ea5e9 100%);
      color:#fff;
      box-shadow:0 14px 36px rgba(37,99,235,.2);
    }
    .page-head h3{margin:0;font-weight:700;letter-spacing:.01em;}
    .page-head-sub{font-size:.9rem;opacity:.92;margin-top:4px;}
    .summary-pills{
      display:flex;flex-wrap:wrap;gap:10px;
    }
    .summary-pill{
      min-width:180px;
      padding:10px 16px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.55);
      background:rgba(15,23,42,.05);
      display:flex;align-items:center;gap:8px;
      font-size:.9rem;
      font-weight:600;
      backdrop-filter:blur(4px);
    }
    .summary-pill i{font-size:1.1rem;}

    .card-shell{
      background:#fff;
      border-radius:18px;
      border:1px solid #e3e8f5;
      box-shadow:0 16px 40px rgba(15,23,42,.06);
    }

    .status-card .label{
      font-size:.82rem;
      color:var(--muted);
    }
    .status-chip{
      display:inline-flex;align-items:center;gap:.35rem;
      padding:.25rem .7rem;
      border-radius:999px;
      font-size:.82rem;
      font-weight:600;
      background:#eef2ff;
      color:#1e3a8a;
    }

    .timeline{
      margin-top:10px;
      border-radius:14px;
      padding:10px 12px;
      background:#f8fafc;
      border:1px dashed #d4dbe8;
      font-size:.8rem;
      color:var(--muted);
    }
    .timeline div+div{margin-top:4px;}

    .slip-thumb{
      max-width:100%;
      border-radius:14px;
      border:1px solid #e2e8f0;
      box-shadow:0 8px 20px rgba(15,23,42,.12);
    }

    /* table modern */
    .table-modern{
      margin:0;
      font-size:.9rem;
    }
    .table-modern thead th{
      background:#f5f7fc;
      border-bottom:1px solid #e3e8f5;
      font-weight:600;
      color:#111827;
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

    .coupon-badge{
      background:#eef2ff;
      color:#3730a3;
      border-radius:999px;
      padding:.18rem .65rem;
      font-weight:700;
      font-size:.8rem;
      border:1px solid #c7d2fe;
    }

    .address-card .label{
      font-size:.85rem;
      color:var(--muted);
    }

    /* mobile: table -> cards */
    @media(max-width: 768px){
      .table-modern thead{display:none;}
      .table-modern tbody tr{
        display:block;
        margin:.6rem;
        border-radius:14px;
        border:1px solid #e5e7eb;
        box-shadow:0 10px 26px rgba(15,23,42,.04);
        padding:.55rem .65rem;
      }
      .table-modern tbody td{
        display:flex;
        justify-content:space-between;
        border:0;
        border-bottom:1px dashed #e5e7eb;
        padding:.35rem 0;
      }
      .table-modern tbody td:last-child{
        border-bottom:0;
      }
      .table-modern tbody td::before{
        content:attr(data-label);
        font-weight:600;
        color:var(--muted);
        padding-right:.75rem;
        max-width:45%;
      }
      .page-head{padding:14px 14px;}
      .summary-pill{min-width:0;}
    }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">

  <!-- หัวออเดอร์ -->
  <div class="page-head mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-3 align-items-start">
      <div>
        <div class="text-white-50 small">รายละเอียดคำสั่งซื้อ</div>
        <h3>#<?= (int)$order['id'] ?></h3>
        <div class="page-head-sub">
          สร้างเมื่อ <?= h($createdFmt) ?>
          <?php if($updatedFmt !== '-'): ?>
            • อัปเดตล่าสุด <?= h($updatedFmt) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="summary-pills">
        <div class="summary-pill">
          <i class="bi bi-list-ul"></i>
          <span>ทั้งหมด <?= count($items) ?> รายการ</span>
        </div>
        <div class="summary-pill">
          <i class="bi bi-cash-stack"></i>
          <span>ยอดสุทธิ <?= baht($grand_total) ?> ฿</span>
        </div>
      </div>
    </div>
  </div>

  <div class="mb-3">
    <a href="my_orders.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> กลับรายการของฉัน
    </a>
  </div>

  <div class="row g-3">
    <!-- สรุปสถานะ -->
    <div class="col-lg-4">
      <div class="card-shell h-100">
        <div class="p-3 border-bottom">
          <div class="fw-semibold"><i class="bi bi-clipboard-check me-1"></i> สถานะคำสั่งซื้อ</div>
        </div>
        <div class="p-3 status-card">
          <div class="mb-2">
            <div class="label">สถานะออเดอร์</div>
            <?php $ob = $orderBadge[$order['order_status']] ?? 'secondary'; ?>
            <span class="status-chip">
              <span class="dot bg-<?= $ob ?> rounded-circle" style="width:8px;height:8px;"></span>
              <?= h(tOrderStatus((string)$order['order_status'])) ?>
            </span>
          </div>

          <div class="mb-2">
            <div class="label">วิธีชำระเงิน</div>
            <div><?= h(tPayMethod((string)$order['payment_method'])) ?></div>
          </div>

          <div class="mb-2">
            <div class="label">สถานะการชำระเงิน</div>
            <?php $pb = $payBadge[$order['payment_status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $pb ?>">
              <?= h(tPaymentStatus((string)$order['payment_status'])) ?>
            </span>
          </div>

          <div class="timeline mt-3">
            <div><i class="bi bi-dot"></i> สร้างคำสั่งซื้อ: <?= h($createdFmt) ?></div>
            <?php if($updatedFmt !== '-'): ?>
              <div><i class="bi bi-dot"></i> อัปเดตล่าสุด: <?= h($updatedFmt) ?></div>
            <?php endif; ?>
          </div>

          <?php if ($order['payment_method']==='bank' && !in_array($order['payment_status'], ['paid','expired','refunded'])): ?>
            <div class="mt-3 d-grid">
              <a class="btn btn-warning" href="upload_slip.php?id=<?= (int)$order['id'] ?>">
                <i class="bi bi-upload me-1"></i> อัปโหลดสลิปโอนเงิน
              </a>
            </div>
          <?php endif; ?>

          <?php if (!empty($order['slip_image'])): ?>
            <div class="mt-3">
              <div class="label mb-1">สลิปที่อัปโหลด</div>
              <a href="uploads/slips/<?= h($order['slip_image']) ?>" target="_blank">
                <img src="uploads/slips/<?= h($order['slip_image']) ?>" class="slip-thumb" alt="สลิปโอนเงิน">
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- รายการสินค้า + ที่อยู่ -->
    <div class="col-lg-8">
      <div class="card-shell mb-3">
        <div class="p-3 border-bottom">
          <div class="fw-semibold"><i class="bi bi-box-seam me-1"></i> รายการสินค้า</div>
        </div>

        <?php if (empty($items)): ?>
          <div class="p-3">
            <div class="alert alert-info mb-0">ไม่มีรายการสินค้าในออเดอร์นี้</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-modern align-middle mb-0">
              <thead>
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
                $sum = (float)$it['quantity'] * (float)$it['unit_price'];
              ?>
                <tr>
                  <td data-label="สินค้า">
                    <div class="d-flex align-items-center gap-3">
                      <img src="<?= h($img) ?>" width="56" height="56" class="rounded border" style="object-fit:cover" alt="">
                      <div class="fw-semibold">
                        <?= h($it['product_name'] ?? ('#'.$it['product_id'])) ?>
                      </div>
                    </div>
                  </td>
                  <td class="text-center" data-label="จำนวน"><?= (int)$it['quantity'] ?></td>
                  <td class="text-end" data-label="ราคาต่อหน่วย"><?= baht($it['unit_price']) ?> ฿</td>
                  <td class="text-end" data-label="รวม"><?= baht($sum) ?> ฿</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">รวมสินค้า</th>
                  <th class="text-end"><?= baht($items_subtotal) ?> ฿</th>
                </tr>
                <tr <?= $discount_total <= 0 ? 'style="display:none;"' : '' ?>>
                  <th colspan="3" class="text-end">
                    ส่วนลดคูปอง
                    <?php if ($coupon_code !== ''): ?>
                      <span class="coupon-badge"><?= h($coupon_code) ?></span>
                    <?php endif; ?>
                  </th>
                  <th class="text-end text-success">- <?= baht($discount_total) ?> ฿</th>
                </tr>
                <tr>
                  <th colspan="3" class="text-end">ค่าจัดส่ง</th>
                  <th class="text-end">0.00 ฿</th>
                </tr>
                <tr>
                  <th colspan="3" class="text-end fs-5">ยอดสุทธิ</th>
                  <th class="text-end fs-5 text-primary"><?= baht($grand_total) ?> ฿</th>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if ($coupon_code !== ''): ?>
            <div class="border-top px-3 py-3 small text-muted">
              <div class="mb-1">
                ใช้คูปอง <strong><?= h($coupon_code) ?></strong>
                <?php if ($coupon_info): ?>
                  <?php
                    $cap = strtolower($coupon_info['type'] ?? 'fixed') === 'percent'
                      ? (float)$coupon_info['value'].'%'
                      : baht($coupon_info['value']).' ฿';
                  ?>
                  (ประเภท: <?= h($coupon_info['type'] ?? '-') ?>, มูลค่า: <?= h($cap) ?>)
                  <?php if ((float)$coupon_info['max_discount'] > 0): ?>
                    • ส่วนลดสูงสุด <?= baht($coupon_info['max_discount']) ?> ฿
                  <?php endif; ?>
                  <?php if (($coupon_info['applies_to'] ?? 'all') !== 'all'): ?>
                    • ขอบเขต: <?= h($coupon_info['applies_to']) ?>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php if ($coupon_info && ((int)$coupon_info['uses_limit']>0 || (int)$coupon_info['per_user_limit']>0)): ?>
                <div>
                  สิทธิ์การใช้:
                  <?php if ((int)$coupon_info['per_user_limit']>0): ?>
                    คนละ <?= (int)$coupon_info['per_user_limit'] ?> ครั้ง
                  <?php endif; ?>
                  <?php if ((int)$coupon_info['uses_limit']>0): ?>
                    <?= (int)$coupon_info['per_user_limit']>0 ? '• ' : '' ?>รวมทั้งระบบ <?= (int)$coupon_info['uses_limit'] ?> ครั้ง
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card-shell address-card">
        <div class="p-3 border-bottom">
          <div class="fw-semibold"><i class="bi bi-geo-alt me-1"></i> ที่อยู่จัดส่ง</div>
        </div>
        <div class="p-3">
          <div class="mb-2">
            <div class="label">ชื่อผู้รับ</div>
            <div><?= h($order['shipping_name'] ?: '-') ?></div>
          </div>
          <div class="mb-2">
            <div class="label">เบอร์ติดต่อ</div>
            <div><?= h($order['shipping_phone'] ?: '-') ?></div>
          </div>
          <div>
            <div class="label">ที่อยู่เต็ม</div>
            <div><?= nl2br(h($order['shipping_address'] ?: '-')) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
</body>
</html>
