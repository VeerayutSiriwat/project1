<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$order_id = (int)($_GET['id'] ?? 0);
$seconds_to_redirect = 8; // ปรับเวลาถอยหลังที่นี่ (วินาที)
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>สั่งซื้อสำเร็จ | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      /* ใช้สีจากธีมร้าน (Bootstrap variables); มี fallback เผื่อไม่ตั้งค่า */
      --brand: var(--bs-primary, #0d6efd);
      --brand-2: var(--bs-success, #198754);
      --ring: var(--bs-primary, #0d6efd);
      --accent: var(--bs-info, #0dcaf0);
    }
    body{
      min-height:100vh; margin:0; display:grid; place-items:center;
      background:
        radial-gradient(900px 500px at 0% 100%, color-mix(in oklab, var(--brand) 10%, transparent), transparent 60%),
        radial-gradient(1200px 600px at 100% 0%, color-mix(in oklab, var(--accent) 12%, transparent), transparent 60%),
        #f7f9fc;
      color:#1f2937;
      font-feature-settings:"liga" 1;
    }
    .wrap{ width:min(980px,94vw); }
    .cardx{
      background:#fff; border:1px solid #e9eef5; border-radius:20px; overflow:hidden;
      box-shadow:0 20px 60px rgba(16,24,40,.08);
    }
    .head{
      padding:18px 20px; border-bottom:1px solid #eef2f6;
      display:flex; align-items:center; gap:14px;
      background:linear-gradient(180deg,#ffffff,#fafcff);
    }
    .brand-chip{
      display:flex; align-items:center; gap:.5rem;
      background: color-mix(in oklab, var(--brand) 8%, #fff);
      color: color-mix(in oklab, var(--brand) 90%, #000);
      border:1px solid color-mix(in oklab, var(--brand) 25%, #cfe2ff);
      padding:.3rem .7rem; border-radius:999px; font-weight:600;
    }
    .logo{
      height:24px; width:auto; display:none; /* แสดงเมื่อโหลดสำเร็จ (JS เปิดให้) */
    }

    /* วงแหวนสำเร็จ */
    .ring{
      width:160px; height:160px; border-radius:50%; position:relative;
      background: conic-gradient(var(--ring) 0deg, #e9eef5 0deg);
      display:grid; place-items:center; animation:sweep 1.1s ease-out forwards;
      box-shadow:0 0 0 8px #fff, 0 16px 40px color-mix(in oklab, var(--brand) 25%, transparent);
    }
    .ring::after{ content:""; position:absolute; inset:10px; border-radius:50%; background:#fff; }
    .check{
      position:relative; z-index:1; width:76px; height:76px; border-radius:50%;
      display:grid; place-items:center; background: radial-gradient(var(--brand), var(--brand-2));
      color:white; font-size:38px; box-shadow:0 10px 26px color-mix(in oklab, var(--brand) 45%, transparent);
      animation:pop .7s cubic-bezier(.2,.7,.2,1.2) .55s both;
    }
    @keyframes sweep{
      from{ background: conic-gradient(var(--ring) 0deg, #e9eef5 0deg); }
      to{   background: conic-gradient(var(--ring) 360deg, #e9eef5 360deg); }
    }
    @keyframes pop{
      0%{ transform:scale(.4) rotate(-18deg); opacity:0 }
      60%{ transform:scale(1.08) }
      100%{ transform:scale(1) rotate(0deg); opacity:1 }
    }

    .title{ font-weight:800; letter-spacing:.2px; color:color-mix(in oklab, var(--brand-2) 60%, #111827); }
    .muted{ color:#6b7280; }

    /* confetti */
    .confetti{ position:fixed; inset:0; pointer-events:none; overflow:hidden; }
    .confetti span{
      position:absolute; width:10px; height:14px; top:-20px; opacity:.95;
      animation:fall linear forwards;
      border-radius:2px;
    }
    @keyframes fall{ to{ transform: translateY(110vh) rotate(720deg); } }

    /* นับถอยหลัง */
    .pill{
      background:#f1f5ff; color: color-mix(in oklab, var(--brand) 80%, #000);
      border:1px solid color-mix(in oklab, var(--brand) 30%, #bcd4ff);
      padding:.42rem .8rem; border-radius:999px; font-weight:600;
    }
    .progressbar{ height:8px; background:#eef2f7; border:1px solid #e5ecf6; border-radius:999px; overflow:hidden; }
    .progressbar > div{
      height:100%; width:0%;
      background:linear-gradient(90deg, var(--brand), var(--accent));
      box-shadow:0 0 18px color-mix(in oklab, var(--brand) 35%, transparent);
      transition:width .25s linear;
    }

    .btn-soft{
      --c: var(--brand);
      color: var(--c);
      background: color-mix(in oklab, var(--c) 6%, #fff);
      border-color: color-mix(in oklab, var(--c) 25%, #cfe2ff);
    }
    .btn-soft:hover{ background: color-mix(in oklab, var(--c) 12%, #fff); }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="cardx">
      <div class="head">
        <div class="brand-chip">
          <img id="logo" class="logo" src="assets/img/logo.png" alt="logo">
          คำสั่งซื้อ #<?= $order_id ?>
        </div>
        <span class="ms-auto pill">จะพากลับไป “คำสั่งซื้อของฉัน” ใน <span id="secs"><?= $seconds_to_redirect ?></span> วินาที</span>
      </div>

      <div class="p-4 p-md-5 text-center">
        <div class="d-flex justify-content-center mb-4">
          <div class="ring"><div class="check"><i class="bi bi-check-lg"></i></div></div>
        </div>

        <h2 class="title mb-2">สั่งซื้อสำเร็จ!</h2>
        <p class="muted mb-4">ขอบคุณสำหรับการสั่งซื้อ เลขที่คำสั่งซื้อของคุณคือ
          <span class="fw-bold text-primary">#<?= $order_id ?></span>
        </p>

        <div class="progressbar mb-4"><div id="bar"></div></div>

        <div class="d-flex flex-wrap justify-content-center gap-2">
          <a href="my_orders.php" class="btn btn-soft border"><i class="bi bi-bag-check"></i> ไปหน้าคำสั่งซื้อของฉัน</a>
          <a href="order_detail.php?id=<?= $order_id ?>" class="btn btn-outline-secondary"><i class="bi bi-receipt"></i> ดูรายละเอียด</a>
          <a href="products.php" class="btn btn-primary"><i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ</a>
        </div>
      </div>
    </div>
  </div>

  <!-- confetti -->
  <div class="confetti" id="confetti"></div>

  <script>
    // โชว์โลโก้ถ้ามีไฟล์
    (function(){
      const img = document.getElementById('logo');
      if(!img) return;
      img.onload = ()=>{ img.style.display='block'; };
      img.onerror = ()=>{ img.remove(); };
    })();

    // confetti เข้ากับธีม (ใช้สี primary/success/info)
    (function confetti(){
      const root = document.getElementById('confetti');
      const getCSS = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
      const colors = [getCSS('--bs-primary')||'#0d6efd', getCSS('--bs-success')||'#198754', getCSS('--bs-info')||'#0dcaf0', '#f59e0b', '#ef4444'];
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

    // นับถอยหลัง + แถบสถานะ + redirect
    (function(){
      const secsEl = document.getElementById('secs');
      const bar = document.getElementById('bar');
      const total = <?= (int)$seconds_to_redirect ?>;
      let left = total;

      function render(){
        secsEl.textContent = left;
        bar.style.width = (Math.max(0,(total-left)/total)*100) + '%';
      }
      render();

      const tick = setInterval(()=>{
        left = Math.max(0, left-1);
        render();
        if(left===0){ clearInterval(tick); location.href='my_orders.php'; }
      }, 1000);
    })();
  </script>
</body>
</html>
