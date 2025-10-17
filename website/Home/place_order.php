<?php
// Home/place_order.php
session_start();
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=checkout.php");
  exit;
}

$user_id = (int)$_SESSION['user_id'];
$minutes_window = 15; // เวลาหมดอายุสำหรับชำระเงินโอน (นาที)

/* ---------- helpers ---------- */
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows>0;
}
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}

/* ---------- helper: แจ้งเตือน ---------- */
function notify_admins(mysqli $conn, string $type, int $refId, string $title, string $message): void {
  if ($res = $conn->query("SELECT id FROM users WHERE role='admin'")) {
    $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
    while ($row = $res->fetch_assoc()) {
      $uid = (int)$row['id'];
      $st->bind_param("isiss", $uid, $type, $refId, $title, $message);
      $st->execute();
    }
    $st->close();
  }
}
function notify_user(mysqli $conn, int $userId, string $type, int $refId, string $title, string $message): void {
  $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
  $st->bind_param("isiss", $userId, $type, $refId, $title, $message);
  $st->execute();
  $st->close();
}

/* ---------- helper เดิม ---------- */
function fmt_addr($a1,$a2,$dist,$prov,$pc){
  $parts = array_filter([trim($a1), trim($a2), trim($dist), trim($prov), trim($pc)]);
  return implode(', ', $parts);
}
function get_profile_address(mysqli $conn, int $uid): array {
  $st = $conn->prepare("
    SELECT COALESCE(full_name,'') full_name,
           COALESCE(phone,'') phone,
           COALESCE(address_line1,'') a1,
           COALESCE(address_line2,'') a2,
           COALESCE(district,'') dist,
           COALESCE(province,'') prov,
           COALESCE(postcode,'') pc
    FROM users WHERE id=? LIMIT 1
  ");
  $st->bind_param("i",$uid);
  $st->execute();
  $u = $st->get_result()->fetch_assoc() ?: [];
  $st->close();

  return [
    'name'    => trim($u['full_name'] ?? ''),
    'phone'   => trim($u['phone'] ?? ''),
    'address' => fmt_addr($u['a1'] ?? '', $u['a2'] ?? '', $u['dist'] ?? '', $u['prov'] ?? '', $u['pc'] ?? '')
  ];
}
function get_book_address(mysqli $conn, int $uid, int $addr_id = 0): array {
  if ($addr_id > 0) {
    $st = $conn->prepare("
      SELECT COALESCE(full_name,'') full_name, COALESCE(phone,'') phone,
             COALESCE(address_line1,'') a1, COALESCE(address_line2,'') a2,
             COALESCE(district,'') dist, COALESCE(province,'') prov,
             COALESCE(postcode,'') pc
      FROM user_addresses WHERE id=? AND user_id=? LIMIT 1
    ");
    $st->bind_param("ii",$addr_id,$uid);
  } else {
    $st = $conn->prepare("
      SELECT COALESCE(full_name,'') full_name, COALESCE(phone,'') phone,
             COALESCE(address_line1,'') a1, COALESCE(address_line2,'') a2,
             COALESCE(district,'') dist, COALESCE(province,'') prov,
             COALESCE(postcode,'') pc
      FROM user_addresses
      WHERE user_id=? AND is_default=1
      ORDER BY id DESC LIMIT 1
    ");
    $st->bind_param("i",$uid);
  }
  $st->execute();
  $a = $st->get_result()->fetch_assoc() ?: [];
  $st->close();

  return [
    'name'    => trim($a['full_name'] ?? ''),
    'phone'   => trim($a['phone'] ?? ''),
    'address' => fmt_addr($a['a1'] ?? '', $a['a2'] ?? '', $a['dist'] ?? '', $a['prov'] ?? '', $a['pc'] ?? '')
  ];
}

/* ---------- สั่งซื้อ ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 1) เลือกวิธีดึงที่อยู่
  $addr_source = $_POST['address_source'] ?? 'custom';
  $book_id     = (int)($_POST['address_id'] ?? 0);

  if ($addr_source === 'profile') {
    $addr = get_profile_address($conn, $user_id);
    $fullname = $addr['name'];
    $phone    = $addr['phone'];
    $address  = $addr['address'];
  } elseif ($addr_source === 'book') {
    $addr = get_book_address($conn, $user_id, $book_id);
    $fullname = $addr['name'];
    $phone    = $addr['phone'];
    $address  = $addr['address'];
  } else { // custom
    $fn   = trim($_POST['fullname'] ?? '');
    $ph   = trim($_POST['phone'] ?? '');
    $a1   = trim($_POST['address_line1'] ?? '');
    $a2   = trim($_POST['address_line2'] ?? '');
    $dist = trim($_POST['district'] ?? '');
    $prov = trim($_POST['province'] ?? '');
    $pc   = trim($_POST['postcode'] ?? '');
    $fullname = $fn;
    $phone    = $ph;
    $address  = fmt_addr($a1,$a2,$dist,$prov,$pc);
  }

  // วิธีชำระเงิน: 'cod' | 'bank'
  $method = $_POST['payment_method'] ?? 'cod';

  // 2) คำนวณสินค้า
  $products = $_POST['product_id'] ?? [];
  $qtys     = $_POST['qty'] ?? [];
  $total = 0.0;
  $items = [];

  foreach ($products as $k => $pid) {
    $pid = (int)$pid;
    $qty = max(1, (int)($qtys[$k] ?? 1));

    $stp = $conn->prepare("SELECT id, price, discount_price, stock FROM products WHERE id=? AND status='active' LIMIT 1");
    $stp->bind_param("i", $pid);
    $stp->execute();
    $p = $stp->get_result()->fetch_assoc();
    $stp->close();
    if (!$p) continue;

    $orig  = (float)$p['price'];
    $price = ($p['discount_price'] && $p['discount_price'] < $p['price']) ? (float)$p['discount_price'] : (float)$p['price'];
    $qty   = min($qty, (int)$p['stock']);
    if ($qty <= 0) continue;

    $sum = $price * $qty;
    $items[] = ['id'=>$pid,'qty'=>$qty,'price'=>$price,'orig_price'=>$orig,'sum'=>$sum];
    $total += $sum;
  }

  if (empty($items)) { die("ไม่มีสินค้าในคำสั่งซื้อ"); }

  // 3) ตรวจความถูกต้องของที่อยู่
  if ($fullname === '' || $phone === '' || $address === '') {
    die("กรุณากรอกข้อมูลผู้รับให้ครบถ้วน (ชื่อ / เบอร์ / ที่อยู่)");
  }

  /* ===== 3.5) ตรวจคูปอง (ถ้ามี) ===== */
  $inputCode = trim($_POST['coupon_code'] ?? '');
  $discount  = 0.0;
  $coupon_id = null;

  if ($inputCode !== '') {
    $sql = "SELECT * FROM coupons WHERE code=? AND status='active' LIMIT 1";
    $stc = $conn->prepare($sql);
    $stc->bind_param("s", $inputCode);
    $stc->execute();
    $c = $stc->get_result()->fetch_assoc();
    $stc->close();

    if ($c) {
      // นับจำนวนการใช้คูปอง
      $totalUsed = 0;
      $userUsed  = 0;
      if (table_exists($conn,'coupon_usages')) {
        $q1 = $conn->prepare("SELECT COUNT(*) c FROM coupon_usages WHERE coupon_id=?");
        $q1->bind_param("i", $c['id']); $q1->execute();
        $totalUsed = (int)($q1->get_result()->fetch_assoc()['c'] ?? 0);
        $q1->close();

        $q2 = $conn->prepare("SELECT COUNT(*) c FROM coupon_usages WHERE coupon_id=? AND user_id=?");
        $q2->bind_param("ii", $c['id'], $user_id); $q2->execute();
        $userUsed = (int)($q2->get_result()->fetch_assoc()['c'] ?? 0);
        $q2->close();
      }

      // ตรวจสิทธิ์
      if ((int)$c['uses_limit']>0 && $totalUsed >= (int)$c['uses_limit']) {
        $reason = 'คูปองนี้ถูกใช้งานครบแล้ว';
      } elseif ((int)$c['per_user_limit']>0 && $userUsed >= (int)$c['per_user_limit']) {
        $reason = 'คุณใช้คูปองนี้ครบจำนวนแล้ว';
      } else {
        // คำนวณส่วนลด
        $base = 0.0;
        foreach ($items as $it) {
          $base += (float)$it['sum'];
        }
        if ($base > 0) {
          $type = strtolower($c['type']);
          $val  = (float)$c['value'];
          if ($type === 'percent') {
            $discount = $base * ($val / 100);
            if (!empty($c['max_discount']) && $c['max_discount'] > 0) {
              $discount = min($discount, (float)$c['max_discount']);
            }
          } else {
            $discount = min($val, $base);
          }
          $coupon_id = (int)$c['id'];
        }
      }
    }
  }

  $conn->begin_transaction();

try {
  // ✅ ใช้ตัวแปร $inputCode ที่ประกาศไว้ตอนต้น (ไม่ใช่ $coupon_code)
  $lock = $conn->prepare("SELECT id, uses_limit, per_user_limit FROM coupons WHERE code=? FOR UPDATE");
  $lock->bind_param('s', $inputCode);
  $lock->execute();
  $coupon = $lock->get_result()->fetch_assoc();
  $lock->close();

  if (!$coupon) {
    throw new Exception('คูปองไม่ถูกต้องหรือไม่พบในระบบ');
  }

  // ✅ ตรวจจำนวนการใช้ (ออเดอร์ที่ยังไม่ถูกยกเลิก/คืนเงิน)
  $q = $conn->prepare("
    SELECT 
      COALESCE(SUM(t.anyone),0) AS used_total,
      COALESCE(SUM(t.mine),0)   AS used_by_me
    FROM (
      SELECT 
        1 AS anyone,
        CASE WHEN cu.user_id=? THEN 1 ELSE 0 END AS mine
      FROM coupon_usages cu
      INNER JOIN orders o ON o.id = cu.order_id
      WHERE cu.coupon_id = ?
        AND cu.context = 'order'
        AND o.payment_status IN ('unpaid','pending','paid')
        AND (o.cancel_reason IS NULL OR o.cancel_reason = '')
      GROUP BY cu.order_id
    ) t
  ");
  $q->bind_param('ii', $user_id, $coupon['id']);
  $q->execute();
  $row = $q->get_result()->fetch_assoc() ?: ['used_total'=>0,'used_by_me'=>0];
  $q->close();

  if (($coupon['uses_limit'] ?? 0) > 0 && $row['used_total'] >= $coupon['uses_limit']) {
    throw new Exception('คูปองนี้มีผู้ใช้ครบแล้ว');
  }
  if (($coupon['per_user_limit'] ?? 0) > 0 && $row['used_by_me'] >= $coupon['per_user_limit']) {
    throw new Exception('คุณใช้คูปองนี้ครบจำนวนแล้ว');
  }

  // ✅ (สร้างคำสั่งซื้อ INSERT orders ... ที่นี่ตามโค้ดคุณ)
  // เช่น:
  // $order_id = ...;  // หลังจาก INSERT สำเร็จแล้ว

  // ✅ บันทึกการใช้คูปอง (อย่าลืมเปลี่ยน $discount_amount ให้ใช้ตัวแปรส่วนลดจริง)
  $discount_amount = $discount ?? 0.0;
  if ($coupon['id'] && isset($order_id)) {
    $ins = $conn->prepare("
      INSERT INTO coupon_usages (coupon_id, user_id, order_id, context, amount, created_at, used_at)
      VALUES (?, ?, ?, 'order', ?, NOW(), NOW())
    ");
    $ins->bind_param('iiid', $coupon['id'], $user_id, $order_id, $discount_amount);
    $ins->execute();
    $ins->close();
  }

  $conn->commit();

} catch (Exception $e) {
  $conn->rollback();
  // ❗ เปลี่ยนจาก die() เป็น redirect กลับหน้า checkout พร้อมข้อความแจ้งเตือนสวยๆ
  $msg = urlencode($e->getMessage());
  $code = urlencode($inputCode);
  header("Location: checkout.php?apply_error={$msg}&apply_code={$code}");
  exit;
}
  $final_total = max(0.0, $total - $discount);

  /* ========== 4) ทำธุรกรรมสั่งซื้อ ========== */
  $conn->begin_transaction();
  try {
    $status     = 'pending';
    $pay_status = 'unpaid';

    $ord_has_discount = has_col($conn,'orders','discount_total');
    $ord_has_coupon   = has_col($conn,'orders','coupon_code');

    // เพิ่มออเดอร์
    $sql = "INSERT INTO orders
            (user_id,total_price".($ord_has_discount?",discount_total":"").($ord_has_coupon?",coupon_code":"").",status,payment_status,shipping_name,shipping_phone,shipping_address,
             payment_method,stock_deducted,created_at,updated_at,expires_at)
            VALUES (?,?,".($ord_has_discount?"?,":"").($ord_has_coupon?"?,":"")."?,?,?,?,?,?,0,NOW(),NOW(),".($method==='bank'?"DATE_ADD(NOW(), INTERVAL ? MINUTE)":"NULL").")";
    if ($method === 'bank') {
      if ($ord_has_discount && $ord_has_coupon)
        $st = $conn->prepare($sql) && $st->bind_param("iddsssssssi",$user_id,$final_total,$discount,$inputCode,$status,$pay_status,$fullname,$phone,$address,$method,$minutes_window);
    }

    $st = $conn->prepare($sql);
    if ($ord_has_discount && $ord_has_coupon)
      $st->bind_param("iddsssssss", $user_id,$final_total,$discount,$inputCode,$status,$pay_status,$fullname,$phone,$address,$method);
    elseif ($ord_has_discount)
      $st->bind_param("iddssssss", $user_id,$final_total,$discount,$status,$pay_status,$fullname,$phone,$address,$method);
    elseif ($ord_has_coupon)
      $st->bind_param("idsssssss", $user_id,$final_total,$inputCode,$status,$pay_status,$fullname,$phone,$address,$method);
    else
      $st->bind_param("idssssss", $user_id,$final_total,$status,$pay_status,$fullname,$phone,$address,$method);
    $st->execute();
    $order_id = (int)$st->insert_id;
    $st->close();

    // รายการสินค้า
    $sti = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)");
    foreach ($items as $it) {
      $sti->bind_param("iiid", $order_id, $it['id'], $it['qty'], $it['price']);
      $sti->execute();
    }
    $sti->close();

    // ตัดสต็อกสำหรับ COD
    if ($method === 'cod') {
      $up = $conn->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?");
      foreach ($items as $it) {
        $up->bind_param("ii", $it['qty'], $it['id']);
        $up->execute();
      }
      $up->close();
      $conn->query("UPDATE orders SET stock_deducted=1 WHERE id={$order_id}");
    }

    // บันทึกคูปอง
    if ($coupon_id && table_exists($conn,'coupon_usages')) {
      $ins = $conn->prepare("INSERT INTO coupon_usages (coupon_id,user_id,order_id,context,amount,used_at) VALUES (?,?,?,?,?,NOW())");
      $ctx = 'order';
      $ins->bind_param("iiiss", $coupon_id,$user_id,$order_id,$ctx,$discount);
      $ins->execute();
      $ins->close();
    }

    $conn->commit();

    // แจ้งเตือน
    $username = '';
    if ($stU = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")) {
      $stU->bind_param("i", $user_id);
      $stU->execute();
      $username = (string)($stU->get_result()->fetch_assoc()['username'] ?? '');
      $stU->close();
    }
    $displayUser = $username !== '' ? $username : "UID {$user_id}";

    if ($method === 'cod') {
      notify_admins($conn,'new_order_cod',$order_id,"ออเดอร์ใหม่ (ปลายทาง)","คำสั่งซื้อ #{$order_id} จาก {$displayUser}".($discount>0?" • คูปองลด ".number_format($discount,2)." บาท":""));
      notify_user($conn,$user_id,'order_status',$order_id,"สั่งซื้อสำเร็จ","คำสั่งซื้อ #{$order_id} เก็บเงินปลายทาง".($discount>0?" • ส่วนลดคูปอง ".number_format($discount,2)." บาท":""));
    } else {
      notify_admins($conn,'new_order_bank',$order_id,"ออเดอร์ใหม่ (โอนธนาคาร)","คำสั่งซื้อ #{$order_id} จาก {$displayUser} • รอชำระเงิน".($discount>0?" • คูปองลด ".number_format($discount,2)." บาท":""));
      notify_user($conn,$user_id,'payment_status',$order_id,"สั่งซื้อสำเร็จ - กรุณาโอนเงิน","คำสั่งซื้อ #{$order_id} กรุณาโอนเงินและอัปโหลดสลิป".($discount>0?" • ใช้คูปองลด ".number_format($discount,2)." บาท":""));
    }

    // เปลี่ยนหน้า
    header("Location: ".($method==='cod'?"order_success.php?id=":"upload_slip.php?id=").$order_id);
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    header("Location: checkout.php?error=order_failed");
    exit;
  }
}
?> 
