<?php
// Home/checkout.php
session_start();
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=checkout.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function pickPrice($row){
  return ($row['discount_price'] && $row['discount_price'] < $row['price'])
          ? (float)$row['discount_price'] : (float)$row['price'];
}

/* ---------------- 1) สร้างตะกร้าที่จะชำระ ---------------- */
$items = [];
$total = 0.0;

if (isset($_GET['product_id']) && isset($_GET['qty'])) {
  // ซื้อเลย
  $pid = (int)$_GET['product_id'];
  $qty = max(1, (int)$_GET['qty']);

  $st = $conn->prepare("SELECT id,name,price,discount_price,image,stock,status FROM products WHERE id=? LIMIT 1");
  $st->bind_param("i",$pid);
  $st->execute();
  if ($p = $st->get_result()->fetch_assoc()){
    if (($p['status'] ?? 'active') === 'active') {
      $price = pickPrice($p);
      $qty   = min($qty, (int)$p['stock']);
      if ($qty > 0) {
        $sum = $price * $qty;
        // เก็บราคาเต็มไว้ด้วยเพื่อรู้ว่าสินค้าชิ้นนี้ “ลดราคาอยู่” หรือไม่
        $items[] = [
          'id'=>$p['id'],'name'=>$p['name'],'image'=>$p['image'],
          'price'=>$price,'qty'=>$qty,'sum'=>$sum,'orig_price'=>(float)$p['price']
        ];
        $total += $sum;
      }
    }
  }
  $st->close();
} else {
  // จากรถเข็น
  $sql = "SELECT ci.quantity, p.id pid, p.name, p.price, p.discount_price, p.image, p.stock, p.status
          FROM cart_items ci JOIN products p ON p.id = ci.product_id
          WHERE ci.user_id=?";
  $st = $conn->prepare($sql);
  $st->bind_param("i",$user_id);
  $st->execute();
  $rs = $st->get_result();
  while($row = $rs->fetch_assoc()){
    if (($row['status'] ?? 'active') !== 'active') continue;
    $price = pickPrice($row);
    $qty   = min((int)$row['quantity'], (int)$row['stock']);
    if ($qty <= 0) continue;
    $sum = $price * $qty;
    $items[] = [
      'id'=>$row['pid'],'name'=>$row['name'],'image'=>$row['image'],
      'price'=>$price,'qty'=>$qty,'sum'=>$sum,'orig_price'=>(float)$row['price']
    ];
    $total += $sum;
  }
  $st->close();
}

/* ---------- helpers สำหรับคูปอง ---------- */
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $q = $c->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}
function has_col(mysqli $c, string $t, string $col): bool {
  $t = preg_replace('/[^a-zA-Z0-9_]/','',$t);
  $col = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $c->query("SHOW COLUMNS FROM `$t` LIKE '$col'");
  return $q && $q->num_rows>0;
}

/* ตรวจคูปอง + คำนวณส่วนลดจริง (ฝั่งเซิร์ฟเวอร์) */
function validate_and_price_coupon(mysqli $conn, int $uid, string $code, array $items): array {
  $out = ['ok'=>false,'msg'=>'','discount'=>0.0,'row'=>null];

  $code = trim($code);
  if ($code==='') return $out;

  // โหลดคูปอง
  $st=$conn->prepare("SELECT * FROM coupons WHERE code=? LIMIT 1");
  $st->bind_param('s',$code); $st->execute();
  $c = $st->get_result()->fetch_assoc(); $st->close();
  if(!$c){ $out['msg']='ไม่พบคูปองนี้'; return $out; }
  $out['row'] = $c;

  $now = date('Y-m-d H:i:s');
  $status = strtolower($c['status'] ?? 'active');
  $starts = ($c['starts_at'] ?? null);
  $ends   = ($c['ends_at'] ?? ($c['expiry_date'] ?? null));
  if ($status!=='active') { $out['msg']='คูปองนี้ถูกปิดใช้งาน'; return $out; }
  if (!empty($starts) && $starts>$now) { $out['msg']='คูปองนี้ยังไม่เริ่มใช้งาน'; return $out; }
  if (!empty($ends)   && $ends<$now)   { $out['msg']='คูปองนี้หมดอายุแล้ว'; return $out; }

  // ใช้กับอะไร
  $applies = strtolower($c['applies_to'] ?? 'all'); // all/products/services/tradein
  if (!in_array($applies,['all','products'],true)) {
    $out['msg']='คูปองนี้ไม่รองรับการซื้อสินค้า'; return $out;
  }

  // ตรวจจำนวนครั้ง
  $perUser = (int)($c['per_user_limit'] ?? 0);
  $usesLim = (int)($c['uses_limit'] ?? 0);
  $usedTot = (int)($c['used_count'] ?? 0);

  $myUsed = 0;
  if (table_exists($conn,'coupon_usages')) {
    $st=$conn->prepare("SELECT COUNT(*) c FROM coupon_usages WHERE coupon_id=? AND user_id=?");
    $cid=(int)$c['id']; $st->bind_param('ii',$cid,$uid); $st->execute();
    $myUsed=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
  }
  if ($perUser>0 && $myUsed >= $perUser){ $out['msg']='คุณใช้คูปองนี้ครบแล้ว'; return $out; }
  if ($usesLim>0 && $usedTot >= $usesLim){ $out['msg']='คูปองนี้มีผู้ใช้ครบแล้ว'; return $out; }

  // ฐานคำนวณ (กันซ้อนกับราคาลด ถ้าไม่อนุญาต)
  $allowStack = (int)($c['allow_stack_with_discount_price'] ?? 0);
  $base = 0.0;
  foreach ($items as $it) {
    $hasDiscount = isset($it['orig_price']) && (float)$it['price'] < (float)$it['orig_price'];
    if (!$allowStack && $hasDiscount) continue; // ชิ้นที่ลดราคาอยู่ ไม่เอามาคิด
    $base += (float)$it['sum'];
  }
  if ($base <= 0) {
    $out['msg']='คูปองนี้ใช้ร่วมกับสินค้าลดราคาไม่ได้';
    return $out;
  }

  // ขั้นต่ำ
  $minOrder = (float)($c['min_order_total'] ?? 0);
  if ($minOrder > 0 && $base < $minOrder) {
    $out['msg'] = 'ยอดสั่งซื้อไม่ถึงขั้นต่ำของคูปอง';
    return $out;
  }

  // คำนวณส่วนลด
  $type = strtolower($c['type'] ?? 'fixed'); // fixed|percent
  $value= (float)($c['value'] ?? 0);
  $max  = (float)($c['max_discount'] ?? 0);

  $disc = 0.0;
  if ($type==='percent') {
    $disc = $base * ($value/100.0);
    if ($max>0) $disc = min($disc, $max);
  } else {
    $disc = min($value, $base);
  }

  $out['ok'] = $disc > 0.0;
  $out['discount'] = $disc;
  if (!$out['ok']) $out['msg']='คูปองนี้ไม่ทำให้ยอดลดลง';
  return $out;
}

/* รายการคูปองของฉัน (ไว้ให้กดเลือก) */
$userCoupons = [];
$cols = [
  "c.id", "c.code", "c.type", "c.value",
  (has_col($conn,'coupons','min_order_total') ? "COALESCE(c.min_order_total,0) AS min_order_total" : "0 AS min_order_total"),
  (has_col($conn,'coupons','applies_to') ? "COALESCE(c.applies_to,'all') AS applies_to" : "'all' AS applies_to"),
  (has_col($conn,'coupons','allow_stack_with_discount_price') ? "COALESCE(c.allow_stack_with_discount_price,0) AS allow_stack_with_discount_price" : "0 AS allow_stack_with_discount_price"),
  (has_col($conn,'coupons','starts_at') ? "c.starts_at" : "NULL AS starts_at"),
  (has_col($conn,'coupons','ends_at')   ? "c.ends_at"   : (has_col($conn,'coupons','expiry_date') ? "c.expiry_date AS ends_at" : "NULL AS ends_at")),
  "c.status",
  (has_col($conn,'coupons','uses_limit')     ? "COALESCE(c.uses_limit,0)     AS uses_limit"     : "0 AS uses_limit"),
  (has_col($conn,'coupons','per_user_limit') ? "COALESCE(c.per_user_limit,0) AS per_user_limit" : "0 AS per_user_limit"),
  "COALESCE(SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END),0) AS used_total"
];
/* เงื่อนไข “คูปองที่ผูกกับฉันหรือสาธารณะ” รองรับได้ทั้งสคีมาที่มี/ไม่มี segment */
$hasSegment = has_col($conn,'coupons','segment');
$publicClause = $hasSegment ? "c.segment='all'" : "c.user_id IS NULL";

/* ประกอบ SQL แบบยืดหยุ่น */
$sql = "
  SELECT ".implode(',', $cols)."
  FROM coupons c
  LEFT JOIN coupon_usages cu ON cu.coupon_id = c.id
  WHERE (c.user_id=? OR {$publicClause}) AND c.status='active'
    AND (".(has_col($conn,'coupons','starts_at') ? "c.starts_at IS NULL OR c.starts_at<=NOW()" : "1=1").")
    AND (".(has_col($conn,'coupons','ends_at')   ? "c.ends_at   IS NULL OR c.ends_at>=NOW()"   : (has_col($conn,'coupons','expiry_date') ? "c.expiry_date IS NULL OR c.expiry_date>=NOW()" : "1=1")).")
  GROUP BY c.id
  ORDER BY c.id DESC
";
if ($st = $conn->prepare($sql)) {
  $st->bind_param('i',$user_id);
  $st->execute();
  $userCoupons = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

/* ====== Preview คูปองบนหน้านี้ (Server คิดจริง) ====== */
$applyFromGet = isset($_GET['apply']) ? trim((string)$_GET['apply']) : '';
$couponPreview = ['ok'=>false,'discount'=>0.0,'code'=>'','msg'=>''];
if ($applyFromGet !== '') {
  $res = validate_and_price_coupon($conn, $user_id, $applyFromGet, $items);
  $couponPreview['ok'] = $res['ok'];
  $couponPreview['discount'] = $res['discount'];
  $couponPreview['code'] = $applyFromGet;
  $couponPreview['msg'] = $res['msg'] ?? '';
}
$initial_discount = (float)$couponPreview['discount'];
$initial_code     = (string)$couponPreview['code'];
$grand_initial    = max(0.0, $total - $initial_discount);

/* ---------------- 2) ดึงที่อยู่ผู้ใช้ไว้เติมอัตโนมัติ ---------------- */
$profile = [
  'full_name' => '', 'phone' => '',
  'address_line1' => '', 'address_line2' => '',
  'district' => '', 'province' => '', 'postcode' => ''
];
$st = $conn->prepare("
  SELECT
    COALESCE(full_name,'')      AS full_name,
    COALESCE(phone,'')          AS phone,
    COALESCE(address_line1,'')  AS address_line1,
    COALESCE(address_line2,'')  AS address_line2,
    COALESCE(district,'')       AS district,
    COALESCE(province,'')       AS province,
    COALESCE(postcode,'')       AS postcode
  FROM users
  WHERE id=? LIMIT 1
");
$st->bind_param("i",$user_id);
$st->execute();
if ($u = $st->get_result()->fetch_assoc()) { $profile = $u; }
$st->close();

/* 2.1 สมุดที่อยู่ (ถ้ามี) */
$book = []; // เปิดใช้ได้ภายหลัง
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ชำระเงิน | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --card:#fff; --line:#e9eef3; --ink:#1f2937; --muted:#6b7280;
      --pri:#2563eb; --pri2:#4f46e5; --good:#16a34a; --warn:#f59e0b;
    }
    body{background:linear-gradient(180deg,#f8fbff,#f6f8fb 50%,#f5f7fa);}
    .card{border-radius:16px;border:1px solid var(--line); background:var(--card);}
    .shadow-soft{box-shadow:0 10px 30px rgba(17,24,39,.06);}
    .page-head{border-radius:20px; color:#fff; padding:18px 18px 16px;
      background:linear-gradient(135deg,var(--pri) 0%, var(--pri2) 55%, #0ea5e9 100%);
      box-shadow:0 8px 24px rgba(37,99,235,.15);}
    .stepper{display:flex; gap:12px; flex-wrap:wrap; margin-top:10px}
    .step{display:flex; align-items:center; gap:8px; color:#e6ecff; font-weight:600;
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
      padding:6px 12px; border-radius:999px;}
    .step .num{width:24px; height:24px; border-radius:999px; display:grid; place-items:center;
      background:#fff; color:#1f2a44; font-weight:800; font-size:.85rem;}
    .step.active{background:#fff; color:#0b1a37;}
    .step.active .num{background:var(--pri); color:#fff;}
    .hint{color:#6b7280}
    .addr-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
    @media(max-width:992px){.addr-grid{grid-template-columns:1fr}}
    .coupon-pill{display:inline-flex;align-items:center;gap:.5rem;border:1px dashed #cdd6e1;border-radius:999px;padding:.35rem .7rem;background:#fff; transition:.15s ease;}
    .coupon-pill:hover{ transform:translateY(-1px); box-shadow:0 8px 24px rgba(17,24,39,.05);}
    .coupon-pill .code{font-weight:800}
    .coupon-pill .val{font-size:.9rem;color:#475569}
    .text-strike{text-decoration:line-through;color:#94a3b8}
    @media(min-width:992px){.sticky-summary{ position:sticky; top:18px; }}
    .line-dash{ height:1px; background:repeating-linear-gradient(90deg, transparent 0 8px, var(--line) 8px 16px); }
    .btn-strong{ font-weight:800; border-radius:12px; padding:.9rem 1rem; }
    .list-thumb{ width:64px; height:64px; object-fit:cover; border-radius:12px; border:1px solid var(--line); background:#fff;}
    .badge-soft{ background:#eef2ff; color:#4338ca; border-radius:999px; }
    .back-link{ text-decoration:none; }
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <div class="page-head">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h3 class="m-0"><i class="bi bi-cash-coin me-2"></i>ชำระเงิน</h3>
      <a href="cart_view.php" class="text-white-50 back-link"><i class="bi bi-arrow-left"></i> กลับไปแก้ไขรถเข็น</a>
    </div>
    <div class="stepper">
      <div class="step"><span class="num">1</span> รถเข็น</div>
      <div class="step active"><span class="num">2</span> ชำระเงิน</div>
    </div>
  </div>

  <?php if(empty($items)): ?>
    <div class="alert alert-warning mt-3"><i class="bi bi-exclamation-triangle"></i> ไม่มีสินค้าในคำสั่งซื้อ</div>
  <?php else: ?>
  <div class="row g-3 mt-3">
    <!-- ซ้าย -->
    <div class="col-lg-7">
      <!-- สินค้า -->
      <div class="card shadow-soft">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span>สินค้า</span>
          <span class="badge badge-soft px-3 py-2">ทั้งหมด <?=count($items)?> รายการ</span>
        </div>
        <ul class="list-group list-group-flush" id="itemList">
          <?php foreach($items as $it):
            $img = $it['image'] ? 'assets/img/'.$it['image'] : 'assets/img/default.png';
            $hasDiscount = isset($it['orig_price']) && (float)$it['orig_price'] > (float)$it['price'];
          ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
              <img src="<?=h($img)?>" class="list-thumb" alt="">
              <div>
                <div class="fw-semibold"><?=h($it['name'])?></div>
                <div class="small text-muted">จำนวน: x <?= (int)$it['qty'] ?></div>
                <?php if($hasDiscount): ?>
                  <div class="small">
                    <span class="text-strike"><?=baht($it['orig_price'])?> ฿</span>
                    <span class="ms-1 fw-semibold text-success"><?=baht($it['price'])?> ฿</span>
                  </div>
                <?php else: ?>
                  <div class="small fw-semibold"><?=baht($it['price'])?> ฿</div>
                <?php endif; ?>
              </div>
            </div>
            <div class="fw-bold"><?=baht($it['sum'])?> ฿</div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer text-end fw-bold">รวมสินค้า: <span id="subtotalText"><?=baht($total)?></span> ฿</div>
      </div>

      <!-- คูปอง -->
      <div class="card shadow-soft mt-3">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-ticket-perforated me-1"></i> คูปองของฉัน</span>
          <span class="small text-muted">กดเพื่อใช้ หรือลองกรอกเองได้</span>
        </div>
        <div class="card-body">
          <?php if(empty($userCoupons)): ?>
            <div class="text-muted">ยังไม่มีคูปองที่ใช้งานได้</div>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mb-2" id="myCoupons">
              <?php foreach($userCoupons as $c):
                $cap = ($c['type']==='percent' ? (float)$c['value'].'%' : baht($c['value']).' ฿');
                $limit = (int)$c['uses_limit'];
                $used  = (int)$c['used_total'];
                $left  = $limit>0 ? max(0, $limit-$used) : 'ไม่จำกัด';
              ?>
              <button type="button"
                      class="coupon-pill"
                      data-code="<?=h($c['code'])?>"
                      data-type="<?=h($c['type'])?>"
                      data-value="<?=h($c['value'])?>"
                      data-min="<?=h($c['min_order_total'])?>"
                      data-applies="<?=h($c['applies_to'])?>"
                      data-stack="<?= (int)$c['allow_stack_with_discount_price'] ?>">
                <span class="code"><?=h($c['code'])?></span>
                <span class="val">ลด <?=h($cap)?></span>
                <span class="val">คงเหลือ: <?=h($left)?></span>
              </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-ticket"></i></span>
            <input type="text" class="form-control" id="couponInput" placeholder="กรอกโค้ดคูปอง" value="<?=h($applyFromGet)?>">
            <button class="btn btn-outline-primary" type="button" id="applyCouponBtn">ใช้คูปอง</button>
          </div>
          <div class="form-text hint mt-1">
            ระบบจะคำนวณส่วนลดให้ดูทันที และส่งโค้ดคูปองไปยืนยันในขั้นตอนสั่งซื้อ (ฝั่งเซิร์ฟเวอร์จะตรวจซ้ำอีกครั้ง)
          </div>

          <?php if($applyFromGet!==''): ?>
            <div class="small mt-2 <?= $couponPreview['ok']?'text-success':'text-danger' ?>">
              <?= h($couponPreview['ok'] ? ('ใช้คูปองแล้ว: -'.baht($initial_discount).' ฿') : ('⚠ '.$couponPreview['msg'])) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- จัดส่ง + ชำระ -->
      <div class="card shadow-soft mt-3">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
          <i class="bi bi-truck"></i> ข้อมูลการจัดส่ง &nbsp; <span class="small text-muted">กรอกรายละเอียดให้ครบถ้วน</span>
        </div>
        <div class="card-body">
          <form action="place_order.php" method="post" id="checkoutForm" class="needs-validation" novalidate>
            <?php foreach($items as $it): ?>
              <input type="hidden" name="product_id[]" value="<?=$it['id']?>">
              <input type="hidden" name="qty[]"        value="<?=$it['qty']?>">
            <?php endforeach; ?>
            <!-- ส่งผลคูปอง/ยอดรวม ไปให้ place_order ตรวจยืนยันอีกครั้ง -->
            <input type="hidden" name="coupon_code" id="coupon_code" value="<?= h($initial_code) ?>">
            <input type="hidden" name="client_subtotal" id="client_subtotal" value="<?= number_format($total,2,'.','') ?>">
            <input type="hidden" name="client_discount" id="client_discount" value="<?= number_format($initial_discount,2,'.','') ?>">
            <input type="hidden" name="client_grand"    id="client_grand"    value="<?= number_format($grand_initial,2,'.','') ?>">

            <!-- แหล่งที่อยู่ -->
            <div class="mb-3">
              <label class="form-label">เลือกที่อยู่</label>
              <div class="d-flex flex-wrap gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="address_source" id="addrProfile" value="profile" checked>
                  <label class="form-check-label" for="addrProfile">ใช้ที่อยู่จากโปรไฟล์</label>
                </div>
                <?php if (!empty($book)): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="address_source" id="addrBook" value="book">
                    <label class="form-check-label" for="addrBook">เลือกจากสมุดที่อยู่</label>
                  </div>
                <?php else: ?>
                  <span class="small text-muted">ยังไม่มีสมุดที่อยู่</span>
                <?php endif; ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="address_source" id="addrCustom" value="custom">
                  <label class="form-check-label" for="addrCustom">กรอกที่อยู่ใหม่</label>
                </div>
              </div>
              <?php if (!empty($book)): ?>
                <div class="ms-1 mt-2" id="bookPicker" style="display:none">
                  <select class="form-select" name="address_id" id="address_id">
                    <?php foreach($book as $b):
                      $label = trim(($b['full_name']??'').' | '.($b['phone']??'').' | '.($b['a1']??'').' '.($b['a2']??'').' '.($b['dist']??'').' '.($b['prov']??'').' '.($b['pc']??'')); ?>
                      <option value="<?= (int)$b['id'] ?>"><?= !empty($b['is_default'])?'[ค่าเริ่มต้น] ':'' ?><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
            </div>

            <!-- ฟิลด์ที่อยู่ -->
            <div class="mb-2">
              <label class="form-label">ชื่อ-นามสกุล</label>
              <input type="text" name="fullname" id="fullname" class="form-control" required value="<?=h($profile['full_name'])?>">
              <div class="invalid-feedback">กรอกชื่อ-นามสกุล</div>
            </div>
            <div class="addr-grid">
              <div>
                <label class="form-label">เบอร์โทร</label>
                <input type="text" name="phone" id="phone" class="form-control" required value="<?=h($profile['phone'])?>">
                <div class="invalid-feedback">กรอกเบอร์โทร</div>
              </div>
              <div>
                <label class="form-label">รหัสไปรษณีย์</label>
                <input type="text" name="postcode" id="postcode" class="form-control" value="<?=h($profile['postcode'])?>">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 1)</label>
              <input type="text" name="address_line1" id="address_line1" class="form-control" required value="<?=h($profile['address_line1'])?>">
              <div class="invalid-feedback">กรอกที่อยู่บรรทัดที่ 1</div>
            </div>
            <div class="mb-2">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 2)</label>
              <input type="text" name="address_line2" id="address_line2" class="form-control" placeholder="ตึก/หมู่บ้าน/ชั้น/ห้อง ฯลฯ (ถ้ามี)" value="<?=h($profile['address_line2'])?>">
            </div>
            <div class="addr-grid">
              <div>
                <label class="form-label">เขต/อำเภอ</label>
                <input type="text" name="district" id="district" class="form-control" value="<?=h($profile['district'])?>">
              </div>
              <div>
                <label class="form-label">จังหวัด</label>
                <input type="text" name="province" id="province" class="form-control" value="<?=h($profile['province'])?>">
              </div>
            </div>

            <hr class="my-3 line-dash">

            <!-- วิธีชำระ -->
            <div class="mb-3">
              <label class="form-label">วิธีการชำระเงิน</label>
              <select name="payment_method" class="form-select" required>
                <option value="cod" selected>เก็บเงินปลายทาง</option>
                <option value="bank">โอนเงินธนาคาร</option>
              </select>
              <div class="form-text hint mt-1">เลือกชำระผ่านธนาคาร ระบบจะรอตรวจสอบสลิปโดยผู้ดูแล</div>
            </div>

            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-success btn-strong" id="submitBtn">
                <i class="bi bi-check-circle me-1"></i>
                ยืนยันการสั่งซื้อ (รวม <span id="submitGrand"><?=baht($grand_initial)?></span> ฿)
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ขวา: สรุป -->
    <div class="col-lg-5">
      <div class="card shadow-soft sticky-summary">
        <div class="card-header fw-semibold">สรุปราคา</div>
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>รวมสินค้า</div>
            <div><span id="sumSubtotal"><?=baht($total)?></span> ฿</div>
          </div>
          <div class="d-flex justify-content-between">
            <div>ส่วนลดคูปอง</div>
            <div>- <span id="sumDiscount"><?= baht($initial_discount) ?></span> ฿</div>
          </div>
          <div class="d-flex justify-content-between">
            <div>ค่าจัดส่ง</div>
            <div><span id="sumShipping">0.00</span> ฿</div>
          </div>
          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5">
            <div>ยอดสุทธิ</div>
            <div><span id="sumGrand"><?=baht($grand_initial)?></span> ฿</div>
          </div>
          <div class="small text-muted mt-1" id="couponNote" style="<?= $applyFromGet!=='' ? 'display:block' : 'display:none' ?>">
            <?php if($applyFromGet!==''): ?>
              <?= h($couponPreview['ok'] ? "ใช้คูปอง $applyFromGet แล้ว" : ('⚠ '.$couponPreview['msg'])) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<script>
/** ===== Client totals (ซิงค์กับ preview จาก PHP) ===== */
let subtotal = <?= json_encode((float)$total) ?>;
let discount = <?= json_encode((float)$initial_discount) ?>;
let shipping = 0.00;
const coupons = (<?= json_encode($userCoupons, JSON_UNESCAPED_UNICODE) ?>)||[];
const applyFromGet = <?= json_encode($applyFromGet, JSON_UNESCAPED_UNICODE) ?>;

const fmt = (n)=> (Number(n)||0).toFixed(2);
const qs = (sel)=> document.querySelector(sel);
const setTxt = (id, v)=> document.getElementById(id).textContent = (Number(v)||0).toFixed(2);
const setBaht = (id, v)=> document.getElementById(id).textContent =
  new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v)||0);

function calcGrand(){
  const grand = Math.max(0, subtotal - discount + shipping);
  setBaht('sumSubtotal', subtotal);
  setTxt('sumDiscount', discount);
  setTxt('sumShipping', shipping);
  setBaht('sumGrand', grand);
  setBaht('submitGrand', grand);
  qs('#client_subtotal')?.setAttribute('value', fmt(subtotal));
  qs('#client_discount')?.setAttribute('value', fmt(discount));
  qs('#client_grand')?.setAttribute('value', fmt(grand));
}

/** ===== Preview coupon ด้านหน้า (เซิร์ฟเวอร์จะตรวจยืนยันอีกครั้ง) ===== */
function computeDiscount(total, c) {
  const min = Number(c.min_order_total||0);
  if (min > 0 && total < min) return {ok:false, amount:0, reason:`ยอดสั่งซื้อไม่ถึงขั้นต่ำ ${fmt(min)} ฿`};
  const applies = String(c.applies_to||'all');
  if (applies !== 'all' && applies !== 'products') return {ok:false, amount:0, reason:'คูปองนี้จำกัดการใช้งาน'};
  let amt = 0;
  if (String(c.type) === 'percent') {
    const rate = Number(c.value||0)/100;
    amt = Math.max(0, total * rate);
  } else {
    amt = Math.min(Number(c.value||0), total);
  }
  if (amt <= 0) return {ok:false, amount:0, reason:'ส่วนลดเป็น 0'};
  return {ok:true, amount:amt, reason:''};
}

function applyCouponObj(c) {
  const note = document.getElementById('couponNote');
  const input = document.getElementById('couponInput');
  const codeHidden = document.getElementById('coupon_code');

  const r = computeDiscount(subtotal, c);
  if (!r.ok) {
    discount = 0.00;
    note.style.display = 'block';
    note.className = 'small text-danger mt-1';
    note.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ใช้คูปอง <b>${c.code}</b> ไม่ได้: ${r.reason}`;
    codeHidden.value = '';
  } else {
    discount = r.amount;
    note.style.display = 'block';
    note.className = 'small text-success mt-1';
    const cap = (c.type==='percent') ? `${Number(c.value)}%` : `${fmt(c.value)} ฿`;
    const minCap = Number(c.min_order_total||0)>0 ? ` (ขั้นต่ำ ${fmt(c.min_order_total)} ฿)` : '';
    note.innerHTML = `<i class="bi bi-check2-circle"></i> ใช้คูปอง <b>${c.code}</b> แล้ว: ลด <b>${fmt(discount)} ฿</b> ${minCap}`;
    input && (input.value = c.code);
    codeHidden.value = c.code;
  }
  calcGrand();
}

document.getElementById('applyCouponBtn')?.addEventListener('click', ()=>{
  const code = (document.getElementById('couponInput')?.value || '').trim();
  if (!code) return;
  const c = coupons.find(x => String(x.code).toUpperCase() === code.toUpperCase());
  if (c) { applyCouponObj(c); return; }
  // ไม่พบในรายการ — preview แบบ fixed=0 (เซิร์ฟเวอร์จะตรวจจริง)
  applyCouponObj({code,type:'fixed',value:0, min_order_total:0, applies_to:'all'});
});

document.getElementById('myCoupons')?.addEventListener('click', (e)=>{
  const pill = e.target.closest('.coupon-pill');
  if (!pill) return;
  const c = {
    code:  pill.dataset.code,
    type:  pill.dataset.type,
    value: Number(pill.dataset.value||0),
    min_order_total: Number(pill.dataset.min||0),
    applies_to: pill.dataset.applies||'all',
    allow_stack_with_discount_price: Number(pill.dataset.stack||0)
  };
  applyCouponObj(c);
});

// เติมจากโปรไฟล์/สมุดที่อยู่
const profile = <?=json_encode($profile, JSON_UNESCAPED_UNICODE)?>;
const book    = <?=json_encode($book, JSON_UNESCAPED_UNICODE)?>;
const el = (id)=>document.getElementById(id);
const inputs = ['fullname','phone','address_line1','address_line2','district','province','postcode'];

function fillFromProfile(){
  el('fullname').value      = profile.full_name || '';
  el('phone').value         = profile.phone || '';
  el('address_line1').value = profile.address_line1 || '';
  el('address_line2').value = profile.address_line2 || '';
  el('district').value      = profile.district || '';
  el('province').value      = profile.province || '';
  el('postcode').value      = profile.postcode || '';
}
function fillFromBook(){
  const sel = document.getElementById('address_id');
  const id  = parseInt(sel?.value || 0, 10);
  const a = Array.isArray(book) ? (book.find(x => String(x.id) === String(id)) || {}) : {};
  el('fullname').value      = a.full_name || '';
  el('phone').value         = a.phone || '';
  el('address_line1').value = a.a1 || '';
  el('address_line2').value = a.a2 || '';
  el('district').value      = a.dist || '';
  el('province').value      = a.prov || '';
  el('postcode').value      = a.pc || '';
}
function clearCustom(){ inputs.forEach(id => el(id).value = ''); }
function toggleBookPicker(show){ const picker = document.getElementById('bookPicker'); if (picker) picker.style.display = show ? 'block' : 'none'; }

document.getElementById('addrProfile')?.addEventListener('change', e=>{ if (!e.target.checked) return; toggleBookPicker(false); fillFromProfile(); });
document.getElementById('addrBook')?.addEventListener('change', e=>{ if (!e.target.checked) return; toggleBookPicker(true); fillFromBook(); });
document.getElementById('addrCustom')?.addEventListener('change', e=>{ if (!e.target.checked) return; toggleBookPicker(false); clearCustom(); });
document.getElementById('address_id')?.addEventListener('change', fillFromBook);

// Bootstrap validation
(function(){
  const form = document.getElementById('checkoutForm');
  form?.addEventListener('submit', function (event) {
    if (!form.checkValidity()) {
      event.preventDefault(); event.stopPropagation();
    }
    form.classList.add('was-validated');
  }, false);
})();

// init
calcGrand(); fillFromProfile();
if (applyFromGet) {
  // ถ้าหน้าถูกเปิดมาพร้อม ?apply= ให้โชว์ note แล้ว
  document.getElementById('couponNote')?.style?.setProperty('display','block');
}
</script>
</body>
</html>
