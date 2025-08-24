<?php
// Home/support_poll.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }

$user_id = (int)$_SESSION['user_id'];
$since   = (int)($_GET['since'] ?? 0);

$st = $conn->prepare("
  SELECT id,sender,message,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS time
  FROM support_messages
  WHERE user_id=? AND id>?
  ORDER BY id ASC
");
$st->bind_param("ii",$user_id,$since);
$st->execute();
$rs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// มาร์คว่า user อ่านแล้ว (แอดมินฝั่งตรงข้าม)
$conn->prepare("UPDATE support_messages SET is_read_by_user=1 WHERE user_id={$user_id} AND sender='admin' AND is_read_by_user=0")->execute();

echo json_encode(['items'=>$rs]);
