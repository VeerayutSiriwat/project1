<?php
// Home/admin/update_status.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  echo json_encode(['ok'=>false,'msg'=>'forbidden']); exit;
}

$id     = (int)($_POST['id'] ?? 0);
$status = trim($_POST['status'] ?? '');

$allowed = ['pending','processing','shipped','delivered','completed','cancelled'];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  echo json_encode(['ok'=>false,'msg'=>'invalid params']); exit;
}

$stmt = $conn->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=? LIMIT 1");
$stmt->bind_param("si", $status, $id);
$ok = $stmt->execute();

echo json_encode(['ok'=>$ok, 'status'=>$status]);
