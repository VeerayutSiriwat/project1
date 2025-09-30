<?php
// Home/service_create.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: login.php?redirect=service.php'); exit;
}

function clean($s){ return trim($s??''); }

$user_id     = (int)$_SESSION['user_id'];
$device_type = clean($_POST['device_type'] ?? '');
$brand       = clean($_POST['brand'] ?? '');
$model       = clean($_POST['model'] ?? '');
$phone       = clean($_POST['phone'] ?? '');
$line_id     = clean($_POST['line_id'] ?? '');
$desired     = clean($_POST['desired_date'] ?? '');
$urgency     = strtolower(clean($_POST['urgency'] ?? 'normal')); // normalize
$issue       = trim($_POST['issue'] ?? '');

// จำกัดค่า urgency ให้เหลือ normal/urgent เท่านั้น
if (!in_array($urgency, ['normal','urgent'], true)) { $urgency = 'normal'; }

if($device_type==='' || $brand==='' || $model==='' || $phone==='' || $issue===''){
  $_SESSION['flash_err']='กรอกข้อมูลให้ครบถ้วน'; header('Location: service.php'); exit;
}

/* ---- upload image (optional) ---- */
$image_path = null;
if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
  $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','gif'])) $ext='jpg';
  $dir = __DIR__ . '/uploads/service/'.date('Ym');
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $fname = 'svc_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
  $full  = $dir.'/'.$fname;
  if (move_uploaded_file($_FILES['image']['tmp_name'], $full)) {
    $image_path = 'uploads/service/'.date('Ym').'/'.$fname; // relative
  }
}

/* --------- ตัวเลือกเพิ่มเติมจากฟอร์ม --------- */
// เกรดอะไหล่: used | standard | premium
$parts_grade = trim($_POST['parts_grade'] ?? 'standard');
if (!in_array($parts_grade, ['used','standard','premium'], true)) {
  $parts_grade = 'standard';
}
// ค่าบวกตามเกรด
$parts_grade_surcharge = round((float)($_POST['parts_grade_surcharge'] ?? $_POST['parts_surcharge'] ?? 0), 2);

// ประกันเพิ่ม (ร้านมี 1 เดือนให้อยู่แล้ว)
$ext_warranty_months = (int)($_POST['ext_warranty_months'] ?? $_POST['warranty_months'] ?? 0);
if (!in_array($ext_warranty_months, [0,3,6,12], true)) {
  $ext_warranty_months = 0;
}
$ext_warranty_price = round((float)($_POST['ext_warranty_price'] ?? $_POST['warranty_price'] ?? 0), 2);

// ราคาประเมินรวม (ถ้ามี)
$estimate_total = round((float)($_POST['estimate_total'] ?? 0), 2);

/* --------- INSERT (15 ตัวแปร = 15 ? ) --------- */
$sql = "
  INSERT INTO service_tickets
  (device_type, brand, model, phone, line_id, issue, image_path, desired_date,
   status, urgency, user_id, created_at, updated_at,
   parts_grade, parts_grade_surcharge, ext_warranty_months, ext_warranty_price,
   base_warranty_months, estimate_total)
  VALUES (?,?,?,?,?,?,?,?,
          'queue', ?, ?, NOW(), NOW(),
          ?, ?, ?, ?, 1, ?)
";
$st = $conn->prepare($sql);
if(!$st){
  $_SESSION['flash_err']='คำสั่งผิดพลาด: '.$conn->error; header('Location: service.php'); exit;
}

/*
ชนิดข้อมูลตามลำดับตัวแปร:
  s s s s s s s s  s  i  s  d  i  d  d
  └──────── 8 s ───────┘  └ urgency ┘  └ user_id ┘  └ parts_grade ┘ └ surcharge ┘ ...
รวมเป็น 'sssssssssisdidd'
*/
$st->bind_param(
  'sssssssssisdidd',
  $device_type, $brand, $model, $phone, $line_id, $issue, $image_path, $desired,
  $urgency, $user_id,
  $parts_grade, $parts_grade_surcharge, $ext_warranty_months, $ext_warranty_price,
  $estimate_total
);

$ok = $st->execute();
$ticket_id = $ok ? (int)$conn->insert_id : 0;
$st->close();

if(!$ok){
  $_SESSION['flash_err']='บันทึกคำขอไม่สำเร็จ'; header('Location: service.php'); exit;
}

/* ---- create status log (first log) ---- */
if ($ticket_id>0){
  if ($st=$conn->prepare("INSERT INTO service_status_logs (ticket_id, status, note, created_at) VALUES (?,?,?,NOW())")){
    $note = 'ส่งคำขอซ่อมเข้าคิวแล้ว (ความเร่งด่วน: '.($urgency==='urgent'?'ด่วน':'ปกติ').')';
    $status='queue';
    $st->bind_param("iss",$ticket_id,$status,$note);
    $st->execute(); $st->close();
  }
}

/* ---- redirect ไปหน้า detail ---- */
$_SESSION['flash_ok'] = "ส่งคำขอซ่อมเรียบร้อย! หมายเลขใบงาน: ST-{$ticket_id}";
header('Location: service_my_detail.php?type=repair&id='.$ticket_id);
exit;
