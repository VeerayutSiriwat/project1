<?php 
// service_pay.php — รองรับคูปองบริการ (context=service) + เลือกช่องทางชำระ + QR

if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows>0;
}
function has_table(mysqli $conn, string $table): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $q = $conn->query("SHOW TABLES LIKE '$table'");
  return $q && $q->num_rows>0;
}
function baht($n){ return number_format((float)$n,2); }

/* ===== auth ===== */
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php?redirect=service_my.php');
  exit;
}
$uid = (int)$_SESSION['user_id'];
$id  = (int)($_GET['id'] ?? 0);
if ($id<=0) { header('Location: service_my.php'); exit; }

/* ===== โหลดใบงานซ่อมของ user ===== */
$ticket = null;
if ($st = $conn->prepare("SELECT * FROM service_tickets WHERE id=? AND user_id=?")) {
  $st->bind_param('ii', $id, $uid);
  $st->execute();
  $ticket = $st->get_result()->fetch_assoc();
  $st->close();
}
if (!$ticket) {
  $_SESSION['flash'] = 'ไม่พบใบงานซ่อมที่ต้องการชำระ';
  header('Location: service_my.php');
  exit;
}

/* ===== เช็ค column/payment ===== */
$hasServicePrice = has_col($conn,'service_tickets','service_price');
$hasPayStatus    = has_col($conn,'service_tickets','payment_status');
$hasPayMethod    = has_col($conn,'service_tickets','pay_method');
$hasPaidAt       = has_col($conn,'service_tickets','paid_at');
$hasSlipCol      = has_col($conn,'service_tickets','payment_slip');

/* ===== subtotal จากรายการซ่อม ถ้ามี ===== */
$itemsSubtotal = 0.0;
if (has_table($conn,'service_ticket_items')) {
  $hasLineTotal = has_col($conn,'service_ticket_items','line_total');

  if ($hasLineTotal) {
    if ($st = $conn->prepare("SELECT line_total FROM service_ticket_items WHERE ticket_id=?")) {
      $st->bind_param('i',$id);
      $st->execute();
      $rs = $st->get_result();
      while($row = $rs->fetch_assoc()){
        $itemsSubtotal += (float)($row['line_total'] ?? 0);
      }
      $st->close();
    }
  } else {
    if ($st = $conn->prepare("SELECT qty, unit_price FROM service_ticket_items WHERE ticket_id=?")) {
      $st->bind_param('i',$id);
      $st->execute();
      $rs = $st->get_result();
      while($row = $rs->fetch_assoc()){
        $qty  = (float)($row['qty'] ?? 0);
        $unit = (float)($row['unit_price'] ?? 0);
        $itemsSubtotal += $qty * $unit;
      }
      $st->close();
    }
  }
}

/* ===== คำนวณยอดเต็ม (servicePrice) ===== */
$servicePrice = 0.0;
if ($itemsSubtotal > 0) {
  $servicePrice = $itemsSubtotal;
} elseif ($hasServicePrice && $ticket['service_price'] !== null) {
  $servicePrice = (float)$ticket['service_price'];
} else {
  $servicePrice = (float)($ticket['estimate_total'] ?? 0);
}

/* ===== ฟังก์ชันตรวจคูปอง (context=service) ===== */
function validate_and_price_service_coupon(mysqli $conn, int $uid, string $code, float $base): array {
  $out = ['ok'=>false,'discount'=>0.0,'msg'=>'','row'=>null];
  $code = trim($code);
  if ($code==='') { $out['msg']=''; return $out; }

  if (!has_table($conn,'coupons')) { $out['msg']='ยังไม่เปิดใช้ระบบคูปอง'; return $out; }
  if (!($st=$conn->prepare("SELECT * FROM coupons WHERE code=? LIMIT 1"))) {
    $out['msg']='คิวรีคูปองผิดพลาด'; return $out;
  }
  $st->bind_param('s',$code); $st->execute();
  $c = $st->get_result()->fetch_assoc(); $st->close();
  if (!$c){ $out['msg']='ไม่พบคูปองนี้'; return $out; }
  $out['row'] = $c;

  $status = strtolower($c['status'] ?? 'active');
  $starts = $c['starts_at'] ?? null;
  $ends   = $c['ends_at'] ?? ($c['expiry_date'] ?? null);
  $now    = date('Y-m-d H:i:s');
  if ($status!=='active') { $out['msg']='คูปองนี้ถูกปิดใช้งาน'; return $out; }
  if (!empty($starts) && $starts>$now) { $out['msg']='คูปองนี้ยังไม่เริ่มใช้งาน'; return $out; }
  if (!empty($ends)   && $ends<$now)   { $out['msg']='คูปองนี้หมดอายุแล้ว'; return $out; }

  $applies = strtolower($c['applies_to'] ?? 'all'); // all|products|services|tradein

  if ($applies === 'tradein') {
    $out['msg'] = 'คูปองนี้ใช้สำหรับเทิร์นสินค้า ไม่สามารถใช้กับค่าบริการได้';
    return $out;
  }
  if (!in_array($applies,['all','services'],true)) {
    $out['msg'] = 'คูปองนี้ไม่รองรับค่าบริการ';
    return $out;
  }

  $perUser = (int)($c['per_user_limit'] ?? 0);
  $usesLim = (int)($c['uses_limit'] ?? 0);
  $usedTot = 0; $myUsed = 0;
  if (has_table($conn,'coupon_usages')) {
    $hasCtx   = has_col($conn,'coupon_usages','context');
    $hasUid   = has_col($conn,'coupon_usages','user_id');
    $hasTid   = has_col($conn,'coupon_usages','ticket_id');

    $sql = "SELECT 
              COUNT(DISTINCT cu.".($hasTid?'ticket_id':'id').") AS used_total,
              SUM(CASE WHEN ".($hasUid?'cu.user_id':'0')."=? THEN 1 ELSE 0 END) AS used_by_me
            FROM coupon_usages cu
            WHERE cu.coupon_id=? ".
            ($hasCtx ? "AND cu.context='service'":"");
    if ($st = $conn->prepare($sql)) {
      $cid = (int)$c['id'];
      $st->bind_param('ii',$uid,$cid);
      $st->execute();
      $row = $st->get_result()->fetch_assoc() ?: ['used_total'=>0,'used_by_me'=>0];
      $st->close();
      $usedTot = (int)$row['used_total'];
      $myUsed  = (int)$row['used_by_me'];
    }
  }
  if ($usesLim>0 && $usedTot >= $usesLim){ $out['msg']='คูปองนี้มีผู้ใช้ครบแล้ว'; return $out; }
  if ($perUser>0 && $myUsed  >= $perUser){ $out['msg']='คุณใช้คูปองนี้ครบแล้ว'; return $out; }

  $minOrder = (float)($c['min_order_total'] ?? 0);
  if ($minOrder>0 && $base<$minOrder){ $out['msg']='ยอดชำระไม่ถึงขั้นต่ำของคูปอง'; return $out; }

  $type = strtolower($c['type'] ?? 'fixed'); // fixed|percent
  $val  = (float)($c['value'] ?? 0);
  $max  = (float)($c['max_discount'] ?? 0);
  $disc = 0.0;
  if ($type==='percent'){
    $disc = $base * ($val/100.0);
    if ($max>0) $disc = min($disc, $max);
  } else {
    $disc = min($val, $base);
  }
  $out['ok'] = $disc>0.0;
  $out['discount'] = $disc;
  if (!$out['ok']) $out['msg']='คูปองนี้ไม่ทำให้ยอดลดลง';
  return $out;
}

/* ===== ลิสต์คูปองของฉัน (เฉพาะบริการ) ===== */
$userCoupons = [];
if (has_table($conn,'coupons')) {
  $cols = [
    "c.id","c.code","c.type","c.value",
    (has_col($conn,'coupons','min_order_total') ? "COALESCE(c.min_order_total,0) AS min_order_total" : "0 AS min_order_total"),
    (has_col($conn,'coupons','applies_to') ? "COALESCE(c.applies_to,'all') AS applies_to" : "'all' AS applies_to"),
    (has_col($conn,'coupons','starts_at') ? "c.starts_at" : "NULL AS starts_at"),
    (has_col($conn,'coupons','ends_at')   ? "c.ends_at"   : (has_col($conn,'coupons','expiry_date') ? "c.expiry_date AS ends_at" : "NULL AS ends_at")),
    "c.status",
    (has_col($conn,'coupons','uses_limit')     ? "COALESCE(c.uses_limit,0)     AS uses_limit"     : "0 AS uses_limit"),
    (has_col($conn,'coupons','per_user_limit') ? "COALESCE(c.per_user_limit,0) AS per_user_limit" : "0 AS per_user_limit"),

    "(SELECT COUNT(*) FROM coupon_usages cu
        WHERE cu.coupon_id=c.id ".(has_col($conn,'coupon_usages','context')?"AND cu.context='service'":"")."
     ) AS used_total",

    "(SELECT COUNT(*) FROM coupon_usages cu2
        WHERE cu2.coupon_id=c.id ".(has_col($conn,'coupon_usages','context')?"AND cu2.context='service'":"")."
          ".(has_col($conn,'coupon_usages','user_id')?"AND cu2.user_id=?":"")."
     ) AS used_by_me"
  ];

  $hasSegment = has_col($conn,'coupons','segment');
  $publicClause = $hasSegment ? "c.segment='all'" : "c.user_id IS NULL";

  $appliesFilter = has_col($conn,'coupons','applies_to')
      ? "(c.applies_to IN ('all','services'))"
      : "1=1";

  $sql = "
    SELECT ".implode(',', $cols)."
    FROM coupons c
    WHERE (c.user_id=? OR {$publicClause})
      AND {$appliesFilter}
      AND c.status='active'
      AND ".(has_col($conn,'coupons','starts_at') ? "(c.starts_at IS NULL OR c.starts_at<=NOW())" : "1=1")."
      AND ".(has_col($conn,'coupons','ends_at')   ? "(c.ends_at   IS NULL OR c.ends_at>=NOW())"   : (has_col($conn,'coupons','expiry_date') ? "(c.expiry_date IS NULL OR c.expiry_date>=NOW())" : "1=1"))."
    ORDER BY c.id DESC
  ";
  if ($st = $conn->prepare($sql)) {
    if (has_col($conn,'coupon_usages','user_id')) {
      $st->bind_param('ii', $uid, $uid);
    } else {
      $st->bind_param('i', $uid);
    }
    $st->execute();
    $userCoupons = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();
  }
}

/* ===== คูปองจาก GET ?apply=CODE ===== */
$applyCode = isset($_GET['apply']) ? trim((string)$_GET['apply']) : '';
$cp = ['ok'=>false,'discount'=>0.0,'msg'=>'','code'=>''];
if ($applyCode!=='') {
  $res = validate_and_price_service_coupon($conn, $uid, $applyCode, $servicePrice);
  $cp['ok']       = $res['ok'];
  $cp['discount'] = (float)$res['discount'];
  $cp['msg']      = $res['msg'] ?? '';
  $cp['code']     = $applyCode;
}
$initial_discount = (float)$cp['discount'];
$finalPay = max(0.0, $servicePrice - $initial_discount);

/* ===== สถานะพร้อมชำระ? ===== */
$paymentStatus = $hasPayStatus ? ($ticket['payment_status'] ?? 'unpaid') : 'unpaid';
if ($paymentStatus === 'paid') {
  $_SESSION['flash'] = 'ใบงานนี้ชำระเงินเรียบร้อยแล้ว';
  header('Location: service_my_detail.php?type=repair&id='.$id);
  exit;
}
if (($ticket['status'] ?? '') !== 'done') {
  $_SESSION['flash'] = 'ใบงานนี้ยังไม่อยู่ในสถานะที่พร้อมให้ชำระเงิน';
  header('Location: service_my_detail.php?type=repair&id='.$id);
  exit;
}

/* ===== POST: ส่งข้อมูลการชำระ ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  $postCoupon = trim($_POST['coupon_code'] ?? '');
  $method     = $_POST['pay_method'] ?? 'bank';
  if (!in_array($method,['bank','cash'],true)) $method = 'bank';

  $slipPath = null;

  // ต้องอัปโหลดสลิปเฉพาะกรณีโอนธนาคาร/QR
  if ($method === 'bank') {
    if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
      $_SESSION['flash'] = 'กรุณาอัปโหลดสลิปเพื่อยืนยันการชำระเงิน';
      header('Location: service_pay.php?id='.$id.($postCoupon?('&apply='.urlencode($postCoupon)):'')); 
      exit;
    }

    $uploadDir = __DIR__.'/uploads/slips';
    if (!is_dir($uploadDir)) @mkdir($uploadDir,0775,true);

    $ext = strtolower(pathinfo($_FILES['slip']['name'],PATHINFO_EXTENSION));
    if(!$ext) $ext = 'jpg';
    $newName  = 'slip_st'.$id.'_u'.$uid.'_'.time().'.'.$ext;
    $destPath = $uploadDir.'/'.$newName;

    if (!move_uploaded_file($_FILES['slip']['tmp_name'], $destPath)) {
      $_SESSION['flash'] = 'อัปโหลดสลิปล้มเหลว กรุณาลองใหม่อีกครั้ง';
      header('Location: service_pay.php?id='.$id.($postCoupon?('&apply='.urlencode($postCoupon)):'')); 
      exit;
    }

    $slipPath = 'uploads/slips/'.$newName;
  }

  if ($hasPayStatus) {

    // update dynamic ตามคอลัมน์ที่มีจริง
    $sets   = ["payment_status='pending'"];
    $types  = '';
    $params = [];

    if ($hasPayMethod) {
      $sets[]  = "pay_method=?";
      $types  .= 's';
      $params[] = $method; // 'bank' หรือ 'cash'
    }
    if ($hasSlipCol && $slipPath !== null) {
      $sets[]  = "payment_slip=?";
      $types  .= 's';
      $params[] = $slipPath;
    }

    $sql = "UPDATE service_tickets SET ".implode(',', $sets)." WHERE id=? AND user_id=?";
    $types .= 'ii';
    $params[] = $id;
    $params[] = $uid;

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $st->close();

    if ($postCoupon !== '') {
      $_SESSION['service_coupon'] = $_SESSION['service_coupon'] ?? [];
      $_SESSION['service_coupon'][$id] = $postCoupon;
    }

    if ($method === 'cash') {
      $_SESSION['flash'] = 'บันทึกการขอชำระเงินสดหน้าร้านแล้ว กรุณาชำระเมื่อมารับเครื่อง';
    } else {
      $_SESSION['flash'] = 'ส่งข้อมูลการชำระเงินแล้ว รอร้านตรวจสอบยืนยัน';
    }

  } else {
    $_SESSION['flash'] = 'ระบบยังไม่รองรับการบันทึกการชำระเงิน กรุณาติดต่อร้านค้า';
  }

  header('Location: service_my_detail.php?type=repair&id='.$id);
  exit;
}

/* path รูป QR ของร้าน (ให้ร้านเอาไฟล์ไปวางเอง) */
$qrRelPath = 'assets/img/qr_bank.jpg';
$qrExists  = is_file(__DIR__.'/'.$qrRelPath);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ชำระค่าบริการซ่อม ST-<?= (int)$ticket['id'] ?> | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
  <style>
    .coupon-pill{display:inline-flex;align-items:center;gap:.5rem;border:1px dashed #cdd6e1;border-radius:999px;padding:.35rem .7rem;background:#fff;}
    .coupon-pill .code{font-weight:800}
    .coupon-pill .val{font-size:.9rem;color:#475569}
  </style>
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/header.php'; ?>

<main class="py-4">
  <div class="container">
    <a href="service_my_detail.php?type=repair&id=<?= (int)$ticket['id'] ?>" class="btn btn-outline-secondary mb-3">
      <i class="bi bi-arrow-left"></i> กลับไปหน้าใบงานซ่อม
    </a>

    <div class="row justify-content-center">
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-header">
            <i class="bi bi-credit-card me-1"></i> ชำระค่าบริการซ่อม
            <span class="badge text-bg-dark">ST-<?= (int)$ticket['id'] ?></span>
          </div>
          <div class="card-body">
            <p class="mb-1 text-muted">รายละเอียดเครื่อง</p>
            <p class="fw-semibold mb-2">
              <?= h($ticket['device_type']) ?> / <?= h($ticket['brand']) ?> / <?= h($ticket['model']) ?>
            </p>

            <div class="mb-2">
              <div class="text-muted">ยอดเต็ม</div>
              <div class="fs-5 fw-bold"><?= baht($servicePrice) ?> ฿</div>
            </div>

            <!-- คูปองบริการ -->
            <div class="card mb-3">
              <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="bi bi-ticket-perforated me-1"></i> คูปองสำหรับค่าบริการ</span>
                <span class="small text-muted">กดเพื่อใช้ หรือกรอกโค้ด</span>
              </div>
              <div class="card-body">
                <?php if(empty($userCoupons)): ?>
                  <div class="text-muted">ยังไม่มีคูปองบริการที่ใช้งานได้</div>
                <?php else: ?>
                  <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach($userCoupons as $c):
                      $cap = ($c['type']==='percent' ? (float)$c['value'].'%' : baht($c['value']).' ฿');
                      $limit = (int)$c['uses_limit'];
                      $per   = (int)$c['per_user_limit'];
                      $usedT = (int)$c['used_total'];
                      $usedM = (int)$c['used_by_me'];
                      $leftT = ($limit>0) ? max(0,$limit-$usedT) : PHP_INT_MAX;
                      $leftM = ($per>0)   ? max(0,$per-$usedM)   : PHP_INT_MAX;
                      if ($leftT===0 || $leftM===0) continue;
                    ?>
                      <a class="coupon-pill text-decoration-none"
                         href="service_pay.php?id=<?= (int)$id ?>&apply=<?= urlencode($c['code']) ?>">
                        <span class="code"><?= h($c['code']) ?></span>
                        <span class="val">ลด <?= h($cap) ?></span>
                        <?php if ((float)$c['min_order_total']>0): ?>
                          <span class="val">ขั้นต่ำ <?= baht($c['min_order_total']) ?> ฿</span>
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <form method="get" class="input-group">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <span class="input-group-text"><i class="bi bi-ticket"></i></span>
                  <input type="text" class="form-control" name="apply" placeholder="กรอกโค้ดคูปอง" value="<?= h($applyCode) ?>">
                  <button class="btn btn-outline-primary" type="submit">ใช้คูปอง</button>
                </form>
                <?php if ($applyCode!==''): ?>
                  <div class="small mt-2 <?= $cp['ok']?'text-success':'text-danger' ?>">
                    <?= h($cp['ok'] ? ('ใช้คูปองแล้ว: -'.baht($initial_discount).' ฿') : ('⚠ '.$cp['msg'])) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="mb-3">
              <div class="text-muted">ยอดสุทธิที่ต้องชำระ</div>
              <div class="fs-3 fw-bold text-primary"><?= baht($finalPay) ?> ฿</div>
            </div>

            <div class="alert alert-info">
              <div class="fw-semibold mb-1">
                <i class="bi bi-bank me-1"></i> ช่องทางชำระเงิน
              </div>
              <ul class="mb-2">
                <li>โอนผ่านธนาคาร/พร้อมเพย์ ตามเลขบัญชีด้านล่าง หรือสแกน QR</li>
                <li>หรือ ชำระเงินสดที่หน้าร้าน เมื่อมารับเครื่อง</li>
              </ul>
              <div>ธนาคารตัวอย่าง: 145-3-21854-22</div>
              <div>ชื่อบัญชี: นายวีรยุทธ ศิริวัฒนานุกูล</div>

              <?php if($qrExists): ?>
                <div class="text-center mt-3">
                  <img src="<?= h($qrRelPath) ?>" class="img-fluid rounded" style="max-width:260px" alt="QR ชำระเงิน">
                  <div class="small text-muted mt-1">
                    * สแกน QR นี้เมื่อเลือกช่องทางโอนผ่านธนาคาร
                  </div>
                </div>
              <?php endif; ?>

            </div>

            <form method="post" enctype="multipart/form-data" class="mt-3">
              <input type="hidden" name="coupon_code" value="<?= h($cp['ok'] ? $applyCode : '') ?>">
              <input type="hidden" name="client_subtotal" value="<?= number_format($servicePrice,2,'.','') ?>">
              <input type="hidden" name="client_discount" value="<?= number_format($initial_discount,2,'.','') ?>">
              <input type="hidden" name="client_grand"    value="<?= number_format($finalPay,2,'.','') ?>">

              <div class="mb-3">
                <label class="form-label">เลือกช่องทางชำระเงิน</label>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="pay_method" id="pm_bank" value="bank" checked>
                  <label class="form-check-label" for="pm_bank">
                    โอนผ่านธนาคาร / พร้อมเพย์ (อัปโหลดสลิป)
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="pay_method" id="pm_cash" value="cash">
                  <label class="form-check-label" for="pm_cash">
                    ชำระเงินสดที่หน้าร้าน (ไม่ต้องอัปโหลดสลิป)
                  </label>
                </div>
              </div>

              <div class="mb-3" id="slipGroup">
                <label class="form-label">อัปโหลดสลิปโอนเงิน</label>
                <input type="file" name="slip" id="slipInput" class="form-control" accept="image/*" required>
                <div class="form-text">รองรับไฟล์รูป เช่น .jpg, .png (ต้องอัปโหลดเมื่อเลือกโอนผ่านธนาคาร)</div>
              </div>

              <button class="btn btn-primary w-100">
                <i class="bi bi-send-check me-1"></i> ยืนยันการชำระเงิน
              </button>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const bank = document.getElementById('pm_bank');
  const cash = document.getElementById('pm_cash');
  const slipGroup = document.getElementById('slipGroup');
  const slipInput = document.getElementById('slipInput');

  function updateSlip() {
    const isBank = bank && bank.checked;
    if (slipGroup) slipGroup.style.display = isBank ? '' : 'none';
    if (slipInput) {
      if (isBank) {
        slipInput.setAttribute('required','required');
      } else {
        slipInput.removeAttribute('required');
        slipInput.value = '';
      }
    }
  }

  bank && bank.addEventListener('change', updateSlip);
  cash && cash.addEventListener('change', updateSlip);
  updateSlip();
});
</script>

</body>
</html>
