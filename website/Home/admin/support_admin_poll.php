<?php
// Home/admin/support_admin_poll.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') { http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }
$uid   = (int)($_GET['uid'] ?? 0);
$since = (int)($_GET['since'] ?? 0);

$st = $conn->prepare("SELECT id,sender,message,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS time FROM support_messages WHERE user_id=? AND id>? ORDER BY id ASC");
$st->bind_param("ii",$uid,$since); $st->execute();
$list = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

// มาร์คว่าแอดมินอ่านแล้ว
$conn->prepare("UPDATE support_messages SET is_read_by_admin=1 WHERE user_id={$uid} AND sender='user' AND is_read_by_admin=0")->execute();

echo json_encode(['items'=>$list]);
