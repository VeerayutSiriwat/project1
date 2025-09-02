<?php
// Home/tradein_create.php
if (session_status()===PHP_SESSION_NONE) { session_start(); }
require __DIR__.'/includes/db.php';
require __DIR__.'/includes/image_helpers.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service.php'); exit; }
$uid = (int)$_SESSION['user_id'];

function val($k,$d=''){ return trim($_POST[$k] ?? $d); }

$device_type = val('device_type');
$brand       = val('brand');
$model       = val('model');
$condition   = val('device_condition','working');  // ตาม enum
$need        = val('need','buy_new');
$offer_price = strlen(val('offer_price')) ? (float)val('offer_price') : null; // ลูกค้าพิมพ์ได้ แต่แอดมินแก้ทีหลัง
$sel_pid     = strlen(val('selected_product_id')) ? (int)val('selected_product_id') : null;

$ok = false;
if ($device_type!=='' && $brand!=='' && $model!=='') {
  $st = $conn->prepare("
    INSERT INTO tradein_requests
      (user_id, device_type, brand, model, device_condition, need, offer_price, selected_product_id, status)
    VALUES (?,?,?,?,?,?,?,?, 'submitted')
  ");
  $st->bind_param('isssssdi', $uid, $device_type, $brand, $model, $condition, $need, $offer_price, $sel_pid);
  $ok = $st->execute();
  $rid = $st->insert_id; $st->close();

  if ($ok) {
    // แนบหลายรูป (ถ้ามี)
    save_tradein_images($_FILES['images'] ?? null, __DIR__.'/assets/img', $conn, $rid, 10);
    header('Location: service_my_detail.php?type=tradein&id='.$rid);
    exit;
  }
}

$_SESSION['flash_error'] = 'บันทึกคำขอไม่ได้ กรุณาลองใหม่';
header('Location: service.php#tradein');
