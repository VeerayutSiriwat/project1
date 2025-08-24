<?php
// Home/index.php
require __DIR__.'/includes/db.php';
function h($s){return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>หน้าแรก | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- Hero Carousel ตรงกลาง -->
<section class="hero py-4">
  <div class="container">
    <div id="hero" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-indicators">
        <button type="button" data-bs-target="#hero" data-bs-slide-to="0" class="active"></button>
        <button type="button" data-bs-target="#hero" data-bs-slide-to="1"></button>
        <button type="button" data-bs-target="#hero" data-bs-slide-to="2"></button>
      </div>
      <div class="carousel-inner rounded-4 overflow-hidden shadow-sm">
        <div class="carousel-item active" data-bs-interval="4000">
          <img src="assets/img/hero1.jpg" class="d-block w-100" alt="Slide 1">
          <div class="carousel-caption text-start">
            <h5 class="mb-1">ขายดี ซ่อมไว ใส่ใจทุกงาน</h5>
            <p class="mb-2">สินค้าแท้ + บริการครบจบในที่เดียว</p>
            <a href="products.php" class="btn btn-primary">ดูสินค้า</a>
          </div>
        </div>
        <div class="carousel-item" data-bs-interval="4000">
          <img src="assets/img/hero2.jpg" class="d-block w-100" alt="Slide 2">
          <div class="carousel-caption">
            <h5 class="mb-1">เครื่องตัดหญ้า รุ่นยอดนิยม</h5>
            <p class="mb-2">เบา เสียงเงียบ ประหยัดน้ำมัน</p>
            <a href="products.php?cat=1" class="btn btn-light text-dark">หมวดเครื่องตัดหญ้า</a>
          </div>
        </div>
        <div class="carousel-item" data-bs-interval="4000">
          <img src="assets/img/hero3.jpg" class="d-block w-100" alt="Slide 3">
          <div class="carousel-caption text-end">
            <h5 class="mb-1">บริการซ่อมมาตรฐาน</h5>
            <p class="mb-2">เช็กสถานะงานซ่อมได้แบบเรียลไทม์</p>
            <a href="#" class="btn btn-outline-light">ดูรายละเอียด</a>
          </div>
        </div>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#hero" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#hero" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
      </button>
    </div>
  </div>
</section>

<section class="py-5">
  <div class="container text-center">
    <h3 class="fw-bold mb-3">ยินดีต้อนรับ</h3>
    <p class="text-muted mb-4">เลือกชมสินค้าในร้านของเรา หรือส่งซ่อมอุปกรณ์ของคุณได้ง่าย ๆ</p>
    <a href="products.php" class="btn btn-primary btn-lg"><i class="bi bi-shop"></i> ไปหน้าสินค้า</a>
  </div>
</section>

<?php include 'assets/html/footer.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
