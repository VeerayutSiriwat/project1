<?php
// Home/logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ปิด session ปัจจุบันให้สะอาด
 */
$_SESSION = [];

// ลบคุกกี้ของ session (ถ้ามีการใช้งานคุกกี้)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),        // ชื่อคุกกี้ของ PHP session
        '',                    // ค่า
        time() - 42000,        // หมดอายุย้อนหลัง
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ทำลายเซสชันปัจจุบัน
session_destroy();

// สร้าง session id ใหม่ (ป้องกัน fixation) เผื่อมีการเริ่มใช้งานใหม่ในหน้าเป้าหมาย
session_start();
session_regenerate_id(true);

// กำหนดหน้า redirect
$redirect = $_GET['redirect'] ?? 'index.php';

// กัน open redirect แบบง่าย: อนุญาตเฉพาะ path ภายในเว็บ
$path = parse_url($redirect, PHP_URL_PATH);
if (!preg_match('#^/?[A-Za-z0-9/_\-.]*$#', (string)$path)) {
    $redirect = 'index.php';
}

// ส่งกลับหน้าเป้าหมาย
header('Location: ' . $redirect);
exit;
