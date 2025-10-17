<?php
// Home/index.php
require __DIR__ . '/includes/db.php';

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function product_img($row){
  $img = trim($row['image'] ?? '');
  if ($img !== '') {
    $path = 'assets/img/' . ltrim($img, '/');
    if (is_file(__DIR__ . '/' . $path)) return $path;
  }
  return 'assets/img/default.png';
}
// เวลาแบบ time-ago สั้นๆ
function time_ago($ts){
  if(!$ts) return '';
  $t = strtotime($ts); $d = time() - $t;
  if($d<60) return 'เมื่อสักครู่';
  if($d<3600) return floor($d/60).' นาทีที่แล้ว';
  if($d<86400) return floor($d/3600).' ชั่วโมงที่แล้ว';
  if($d<2592000) return floor($d/86400).' วันที่แล้ว';
  return date('Y-m-d', $t);
}
function stars($n){
  $n = max(0,min(5,(int)$n));
  return str_repeat('★',$n).str_repeat('☆',5-$n);
}

/* ---------- สินค้า ---------- */

$dealProducts   = [];

if ($res = $conn->query("
  SELECT id, name, price, discount_price, image, created_at
  FROM products
  WHERE status='active'
  ORDER BY created_at DESC
  LIMIT 8
")) { $latestProducts = $res->fetch_all(MYSQLI_ASSOC); $res->close(); }

if ($res = $conn->query("
  SELECT id, name, price, discount_price, image, created_at
  FROM products
  WHERE status='active'
    AND discount_price IS NOT NULL
    AND discount_price > 0
    AND discount_price < price
  ORDER BY (price - discount_price) / price DESC, created_at DESC
  LIMIT 8
")) { $dealProducts = $res->fetch_all(MYSQLI_ASSOC); $res->close(); }

/* ---------- รีวิวจริงจากลูกค้า (ตาราง product_reviews) ---------- */
$reviews = [];
if ($res = $conn->query("
  SELECT pr.id, pr.rating, pr.content, pr.created_at,
         u.username,
         p.id AS product_id, p.name AS product_name, p.image
  FROM product_reviews pr
  JOIN users u ON u.id = pr.user_id
  JOIN products p ON p.id = pr.product_id
  WHERE pr.parent_id IS NULL      -- เฉพาะโพสต์รีวิวหลัก (ไม่เอารีพลาย)
    AND COALESCE(pr.is_admin,0)=0 -- ไม่ใช่แอดมิน
  ORDER BY pr.created_at DESC
  LIMIT 6
")) { $reviews = $res->fetch_all(MYSQLI_ASSOC); $res->close(); }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>หน้าแรก | WEB APP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* ====== Hero ====== */
    .hero-wrap{
      background: radial-gradient(1200px 400px at 10% -20%, #cfe7ff 0%, transparent 60%),
                  radial-gradient(900px 300px at 110% 0%, #ffe6f0 0%, transparent 60%);
    }
    .hero .carousel-inner img{ object-fit: cover; height: 480px; filter: saturate(105%); }
    .hero .carousel-caption{ backdrop-filter: blur(2px); text-shadow: 0 6px 24px rgba(0,0,0,.25); }

    /* ====== Section Title ====== */
    .sec-head{ display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; }
    .sec-head .line{ flex:1; height:2px; background:linear-gradient(90deg,#dbe7ff,transparent); }

    /* ====== Product Card ====== */
    .p-card{ position:relative; border:1px solid #edf2f7; border-radius:18px; overflow:hidden; background:#fff; transition:.15s ease; }
    .p-card:hover{ transform: translateY(-3px); box-shadow:0 12px 40px rgba(2,6,23,.08); }
    .p-thumb{ aspect-ratio: 1/1; background:#f7fafc; display:flex; align-items:center; justify-content:center; }
    .p-thumb img{ width:100%; height:100%; object-fit:cover; }
    .p-body{ padding:14px; }
    .p-name{ font-weight:600; line-height:1.25; height:3rem; overflow:hidden; }
    .price{ font-size:1.1rem; }
    .price .old{ color:#9aa6b2; text-decoration:line-through; margin-left:.5rem; }
    .ribbon{ position:absolute; top:12px; left:12px; z-index:2; background:#dc2626; color:#fff; padding:4px 10px; border-radius:999px; font-weight:700; font-size:.85rem; box-shadow:0 8px 24px rgba(220,38,38,.35); }
    .ribbon.blue{ background:#2563eb; box-shadow:0 8px 24px rgba(37,99,235,.35); }
    .p-actions{ display:flex; gap:.5rem; }
    .btn-soft{ border:1px solid #e6ecf3; background:#fff; color:#0f172a; }
    .btn-soft:hover{ background:#f1f5f9; }

    /* ====== Feature bar ====== */
    .f-card{ border:1px solid #edf2f7; border-radius:16px; padding:14px 16px; background:#fff; }

    /* ====== รีวิวลูกค้า ====== */
    .t-card{ border:1px solid #edf2f7; border-radius:18px; padding:18px; background:#fff; box-shadow:0 8px 30px rgba(2,6,23,.04); transition:.15s; }
    .t-card:hover{ transform: translateY(-3px); box-shadow:0 12px 42px rgba(2,6,23,.08); }
    .t-stars{ font-size:1.1rem; color:#f59e0b; letter-spacing:1px; }
    .t-name{ font-weight:600; color:#0f172a; }
    .t-meta{ color:#6b7280; font-size:.85rem; }
    .t-prod{ display:flex; align-items:center; gap:.5rem; }
    .t-thumb{ width:44px; height:44px; border-radius:10px; overflow:hidden; background:#f7fafc; border:1px solid #eef2f7; }
    .t-thumb img{ width:100%; height:100%; object-fit:cover; }
    .t-content{ color:#111827; min-height:3.2em; }
    .t-quote{ font-size:2rem; color:#dbeafe; line-height:1; }
    
    /* ====== Newsletter ====== */
    .news-wrap{
      border-radius:20px; padding:28px; background:linear-gradient(135deg,#eef2ff 0%, #ffffff 70%);
      border:1px solid #edf2f7;
    }

    @media (max-width: 991px){ .hero .carousel-inner img{ height: 360px; } }
  </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- ===================== HERO ===================== -->
<section class="hero hero-wrap pt-3 pb-4">
  <div class="container">
    <div id="hero" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-indicators">
        <button type="button" data-bs-target="#hero" data-bs-slide-to="0" class="active"></button>
        <button type="button" data-bs-target="#hero" data-bs-slide-to="1"></button>
        <button type="button" data-bs-target="#hero" data-bs-slide-to="2"></button>
      </div>

      <div class="carousel-inner rounded-4 overflow-hidden shadow-sm">
        <div class="carousel-item active" data-bs-interval="4200">
          <img src="assets/img/hero1.jpg" class="d-block w-100" alt="Slide 1">
          <div class="carousel-caption text-start">
            <h2 class="fw-bold mb-2">ขายดี ซ่อมไว ใส่ใจทุกงาน</h2>
            <p class="mb-3">สินค้าแท้ + บริการครบจบในที่เดียว</p>
            <a href="products.php" class="btn btn-primary btn-lg rounded-pill px-4">ดูสินค้า</a>
          </div>
        </div>
        <div class="carousel-item" data-bs-interval="4200">
          <img src="assets/img/hero2.jpg" class="d-block w-100" alt="Slide 2">
          <div class="carousel-caption">
            <h2 class="fw-bold mb-2">เครื่องตัดหญ้า รุ่นยอดนิยม</h2>
            <p class="mb-3">เบา เงียบ ประหยัดน้ำมัน</p>
            <a href="products.php" class="btn btn-light text-dark btn-lg rounded-pill px-4">ไปหน้าสินค้า</a>
          </div>
        </div>
        <div class="carousel-item" data-bs-interval="4200">
          <img src="assets/img/hero3.jpg" class="d-block w-100" alt="Slide 3">
          <div class="carousel-caption text-end">
            <h2 class="fw-bold mb-2">บริการซ่อมมาตรฐาน</h2>
            <p class="mb-3">เช็กสถานะงานซ่อมได้แบบเรียลไทม์</p>
            <a href="contact.php" class="btn btn-outline-light btn-lg rounded-pill px-4">ติดต่อเรา</a>
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

<!-- ===================== Feature mini cards ===================== -->
<section class="py-3">
  <div class="container">
    <div class="row g-3">
      <div class="col-6 col-md-3"><div class="f-card d-flex align-items-center gap-3">
        <i class="bi bi-truck fs-3 text-primary"></i><div><div class="fw-semibold">ส่งด่วนทั่วไทย</div><div class="text-muted small">แพ็คดี ส่งไว</div></div></div></div>
      <div class="col-6 col-md-3"><div class="f-card d-flex align-items-center gap-3">
        <i class="bi bi-shield-check fs-3 text-success"></i><div><div class="fw-semibold">ของแท้ 100%</div><div class="text-muted small">รับประกันสินค้า</div></div></div></div>
      <div class="col-6 col-md-3"><div class="f-card d-flex align-items-center gap-3">
        <i class="bi bi-headset fs-3 text-danger"></i><div><div class="fw-semibold">ซัพพอร์ตไว</div><div class="text-muted small">แชทถามแอดมินได้</div></div></div></div>
      <div class="col-6 col-md-3"><div class="f-card d-flex align-items-center gap-3">
        <i class="bi bi-tools fs-3 text-warning"></i><div><div class="fw-semibold">บริการซ่อม</div><div class="text-muted small">งานมาตรฐาน</div></div></div></div>
    </div>
  </div>
</section>

<!-- ===================== DEALS ===================== -->
<section class="py-4">
  <div class="container">
    <div class="sec-head">
      <h3 class="fw-bold mb-0"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>ดีลแรงวันนี้</h3>
      <div class="line"></div>
      <a class="text-decoration-none" href="products.php">ดูทั้งหมด</a>
    </div>

    <?php if (empty($dealProducts)): ?>
      <div class="text-center text-muted py-5">ยังไม่มีดีลพิเศษในตอนนี้</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach($dealProducts as $p):
          $hasDiscount = ($p['discount_price']!==null && (float)$p['discount_price']>0 && (float)$p['discount_price'] < (float)$p['price']);
          $price  = $hasDiscount ? (float)$p['discount_price'] : (float)$p['price'];
          $disc   = $hasDiscount ? round(100 - ($p['discount_price'] / $p['price'] * 100)) : 0;
          $img    = product_img($p);
        ?>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="p-card h-100">
            <?php if($disc>0): ?><div class="ribbon">-<?= (int)$disc ?>%</div><?php endif; ?>
            <a class="p-thumb d-block" href="product.php?id=<?= (int)$p['id'] ?>">
              <img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>">
            </a>
            <div class="p-body">
              <div class="p-name mb-1"><a class="stretched-link text-decoration-none text-dark" href="product.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></div>
              <div class="price fw-bold">
                <?= baht($price) ?> ฿
                <?php if($hasDiscount): ?><span class="old"><?= baht($p['price']) ?> ฿</span><?php endif; ?>
              </div>
              <div class="mt-2 p-actions">
                <a href="product.php?id=<?= (int)$p['id'] ?>" class="btn btn-soft w-100"><i class="bi bi-eye"></i> รายละเอียด</a>
                <a href="cart_add.php?id=<?= (int)$p['id'] ?>" class="btn btn-primary w-100"><i class="bi bi-cart-plus"></i> ใส่รถ</a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>


<!-- ===================== REAL CUSTOMER REVIEWS ===================== -->
<section class="py-5" style="background:linear-gradient(180deg,#f8fbff,#fff)">
  <div class="container">
    <div class="sec-head">
      <h3 class="fw-bold mb-0"><i class="bi bi-chat-quote text-success me-1"></i>รีวิวจากลูกค้าจริง</h3>
      <div class="line"></div>
    </div>

    <?php if (empty($reviews)): ?>
      <div class="text-center text-muted py-5">ยังไม่มีรีวิวจากลูกค้า</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach($reviews as $rv):
          $thumb = product_img($rv); // ใช้ image ของสินค้าในแถวเดียวกัน
        ?>
        <div class="col-md-6 col-lg-4">
          <div class="t-card h-100">
            <div class="d-flex justify-content-between">
              <div class="t-stars"><?= stars($rv['rating']) ?></div>
              <div class="t-meta"><?= h(time_ago($rv['created_at'])) ?></div>
            </div>
            <div class="d-flex align-items-start gap-3 mt-2">
              <div class="t-thumb"><img src="<?= h($thumb) ?>" alt="<?= h($rv['product_name']) ?>"></div>
              <div class="t-prod">
                <div>
                  <a href="product.php?id=<?= (int)$rv['product_id'] ?>" class="text-decoration-none fw-semibold">
                    <?= h($rv['product_name']) ?>
                  </a>
                  <div class="t-meta">โดย @<?= h($rv['username']) ?></div>
                </div>
              </div>
            </div>
            <div class="t-content mt-3">
              <span class="t-quote">“</span>
              <?= nl2br(h(mb_strimwidth($rv['content'], 0, 220, '...','UTF-8'))) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ===================== FAQ + NEWSLETTER ===================== -->
<section class="py-4">
  <div class="container">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="sec-head"><h3 class="fw-bold mb-0"><i class="bi bi-question-circle text-primary me-1"></i>คำถามที่พบบ่อย</h3><div class="line"></div></div>
        <div class="accordion" id="faq">
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#f1">จัดส่งใช้เวลากี่วัน?</button></h2>
            <div id="f1" class="accordion-collapse collapse show" data-bs-parent="#faq"><div class="accordion-body">ปกติ 1–3 วันทำการ และมีบริการส่งด่วนในเขตบริการ</div></div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f2">ประกันสินค้าอย่างไร?</button></h2>
            <div id="f2" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body">สินค้ามีประกันตามเงื่อนไขผู้ผลิต และรับซ่อมโดยทีมช่างของเรา</div></div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f3">รับซ่อม/รับเทิร์นไหม?</button></h2>
            <div id="f3" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body">มีบริการซ่อมและรับเทิร์น ติดต่อได้ที่หน้า <a href="service.php">บริการซ่อม</a></div></div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="news-wrap h-100 d-flex flex-column">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-wrench-adjustable fs-3 text-danger"></i>
            <h3 class="fw-bold mb-0">บริการซ่อมมาตรฐาน</h3>
          </div>
          <div class="text-muted mb-3">ติดตามสถานะงานซ่อมได้แบบเรียลไทม์</div>
            <div class="mt-auto">
              <a href="contact.php" class="btn btn-light text-dark rounded-pill px-3"><i class="bi bi-chat-dots"></i> ส่งคำถาม</a>
            </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php include 'assets/html/footer.html'; ?>

</body>
</html>
