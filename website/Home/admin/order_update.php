<?php
// Home/admin/order_update.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

// ต้องเป็นแอดมิน
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/orders.php');
  exit;
}

// กัน CSRF (ถ้ามีส่งมากับฟอร์ม)
if (!empty($_POST['csrf']) && (!isset($_SESSION['csrf_token']) || $_POST['csrf'] !== $_SESSION['csrf_token'])) {
  $_SESSION['flash'] = 'Invalid CSRF token';
  header('Location: ' . ($_POST['return'] ?? 'orders.php'));
  exit;
}

$id     = (int)($_POST['id'] ?? 0);
$type   = $_POST['type'] ?? '';
$val    = $_POST['value'] ?? '';
$note   = trim($_POST['note'] ?? '');           // โน้ตเหตุผล (ถ้ามี)
$return = $_POST['return'] ?? 'orders.php';

if ($id <= 0 || !in_array($type, ['payment_status','order_status','cancel_decision'], true)) {
  $_SESSION['flash'] = 'ข้อมูลไม่ถูกต้อง';
  header("Location: $return"); exit;
}

/* ========= แผนที่ชื่อสถานะภาษาไทย (ใช้ใน notification) ========= */
$PAY_THAI = [
  'unpaid'   => 'ยังไม่ชำระ',
  'pending'  => 'รอตรวจสอบ',
  'paid'     => 'ชำระแล้ว',
  'refunded' => 'คืนเงินแล้ว',
  'expired'  => 'หมดเวลาชำระ',
];
$ORDER_THAI = [
  'pending'          => 'ใหม่/กำลังตรวจสอบ',
  'processing'       => 'กำลังเตรียม/แพ็ค',
  'shipped'          => 'ส่งออกจากคลัง',
  'delivered'        => 'ถึงปลายทาง',
  'completed'        => 'เสร็จสิ้น',
  'cancel_requested' => 'รอยืนยันยกเลิก',
  'cancelled'        => 'ยกเลิก',
];

/* ========= helper: เพิ่ม notification ========= */
$addNotify = function(int $userId, string $type, int $refId, string $title, string $message) use ($conn) {
  $stN = $conn->prepare("INSERT INTO notifications (user_id, type, ref_id, title, message, is_read) VALUES (?, ?, ?, ?, ?, 0)");
  $stN->bind_param("isiss", $userId, $type, $refId, $title, $message);
  $stN->execute();
  $stN->close();
};

$conn->begin_transaction();
try {
    // lock แถวออเดอร์ (เพิ่ม user_id มาใช้ส่งแจ้งเตือน)
    $st = $conn->prepare("SELECT id, user_id, payment_method, payment_status, status, stock_deducted FROM orders WHERE id=? FOR UPDATE");
    $st->bind_param("i", $id);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$order) { throw new Exception('ไม่พบออเดอร์'); }

    $userId       = (int)$order['user_id'];
    $oldPayStatus = (string)$order['payment_status'];
    $oldOrdStatus = (string)$order['status'];

    // ฟังก์ชันย่อยจัดการสต็อก
    $getItems = function() use($conn,$id) {
        $s = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
        $s->bind_param("i", $id);
        $s->execute();
        $res = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $res;
    };
    $deduct = function($items) use($conn) {
        $up = $conn->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?");
        foreach ($items as $it) {
            $q = (int)$it['quantity']; $pid = (int)$it['product_id'];
            $up->bind_param("ii", $q, $pid);
            $up->execute();
        }
        $up->close();
    };
    $restore = function($items) use($conn) {
        $up = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id=?");
        foreach ($items as $it) {
            $q = (int)$it['quantity']; $pid = (int)$it['product_id'];
            $up->bind_param("ii", $q, $pid);
            $up->execute();
        }
        $up->close();
    };

    /* ========== 1) อัปเดต payment_status ========== */
    if ($type === 'payment_status') {
        // unpaid / pending / paid / refunded / expired
        if ($oldPayStatus !== $val) {
          $up = $conn->prepare("UPDATE orders SET payment_status=? WHERE id=?");
          $up->bind_param("si", $val, $id);
          $up->execute();
          $up->close();

          // แจ้งเตือนผู้ใช้
          $title   = "อัปเดตการชำระเงิน";
          $statusT = $PAY_THAI[$val] ?? $val;
          $msg     = "คำสั่งซื้อ #{$id} สถานะการชำระเงิน: {$statusT}";
          $addNotify($userId, 'payment_status', $id, $title, $msg);
        }

        // โอนเงินธนาคาร: เปลี่ยนเป็น paid แล้วตัดสต็อก (ถ้ายัง)
        if ($order['payment_method']==='bank' && $val==='paid' && (int)$order['stock_deducted']===0) {
            $items = $getItems();
            $deduct($items);
            $conn->query("UPDATE orders SET stock_deducted=1 WHERE id={$id}");
        }

        // หาก refunded และเคยตัดสต็อก → คืนสต็อก
        if ($val==='refunded' && (int)$order['stock_deducted']===1) {
            $items = $getItems();
            $restore($items);
            $conn->query("UPDATE orders SET stock_deducted=0 WHERE id={$id}");
        }
    }

    /* ========== 2) อัปเดต order_status ========== */
    if ($type === 'order_status') {
        // รองรับ cancel_requested ด้วย (ถ้าจำเป็นต้องตั้งเองจากฝั่งแอดมิน)
        if ($oldOrdStatus !== $val) {
          $up = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
          $up->bind_param("si", $val, $id);
          $up->execute();
          $up->close();

          $title   = "อัปเดตสถานะคำสั่งซื้อ";
          $statusT = $ORDER_THAI[$val] ?? $val;
          $msg     = "คำสั่งซื้อ #{$id}: {$statusT}";
          $addNotify($userId, 'order_status', $id, $msg, $msg);
        }

        // เสร็จสิ้น/ถึงปลายทาง แล้วยังไม่ตัดสต็อก → ตัดสต็อก
        if (in_array($val, ['delivered','completed'], true) && (int)$order['stock_deducted']===0) {
            $items = $getItems();
            $deduct($items);
            $conn->query("UPDATE orders SET stock_deducted=1 WHERE id={$id}");
        }

        // ยกเลิก และเคยตัดสต็อก → คืนสต็อก
        if ($val==='cancelled' && (int)$order['stock_deducted']===1) {
            $items = $getItems();
            $restore($items);
            $conn->query("UPDATE orders SET stock_deducted=0 WHERE id={$id}");
        }
    }

    /* ========== 3) ตัดสินใจคำขอยกเลิก (approve/reject) ========== */
    if ($type === 'cancel_decision') {
        // อนุญาตให้ทำเฉพาะตอนที่ออเดอร์อยู่ในสถานะ cancel_requested
        if ($oldOrdStatus !== 'cancel_requested') {
            throw new Exception('ออเดอร์ไม่ได้อยู่ในสถานะรอยืนยันยกเลิก');
        }

        if ($val === 'approve') {
            // อนุมัติ: เปลี่ยนเป็น cancelled + คืนสต็อกถ้าเคยตัด
            $up = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=?");
            $up->bind_param("i", $id);
            $up->execute();
            $up->close();

            if ((int)$order['stock_deducted']===1) {
                $items = $getItems();
                $restore($items);
                $conn->query("UPDATE orders SET stock_deducted=0 WHERE id={$id}");
            }

            $title = "คำขอยกเลิกได้รับการอนุมัติ";
            $msg   = "คำสั่งซื้อ #{$id} ถูกยกเลิกเรียบร้อย".($note ? " • เหตุผล: {$note}" : "");
            $addNotify($userId, 'order_status', $id, $title, $msg);

        } elseif ($val === 'reject') {
            // ปฏิเสธ: กลับไปสถานะก่อนหน้า (เลือกเป็น pending ถ้าก่อนหน้าไม่ชัด)
            $newStatus = ($oldOrdStatus === 'cancel_requested') ? 'pending' : $oldOrdStatus;
            $up = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
            $up->bind_param("si", $newStatus, $id);
            $up->execute();
            $up->close();

            $title = "คำขอยกเลิกถูกปฏิเสธ";
            $msg   = "คำสั่งซื้อ #{$id} ยังดำเนินการต่อ".($note ? " • เหตุผล: {$note}" : "");
            $addNotify($userId, 'order_status', $id, $title, $msg);
        } else {
            throw new Exception('ค่าสั่งงานไม่ถูกต้อง (approve/reject)');
        }
    }

    $conn->commit();
    $_SESSION['flash'] = "อัปเดตคำสั่งซื้อ #{$id} เรียบร้อย";
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = "อัปเดตไม่สำเร็จ: ".$e->getMessage();
}

header("Location: $return");
