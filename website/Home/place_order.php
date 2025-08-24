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
           COALESCE(district,'')     dist,
           COALESCE(province,'')     prov,
           COALESCE(postcode,'')     pc
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
    $fn   = trim($_POST['fullname']       ?? '');
    $ph   = trim($_POST['phone']          ?? '');
    $a1   = trim($_POST['address_line1']  ?? '');
    $a2   = trim($_POST['address_line2']  ?? '');
    $dist = trim($_POST['district']       ?? '');
    $prov = trim($_POST['province']       ?? '');
    $pc   = trim($_POST['postcode']       ?? '');
    $fullname = $fn;
    $phone    = $ph;
    $address  = fmt_addr($a1,$a2,$dist,$prov,$pc);
  }

  // วิธีชำระเงิน: 'cod' | 'bank'
  $method = $_POST['payment_method'] ?? 'cod';

  // 2) คำนวณสินค้า
  $products = $_POST['product_id'] ?? [];
  $qtys     = $_POST['qty']        ?? [];
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

    $price = ($p['discount_price'] && $p['discount_price'] < $p['price']) ? (float)$p['discount_price'] : (float)$p['price'];
    $qty   = min($qty, (int)$p['stock']);
    if ($qty <= 0) continue;

    $items[] = ['id'=>$pid, 'qty'=>$qty, 'price'=>$price];
    $total  += $price * $qty;
  }

  if (empty($items)) { die("ไม่มีสินค้าในคำสั่งซื้อ"); }

  // 3) ตรวจความถูกต้องพื้นฐานของที่อยู่
  if ($fullname === '' || $phone === '' || $address === '') {
    die("กรุณากรอกข้อมูลผู้รับให้ครบถ้วน (ชื่อ / เบอร์ / ที่อยู่)");
  }

  // 4) ทำธุรกรรม
  $conn->begin_transaction();
  try {
    $status     = 'pending';
    $pay_status = 'unpaid';

    if ($method === 'bank') {
      $sql = "INSERT INTO orders
              (user_id,total_price,status,payment_status,shipping_name,shipping_phone,shipping_address,
               payment_method,stock_deducted,created_at,updated_at,expires_at)
              VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))";
      $st = $conn->prepare($sql);
      $st->bind_param("idssssssi",
          $user_id,$total,$status,$pay_status,$fullname,$phone,$address,$method,$minutes_window
      );
    } else { // COD
      $sql = "INSERT INTO orders
              (user_id,total_price,status,payment_status,shipping_name,shipping_phone,shipping_address,
               payment_method,stock_deducted,created_at,updated_at,expires_at)
              VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW(), NULL)";
      $st = $conn->prepare($sql);
      $st->bind_param("idssssss",
          $user_id,$total,$status,$pay_status,$fullname,$phone,$address,$method
      );
    }
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

    // ตัดสต็อกทันทีสำหรับ COD
    if ($method === 'cod') {
      $up = $conn->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?");
      foreach ($items as $it) {
        $q = (int)$it['qty']; $pid = (int)$it['id'];
        $up->bind_param("ii", $q, $pid);
        $up->execute();
      }
      $up->close();
      $conn->query("UPDATE orders SET stock_deducted=1 WHERE id={$order_id}");
    }

    $conn->commit();

    /* ===== แจ้งเตือนหลัง commit ===== */
    // ดึง username สำหรับข้อความ
    $username = '';
    if ($stU = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")) {
      $stU->bind_param("i", $user_id);
      $stU->execute();
      $username = (string)($stU->get_result()->fetch_assoc()['username'] ?? '');
      $stU->close();
    }
    $displayUser = $username !== '' ? $username : "UID {$user_id}";

    if ($method === 'cod') {
      // แอดมิน
      notify_admins(
        $conn,
        'new_order_cod',
        $order_id,
        "ออเดอร์ใหม่ (ปลายทาง)",
        "คำสั่งซื้อ #{$order_id} จาก {$displayUser}"
      );
      // ลูกค้า
      notify_user(
        $conn,
        $user_id,
        'order_status',
        $order_id,
        "สั่งซื้อสำเร็จ",
        "คำสั่งซื้อ #{$order_id} ชำระแบบเก็บเงินปลายทาง"
      );
    } else { // bank
      // หา expires_at ไว้แสดง
      $expires_at = '';
      if ($stE = $conn->prepare("SELECT expires_at FROM orders WHERE id=? LIMIT 1")) {
        $stE->bind_param("i", $order_id);
        $stE->execute();
        $expires_at = (string)($stE->get_result()->fetch_assoc()['expires_at'] ?? '');
        $stE->close();
      }
      $deadlineTxt = $expires_at ? " ภายใน ".date('d/m/Y H:i', strtotime($expires_at)) : '';

      // แอดมิน
      notify_admins(
        $conn,
        'new_order_bank',
        $order_id,
        "ออเดอร์ใหม่ (โอนธนาคาร)",
        "คำสั่งซื้อ #{$order_id} จาก {$displayUser} • รอชำระเงิน{$deadlineTxt}"
      );
      // ลูกค้า
      notify_user(
        $conn,
        $user_id,
        'payment_status',
        $order_id,
        "สั่งซื้อสำเร็จ - กรุณาโอนเงิน",
        "คำสั่งซื้อ #{$order_id}{$deadlineTxt} และอัปโหลดสลิปในหน้า ‘คำสั่งซื้อของฉัน’"
      );
    }
    /* ===== จบแจ้งเตือน ===== */

    // เปลี่ยนหน้า
    if ($method === 'cod') {
      header("Location: order_success.php?id=".$order_id);
    } else {
      header("Location: upload_slip.php?id=".$order_id);
    }
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    header("Location: checkout.php?error=order_failed");
    exit;
  }
}
