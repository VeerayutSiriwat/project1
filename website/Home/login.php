<?php
// login.php
$error ??= ''; $success ??= ''; $redirect ??= 'index.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/includes/db.php';

require_once __DIR__.'/includes/google_config.php';
$gclient = make_google_client();

require_once __DIR__.'/includes/google_config.php';
$gclient = make_google_client();

/* ตั้งค่า redirect ของหน้านี้ */
$redirect = $_GET['redirect'] ?? 'index.php';
$path = parse_url('/' . ltrim($redirect, '/'), PHP_URL_PATH);
if (!preg_match('#^/[A-Za-z0-9/_\-.]*$#', (string)$path)) {
    $redirect = 'index.php';
}

/* ทำ state: token + redirect + source */
$_SESSION['oauth2state_token'] = bin2hex(random_bytes(16));
$statePayload = [
  't' => $_SESSION['oauth2state_token'],
  'redirect' => $redirect,
  'from' => 'login',
];
$state = rtrim(strtr(base64_encode(json_encode($statePayload)), '+/', '-_'), '=');

/* URL ไปขอสิทธิ์ */
$googleAuthUrl = $gclient->createAuthUrl() . '&state=' . urlencode($state);


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
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    :root{
      --bg1:#0f172a; /* slate-900 */
      --bg2:#111827; /* gray-900 */
      --primary:#6366f1; /* indigo-500 */
      --primary-600:#4f46e5;
      --ring: rgba(99,102,241,.35);
      --card-bg: rgba(255,255,255,.08);
      --card-border: rgba(255,255,255,.15);
      --text:#e5e7eb;
      --muted:#a1a1aa;
    }
    html,body{height:100%}
    body{
      font-family:'Prompt', system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,'Apple Color Emoji','Segoe UI Emoji';
      color:var(--text);
      background:
        radial-gradient(1200px 600px at 10% -10%, #1d4ed8 0%, transparent 60%),
        radial-gradient(1000px 500px at 110% 10%, #7c3aed 0%, transparent 55%),
        radial-gradient(900px 500px at 50% 120%, #0ea5e9 0%, transparent 55%),
        linear-gradient(180deg,var(--bg1),var(--bg2));
      background-attachment: fixed;
    }

    /* Glass card */
    .auth-wrap{
      position:relative;
      min-height: calc(100vh - 64px);
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap: clamp(24px, 4vw, 48px);
      align-items:center;
      padding: clamp(16px, 4vw, 48px) 0;
    }
    @media (max-width: 991.98px){
      .auth-wrap{grid-template-columns: 1fr}
      .brand-side{display:none}
    }

    .brand-side{ position:relative; }
    .brand-card{
      border-radius: 28px;
      background: linear-gradient(160deg, rgba(255,255,255,.12), rgba(255,255,255,.06));
      border: 1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(12px);
      box-shadow: 0 20px 60px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.15);
      overflow:hidden;
    }
    .brand-hero{
      aspect-ratio: 16/10;
      background:
        radial-gradient(600px 300px at 80% 20%, rgba(255,255,255,.18), transparent 60%),
        linear-gradient(135deg, rgba(99,102,241,.25), rgba(14,165,233,.25)),
        url('assets/img/ser_log.png') center/cover no-repeat;
      filter: saturate(1.05) contrast(1.02);
      position:relative;
    }
    .brand-hero::after{
      content:"";
      position:absolute; inset:0;
      background: radial-gradient(800px 280px at -10% 110%, rgba(124,58,237,.20), transparent 60%);
    }
    .brand-body{ padding: clamp(18px, 2.4vw, 30px); }
    .badge-soft{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px;
      border-radius:999px;
      background: rgba(99,102,241,.18);
      border:1px solid rgba(99,102,241,.35);
      color:#e0e7ff;
      font-weight:600; font-size: .9rem;
    }

    .glass-card{
      border-radius: 28px;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      box-shadow: 0 24px 80px rgba(2,6,23,.45), inset 0 1px 0 rgba(255,255,255,.08);
      overflow:hidden;
    }
    .card-head{ padding: clamp(18px,2.4vw,28px) clamp(18px,2.4vw,28px) 0; }
    .card-body{
      padding: clamp(18px,2.4vw,28px);
      padding-top: 12px;
    }

    .title{
      font-weight: 800;
      letter-spacing: .2px;
      background: linear-gradient(180deg,#fff, #c7d2fe);
      -webkit-background-clip: text;
      background-clip:text;
      color: transparent;
      text-align:center;
    }
    .subtitle{ text-align:center; color: var(--muted); margin-top:.25rem; }

    /* Floating labels */
    .form-floating>.form-control,
    .form-floating>.form-control-plaintext{
      background: rgba(0,0,0,.25);
      border:1px solid rgba(255,255,255,.18);
      color:#fff;
    }
    .form-floating>.form-control:focus{
      border-color: var(--ring);
      box-shadow: 0 0 0 .25rem var(--ring);
      background: rgba(0,0,0,.28);
      color:#fff;
    }
    .form-floating label{ color:#cbd5e1; }
    .input-group .btn-ghost{
      background: rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.18);
      color:#e5e7eb;
    }
    .input-group .btn-ghost:hover{ background: rgba(255,255,255,.12); }

    /* Primary button */
    .btn-primary{
      background: linear-gradient(180deg, var(--primary), var(--primary-600));
      border: 0;
      box-shadow: 0 10px 30px rgba(79,70,229,.45);
      padding:.8rem 1rem;
      font-weight:700;
      letter-spacing:.2px;
    }
    .btn-primary:hover{ filter: brightness(1.08); transform: translateY(-1px); }
    .btn-primary:active{ transform: translateY(0); }

    /* Google button */
    .btn-google{
      background: rgba(255,255,255,.9);
      color:#111827;
      border:1px solid rgba(0,0,0,.08);
      display:flex; align-items:center; justify-content:center; gap:10px;
      font-weight:600;
    }
    .btn-google:hover{ background:#fff; }

    /* Password strength */
    .strength{
      height: 8px; border-radius: 999px; background: rgba(255,255,255,.14);
      overflow:hidden;
    }
    .strength>span{
      display:block; height:100%; width:0%;
      transition: width .35s ease;
      background: linear-gradient(90deg,#ef4444,#f59e0b,#22c55e);
    }
    .helper{ color:#cbd5e1; font-size:.9rem }

    /* Divider */
    .divider{
      display:flex; align-items:center; gap:12px; color:#cbd5e1;
    }
    .divider::before, .divider::after{
      content:""; flex:1; height:1px; background: rgba(255,255,255,.18);
    }

    /* Toast position */
    .toast-container{ z-index: 1080; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">

<div class="container auth-wrap">
  <!-- Left showcase / brand -->
  <div class="brand-side">
    <div class="brand-card">
      <div class="brand-hero"></div>
      <div class="brand-body">
        <span class="badge-soft">
          <i class="bi bi-shield-check"></i> ปลอดภัยด้วยการเข้ารหัส SSL/TLS
        </span>
        <h2 class="mt-3 mb-2 fw-bold">ยินดีต้อนรับกลับมา</h2>
        <p class="mb-0 text-secondary">
          เข้าสู่ระบบเพื่อจัดการสินค้า ออเดอร์ และแดชบอร์ดของคุณได้ทันที
        </p>
      </div>
    </div>
  </div>

  <!-- Right login card -->
  <div>
    <div class="text-center mb-3">
</div>
    <div class="glass-card">
      <div class="card-head">
        <h1 class="title h3 mb-1">
          <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ
        </h1>
        <div class="subtitle">เข้าสู่ระบบเพื่อใช้งานระบบร้านค้า</div>
      </div>

      <div class="card-body">
        <?php if (!empty($error ?? '')): ?>
          <!-- Hidden alert (Toast จะเด้งขึ้นแทน) -->
          <div class="alert alert-danger d-none"><?= htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? 'index.php', ENT_QUOTES, 'UTF-8') ?>">

          <div class="form-floating mb-3">
            <input type="text" class="form-control" id="username" name="username" placeholder="username" required autofocus>
            <label for="username"><i class="bi bi-person"></i> ชื่อผู้ใช้</label>
          </div>

          <div class="mb-2">
            <div class="input-group">
              <div class="form-floating flex-grow-1">
                <input type="password" class="form-control" id="password" name="password" placeholder="password" required>
                <label for="password"><i class="bi bi-lock"></i> รหัสผ่าน</label>
              </div>
              <button class="btn btn-ghost" type="button" id="togglePass" aria-label="แสดง/ซ่อนรหัสผ่าน">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between mt-1 helper">
              <small><a href="forgot.php" class="link-light text-decoration-none">ลืมรหัสผ่าน?</a></small>
            </div>
          </div>

          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary">เข้าสู่ระบบ</button>
          </div>

          <div class="divider my-3"><span>หรือ</span></div>

          <a href="google_start.php?redirect=<?= urlencode($redirect ?? 'index.php') ?>&from=login"
             class="btn btn-google w-100 mb-2">
            <!-- Inline G icon -->
            <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.4 29.4 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.5 6.1 28.9 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c10.3 0 19-7.5 19-20 0-1.3-.1-2.7-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.4 18.9 12 24 12c3 0 5.7 1.1 7.8 2.9l5.7-5.7C33.5 6.1 28.9 4 24 4 16.3 4 9.6 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.3 0 10.1-2 13.6-5.2l-6.3-5.2C29.2 35.4 26.8 36 24 36c-5.3 0-9.8-3.4-11.4-8l-6.5 5C9.4 39.6 16.1 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1 3.4-4.4 8-11.3 8-6.6 0-12-5.4-12-12 0-1.3.2-2.7.6-3.9l-6.6-4.8C4.7 17.6 4 20.7 4 24c0 11.1 8.9 20 20 20 10.3 0 19-7.5 19-20 0-1.3-.1-2.7-.4-3.5z"/></svg>
            เข้าสู่ระบบด้วย Google
          </a>

          <hr class="text-white-50 my-3">

          <div class="text-center small text-secondary">
            ยังไม่มีบัญชี? <a href="register.php" class="text-decoration-none fw-semibold">สมัครสมาชิก</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Toast for error -->
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        <?= htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8') ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Show/Hide password
  (function(){
    const pass = document.getElementById('password');
    const toggle = document.getElementById('togglePass');
    if (toggle && pass){
      toggle.addEventListener('click', ()=>{
        const show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        toggle.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      });
    }
  })();

  // Password strength (client hint)
  (function(){
    const input = document.getElementById('password');
    const bar = document.getElementById('strengthBar');
    const text = document.getElementById('strengthText');
    if (!input || !bar || !text) return;

    input.addEventListener('input', ()=>{
      const v = input.value || '';
      let s = 0;
      if (v.length >= 8) s++;
      if (/[A-Z]/.test(v)) s++;
      if (/[a-z]/.test(v)) s++;
      if (/\d/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;

      const pct = [0,25,45,70,100][Math.min(s,4)];
      bar.style.width = pct + '%';

      const label = s<=1 ? 'อ่อน' : s<=3 ? 'ปานกลาง' : 'แข็งแรง';
      text.textContent = 'ความแข็งแรงรหัสผ่าน: ' + label;
    });
  })();

  // Error toast
  (function(){
    const hasError = <?= !empty($error ?? '') ? 'true' : 'false' ?>;
    if (hasError){
      const toastEl = document.getElementById('errorToast');
      const t = new bootstrap.Toast(toastEl, { delay: 4000 });
      t.show();
    }
  })();
</script>
</body>
</html>
