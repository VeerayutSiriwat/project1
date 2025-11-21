<?php
// Home/tradein_action.php (revamped to match your coupons schema)
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . "/includes/db.php";

$uid = (int)($_SESSION['user_id'] ?? 0);
$id  = (int)($_POST['id'] ?? 0);
$act = trim((string)($_POST['action'] ?? ''));

if ($uid<=0 || $id<=0) { http_response_code(403); exit('forbidden'); }

/* โหลดคำขอ (ต้องเป็นของผู้ใช้เอง) */
$req = null;
if ($st=$conn->prepare("SELECT id,user_id,status,offer_price FROM tradein_requests WHERE id=? AND user_id=? LIMIT 1")){
  $st->bind_param('ii', $id, $uid);
  $st->execute();
  $req = $st->get_result()->fetch_assoc();
  $st->close();
}
if (!$req) { http_response_code(404); exit('not found'); }

/* อนุญาตตอบรับ/ปฏิเสธได้เฉพาะตอนสถานะ offered */
if ($req['status'] !== 'offered') {
  $_SESSION['flash'] = 'ข้อเสนอนี้ไม่พร้อมให้ตอบรับแล้ว';
  header("Location: service_my_detail.php?type=tradein&id=".$id);
  exit;
}

/* ====== ดำเนินการ ====== */
if ($act === 'accept') {

  // อัปเดตสถานะเป็น accepted + log
  if ($st=$conn->prepare("UPDATE tradein_requests SET status='accepted', updated_at=NOW() WHERE id=?")){
    $st->bind_param('i', $id); $st->execute(); $st->close();
  }
  $conn->query("INSERT INTO tradein_status_logs (request_id,status,note,created_at) VALUES ("
               .(int)$id.",'accepted','ลูกค้ายอมรับข้อเสนอ',NOW())");

  // ถ้ามีราคาเสนอ -> ออกคูปองเครดิตเทิร์น
  $offer = (float)($req['offer_price'] ?? 0);
  if ($offer > 0.0) {

    // สร้างโค้ดยูนีค TR{ID}-{RANDOM}
    $code = '';
    for ($i=0; $i<5; $i++) {
      $rand = strtoupper(substr(bin2hex(random_bytes(3)),0,6));
      $code = sprintf('TR%04d-%s', (int)$id, $rand);
      $chk  = $conn->prepare("SELECT 1 FROM coupons WHERE code=? LIMIT 1");
      $chk->bind_param('s', $code); $chk->execute();
      $exists = $chk->get_result()->num_rows > 0;
      $chk->close();
      if (!$exists) break; // ได้โค้ดที่ไม่ซ้ำ
    }

    // ค่าตั้งต้นคูปอง
    $type        = 'fixed';       // มูลค่าตายตัว
    $value       = $offer;        // มูลค่าเทิร์น
    $note        = 'Trade-in credit TR-'.(int)$id;
    $minOrder    = 0.00;          // ไม่กำหนดยอดสั่งซื้อขั้นต่ำ
    $usesLimit   = 1;             // ใช้ได้รวม 1 ครั้ง
    $perUser     = 1;             // ต่อผู้ใช้ 1 ครั้ง
    $appliesTo   = 'all';         // ใช้ได้ทุกอย่างใน Checkout (จะจำกัดเฉพาะ products ก็ได้)
    $stackable   = 0;             // ไม่รวมกับราคาที่ลดแล้ว (ปรับได้ตามกติกา)

    /* ใส่ตาม schema จริงของคุณ: min_order_total, starts_at, ends_at, uses_limit, per_user_limit, applies_to, allow_stack_with_discount_price, status */
    if ($st=$conn->prepare("
  INSERT INTO coupons
    (user_id, tradein_id, code, type, value, note, min_order_total,
     starts_at, ends_at, uses_limit, per_user_limit, applies_to,
     allow_stack_with_discount_price, status, created_at)
  VALUES
    (?,?,?,?,?,?,?,
     NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?, ?, ?, 'active', NOW())
")){
  // types: i i s s d s d i i s i  => รวม 11 ตัว
  $st->bind_param(
    'iissdsdiisi',
    $uid,        // 1 user_id
    $id,         // 2 tradein_id
    $code,       // 3 code
    $type,       // 4 type
    $value,      // 5 value
    $note,       // 6 note
    $minOrder,   // 7 min_order_total
    $usesLimit,  // 8 uses_limit
    $perUser,    // 9 per_user_limit
    $appliesTo,  // 10 applies_to
    $stackable   // 11 allow_stack_with_discount_price
  );
  $st->execute();
  $st->close();
}


    $_SESSION['flash'] = 'ยืนยันแล้ว: ได้คูปองเครดิตเทิร์น <b>'.htmlspecialchars($code,ENT_QUOTES,'UTF-8').'</b> มูลค่า '
                       . number_format($offer,2).' ฿ (อายุ 90 วัน)';
  } else {
    $_SESSION['flash'] = 'ยืนยันแล้ว: คุณยอมรับข้อเสนอเรียบร้อย';
  }

} elseif ($act === 'reject') {

  if ($st=$conn->prepare("UPDATE tradein_requests SET status='rejected', updated_at=NOW() WHERE id=?")){
    $st->bind_param('i', $id); $st->execute(); $st->close();
  }
  $conn->query("INSERT INTO tradein_status_logs (request_id,status,note,created_at) VALUES ("
               .(int)$id.",'rejected','ลูกค้าปฏิเสธข้อเสนอ',NOW())");

  $_SESSION['flash'] = 'บันทึกแล้ว: คุณปฏิเสธข้อเสนอ';

} else {

  $_SESSION['flash'] = 'คำสั่งไม่ถูกต้อง';

}

/* === ออกคูปองเครดิตเทิร์นอัตโนมัติ กรณีแอดมินเปลี่ยนสถานะเป็น accepted/completed === */
$shouldIssue = in_array($status, ['accepted','completed'], true);

// มีราคาเสนอหรือไม่ และยังไม่มีคูปองของงานนี้
if ($shouldIssue) {
  // ดึงข้อมูลที่ต้องใช้
  $q = $conn->query("SELECT user_id, offer_price FROM tradein_requests WHERE id={$id} LIMIT 1");
  $row = $q ? $q->fetch_assoc() : null;
  $offer = (float)($row['offer_price'] ?? 0);
  $uId   = (int)($row['user_id'] ?? 0);

  if ($offer > 0 && $uId > 0) {
    // ตรวจว่ามีคูปองผูกกับ tradein_id นี้หรือยัง
    $exists = 0;
    if ($st = $conn->prepare("SELECT COUNT(*) c FROM coupons WHERE tradein_id=?")) {
      $st->bind_param('i', $id); $st->execute();
      $exists = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
      $st->close();
    }

    if ($exists === 0) {
      // สร้างรหัส
      $rand = substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);
      $code = sprintf('TR%04d-%s', (int)$id, $rand);

      // กันซ้ำแบบง่าย
      if ($conn->query("SELECT 1 FROM coupons WHERE code='".$conn->real_escape_string($code)."' LIMIT 1")->num_rows){
        $code .= 'X';
      }

      // ค่าคงที่ตาม schema ปัจจุบันของคุณ
      $type        = 'fixed';
      $note        = 'Trade-in credit TR-'.(int)$id;
      $minOrder    = 0.00;
      $usesLimit   = 1;     // ทั้งร้านใช้ได้รวม 1 ครั้ง
      $perUser     = 1;     // ต่อผู้ใช้ 1 ครั้ง
      $appliesTo   = 'tradein'; // ใช้ได้ tradein
      $stackable   = 0;     // ไม่ซ้อนกับราคาลด

      if ($st = $conn->prepare("
        INSERT INTO coupons
          (user_id, tradein_id, code, type, value, note, min_order_total,
           starts_at, ends_at, uses_limit, per_user_limit, applies_to,
           allow_stack_with_discount_price, status, created_at)
        VALUES
          (?,?,?,?,?,?,?,
           NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?, ?, ?, 'active', NOW())
      ")){
        // types: i i s s d s d i i s i   (รวม 11 ตัว)
        $st->bind_param(
          'iissdsdiisi',
          $uId,       // user_id
          $id,        // tradein_id
          $code,      // code
          $type,      // type
          $offer,     // value
          $note,      // note
          $minOrder,  // min_order_total
          $usesLimit, // uses_limit
          $perUser,   // per_user_limit
          $appliesTo, // applies_to
          $stackable  // allow_stack_with_discount_price
        );
        $st->execute(); $st->close();
      }
    }
  }
}

header("Location: service_my_detail.php?type=tradein&id=".$id);
