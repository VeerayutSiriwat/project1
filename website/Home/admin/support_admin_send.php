<?php
// Home/admin/support_admin_send.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') { http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }
$admin_id = (int)$_SESSION['user_id'];
$uid = (int)($_POST['uid'] ?? 0);
$msg = trim($_POST['message'] ?? '');
if ($uid<=0 || $msg===''){ echo json_encode(['ok'=>false]); exit; }

$st = $conn->prepare("INSERT INTO support_messages (user_id,sender,admin_id,message,is_read_by_user) VALUES (?,'admin',?,?,0)");
$st->bind_param("iis",$uid,$admin_id,$msg);
$st->execute(); $id = $conn->insert_id; $st->close();

// mark admin read user's messages
$conn->prepare("UPDATE support_messages SET is_read_by_admin=1 WHERE user_id={$uid} AND sender='user' AND is_read_by_admin=0")->execute();

// แจ้งเตือนลูกค้า
$nt = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
$type='support_msg'; $title='แอดมินตอบกลับข้อความ'; $preview=mb_substr($msg,0,120);
$nt->bind_param("isiss",$uid,$type,$id,$title,$preview);
$nt->execute(); $nt->close();

echo json_encode(['ok'=>true,'id'=>$id]);


