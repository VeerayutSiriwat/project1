<?php
// Home/notify_api.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}
$uid = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$limit  = max(1, min(20, (int)($_GET['limit'] ?? 10)));

if ($action === 'count') {
  $st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
  $st->bind_param('i', $uid);
  $st->execute();
  $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
  echo json_encode(['ok'=>true,'count'=>$c]); exit;
}

if ($action === 'mark_all_read') {
  $st = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
  $st->bind_param('i', $uid);
  $st->execute();
  $st->close();
  echo json_encode(['ok'=>true]); exit;
}

if ($action === 'list') {
  $st = $conn->prepare("SELECT id,type,ref_id,title,message,is_read,created_at
                        FROM notifications
                        WHERE user_id=?
                        ORDER BY created_at DESC
                        LIMIT ?");
  $st->bind_param('ii', $uid, $limit);
  $st->execute();
  $rs = $st->get_result();
  $rows = [];
  while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
  $st->close();
  echo json_encode(['ok'=>true,'items'=>$rows]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
