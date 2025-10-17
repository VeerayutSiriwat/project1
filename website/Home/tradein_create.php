<?php
// Home/tradein_create.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=service.php'); exit; }

function v($k,$d=''){ return trim((string)($_POST[$k] ?? $d)); }

/* ===== helpers เหมือน place_order ===== */
function notify_admins(mysqli $conn,string $type,int $refId,string $title,string $msg):void{
  if($r=$conn->query("SELECT id FROM users WHERE role='admin'")){
    $st=$conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
    while($u=$r->fetch_assoc()){ $uid=(int)$u['id']; $st->bind_param("isiss",$uid,$type,$refId,$title,$msg); $st->execute(); }
    $st->close();
  }
}
function notify_user(mysqli $conn,int $uid,string $type,int $refId,string $title,string $msg):void{
  $st=$conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
  $st->bind_param("isiss",$uid,$type,$refId,$title,$msg); $st->execute(); $st->close();
}
function display_user_name(mysqli $conn,int $uid):string{
  $n=''; if($st=$conn->prepare("SELECT COALESCE(NULLIF(TRIM(full_name),''),username) AS dn FROM users WHERE id=? LIMIT 1")){
    $st->bind_param("i",$uid); $st->execute(); $n=$st->get_result()->fetch_assoc()['dn']??''; $st->close();
  } return $n!==''?$n:"UID {$uid}";
}

/* ===== รับค่า ===== */
$uid         = (int)$_SESSION['user_id'];
$device_type = v('device_type');
$brand       = v('brand');
$model       = v('model');
$condition   = v('device_condition','working'); // UI อาจส่ง working | minor_issue | broken
// map ให้ตรง enum จริง: working | no_power | broken_part
if (!in_array($condition,['working','no_power','broken_part'],true)) {
  if ($condition==='minor_issue') $condition='broken_part';
  elseif ($condition==='broken')  $condition='no_power';
  else $condition='working';
}
$need        = v('need','buy_new');            // UI อาจส่ง buy_new | cash
if (!in_array($need,['buy_new','discount'],true)) $need = ($need==='cash'?'discount':'buy_new');

$offer_price = strlen(v('offer_price')) ? (float)v('offer_price') : 0.0;
$sel_pid     = strlen(v('selected_product_id')) ? (int)v('selected_product_id') : null;

/* ===== อัปโหลดหลายรูป (เก็บรูปแรกใส่ image_path) ===== */
$first_image_rel = null;
if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
  $subdir = 'uploads/tradein/'.date('Ym');
  $dir = __DIR__.'/'.$subdir; if(!is_dir($dir)) @mkdir($dir,0775,true);

  $count = count($_FILES['images']['name']);
  $kept = 0;
  for ($i=0; $i<$count && $kept<12; $i++) {
    if (!is_uploaded_file($_FILES['images']['tmp_name'][$i])) continue;
    $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) $ext='jpg';
    $fname = 'ti_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'_'.($i+1).'.'.$ext;
    if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dir.'/'.$fname)) {
      if (!$first_image_rel) $first_image_rel = $subdir.'/'.$fname;
      // ถ้ามีตาราง tradein_images ค่อย INSERT ทีหลัง; ตอนนี้เก็บไฟล์ไว้ก่อน
      $kept++;
    }
  }
}

/* ===== Validate ===== */
if ($device_type==='' || $brand==='' || $model==='') {
  $_SESSION['flash_error']='กรอกข้อมูลไม่ครบ'; header('Location: service.php#tradein'); exit;
}

/* ===== INSERT ให้ตรงตารางในรูป (status เริ่ม 'submitted') ===== */
$conn->begin_transaction();
try{
  $st=$conn->prepare("
    INSERT INTO tradein_requests
      (user_id,device_type,brand,model,device_condition,need,image_path,offer_price,selected_product_id,
       status,created_at,updated_at,scheduled_at,schedule_status)
    VALUES (?,?,?,?,?,?,?,?,?,'submitted',NOW(),NOW(),NULL,'none')
  ");
  // selected_product_id อนุญาต NULL
  if ($sel_pid===null) { $null = NULL; $sel_pid = $null; }
  $st->bind_param("issssssdi",
    $uid,$device_type,$brand,$model,$condition,$need,$first_image_rel,$offer_price,$sel_pid
  );
  $st->execute();
  $rid = (int)$st->insert_id;
  $st->close();

  // log แรก (submitted)
  if ($lg=$conn->prepare("INSERT INTO tradein_status_logs (request_id,status,note,created_at) VALUES (?,?,?,NOW())")){
    $s='submitted'; $n='ส่งคำขอเทิร์นใหม่';
    $lg->bind_param("iss",$rid,$s,$n); $lg->execute(); $lg->close();
  }

  $conn->commit();

  /* ===== Notify ===== */
  $displayUser = display_user_name($conn,$uid);
  $needTxt = ($need==='discount') ? 'ขายรับเงินสด' : 'เทิร์นซื้อใหม่';
  $bits = [];
  if ($condition!=='') $bits[] = "สภาพ: {$condition}";
  if ($offer_price>0)  $bits[] = 'เสนอราคา ~'.number_format($offer_price,2).'฿';
  $extra = $bits ? (' • '.implode(' • ',$bits)) : '';

  $prodTxt='';
  if (!empty($sel_pid) && ($p=$conn->prepare("SELECT name FROM products WHERE id=? LIMIT 1"))){
    $p->bind_param("i",$sel_pid); $p->execute();
    if($r=$p->get_result()->fetch_assoc()) $prodTxt=" • สินค้าที่เล็ง: ".$r['name'];
    $p->close();
  }

  notify_admins($conn,'new_tradein',$rid,'คำขอเทิร์นใหม่',"TI-{$rid} จาก {$displayUser} • {$needTxt}{$extra}{$prodTxt}");
  notify_user($conn,$uid,'tradein_status',$rid,'ส่งคำขอเทิร์นแล้ว',"คำขอ TI-{$rid} อยู่ระหว่างการประเมิน • {$needTxt}");

  header('Location: service_my_detail.php?type=tradein&id='.$rid);
  exit;

}catch(Throwable $e){
  $conn->rollback();
  $_SESSION['flash_error']='บันทึกคำขอไม่ได้ กรุณาลองใหม่'; header('Location: service.php#tradein'); exit;
}
