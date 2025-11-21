<?php
// Home/about.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/includes/db.php';
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เกี่ยวกับเรา | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    :root{
      --ink:#0f172a;
      --muted:#6b7280;
      --brand:#0d6efd;
      --brand-light:#e8f0ff;
      --border:#e5e7eb;
    }
    body{
      background:#f8fafc;
      color:var(--ink);
    }
    .hero-sec{
      background:
        radial-gradient(1200px 400px at 10% -10%, #dceafe, transparent),
        linear-gradient(180deg,#ffffff,#f3f7ff);
      padding:5rem 0;
    }
    .hero-img{
      border-radius:22px;
      box-shadow:0 30px 60px rgba(13,110,253,.15);
      border:1px solid #eef3ff;
    }
    .value-box{
      border-radius:18px;
      border:1px solid var(--border);
      background:#fff;
      padding:20px;
      height:100%;
      box-shadow:0 20px 50px rgba(0,0,0,.04);
    }
    .value-icon{
      width:55px;height:55px;border-radius:14px;
      background:#e8f0ff;color:#0d6efd;
      display:flex;align-items:center;justify-content:center;
      font-size:24px;margin-bottom:10px;
    }
    .step{
      text-align:center;
      padding:25px;
      border-radius:18px;
      border:1px solid #e5e7eb;
      background:#fff;
      box-shadow:0 12px 35px rgba(0,0,0,.05);
      height:100%;
    }
    .step .num{
      width:48px;height:48px;border-radius:999px;
      background:#0d6efd;color:#fff;
      display:flex;align-items:center;justify-content:center;
      font-size:20px;margin:0 auto 12px;
    }
    .stat-box{
      border-radius:18px;
      background:#fff;
      padding:30px;
      border:1px solid #e4e6eb;
      box-shadow:0 18px 45px rgba(0,0,0,.05);
      text-align:center;
    }
    .review{
      border-radius:18px;
      border:1px solid #e5e7eb;
      background:#fff;
      padding:20px;
      box-shadow:0 10px 28px rgba(0,0,0,.05);
      height:100%;
    }
    .review .stars{color:#fbbf24;font-size:18px;}
  </style>
</head>
<body>

<?php include __DIR__.'/includes/header.php'; ?>

<!-- ======================================= -->
<!-- HERO SECTION -->
<!-- ======================================= -->
<section class="hero-sec">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h1 class="fw-bold mb-3" style="line-height:1.3;">
          ระบบร้านออนไลน์ที่ทันสมัย  
          <span class="text-primary">ใช้ง่าย ปลอดภัย และไว้ใจได้</span>
        </h1>
        <p class="text-muted mb-3">
          เราพัฒนา WEB APP เพื่อให้ร้านค้าที่ต้องการระบบจัดการสินค้า ออเดอร์ และงานบริการ  
          สามารถใช้งานได้อย่างสะดวกในที่เดียว — พร้อมความปลอดภัยและความเสถียรระดับมืออาชีพ
        </p>
        <a href="products.php" class="btn btn-primary me-2">
          <i class="bi bi-shop"></i> เยี่ยมชมสินค้า
        </a>
        <a href="service.php" class="btn btn-outline-primary">
          <i class="bi bi-tools"></i> บริการของเรา
        </a>
      </div>
      <div class="col-lg-6">
        <img src="assets/img/about_hero.png" class="img-fluid hero-img" alt="">
      </div>
    </div>
  </div>
</section>

<!-- ======================================= -->
<!-- OUR VALUES -->
<!-- ======================================= -->
<section class="py-5">
  <div class="container">
    <h3 class="fw-bold mb-4">สิ่งที่เรายึดมั่น</h3>

    <div class="row g-4">
      <div class="col-md-4">
        <div class="value-box">
          <div class="value-icon"><i class="bi bi-shield-check"></i></div>
          <h5 class="fw-semibold">ปลอดภัยที่สุด</h5>
          <p class="text-muted mb-0">ระบบป้องกันข้อมูลหลายชั้น ไม่เก็บข้อมูลที่ไม่จำเป็น ปลอดภัยทั้งร้านค้าและลูกค้า</p>
        </div>
      </div>

      <div class="col-md-4">
        <div class="value-box">
          <div class="value-icon"><i class="bi bi-lightning-charge"></i></div>
          <h5 class="fw-semibold">เร็วและเสถียร</h5>
          <p class="text-muted mb-0">ระบบออกแบบให้โหลดไว รองรับผู้ใช้จำนวนมาก และใช้งานได้ทุกอุปกรณ์</p>
        </div>
      </div>

      <div class="col-md-4">
        <div class="value-box">
          <div class="value-icon"><i class="bi bi-emoji-smile"></i></div>
          <h5 class="fw-semibold">บริการด้วยใจ</h5>
          <p class="text-muted mb-0">พร้อมช่วยเหลือ ตอบไว และคอยอัปเดตระบบให้ดียิ่งขึ้นตลอดเวลา</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================================= -->
<!-- PROCESS / ขั้นตอนการทำงาน -->
<!-- ======================================= -->
<section class="py-5 bg-light">
  <div class="container">
    <h3 class="fw-bold mb-4">เราทำงานกันอย่างไร?</h3>

    <div class="row g-4">
      <div class="col-md-3">
        <div class="step">
          <div class="num">1</div>
          <h6 class="fw-bold">วิเคราะห์ความต้องการ</h6>
          <p class="text-muted small">เรารับฟังปัญหาและเป้าหมายของร้านคุณอย่างละเอียด</p>
        </div>
      </div>
      <div class="col-md-3">
        <div class="step">
          <div class="num">2</div>
          <h6 class="fw-bold">ออกแบบระบบ</h6>
          <p class="text-muted small">ออกแบบให้ใช้งานง่ายที่สุด ไม่ซับซ้อน ไม่รกตา</p>
        </div>
      </div>
      <div class="col-md-3">
        <div class="step">
          <div class="num">3</div>
          <h6 class="fw-bold">พัฒนาและทดสอบ</h6>
          <p class="text-muted small">ทดสอบจริงทุกขั้นตอนเพื่อความเสถียรของระบบ</p>
        </div>
      </div>
      <div class="col-md-3">
        <div class="step">
          <div class="num">4</div>
          <h6 class="fw-bold">ดูแลหลังใช้งาน</h6>
          <p class="text-muted small">ระบบอัปเดตฟรี และทีมงานพร้อมช่วยตลอดเวลา</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================================= -->
<!-- STATISTICS -->
<!-- ======================================= -->
<section class="py-5">
  <div class="container">
    <h3 class="fw-bold mb-4">ตัวเลขที่บอกคุณภาพของเรา</h3>
    <div class="row g-4">

      <div class="col-md-3">
        <div class="stat-box">
          <h2 class="fw-bold text-primary">+1,200</h2>
          <div class="text-muted">คำสั่งซื้อที่ผ่านระบบ</div>
        </div>
      </div>

      <div class="col-md-3">
        <div class="stat-box">
          <h2 class="fw-bold text-primary">98%</h2>
          <div class="text-muted">ลูกค้าพอใจในการใช้งาน</div>
        </div>
      </div>

      <div class="col-md-3">
        <div class="stat-box">
          <h2 class="fw-bold text-primary">24/7</h2>
          <div class="text-muted">พร้อมช่วยเหลือตลอดเวลา</div>
        </div>
      </div>

      <div class="col-md-3">
        <div class="stat-box">
          <h2 class="fw-bold text-primary">3 ปี</h2>
          <div class="text-muted">ประสบการณ์พัฒนาระบบจริง</div>
        </div>
      </div>

    </div>
  </div>
</section>



<?php include __DIR__.'/assets/html/footer.html'; ?>
</body>
</html>
