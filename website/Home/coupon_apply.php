<?php
// Home/coupon_apply.php
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/includes/db.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if($uid<=0){ header('Location: login.php?redirect=checkout.php'); exit; }

if (isset($_GET['remove'])) {
  unset($_SESSION['applied_coupon']);
  $_SESSION['flash']='ลบคูปองแล้ว';
  header('Location: checkout.php'); exit;
}

$code = strtoupper(trim($_POST['coupon_code'] ?? ''));
if ($code===''){ $_SESSION['flash']='กรุณากรอกรหัสคูปอง'; header('Location: checkout.php'); exit; }

/* สมมุติว่ามีฟังก์ชัน/เซสชันคำนวณยอดตะกร้า */
$total = (float)($_SESSION['cart_total'] ?? 0);

/* ดึงคูปอง (คูปองส่วนตัว: user_id ตรง หรือ คูปองสาธารณะ: user_id IS NULL) */
if ($st=$conn->prepare("SELECT * FROM coupons WHERE code=? AND (user_id IS NULL OR user_id=?) LIMIT 1")){
  $st->bind_param('si',$code,$uid); $st->execute();
  $cp = $st->get_result()->fetch_assoc(); $st->close();
}
if(!$cp){ $_SESSION['flash']='ไม่พบคูปอง (หรือไม่ใช่ของบัญชีคุณ)'; header('Location: checkout.php'); exit; }

$now = date('Y-m-d H:i:s');
if($cp['expiry_date'] && $cp['expiry_date'] < $now){
  $_SESSION['flash']='คูปองหมดอายุแล้ว'; header('Location: checkout.php'); exit;
}
if((int)$cp['usage_limit']>0 && (int)$cp['used_count']>=(int)$cp['usage_limit']){
  $_SESSION['flash']='คูปองนี้ถูกใช้ครบจำนวนแล้ว'; header('Location: checkout.php'); exit;
}
if($total < (float)$cp['min_order']){
  $_SESSION['flash']='ยอดสั่งซื้อยังไม่ถึงขั้นต่ำของคูปอง'; header('Location: checkout.php'); exit;
}

/* บันทึกคูปองลง Session; ส่วนลดจริงไปคำนวณตอน confirm order */
$_SESSION['applied_coupon'] = [
  'code'=>$cp['code'],
  'type'=>$cp['type'],
  'value'=>$cp['value'],
  'tradein_id'=>$cp['tradein_id'] ?? null
];
$_SESSION['flash']='ใช้คูปองแล้ว';

header('Location: checkout.php');
