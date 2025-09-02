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
$urgency     = clean($_POST['urgency'] ?? 'normal');
$issue       = trim($_POST['issue'] ?? '');

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
    // เก็บ relative path เพื่อไปแสดงผล
    $image_path = 'uploads/service/'.date('Ym').'/'.$fname;
  }
}

/* ---- insert ticket ---- */
/* หมายเหตุ: จากสครีนดูเหมือนมีคอลัมน์เหล่านี้ใน service_tickets:
   id (PK), device_type, brand, model, phone, line_id, issue (text),
   image_path (varchar), desired_date (date), status (enum ...), urgency (enum ...),
   created_at, updated_at, user_id (น่าจะมี ถ้าไม่มี DB จะเมินค่า bind ไว้ได้)
*/
$sql = "
  INSERT INTO service_tickets
  (device_type, brand, model, phone, line_id, issue, image_path, desired_date, status, urgency, user_id, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?, 'queue', ?, ?, NOW(), NOW())
";
$st = $conn->prepare($sql);
$st->bind_param(
  "ssssssssis",
  $device_type, $brand, $model, $phone, $line_id, $issue, $image_path, $desired, $urgency, $user_id
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
    $note = 'ส่งคำขอซ่อมเข้าคิวแล้ว';
    $status='queue';
    $st->bind_param("iss",$ticket_id,$status,$note);
    $st->execute(); $st->close();
  }
}

/* สร้างรหัสแสดงผลใบงาน (สวยขึ้น) เช่น ST-1001 */
$pretty = 'ST-'.$ticket_id;
$_SESSION['flash_ok'] = "ส่งคำขอซ่อมเรียบร้อย! หมายเลขใบงาน: {$pretty}";
header('Location: service_track.php?ticket='.$pretty.'&phone='.urlencode($phone));
exit;
