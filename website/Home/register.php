<?php
// Home/register.php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require __DIR__ . '/includes/db.php';
require_once __DIR__.'/includes/google_config.php';

/* ---- ตั้งค่า redirect ให้เสร็จก่อน ---- */
$redirect = $_GET['redirect'] ?? 'index.php';
// ป้องกัน open redirect
$path = parse_url('/' . ltrim($redirect, '/'), PHP_URL_PATH);
if (!preg_match('#^/[A-Za-z0-9/_\-.]*$#', (string)$path)) {
    $redirect = 'index.php';
}

/* ---- เตรียม Google Auth ---- */
$gclient = make_google_client();
$_SESSION['oauth2state_token'] = bin2hex(random_bytes(16));
$statePayload = [
  't' => $_SESSION['oauth2state_token'], // token กัน CSRF
  'redirect' => $redirect,               // redirect หลังสมัครสำเร็จ
  'from' => 'register'                   // มาจากหน้า register
];
$state = rtrim(strtr(base64_encode(json_encode($statePayload)), '+/', '-_'), '=');
$googleAuthUrl = $gclient->createAuthUrl() . '&state=' . urlencode($state);

$error = '';
$success = '';

// ถ้าล็อกอินอยู่แล้ว ไม่ต้องสมัครซ้ำ
if (!empty($_SESSION['user_id'])) {
  header('Location: '.$redirect);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $redirect = $_POST['redirect'] ?? $redirect;

  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $confirm  = trim($_POST['confirm'] ?? '');

  // ตรวจความถูกต้องเบื้องต้น
  if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'อีเมลไม่ถูกต้อง';
  } elseif (strlen($username) < 3) {
    $error = 'ชื่อผู้ใช้ต้องมีอย่างน้อย 3 ตัวอักษร';
  } elseif (strlen($password) < 6) {
    $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
  } elseif ($password !== $confirm) {
    $error = 'รหัสผ่านยืนยันไม่ตรงกัน';
  } else {
    // ตรวจซ้ำ username / email
    $stmt = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();

    if ($dup) {
      $error = 'ชื่อผู้ใช้หรืออีเมลถูกใช้แล้ว';
    } else {
      // บันทึกผู้ใช้ใหม่
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $role = 'user';
      $is_active = 1;

      $stmt = $conn->prepare("
        INSERT INTO users (username, email, password_hash, role, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
      ");
      $stmt->bind_param("ssssi", $username, $email, $hash, $role, $is_active);

      if ($stmt->execute()) {
        // สมัครสำเร็จ → ล็อกอินอัตโนมัติ
        $_SESSION['user_id'] = $stmt->insert_id;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;

        header("Location: " . $redirect);
        exit;
      } else {
        $error = 'ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่อีกครั้ง';
      }
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>สมัครสมาชิก | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
          <h4 class="mb-3 fw-bold text-center text-primary">
            <i class="bi bi-person-plus"></i> สมัครสมาชิก
          </h4>

          <?php if($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <form method="post" novalidate>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
              <label class="form-label">ชื่อผู้ใช้</label>
              <input type="text" name="username" class="form-control" minlength="3" required value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">อีเมล</label>
              <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">รหัสผ่าน</label>
              <input type="password" name="password" class="form-control" minlength="6" required>
              <div class="form-text">อย่างน้อย 6 ตัวอักษร</div>
            </div>

            <div class="mb-4">
              <label class="form-label">ยืนยันรหัสผ่าน</label>
              <input type="password" name="confirm" class="form-control" minlength="6" required>
            </div>

            <div class="d-grid mb-3">
  <a href="google_start.php?redirect=<?= urlencode($redirect) ?>&from=register"
     class="btn btn-light border">
    <img src="https://developers.google.com/identity/images/g-logo.png" width="18" style="margin-top:-3px">
    &nbsp; สมัครด้วย Google
  </a>
</div>

            <div class="text-center my-2 text-secondary">หรือ</div>

            <div class="d-grid">
              <button type="submit" class="btn btn-success">สมัครสมาชิก</button>
            </div>
          </form>

          <div class="text-center small mt-3">
            มีบัญชีอยู่แล้ว? <a href="login.php?redirect=<?= urlencode($redirect) ?>" class="text-decoration-none">เข้าสู่ระบบ</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
