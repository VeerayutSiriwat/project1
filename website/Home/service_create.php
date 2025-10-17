<?php
// Home/service_create.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service.php'); exit; }

function clean($s){ return trim((string)($s??'')); }

/* ===== helpers เหมือน place_order ===== */
function notify_admins(mysqli $conn, string $type, int $refId, string $title, string $message): void {
  if ($res = $conn->query("SELECT id FROM users WHERE role='admin'")) {
    $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
    while ($row = $res->fetch_assoc()) { $uid=(int)$row['id']; $st->bind_param("isiss",$uid,$type,$refId,$title,$message); $st->execute(); }
    $st->close();
  }
}
function notify_user(mysqli $conn, int $userId, string $type, int $refId, string $title, string $message): void {
  $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
  $st->bind_param("isiss", $userId, $type, $refId, $title, $message); $st->execute(); $st->close();
}
function display_user_name(mysqli $conn, int $userId): string {
  $name = '';
  if ($st = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(full_name),''), username) AS dn FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param("i",$userId); $st->execute(); $name = $st->get_result()->fetch_assoc()['dn'] ?? ''; $st->close();
  }
  return $name!=='' ? $name : "UID {$userId}";
}

/* ===== รับค่า ===== */
$user_id     = (int)$_SESSION['user_id'];
$device_type = clean($_POST['device_type'] ?? '');
$brand       = clean($_POST['brand'] ?? '');
$model       = clean($_POST['model'] ?? '');
$phone       = clean($_POST['phone'] ?? '');
$line_id     = clean($_POST['line_id'] ?? '');
$desired     = clean($_POST['desired_date'] ?? '');         // DATE (Y-m-d) หรือว่าง
$urgency     = strtolower(clean($_POST['urgency'] ?? 'normal'))==='urgent' ? 'urgent':'normal';
$issue       = clean($_POST['issue'] ?? '');

if($device_type==='' || $brand==='' || $model==='' || $phone==='' || $issue===''){
  $_SESSION['flash_err']='กรอกข้อมูลให้ครบถ้วน'; header('Location: service.php'); exit;
}

/* ===== อัปโหลดรูป (ตัวเดียว, ไม่บังคับ) ===== */
$image_path = null;
if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
  $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
  if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) $ext = 'jpg';
  $subdir = 'uploads/service/'.date('Ym');
  $dir = __DIR__.'/'.$subdir; if (!is_dir($dir)) @mkdir($dir,0775,true);
  $fname = 'svc_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
  if (move_uploaded_file($_FILES['image']['tmp_name'], $dir.'/'.$fname)) {
    $image_path = $subdir.'/'.$fname; // เก็บเป็น relative
  }
}

/* ===== ตัวเลือกจากฟอร์มให้ตรง schema ===== */
$parts_grade = clean($_POST['parts_grade'] ?? 'standard');
if (!in_array($parts_grade,['used','standard','premium'],true)) $parts_grade='standard';

$parts_grade_surcharge = round((float)($_POST['parts_grade_surcharge'] ?? $_POST['parts_surcharge'] ?? 0),2);
$ext_warranty_months   = (int)($_POST['ext_warranty_months'] ?? $_POST['warranty_months'] ?? 0);
if (!in_array($ext_warranty_months,[0,3,6,12],true)) $ext_warranty_months=0;
$ext_warranty_price    = round((float)($_POST['ext_warranty_price'] ?? $_POST['warranty_price'] ?? 0),2);

$estimate_total        = round((float)($_POST['estimate_total'] ?? 0),2);
$urgent_fee            = round((float)($_POST['urgent_fee'] ?? 0),2); // ใช้ทำข้อความแจ้งเตือนเท่านั้น

/* ===== INSERT ให้ตรงตารางในรูป (status เริ่ม 'queued') ===== */
$conn->begin_transaction();
try {
  $sql = "
    INSERT INTO service_tickets
      (user_id, device_type, brand, model, urgency, issue, line_id, phone, desired_date,
       scheduled_at, schedule_status, image_path, status,
       parts_grade, parts_grade_surcharge, ext_warranty_months, ext_warranty_price,
       base_warranty_months, estimate_total, created_at, updated_at)
    VALUES
      (?,?,?,?,?,?,?,?,?,
       NULL,'none',?,'queued',
       ?,?,?,?,
       1,?, NOW(), NOW())
  ";
  $st = $conn->prepare($sql);
  $st->bind_param(
    'issssssssssdidd',
    $user_id,$device_type,$brand,$model,$urgency,$issue,$line_id,$phone,$desired,
    $image_path,
    $parts_grade,$parts_grade_surcharge,$ext_warranty_months,$ext_warranty_price,
    $estimate_total
  );
  $st->execute();
  $ticket_id = (int)$st->insert_id;
  $st->close();

  // log แรก (queued)
  if ($st2 = $conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")) {
    $note = 'ส่งคำขอซ่อมเข้าคิวแล้ว (ความเร่งด่วน: '.($urgency==='urgent'?'ด่วน':'ปกติ').')';
    $firstStatus = 'queued';
    $st2->bind_param("iss",$ticket_id,$firstStatus,$note);
    $st2->execute(); $st2->close();
  }

  $conn->commit();

  /* ===== แจ้งเตือนเหมือน place_order ===== */
  $displayUser = display_user_name($conn,$user_id);
  $bits = [];
  if ($urgency==='urgent') $bits[]='งานด่วน';
  if ($parts_grade_surcharge>0) $bits[]='อะไหล่ +'.number_format($parts_grade_surcharge,2).'฿';
  if ($ext_warranty_price>0)   $bits[]='ประกันเพิ่ม +'.number_format($ext_warranty_price,2).'฿';
  if ($urgent_fee>0)           $bits[]='คิวด่วน +'.number_format($urgent_fee,2).'฿';
  $addonTxt = $bits ? (' • '.implode(' • ',$bits)) : '';

  notify_admins($conn,'new_repair',$ticket_id,'ใบงานซ่อมใหม่',"ST-{$ticket_id} จาก {$displayUser}{$addonTxt}");
  $tail = $desired!=='' ? " • วันที่ที่ต้องการ: {$desired}" : '';
  notify_user($conn,$user_id,'service_status',$ticket_id,'ส่งคำขอซ่อมแล้ว',"ใบงาน ST-{$ticket_id} เข้าคิวตรวจสอบ{$tail}");

  // ไปหน้ารายละเอียดของลูกค้า
  $_SESSION['flash_ok'] = "ส่งคำขอซ่อมเรียบร้อย! หมายเลขใบงาน: ST-{$ticket_id}";
  header('Location: service_my_detail.php?type=repair&id='.$ticket_id);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_err']='บันทึกคำขอไม่สำเร็จ'; header('Location: service.php'); exit;
}
