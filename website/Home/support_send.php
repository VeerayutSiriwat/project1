<?php
// Home/support_send.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }

function notify_admins(mysqli $conn, string $type, int $refId, string $title, string $message): void {
  if ($res = $conn->query("SELECT id FROM users WHERE role='admin'")) {
    $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
    while ($row = $res->fetch_assoc()) {
      $adminId = (int)$row['id'];
      $st->bind_param("isiss", $adminId, $type, $refId, $title, $message);
      $st->execute();
    }
    $st->close();
  }
}

$user_id = (int)$_SESSION['user_id'];
$msg = trim($_POST['message'] ?? '');
if ($msg===''){ echo json_encode(['ok'=>false]); exit; }

$st = $conn->prepare("INSERT INTO support_messages (user_id,sender,message,is_read_by_admin) VALUES (?,?,?,0)");
$sender='user';
$st->bind_param("iss",$user_id,$sender,$msg);
$st->execute();
$insertId = $conn->insert_id;
$st->close();

notify_admins($conn,'support_msg',$insertId,'มีข้อความใหม่จากลูกค้า',"UID {$user_id}: ".$msg);

echo json_encode(['ok'=>true,'id'=>$insertId]);
