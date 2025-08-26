<?php
// Home/google_start.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/google_config.php';

$client = make_google_client();

/* รับ redirect และ from */
$redirect = $_GET['redirect'] ?? 'index.php';
$from     = $_GET['from'] ?? 'login';

/* กัน open redirect */
$path = parse_url('/' . ltrim($redirect, '/'), PHP_URL_PATH);
if (!preg_match('#^/[A-Za-z0-9/_\-.]*$#', (string)$path)) {
    $redirect = 'index.php';
}

/* สร้าง state + เก็บ token กัน CSRF */
$_SESSION['oauth2state_token'] = bin2hex(random_bytes(16));
$statePayload = [
  't' => $_SESSION['oauth2state_token'],
  'redirect' => $redirect,
  'from' => $from
];
$state = rtrim(strtr(base64_encode(json_encode($statePayload)), '+/', '-_'), '=');

/* ตั้งค่า state ลงใน client และ redirect ออกไป */
$client->setState($state);
$authUrl = $client->createAuthUrl();

header('Location: ' . $authUrl);
exit;
