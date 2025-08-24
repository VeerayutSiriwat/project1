<?php
// Home/admin/support_threads_api.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') {
  http_response_code(401); echo json_encode(['error'=>'unauth']); exit;
}

$single = (int)($_GET['single'] ?? 0);
if ($single > 0) {
  // คืนชื่อผู้ใช้ให้หัวห้อง
  $st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
  $st->bind_param("i",$single); $st->execute();
  $u = $st->get_result()->fetch_assoc(); $st->close();
  echo json_encode(['username'=>$u['username'] ?? null]); exit;
}

$sql = "
  SELECT 
    u.id,
    u.username,
    (SELECT m1.message FROM support_messages m1 WHERE m1.user_id=u.id ORDER BY m1.id DESC LIMIT 1) AS last_message,
    (SELECT DATE_FORMAT(m2.created_at,'%Y-%m-%d %H:%i') FROM support_messages m2 WHERE m2.user_id=u.id ORDER BY m2.id DESC LIMIT 1) AS last_time,
    SUM(CASE WHEN m.sender='user' AND m.is_read_by_admin=0 THEN 1 ELSE 0 END) AS unread,
    COUNT(m.id) AS total_msgs
  FROM users u
  LEFT JOIN support_messages m ON m.user_id=u.id
  WHERE u.role='user'
  GROUP BY u.id
  HAVING total_msgs > 0
  ORDER BY unread DESC, last_time DESC
  LIMIT 50
";
$items = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
echo json_encode(['items'=>$items]);
