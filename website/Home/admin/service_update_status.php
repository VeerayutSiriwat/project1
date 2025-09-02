<?php
// Home/admin/service_update_status.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('forbidden'); }

$id     = (int)($_POST['id'] ?? 0);
$status = trim($_POST['status'] ?? '');
$note   = trim($_POST['note'] ?? '');

if($id<=0 || $status===''){ header('Location: service_tickets.php'); exit; }

$conn->begin_transaction();
try{
  // update ticket
  if($st=$conn->prepare("UPDATE service_tickets SET status=?, updated_at=NOW() WHERE id=?")){
    $st->bind_param("si",$status,$id); $st->execute(); $st->close();
  }
  // insert log
  if($st=$conn->prepare("INSERT INTO service_status_logs (ticket_id,status,note,created_at) VALUES (?,?,?,NOW())")){
    $st->bind_param("iss",$id,$status,$note); $st->execute(); $st->close();
  }
  $conn->commit();
}catch(Throwable $e){ $conn->rollback(); }

header('Location: service_ticket_detail.php?id='.$id);
