<?php
// Home/support_end_chat.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/includes/db.php';

function jexit($ok, $extra = []) {
  echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// auth
if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  jexit(false, ['error' => 'unauthorized']);
}

$user_id  = (int)($_SESSION['user_id']);
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

// กำหนด target ผู้ใช้ที่จะสิ้นสุดแชท
// - ถ้าเป็นแอดมิน อนุญาตส่ง uid มากับ POST/GET (เช่น support_admin UI)
// - ถ้าเป็นผู้ใช้ทั่วไป จะจบแชทของตัวเองเท่านั้น
$target_uid = $user_id;
if ($is_admin) {
  $t = $_POST['uid'] ?? $_GET['uid'] ?? null;
  if ($t !== null) {
    $target_uid = (int)$t;
  }
}

// ป้องกันค่าเพี้ยน
if ($target_uid <= 0) {
  http_response_code(400);
  jexit(false, ['error' => 'invalid uid']);
}

// ตรวจสอบว่าผู้ใช้ปลายทางมีจริง
$st = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $target_uid);
$st->execute();
$exists = $st->get_result()->fetch_assoc();
$st->close();
if (!$exists) {
  http_response_code(404);
  jexit(false, ['error' => 'user not found']);
}

// เริ่มทำธุรกรรม
$conn->begin_transaction();

try {
  $deleted_msgs = 0;
  $aff_threads  = 0;

  // ลบข้อความเก่าทั้งหมดของผู้ใช้รายนี้ (ตารางหลักของแชท)
  if ($conn->query("SHOW TABLES LIKE 'support_messages'")->num_rows > 0) {
    $del = $conn->prepare("DELETE FROM support_messages WHERE user_id=?");
    $del->bind_param('i', $target_uid);
    $del->execute();
    $deleted_msgs = $del->affected_rows;
    $del->close();
  }

  // อัปเดตสถานะเธรด ถ้ามีตาราง support_threads
  if ($conn->query("SHOW TABLES LIKE 'support_threads'")->num_rows > 0) {
    // มีได้หลายแบบ ถ้ามีคอลัมน์ status/closed_at ก็ปิดเธรด
    // ถ้าไม่มี ให้ลบทั้งเธรดแทน (เลือกแบบ update ก่อน แล้ว fallback เป็น delete)
    $hasStatus = false;
    $desc = $conn->query("SHOW COLUMNS FROM support_threads");
    while ($row = $desc->fetch_assoc()) {
      if ($row['Field'] === 'status') $hasStatus = true;
    }
    $desc->free();

    if ($hasStatus) {
      $up = $conn->prepare("UPDATE support_threads SET status='closed', closed_at=NOW() WHERE user_id=?");
      $up->bind_param('i', $target_uid);
      $up->execute();
      $aff_threads = $up->affected_rows;
      $up->close();
    } else {
      $dl = $conn->prepare("DELETE FROM support_threads WHERE user_id=?");
      $dl->bind_param('i', $target_uid);
      $dl->execute();
      $aff_threads = $dl->affected_rows;
      $dl->close();
    }
  }

  // เก็บ noti ให้ผู้ใช้ทราบว่าเธรดถูกปิด (ไม่จำเป็นก็ข้ามได้)
  if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0) {
    $title = 'สิ้นสุดการแชท';
    $msg   = $is_admin
      ? 'แอดมินได้สิ้นสุดการสนทนาแล้ว คุณสามารถเริ่มแชทใหม่ได้ทุกเมื่อ'
      : 'คุณได้สิ้นสุดการสนทนาแล้ว สามารถเริ่มใหม่ได้ทุกเมื่อ';
    $nt = $conn->prepare("INSERT INTO notifications(user_id, type, ref_id, title, message, is_read, created_at) VALUES(?, 'support_end', 0, ?, ?, 0, NOW())");
    $nt->bind_param('iss', $target_uid, $title, $msg);
    $nt->execute();
    $nt->close();
  }

  $conn->commit();
  jexit(true, [
    'deleted_messages' => (int)$deleted_msgs,
    'affected_threads' => (int)$aff_threads,
    'target_uid'       => (int)$target_uid
  ]);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  jexit(false, ['error' => 'server_error', 'detail' => $e->getMessage()]);
}
