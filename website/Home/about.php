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
  <style>
    :root{ --brand:#0d6efd; --soft:#f5f8ff; }
    .hero{ background:radial-gradient(1200px 400px at 10% -10%,#cfe3ff,transparent),linear-gradient(180deg,#fff,#f8fbff); }
    .hero-card{ border:1px solid #e9eef5;border-radius:20px;box-shadow:0 20px 60px rgba(13,110,253,.1)}
    .value{border-radius:16px;border:1px solid #e9eef5;padding:18px;background:#fff;height:100%}
    .value .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#eef5ff;color:#0d6efd}
    .team img{border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<section class="hero py-5">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-6">
        <h1 class="fw-bold mb-3">เราเชื่อในประสบการณ์ช้อปปิ้งที่ <span class="text-primary">ง่าย เร็ว และจริงใจ</span></h1>
        <p class="text-muted">WEB APP เริ่มต้นจากทีมเล็ก ๆ ที่อยากทำระบบร้านออนไลน์ที่ใช้ง่ายทั้งลูกค้าและผู้ดูแล เราพัฒนาด้วยแนวคิด “เรียบแต่ครบ” และรับฟังเสียงผู้ใช้เสมอ</p>
        <div class="d-flex gap-2 mt-3">
          <a class="btn btn-primary" href="products.php"><i class="bi bi-shop"></i> เริ่มช้อปเลย</a>
          <a class="btn btn-outline-primary" href="contact.php"><i class="bi bi-chat-dots"></i> ติดต่อทีมงาน</a>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="p-4 hero-card bg-white">
          <img src="assets/img/about_hero.svg" class="img-fluid" alt="About">
        </div>
      </div>
    </div>
  </div>
</section>

<section class="py-5">
  <div class="container">
    <h3 class="mb-4">สิ่งที่เรายึดมั่น</h3>
    <div class="row g-3">
      <div class="col-md-4"><div class="value"><div class="ic mb-2"><i class="bi bi-shield-check"></i></div><h5>ปลอดภัย</h5><p class="text-muted mb-0">คัดกรองธุรกรรมและปกป้องข้อมูลผู้ใช้ตามมาตรฐาน</p></div></div>
      <div class="col-md-4"><div class="value"><div class="ic mb-2"><i class="bi bi-lightning-charge"></i></div><h5>รวดเร็ว</h5><p class="text-muted mb-0">ออกแบบให้โหลดไว ใช้งานง่าย ทั้งมือถือและเดสก์ท็อป</p></div></div>
      <div class="col-md-4"><div class="value"><div class="ic mb-2"><i class="bi bi-emoji-smile"></i></div><h5>บริการด้วยใจ</h5><p class="text-muted mb-0">มีทีมซัพพอร์ตช่วยเหลือ ตอบกลับไว</p></div></div>
    </div>
  </div>
</section>

<section class="py-5 bg-light">
  <div class="container">
    <h3 class="mb-4">ทีมของเรา</h3>
    <div class="row g-3 team">
      <div class="col-6 col-md-3 text-center">
        <img src="assets/img/team1.jpg" class="img-fluid mb-2" alt="">
        <div class="fw-semibold">Support</div><div class="text-muted small">ดูแลลูกค้า</div>
      </div>
      <div class="col-6 col-md-3 text-center">
        <img src="assets/img/team2.jpg" class="img-fluid mb-2" alt="">
        <div class="fw-semibold">Ops</div><div class="text-muted small">หลังบ้านระบบ</div>
      </div>
      <div class="col-6 col-md-3 text-center">
        <img src="assets/img/team3.jpg" class="img-fluid mb-2" alt="">
        <div class="fw-semibold">Dev</div><div class="text-muted small">พัฒนาแพลตฟอร์ม</div>
      </div>
      <div class="col-6 col-md-3 text-center">
        <img src="assets/img/team4.jpg" class="img-fluid mb-2" alt="">
        <div class="fw-semibold">Design</div><div class="text-muted small">ประสบการณ์ใช้งาน</div>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
