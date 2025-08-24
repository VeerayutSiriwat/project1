<?php
/* File: Home/admin/product_delete.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php?redirect=admin/products.php"); exit;
}
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/image_helpers.php'; // << ใช้ช่วยลบไฟล์รูปหลายรูป

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo "Method Not Allowed"; exit; }

/* ตรวจ CSRF */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['flash_error'] = 'CSRF token ไม่ถูกต้อง';
    header("Location: products.php"); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { $_SESSION['flash_error'] = 'รหัสสินค้าไม่ถูกต้อง'; header("Location: products.php"); exit; }

/* ดึงข้อมูลที่จำเป็นก่อนลบ (ชื่อไฟล์รูป) */
$cover = null;
$coverStmt = $conn->prepare("SELECT image FROM products WHERE id=? LIMIT 1");
$coverStmt->bind_param('i', $id);
$coverStmt->execute();
$prod = $coverStmt->get_result()->fetch_assoc();
$coverStmt->close();
if (!$prod) { $_SESSION['flash_error'] = 'ไม่พบสินค้า'; header("Location: products.php"); exit; }
$cover = $prod['image'] ?? null;

/* ดึงรายการรูปในแกลเลอรี่ (จะเอาไว้ลบไฟล์หลัง commit) */
$imgs = [];
$st = $conn->prepare("SELECT filename FROM product_images WHERE product_id=?");
$st->bind_param('i', $id);
$st->execute();
$res = $st->get_result();
while($r = $res->fetch_assoc()){ $imgs[] = $r['filename']; }
$st->close();

/* เตรียมรวมไฟล์ที่จะลบ (กันซ้ำ) */
if (!empty($cover)) $imgs[] = $cover;
$imgs = array_values(array_unique(array_filter($imgs)));

/* เริ่มทรานแซกชัน */
$conn->begin_transaction();
try {
    // ลบรายการเสริมที่อาจอ้างสินค้านี้ (ถ้ามีตารางเหล่านี้)
    // หมายเหตุ: หากฐานข้อมูลคุณตั้ง FK ON DELETE CASCADE แล้ว บล็อกนี้สามารถข้ามได้
    if ($conn->prepare("DELETE FROM cart_items WHERE product_id=?")) {
        $x = $conn->prepare("DELETE FROM cart_items WHERE product_id=?");
        $x->bind_param('i', $id); $x->execute(); $x->close();
    }
    if ($conn->prepare("DELETE FROM order_items WHERE product_id=?")) {
        // ปกติไม่ควรลบ order_items ที่มีในออเดอร์ประวัติแล้ว
        // ถ้าตารางนี้มี FK ห้ามลบ ให้คอมเมนต์บรรทัดนี้ทิ้ง
        // $y = $conn->prepare("DELETE FROM order_items WHERE product_id=?");
        // $y->bind_param('i', $id); $y->execute(); $y->close();
    }

    // ลบรูปในตารางแกลเลอรี่
    $dp = $conn->prepare("DELETE FROM product_images WHERE product_id=?");
    $dp->bind_param('i', $id);
    $dp->execute();
    $dp->close();

    // ลบสินค้า
    $del = $conn->prepare("DELETE FROM products WHERE id=? LIMIT 1");
    $del->bind_param('i', $id);
    $del->execute();
    if ($del->affected_rows < 1) { throw new Exception('ลบสินค้าไม่สำเร็จ'); }
    $del->close();

    $conn->commit();

    // หลัง commit แล้วค่อยลบไฟล์ในดิสก์ (ถ้าพังจะไม่กระทบข้อมูลใน DB)
    $base = __DIR__ . '/../assets/img/';
    foreach ($imgs as $fn) {
        $path = $base . $fn;
        if (is_file($path)) { @chmod($path, 0666); @unlink($path); }
    }

    $_SESSION['flash_success'] = "ลบสินค้า #{$id} และรูปทั้งหมดเรียบร้อยแล้ว";
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // refresh token
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = 'ลบสินค้าไม่สำเร็จ: ' . $e->getMessage();
}

header("Location: products.php"); exit;
