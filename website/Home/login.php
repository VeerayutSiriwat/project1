<?php
// login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/includes/db.php';

/* -----------------------------
   ค่าตั้งต้น / รับ redirect
------------------------------ */
$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = $_POST['redirect'] ?? $redirect;
}

/* ป้องกัน open redirect แบบง่าย */
$path = parse_url('/' . ltrim($redirect, '/'), PHP_URL_PATH);
if (!preg_match('#^/[A-Za-z0-9/_\-.]*$#', (string)$path)) {
    $redirect = 'index.php';
}

/* -----------------------------
   ถ้าเข้าสู่ระบบอยู่แล้ว → ส่งตาม role
------------------------------ */
if (!empty($_SESSION['user_id'])) {
    $to = (($_SESSION['role'] ?? '') === 'admin') ? 'admin/dashboard.php' : 'index.php';
    header("Location: {$to}");
    exit;
}

/* -----------------------------
   เมื่อ submit ฟอร์ม
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();

            if ((int)$user['is_active'] !== 1) {
                $error = 'บัญชีนี้ถูกปิดการใช้งาน';
            } elseif (password_verify($password, $user['password_hash'])) {
                // สำเร็จ → set session
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                // เลือกปลายทางตาม role
                $to = ($user['role'] === 'admin') ? 'admin/dashboard.php' : $redirect;
                header("Location: {$to}");
                exit;
            } else {
                $error = 'รหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $error = 'ไม่พบบัญชีผู้ใช้';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เข้าสู่ระบบ | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">

<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
          <h4 class="mb-3 fw-bold text-center text-primary">
            <i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ
          </h4>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <form method="post" novalidate>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
              <label class="form-label">ชื่อผู้ใช้</label>
              <input type="text" name="username" class="form-control" required autofocus>
            </div>

            <div class="mb-3">
              <label class="form-label">รหัสผ่าน</label>
              <input type="password" name="password" class="form-control" required>
            </div>

            <div class="d-grid mb-3">
              <button type="submit" class="btn btn-primary">เข้าสู่ระบบ</button>
            </div>
          </form>

          <div class="text-center small">
            ยังไม่มีบัญชี? <a href="register.php" class="text-decoration-none">สมัครสมาชิก</a>
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
