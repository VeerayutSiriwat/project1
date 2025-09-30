<?php
// Home/admin/coupon_save.php
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  header('Location: ../login.php'); exit;
}

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
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

/* ---- รับค่า ---- */
$id   = (int)($_POST['id']   ?? 0);
$code = strtoupper(trim($_POST['code'] ?? ''));

$type  = ($_POST['type'] ?? 'fixed');            // fixed | percent
$value = (float)($_POST['value'] ?? 0);

$ends_at = trim($_POST['ends_at'] ?? '');        // datetime-local
$starts_at = trim($_POST['starts_at'] ?? '');    // เผื่ออนาคต (ฟอร์มยังไม่โชว์)

$min_order_total = (float)($_POST['min_order_total'] ?? 0);
$uses_limit      = (int)  ($_POST['uses_limit']      ?? 0);
$per_user_limit  = (int)  ($_POST['per_user_limit']  ?? 0);

$applies_to = trim($_POST['applies_to'] ?? 'all'); // all|products|services|tradein (มีในสคีนคุณ)
$note       = trim($_POST['note'] ?? '');
$status     = trim($_POST['status'] ?? 'active');  // active|inactive
$allow_stack = isset($_POST['allow_stack_with_discount_price'])
  ? (int)$_POST['allow_stack_with_discount_price'] : 0;

/* ---- แปลงรูปแบบเวลา ---- */
$ends_at_sql   = ($ends_at!=='')   ? date('Y-m-d H:i:s', strtotime($ends_at))   : null;
$starts_at_sql = ($starts_at!=='') ? date('Y-m-d H:i:s', strtotime($starts_at)) : null;

/* ---- สุ่มโค้ดถ้าไม่ใส่ และกันซ้ำ ---- */
if ($code==='') {
  do {
    $code = 'PROMO-'.substr(strtoupper(bin2hex(random_bytes(4))),0,8);
    $exists = $conn->query("SELECT 1 FROM coupons WHERE code='".$conn->real_escape_string($code)."' LIMIT 1")->num_rows;
  } while($exists);
} else {
  // ถ้าแก้ไข ให้ยอมซ้ำได้กับตัวเอง แต่ห้ามชนตัวอื่น
  $st = $conn->prepare("SELECT id FROM coupons WHERE code=? LIMIT 1");
  $st->bind_param('s', $code);
  $st->execute();
  $dup = $st->get_result()->fetch_assoc();
  $st->close();
  if ($dup && (int)$dup['id'] !== $id) {
    $_SESSION['flash'] = 'โค้ดคูปองซ้ำกับคูปองอื่น กรุณาเปลี่ยน';
    header('Location: coupon_form.php'.($id>0?('?id='.$id):'')); exit;
  }
}

/* ---- สร้างชุดคอลัมน์ตามสคีมาที่มีจริง ---- */
$cols = [
  'code'   => $code,
  'type'   => $type,
  'value'  => $value,
  'note'   => $note,
  'status' => $status,
];

if (has_col($conn,'coupons','min_order_total')) $cols['min_order_total'] = $min_order_total;
if (has_col($conn,'coupons','uses_limit'))      $cols['uses_limit']      = $uses_limit;
if (has_col($conn,'coupons','per_user_limit'))  $cols['per_user_limit']  = $per_user_limit;
if (has_col($conn,'coupons','applies_to'))      $cols['applies_to']      = $applies_to;
if (has_col($conn,'coupons','allow_stack_with_discount_price')) $cols['allow_stack_with_discount_price'] = $allow_stack;

if (has_col($conn,'coupons','starts_at')) $cols['starts_at'] = $starts_at_sql;          // null ได้
// รองรับ ends_at หรือ expiry_date (ของเก่า)
if (has_col($conn,'coupons','ends_at'))          $cols['ends_at']      = $ends_at_sql;
elseif (has_col($conn,'coupons','expiry_date'))  $cols['expiry_date']  = $ends_at_sql;

$now = date('Y-m-d H:i:s');
if ($id>0) { $cols['updated_at'] = $now; }
else {
  if (has_col($conn,'coupons','created_at')) $cols['created_at'] = $now;
  if (has_col($conn,'coupons','updated_at')) $cols['updated_at'] = $now;
}

/* ---- สร้าง/อัพเดต ---- */
if ($id>0) {
  // UPDATE
  $set   = [];
  $types = '';
  $vals  = [];
  foreach ($cols as $k=>$v) {
    $set[] = "`$k` = ?";
    // type binding
    if (is_int($v)) $types .= 'i';
    elseif (is_float($v)) $types .= 'd';
    else $types .= 's';
    $vals[] = $v;
  }
  $types .= 'i'; // for id
  $vals[] = $id;

  $sql = "UPDATE coupons SET ".implode(',', $set)." WHERE id=?";
  $st  = $conn->prepare($sql);
  $st->bind_param($types, ...$vals);
  $st->execute(); $st->close();

  $_SESSION['flash'] = 'อัปเดตคูปองเรียบร้อย';
} else {
  // INSERT
  $fields = array_keys($cols);
  $qs     = array_fill(0, count($fields), '?');

  $types = '';
  $vals  = [];
  foreach ($cols as $v) {
    if (is_int($v)) $types.='i';
    elseif (is_float($v)) $types.='d';
    else $types.='s';
    $vals[] = $v;
  }

  $sql = "INSERT INTO coupons (".implode(',', $fields).") VALUES (".implode(',', $qs).")";
  $st  = $conn->prepare($sql);
  $st->bind_param($types, ...$vals);
  $st->execute(); $st->close();

  $_SESSION['flash'] = 'สร้างคูปองใหม่เรียบร้อย';
}

header('Location: coupons_list.php');
