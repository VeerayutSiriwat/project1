<?php
// Home/notify_api.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}

$uid    = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$limit  = max(1, min(20, (int)($_GET['limit'] ?? 10)));

/* ---- นับจำนวนที่ยังไม่อ่าน ---- */
if ($action === 'count') {
  $st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
  $st->bind_param('i', $uid);
  $st->execute();
  $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
  echo json_encode(['ok'=>true,'count'=>$c]); exit;
}

/* ---- ทำเครื่องหมายอ่านทั้งหมด ---- */
if ($action === 'mark_all_read') {
  $st = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
  $st->bind_param('i', $uid);
  $st->execute();
  $aff = $st->affected_rows;
  $st->close();
  echo json_encode(['ok'=>true,'updated'=>$aff]); exit;
}

/* ---- ทำเครื่องหมายอ่านทีละรายการ ---- */
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }

  $st = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
  $st->bind_param('ii', $id, $uid);
  $st->execute();
  $ok = $st->affected_rows > 0;
  $st->close();
  echo json_encode(['ok'=>$ok]); exit;
}

/* ---- ทำเครื่องหมายอ่านหลายรายการพร้อมกัน (ids[]=1&ids[]=2 … หรือ ids=1,2,3) ---- */
if ($action === 'mark_read_many' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $ids = $_POST['ids'] ?? [];
  if (!is_array($ids)) { $ids = explode(',', (string)$ids); }
  $ids = array_values(array_filter(array_map('intval', $ids), fn($x)=>$x>0));

  if (empty($ids)) { echo json_encode(['ok'=>false,'error'=>'no ids']); exit; }

  // สร้าง IN จากตัวเลขที่ validate แล้ว (ปลอดภัย)
  $in  = implode(',', $ids);
  $sql = "UPDATE notifications SET is_read=1 WHERE user_id=? AND id IN ($in)";
  $st  = $conn->prepare($sql);
  $st->bind_param('i', $uid);
  $st->execute();
  $aff = $st->affected_rows;
  $st->close();

  echo json_encode(['ok'=>true,'updated'=>$aff]); exit;
}

/* ---- ดึงรายการล่าสุด ---- */
if ($action === 'list') {
  $st = $conn->prepare(
    "SELECT id,type,ref_id,title,message,is_read,created_at
     FROM notifications
     WHERE user_id=?
     ORDER BY created_at DESC
     LIMIT ?"
  );
  $st->bind_param('ii', $uid, $limit);
  $st->execute();
  $rs = $st->get_result();
  $rows = [];
  while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
  $st->close();
  echo json_encode(['ok'=>true,'items'=>$rows]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
