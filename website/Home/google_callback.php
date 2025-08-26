<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/google_config.php';

$client = make_google_client();

/* ตรวจสอบ state กัน CSRF + ดึง redirect */
if (!isset($_GET['state'])) {
    http_response_code(400);
    exit('Missing state');
}
$rawState = $_GET['state'];
$decoded = json_decode(base64_decode(strtr($rawState, '-_', '+/')), true);
if (!is_array($decoded) || empty($decoded['t'])) {
    http_response_code(400);
    exit('Bad state');
}
if (!hash_equals($_SESSION['oauth2state_token'] ?? '', $decoded['t'])) {
    http_response_code(400);
    exit('Invalid OAuth state');
}
unset($_SESSION['oauth2state_token']);

$redirectAfter = $decoded['redirect'] ?? 'index.php';

/* ต้องมี code */
if (!isset($_GET['code'])) {
    http_response_code(400);
    exit('Missing code');
}

/* แลก token */
$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) {
    http_response_code(400);
    exit('OAuth error: ' . htmlspecialchars($token['error']));
}
$client->setAccessToken($token);

/* ดึงข้อมูลโปรไฟล์ */
$oauth2 = new Google_Service_Oauth2($client);
$info   = $oauth2->userinfo->get();  // Google_Service_Oauth2_Userinfoplus

$googleId = $info->id;
$email    = $info->email ?? '';
$name     = $info->name ?? '';
$avatar   = $info->picture ?? '';

/* กันเคสไม่มีอีเมลจาก Google */
if ($email === '') {
    http_response_code(400);
    exit('ไม่พบอีเมลจากบัญชี Google นี้ ไม่สามารถสมัคร/เข้าสู่ระบบได้');
}

/* 1) มี google_id นี้อยู่แล้ว -> เข้าสู่ระบบ */
$stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE google_id = ? LIMIT 1");
$stmt->bind_param('s', $googleId);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
    $u = $res->fetch_assoc();
    if ((int)$u['is_active'] !== 1) { exit('บัญชีถูกปิดการใช้งาน'); }

    // login
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['role'] = $u['role'];

    $to = ($u['role'] === 'admin') ? 'admin/dashboard.php' : $redirectAfter;
    header('Location: ' . $to); exit;
}

/* 2) ยังไม่เคยผูก แต่มี email อยู่แล้ว -> ผูกให้ */
$stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
    $u = $res->fetch_assoc();
    if ((int)$u['is_active'] !== 1) { exit('บัญชีถูกปิดการใช้งาน'); }

    $stmt2 = $conn->prepare("UPDATE users
        SET google_id = ?, provider = 'google',
            full_name = COALESCE(NULLIF(full_name,''), ?),
            avatar    = COALESCE(NULLIF(avatar,''), ?)
        WHERE id = ?");
    $stmt2->bind_param('sssi', $googleId, $name, $avatar, $u['id']);
    $stmt2->execute();

    // login
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['role'] = $u['role'];

    $to = ($u['role'] === 'admin') ? 'admin/dashboard.php' : $redirectAfter;
    header('Location: ' . $to); exit;
}

/* 3) ไม่มีทั้ง google_id และ email -> สร้างผู้ใช้ใหม่ */
function gen_username($seed, mysqli $conn): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9_.-]+/i', '', strstr($seed, '@', true) ?: $seed))) ?: 'user';
    $u = $base; $i = 0;
    $q = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    while (true) {
        $q->bind_param('s', $u);
        $q->execute();
        $r = $q->get_result();
        if (!$r || $r->num_rows === 0) break;
        $i++; $u = $base . $i;
    }
    return $u;
}

$username  = gen_username($email ?: $name ?: 'user', $conn);
$role      = 'user';
$is_active = 1;

$stmt = $conn->prepare("
    INSERT INTO users
    (username, email, password_hash, role, is_active, google_id, provider, full_name, avatar, created_at)
    VALUES (?, ?, NULL, ?, ?, ?, 'google', ?, ?, NOW())
");
$stmt->bind_param('sssisss', $username, $email, $role, $is_active, $googleId, $name, $avatar);
$stmt->execute();

$newId = $conn->insert_id;

// login
$_SESSION['user_id'] = (int)$newId;
$_SESSION['username'] = $username;
$_SESSION['role'] = $role;

header('Location: ' . $redirectAfter);
exit;
