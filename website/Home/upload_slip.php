<?php 
// Home/upload_slip.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }

// ---------- Notify helpers (NEW) ----------
function notify_admins(mysqli $conn, string $type, int $refId, string $title, string $message): void {
  if ($res = $conn->query("SELECT id FROM users WHERE role='admin'")) {
    $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
    while ($row = $res->fetch_assoc()) {
      $adminId = (int)$row['id'];
      $st->bind_param("isiss", $adminId, $type, $refId, $title, $message);
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

// ---------- รับ order_id ----------
$order_id = (int)($_GET['id'] ?? ($_POST['order_id'] ?? 0));
if ($order_id <= 0) { http_response_code(400); exit('ไม่พบหมายเลขคำสั่งซื้อ'); }

// ---------- ดึงข้อมูลคำสั่งซื้อ + ยอดรวม ----------
$st = $conn->prepare("
  SELECT 
    o.id, o.user_id, o.payment_method, o.payment_status, o.slip_image,
    o.created_at, o.expires_at, COALESCE(o.total_price, SUM(oi.quantity * oi.unit_price)) AS total_amount
  FROM orders o
  LEFT JOIN order_items oi ON oi.order_id = o.id
  WHERE o.id = ? AND o.user_id = ?
  GROUP BY o.id, o.user_id, o.payment_method, o.payment_status, o.slip_image, o.created_at, o.expires_at, o.total_price
  LIMIT 1
");
$st->bind_param("ii", $order_id, $_SESSION['user_id']);
$st->execute();
$order = $st->get_result()->fetch_assoc();
$st->close();

if (!$order) { http_response_code(403); exit('คุณไม่มีสิทธิ์ในคำสั่งซื้อนี้'); }

// ---------- ตั้งค่ากรอบเวลาชำระ ----------
$minutes_window = 15; // สำรองกรณีไม่มี expires_at
$expires_ts = $order['expires_at']
  ? strtotime($order['expires_at'])
  : strtotime($order['created_at'].' +'.$minutes_window.' minutes');
$remaining = max(0, $expires_ts - time());

// ---------- ฟังก์ชัน mark expired (พร้อมแจ้งเตือน) ----------
function mark_expired_if_eligible(mysqli $conn, int $order_id): bool {
  $q = $conn->prepare("
    UPDATE orders
    SET payment_status = 'expired', updated_at = NOW()
    WHERE id = ?
      AND payment_method = 'bank'
      AND payment_status IN ('unpaid','pending')
      AND (slip_image IS NULL OR slip_image = '')
      AND NOW() >= COALESCE(expires_at, DATE_ADD(created_at, INTERVAL 15 MINUTE))
    LIMIT 1
  ");
  $q->bind_param("i", $order_id);
  $ok = $q->execute();
  $aff = $conn->affected_rows;
  $q->close();

  if ($ok && $aff > 0) {
    if ($st = $conn->prepare("SELECT user_id FROM orders WHERE id=? LIMIT 1")) {
      $st->bind_param("i", $order_id);
      $st->execute();
      $uid = (int)($st->get_result()->fetch_assoc()['user_id'] ?? 0);
      $st->close();
      if ($uid > 0) {
        notify_user($conn, $uid, 'payment_status', $order_id, 'หมดเวลาชำระเงิน', "คำสั่งซื้อ #{$order_id} หมดเวลาชำระแล้ว");
        notify_admins($conn, 'order_expired', $order_id, 'ออเดอร์หมดเวลาชำระ', "คำสั่งซื้อ #{$order_id} หมดเวลา (โอนธนาคาร)");
      }
    }
  }
  return $ok && ($aff > 0);
}

// ---------- AJAX: หมดเวลา -> mark expired ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'expire') {
  header('Content-Type: application/json; charset=utf-8');
  if (time() >= $expires_ts) {
    $updated = mark_expired_if_eligible($conn, $order_id);
    echo json_encode(['status' => $updated ? 'ok' : 'noop']);
  } else {
    echo json_encode(['status' => 'noop']);
  }
  exit;
}

// ---------- ถ้าเปิดหน้ามาแล้วหมดเวลา ให้ mark เลย ----------
if ($remaining <= 0) {
  mark_expired_if_eligible($conn, $order_id);
  // รีเฟรชสถานะล่าสุด
  $st = $conn->prepare("
    SELECT id, user_id, payment_method, payment_status, slip_image, created_at, expires_at,
           COALESCE(total_price, 0) AS total_amount
    FROM orders WHERE id = ? AND user_id = ? LIMIT 1
  ");
  $st->bind_param("ii", $order_id, $_SESSION['user_id']);
  $st->execute();
  $order = $st->get_result()->fetch_assoc();
  $st->close();
}

// ---------- พาธรูป QR ----------
$qr_web    = 'assets/img/qr_bank.jpg';
$qr_fs     = __DIR__ . '/assets/img/qr_bank.jpg';
$qr_exists = is_file($qr_fs);

// ---------- โซนอัปโหลด ----------
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'expire') {
  if ($remaining <= 0) {
    $error = 'หมดเวลาการชำระเงิน กรุณาสร้างคำสั่งซื้อใหม่';
  } else {
    if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
      $error = 'กรุณาเลือกไฟล์สลิปให้ถูกต้อง';
    } else {
      $uploadDir = __DIR__ . '/uploads/slips';
      if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
          $error = 'ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้';
        }
      }
      if ($error === '') {
        $maxSize = 8 * 1024 * 1024;
        $allowedExt = ['jpg','jpeg','png','webp','pdf'];
        $file = $_FILES['slip'];

        if ($file['size'] <= 0 || $file['size'] > $maxSize) {
          $error = 'ไฟล์ใหญ่เกินกำหนด (สูงสุด 8MB)';
        } else {
          $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
          if (!in_array($ext, $allowedExt, true)) {
            $error = 'อนุญาตเฉพาะไฟล์: jpg, jpeg, png, webp, pdf';
          } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $okMimes = ['image/jpeg','image/png','image/webp','application/pdf'];
            if (!in_array($mime, $okMimes, true)) {
              $error = 'ชนิดไฟล์ไม่ถูกต้อง';
            } else {
              $rand = bin2hex(random_bytes(4));
              $basename = "slip_{$order_id}_" . time() . "_{$rand}." . $ext;
              $destPath = $uploadDir . '/' . $basename;

              if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
                $error = 'อัปโหลดไฟล์ไม่สำเร็จ (สิทธิ์โฟลเดอร์หรือ path ไม่ถูกต้อง)';
              } else {
                // อัปเดตคำสั่งซื้อ -> pending + เก็บชื่อไฟล์
                $relName = $basename;
                $st2 = $conn->prepare("
                  UPDATE orders
                  SET payment_status = 'pending', slip_image = ?, updated_at = NOW()
                  WHERE id = ? AND user_id = ?
                ");
                $st2->bind_param("sii", $relName, $order_id, $_SESSION['user_id']);
                $st2->execute();
                $st2->close();

                // ยิงแจ้งเตือนหลังอัปโหลดสำเร็จเท่านั้น
                $uid = (int)$_SESSION['user_id'];
                notify_admins(
                  $conn,
                  'slip_uploaded',
                  $order_id,
                  'มีสลิปใหม่รอตรวจสอบ',
                  "คำสั่งซื้อ #{$order_id} จากผู้ใช้ UID {$uid}"
                );
                notify_user(
                  $conn,
                  $uid,
                  'payment_status',
                  $order_id,
                  'รับสลิปแล้ว - รอตรวจสอบ',
                  "คำสั่งซื้อ #{$order_id} กำลังรอตรวจสอบสลิป"
                );

                $success = true;
              }
            }
          }
        }
      }
    }
  }
}

// ---------- ค่าที่ใช้ในตัวจับเวลาใหม่ (ไม่มีวงกลมแล้ว) ----------
$created_ts  = strtotime($order['created_at']);
$expires_ts  = $order['expires_at'] ? strtotime($order['expires_at'])
                                    : ($created_ts + $minutes_window*60);
$now_ts      = time();
$total_all   = max(1, $expires_ts - $created_ts);
$remaining   = max(0, $expires_ts - $now_ts);

// ---------- เวลา redirect หลังอัปโหลดสำเร็จ ----------
$seconds_to_redirect = 8;
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>อัปโหลดสลิปโอนเงิน #<?= (int)$order_id ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* ====== Global / Layout (ฟ้า-ขาว) ====== */
    body.bg-page{
      min-height:100vh;
      margin:0;
      background:
        radial-gradient(circle at top left, #e0f2ff 0, #f5f9ff 45%, #ffffff 80%);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color:#0f172a;
    }
    .page-shell{
      max-width:1120px;
    }
    .page-header{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem 1.5rem;
      align-items:center;
      justify-content:space-between;
      margin-bottom:1.25rem;
    }
    .page-title{
      font-size:1.4rem;
      font-weight:700;
      display:flex;
      align-items:center;
      gap:.5rem;
    }
    .page-title i{
      font-size:1.5rem;
      color:#0d6efd;
    }
    .page-sub{
      font-size:.9rem;
      color:#64748b;
    }
    .page-meta{
      text-align:right;
      font-size:.85rem;
    }
    .page-meta strong{
      display:block;
      font-size:.95rem;
    }
    .page-meta .badge-pill{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      border-radius:999px;
      padding:.25rem .7rem;
      border:1px solid #c7ddff;
      background:#e8f1ff;
      color:#1d4ed8;
      font-weight:600;
      margin-top:.25rem;
    }

    /* ====== Grid หลัก ====== */
    .grid{display:grid;gap:1.25rem;grid-template-columns:1fr}
    @media (min-width:992px){.grid{grid-template-columns:1.1fr .9fr}}

    /* ====== QR Card & Timer ====== */
    .qr-card{
      border-radius:18px;
      border:1px solid #e1ebf7;
      background:linear-gradient(180deg,#ffffff 0%,#f6f9ff 100%);
      overflow:hidden;
      box-shadow:0 16px 40px rgba(15,23,42,.07);
      position:relative;
    }
    .qr-head{
      display:flex;align-items:center;justify-content:space-between;
      padding:14px 18px;
      border-bottom:1px solid #e5edf8;
      background:linear-gradient(90deg,#ffffff,#f3f7ff);
    }
    .qr-badge{
      background:#e2f0ff;
      border:1px solid #bed8ff;
      color:#0b5ed7;
      border-radius:999px;
      padding:.35rem .9rem;
      font-weight:600;
      font-size:.85rem;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }
    .qr-badge i{font-size:1rem;}
    .muted{color:#6b7280}
    .qr-wrap{position:relative;width:100%;max-width:320px;min-height:280px;margin:18px auto 8px; padding-top:22px}
    .qr-img{
      position:relative;
      border-radius:16px;
      background:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      border:1px solid #e3ebf5;
      box-shadow:0 14px 36px rgba(15,23,42,.14);
      min-height:280px;
    }
    .qr-img img{width:100%;height:100%;object-fit:contain;border-radius:14px;padding:8px}
    .disabled-cover{
      position:absolute;inset:0;
      background:rgba(248,250,252,.96);
      display:flex;align-items:center;justify-content:center;
      font-weight:700;
      border-radius:0;
      color:#64748b;
      text-align:center;
    }

    /* ====== Time pill + bar ====== */
    .time-pill{
      position:absolute; left:50%; transform:translateX(-50%);
      top:-14px; background:#0b5ed7; color:#fff;
      padding:.35rem .9rem; border-radius:999px;
      font-weight:700; font-size:.9rem;
      box-shadow:0 6px 20px rgba(13,110,253,.25);
      display:inline-flex; align-items:center; gap:.35rem; z-index:2;
      border:1px solid rgba(255,255,255,.7);
    }
    .time-pill i{font-size:1rem;}
    .timebar{
      height:9px; background:#eef2f7; border:1px solid #e3ebf6;
      border-radius:999px; overflow:hidden;
      box-shadow:inset 0 1px 2px rgba(15,23,42,.05);
      margin-top:10px;
    }
    .timebar-fill{
      height:100%;
      background:linear-gradient(90deg,#0d6efd,#38bdf8);
      width:0%;
      transition:width .25s linear;
    }

    /* ====== Upload Card ====== */
    .card{
      border-radius:18px;
      border:1px solid #e1ebf7;
      box-shadow:0 14px 40px rgba(15,23,42,.06);
    }
    .card-title{
      display:flex;
      align-items:center;
      gap:.5rem;
      font-weight:700;
    }
    .card-title i{
      color:#0d6efd;
      font-size:1.2rem;
    }
    .upload-zone{
      border-radius:14px;
      border:1px dashed #cbd5e1;
      background:radial-gradient(circle at top,#f5f9ff, #ffffff);
      padding:1rem 1.1rem;
    }
    .upload-zone:hover{
      border-style:solid;
      border-color:#93c5fd;
      box-shadow:0 0 0 1px #bfdbfe;
    }
    .upload-hint{
      font-size:.8rem;
      color:#6b7280;
    }
    .order-mini{
      border-radius:14px;
      padding:.85rem .9rem;
      background:#f8fafc;
      border:1px solid #e2e8f0;
      font-size:.87rem;
    }
    .badge-status{
      border-radius:999px;
      padding:.18rem .6rem;
      font-size:.75rem;
      font-weight:600;
    }

    /* ====== ส่วน “อัปโหลดสำเร็จ → รอตรวจสอบ” (เดิม) ====== */
    :root{ --brand: var(--bs-primary, #0d6efd); --brand-2: var(--bs-success, #198754); --accent: var(--bs-info, #0dcaf0); }
    .success-wrap{ display:grid; place-items:center; min-height:60vh; }
    .cardx{
      background:#fff;
      border:1px solid #e1ebf7;
      border-radius:22px;
      overflow:hidden;
      box-shadow:0 24px 70px rgba(15,23,42,.12);
      width:min(900px,94vw);
    }
    .head{
      padding:16px 20px;
      border-bottom:1px solid #eef2f6;
      display:flex;
      gap:12px;
      align-items:center;
      background:linear-gradient(180deg,#ffffff,#f4f8ff);
    }
    .pill{
      margin-left:auto;
      background:#f1f5ff;
      border:1px solid color-mix(in oklab, var(--brand) 30%, #bcd4ff);
      padding:.42rem .8rem;
      border-radius:999px;
      font-weight:600;
      font-size:.8rem;
      display:flex;
      align-items:center;
      gap:.25rem;
    }
    .ring{
      width:150px; height:150px;
      border-radius:50%;
      position:relative;
      background: conic-gradient(var(--brand) 0deg, #e9eef5 0deg);
      display:grid; place-items:center;
      animation:sweep 1.1s ease-out forwards;
      box-shadow:0 0 0 8px #fff, 0 16px 40px color-mix(in oklab, var(--brand) 25%, transparent);
    }
    .ring::after{
      content:""; position:absolute; inset:10px;
      border-radius:50%; background:#fff;
    }
    .check{
      position:relative; z-index:1;
      width:66px; height:66px;
      border-radius:50%; display:grid; place-items:center;
      background: radial-gradient(var(--brand), var(--brand-2));
      color:white; font-size:34px;
      box-shadow:0 10px 26px color-mix(in oklab, var(--brand) 45%, transparent);
      animation:pop .7s cubic-bezier(.2,.7,.2,1.2) .55s both;
    }
    @keyframes sweep{
      from{ background:conic-gradient(var(--brand) 0deg,#e9eef5 0deg);}
      to{ background:conic-gradient(var(--brand) 360deg,#e9eef5 360deg);}
    }
    @keyframes pop{
      0%{ transform:scale(.4); opacity:0 }
      60%{ transform:scale(1.08) }
      100%{ transform:scale(1); opacity:1 }
    }
    .progressbar{
      height:8px;
      background:#eef2f7;
      border:1px solid #e5ecf6;
      border-radius:999px;
      overflow:hidden;
    }
    .progressbar > div{
      height:100%; width:0%;
      background:linear-gradient(90deg, var(--brand), var(--accent));
      transition:width .25s linear;
    }
    .confetti{ position:fixed; inset:0; pointer-events:none; overflow:hidden; }
    .confetti span{
      position:absolute; width:10px; height:14px; top:-20px;
      opacity:.95; animation:fall linear forwards; border-radius:2px;
    }
    @keyframes fall{ to{ transform: translateY(110vh) rotate(720deg); } }

    .small-muted{font-size:.8rem;color:#94a3b8;}
  </style>
</head>
<body class="bg-page">
  <div class="page-shell container py-4">
    <div class="page-header">
      <div>
        <div class="page-title">
          <i class="bi bi-receipt-cutoff"></i>
          <span>อัปโหลดสลิปโอนเงิน</span>
        </div>
        <div class="page-sub">
          ช่วยยืนยันการชำระเงินคำสั่งซื้อของคุณให้เสร็จภายในเวลาที่กำหนด
        </div>
      </div>
      <div class="page-meta">
        <strong>คำสั่งซื้อ #<?= (int)$order_id ?></strong>
        <div>ยอดที่ต้องชำระ: <b><?= baht($order['total_amount']) ?> บาท</b></div>
        <span class="badge-pill">
          <i class="bi bi-shield-check"></i>
          ระบบเข้ารหัสสลิปอย่างปลอดภัย
        </span>
      </div>
    </div>

  <?php if ($success): ?>
    <?php $seconds_to_redirect = 8; ?>
    <div class="success-wrap">
      <div class="cardx">
        <div class="head">
          <span class="fw-semibold"><i class="bi bi-check2-circle me-1 text-primary"></i>คำสั่งซื้อ #<?= (int)$order_id ?></span>
          <span class="pill">
            <i class="bi bi-arrow-counterclockwise"></i>
            จะพากลับไป “คำสั่งซื้อของฉัน” ใน <span id="secs"><?= $seconds_to_redirect ?></span> วินาที
          </span>
        </div>
        <div class="p-4 p-md-5 text-center">
          <div class="d-flex justify-content-center mb-4">
            <div class="ring"><div class="check"><i class="bi bi-cloud-upload"></i></div></div>
          </div>
          <h2 class="mb-2">อัปโหลดสลิปเรียบร้อย 🎉</h2>
          <p class="text-muted mb-4">
            ระบบได้รับสลิปแล้ว และตั้งค่าสถานะเป็น <b>รอตรวจสอบ</b><br>
            ยอดสั่งซื้อ <b><?= baht($order['total_amount']) ?> บาท</b>
          </p>
          <div class="progressbar mb-4"><div id="bar"></div></div>
          <div class="d-flex flex-wrap justify-content-center gap-2">
            <a href="my_orders.php" class="btn btn-outline-primary">
              <i class="bi bi-bag-check"></i> ไปหน้าคำสั่งซื้อของฉัน
            </a>
            <a href="order_detail.php?id=<?= (int)$order_id ?>" class="btn btn-outline-secondary">
              <i class="bi bi-receipt"></i> ดูรายละเอียด
            </a>
            <a href="products.php" class="btn btn-primary">
              <i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ
            </a>
          </div>
          <div class="small-muted mt-3">
            คุณจะได้รับการแจ้งเตือนทันทีเมื่อแอดมินตรวจสอบสลิปเสร็จแล้ว
          </div>
        </div>
      </div>
    </div>
    <div class="confetti" id="confetti"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // confetti
      (function(){
        const root = document.getElementById('confetti');
        const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
        const colors = [css('--bs-primary')||'#0d6efd', css('--bs-success')||'#198754', css('--bs-info')||'#0dcaf0', '#f59e0b', '#ef4444'];
        const N = 90;
        for(let i=0;i<N;i++){
          const s = document.createElement('span');
          s.style.background = colors[Math.floor(Math.random()*colors.length)];
          s.style.left = Math.random()*100 + 'vw';
          s.style.transform = `translateY(-40px) rotate(${Math.random()*360}deg)`;
          s.style.animationDuration = (3+Math.random()*2) + 's';
          s.style.animationDelay = (Math.random()*0.8) + 's';
          root.appendChild(s);
          setTimeout(()=>s.remove(), 6000);
        }
      })();
      // countdown + progress + redirect
      (function(){
        const secsEl = document.getElementById('secs');
        const bar = document.getElementById('bar');
        const total = <?= (int)$seconds_to_redirect ?>;
        let left = total;
        const render = ()=>{
          secsEl.textContent = left;
          bar.style.width = (Math.max(0,(total-left)/total)*100) + '%';
        };
        render();
        const t = setInterval(()=>{
          left = Math.max(0, left-1);
          render();
          if(left===0){ clearInterval(t); location.href='my_orders.php'; }
        }, 1000);
      })();
    </script>

  <?php else: ?>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="grid">
      <!-- ซ้าย: QR + Countdown -->
      <div class="qr-card">
        <div class="qr-head">
          <div>
            <div class="fw-bold">โอนผ่านธนาคาร (QR)</div>
            <div class="small muted">เวลาชำระ: <?= (int)$minutes_window ?> นาที (นับจากสร้างคำสั่งซื้อ)</div>
          </div>
          <span class="qr-badge">
            <i class="bi bi-hash"></i>
            #<?= (int)$order_id ?>
          </span>
        </div>

        <div class="p-3 position-relative" id="qrBox">
          <div class="qr-wrap">

            <!-- แคปซูลแสดงเวลาที่เหลือ -->
            <div class="time-pill" id="timePill">
              <i class="bi bi-clock-history"></i>
              เหลือเวลา <span id="mm">--</span>:<span id="ss">--</span> นาที
            </div>

            <!-- QR -->
            <div class="qr-img">
              <?php if ($order['payment_method'] !== 'bank'): ?>
                <div class="text-center p-3">
                  <div class="fw-bold mb-1">ออเดอร์นี้ไม่ได้เลือกชำระด้วยการโอน</div>
                  <div class="small muted">วิธีที่เลือก: <?= h($order['payment_method']) ?></div>
                </div>
              <?php elseif (!$qr_exists): ?>
                <div class="text-center p-3">
                  <div class="fw-bold mb-1 text-danger">ไม่พบรูป QR ของร้าน</div>
                  <div class="small muted">วางไฟล์ไว้ที่ <code>Home/assets/img/qr_bank.jpg</code></div>
                </div>
              <?php elseif ($remaining <= 0): ?>
                <div class="text-center p-3">
                  <div class="fw-bold mb-1">หมดเวลาการชำระเงิน</div>
                  <div class="small muted">กรุณาสร้างคำสั่งซื้อใหม่</div>
                </div>
              <?php else: ?>
                <img id="qrImg" src="<?= h($qr_web) ?>" alt="QR สำหรับชำระเงิน">
              <?php endif; ?>
            </div>

            <!-- Progress bar -->
            <div class="timebar mt-3" aria-label="progress">
              <div class="timebar-fill" id="timebarFill" style="width:0%"></div>
            </div>

          </div>

          <?php if ($remaining <= 0): ?>
            <div class="disabled-cover">
              <div>
                <div class="mb-1"><i class="bi bi-hourglass-split me-1"></i>หมดเวลาการชำระ</div>
                <div class="small-muted">กรุณาสร้างคำสั่งซื้อใหม่จากหน้า “คำสั่งซื้อของฉัน”</div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="px-3 pb-3 small muted">
          * เมื่อเวลาหมด QR จะหายไปและไม่สามารถอัปโหลดสลิปได้
        </div>
      </div>

      <!-- ขวา: อัปโหลดสลิป -->
      <div class="card">
        <div class="card-body">
          <h5 class="card-title mb-3">
            <i class="bi bi-cloud-arrow-up"></i>
            อัปโหลดสลิปโอนเงิน
          </h5>

          <div class="order-mini mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span><i class="bi bi-tag me-1 text-primary"></i>ยอดที่ต้องชำระ</span>
              <strong class="text-primary"><?= baht($order['total_amount']) ?> บาท</strong>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small text-muted">
                วิธีชำระ: <?= $order['payment_method']==='bank' ? 'โอนผ่านธนาคาร' : 'เก็บเงินปลายทาง' ?>
              </span>
              <span class="badge-status bg-light text-secondary border">
                <?php
                  echo $order['payment_status']==='unpaid'  ? 'ยังไม่ชำระ' :
                       ($order['payment_status']==='pending' ? 'รอตรวจสอบ' :
                       ($order['payment_status']==='paid'    ? 'ชำระแล้ว' :
                       ($order['payment_status']==='expired' ? 'หมดเวลาชำระ' :
                       ($order['payment_status']==='refunded'?'คืนเงินแล้ว' : h($order['payment_status'])))));
                ?>
              </span>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" id="slipForm">
            <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">

            <div class="upload-zone mb-3">
              <label class="form-label mb-1 fw-semibold">
                เลือกรูปสลิป / ไฟล์สลิป
                <span class="text-danger">*</span>
              </label>
              <input type="file" name="slip" id="slipInput" class="form-control"
                     accept=".jpg,.jpeg,.png,.webp,.pdf,image/*" required <?= $remaining<=0?'disabled':'' ?>>
              <div class="d-flex justify-content-between mt-1 upload-hint">
                <span><i class="bi bi-info-circle me-1"></i>รองรับ: jpg, jpeg, png, webp, pdf</span>
                <span>ขนาดไม่เกิน 8MB</span>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-2">
              <button id="btnUpload" class="btn btn-success px-3" type="submit" <?= $remaining<=0?'disabled':'' ?>>
                <i class="bi bi-cloud-upload me-1"></i>อัปโหลดสลิป
              </button>
              <a href="my_orders.php" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i>ยกเลิกและกลับไปหน้าคำสั่งซื้อ
              </a>
            </div>

            <div class="small text-muted">
              หลังอัปโหลดแล้ว ระบบจะเปลี่ยนสถานะเป็น <b>รอตรวจสอบ</b> และแจ้งเตือนให้คุณอัตโนมัติเมื่อแอดมินตรวจเสร็จ
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const pm      = <?= json_encode($order['payment_method']) ?>;
      let remaining = <?= (int)$remaining ?>;
      const total   = <?= (int)$total_all ?>;

      const mm        = document.getElementById('mm');
      const ss        = document.getElementById('ss');
      const fill      = document.getElementById('timebarFill');
      const slipInput = document.getElementById('slipInput');
      const btnUpload = document.getElementById('btnUpload');
      const qrBox     = document.getElementById('qrBox');

      if (!mm || !ss || !fill) return;

      function render(){
        const m = Math.floor(remaining/60);
        const s = remaining%60;
        mm.textContent = String(m).padStart(2,'0');
        ss.textContent = String(s).padStart(2,'0');

        const pct = Math.min(100, Math.max(0, ((total - remaining)/total)*100));
        fill.style.width = pct + '%';

        if (remaining <= 0){
          // ล็อกอัปโหลด + ปิด QR
          if (slipInput) slipInput.disabled = true;
          if (btnUpload){ btnUpload.disabled = true; btnUpload.classList.add('disabled'); }
          document.getElementById('qrImg')?.remove();
          const cover = document.createElement('div');
          cover.className = 'disabled-cover';
          cover.innerHTML = '<div><div class="mb-1"><i class="bi bi-hourglass-split me-1"></i>หมดเวลาการชำระ</div><div class="small-muted">กรุณาสร้างคำสั่งซื้อใหม่จากหน้า “คำสั่งซื้อของฉัน”</div></div>';
          qrBox?.appendChild(cover);

          // แจ้งหมดอายุไปที่เซิร์ฟเวอร์
          fetch(location.pathname + '?id=' + <?= (int)$order_id ?>, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ action:'expire', order_id:'<?= (int)$order_id ?>' })
          }).catch(()=>{});
          clearInterval(tick);
        }
      }

      // แสดงค่าตั้งต้น
      render();

      // เดินเวลาเฉพาะกรณีโอนธนาคาร
      if (pm !== 'bank') return;
      const tick = setInterval(()=>{ remaining = Math.max(0, remaining - 1); render(); }, 1000);
    })();
    </script>
  <?php endif; ?>
  </div>
</body>
</html>
