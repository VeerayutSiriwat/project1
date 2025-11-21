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
      // นับจำนวนการใช้คูปอง (ง่าย ๆ รวมทุก context)
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

      // ถ้าเกินสิทธิ์ก็แค่ไม่ลด แต่ยังสั่งซื้อได้
      if (!((int)$c['uses_limit']>0 && $totalUsed >= (int)$c['uses_limit'])
          && !((int)$c['per_user_limit']>0 && $userUsed >= (int)$c['per_user_limit'])) {

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

  // ยอดสุดท้ายหลังหักส่วนลดคูปอง
  $final_total = max(0.0, $total - $discount);

  /* ========== 4) ทำธุรกรรมสั่งซื้อ ========== */
  $conn->begin_transaction();
  try {
    $status     = 'pending';
    $pay_status = 'unpaid';

    $ord_has_discount = has_col($conn,'orders','discount_total');
    $ord_has_coupon   = has_col($conn,'orders','coupon_code');

    // ===== สร้าง SQL + bind param แบบ dynamic รองรับทั้ง COD / BANK =====
    $cols   = "user_id,total_price";
    $values = "?,?";
    $types  = "id";
    $params = [$user_id, $final_total];

    if ($ord_has_discount) {
      $cols   .= ",discount_total";
      $values .= ",?";
      $types  .= "d";
      $params[] = $discount;
    }
    if ($ord_has_coupon) {
      $cols   .= ",coupon_code";
      $values .= ",?";
      $types  .= "s";
      $params[] = $inputCode;
    }

    $cols .= ",status,payment_status,shipping_name,shipping_phone,shipping_address,payment_method,stock_deducted,created_at,updated_at,expires_at";

    if ($method === 'bank') {
      // มี expires_at เป็น DATE_ADD(... ? MINUTE)
      $values .= ",?,?,?,?,?,?,0,NOW(),NOW(),DATE_ADD(NOW(), INTERVAL ? MINUTE)";
      $types  .= "ssssssi";
      array_push($params, $status, $pay_status, $fullname, $phone, $address, $method, $minutes_window);
    } else {
      // COD: expires_at = NULL
      $values .= ",?,?,?,?,?,?,0,NOW(),NOW(),NULL";
      $types  .= "ssssss";
      array_push($params, $status, $pay_status, $fullname, $phone, $address, $method);
    }

    $sql = "INSERT INTO orders ($cols) VALUES ($values)";
    $st  = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
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

    // บันทึกคูปอง ถ้ามี
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
    // ถ้าอยาก debug ชั่วคราว ลอง var_dump($e->getMessage()); die();
    header("Location: checkout.php?error=order_failed");
    exit;
  }
}
?>
