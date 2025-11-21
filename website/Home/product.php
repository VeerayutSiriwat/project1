<?php 
// Home/product.php
session_start();
require __DIR__ . '/includes/db.php';

/* ------------ helpers ------------ */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function starHtml($n){
  $n = max(1, min(5, (int)$n));
  $s = '';
  for ($i=1; $i<=5; $i++){
    $s .= '<i class="bi '.($i <= $n ? 'bi-star-fill text-warning' : 'bi-star text-muted').'"></i>';
  }
  return $s;
}
function add_notify(mysqli $conn, int $userId, string $type, int $refId, string $title, string $message): void {
  $st = $conn->prepare("INSERT INTO notifications (user_id, type, ref_id, title, message, is_read) VALUES (?, ?, ?, ?, ?, 0)");
  $st->bind_param("isiss", $userId, $type, $refId, $title, $message);
  $st->execute();
  $st->close();
}

/** รวม path helper */
function resolve_upload_path(string $p): array {
  $p = trim($p);
  if ($p === '') return ['', ''];
  if (preg_match('~^uploads/~', $p)) {
    $rel = $p;
  } else {
    $rel = 'uploads/' . ltrim($p, '/');
  }
  $abs = __DIR__ . '/' . $rel;
  return [$rel, $abs];
}
function avatarPathFromRow(array $row): string {
  if (!empty($row['avatar'])) {
    [$rel, $abs] = resolve_upload_path($row['avatar']);
    if ($rel && is_file($abs)) return $rel;
  }
  if (!empty($row['profile_pic'])) {
    [$rel, $abs] = resolve_upload_path($row['profile_pic']);
    if ($rel && is_file($abs)) return $rel;
  }
  $rel = 'uploads/Default_pfp.svg.png';
  $abs = __DIR__ . '/' . $rel;
  return is_file($abs) ? $rel : 'https://via.placeholder.com/80?text=U';
}

/* ------------ params ------------ */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "<div class='container py-5'><div class='alert alert-danger'>ไม่พบสินค้า</div></div>"; exit; }

$loggedIn = !empty($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? 'user';

/* ------------ product ------------ */
$stmt = $conn->prepare("
  SELECT p.*, c.name AS category_name
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.id = ? AND p.status = 'active'
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) { echo "<div class='container py-5'><div class='alert alert-danger'>ไม่พบสินค้านี้</div></div>"; exit; }

/* ------------ gallery ------------ */
$gallery = [];
$imgStmt = $conn->prepare("SELECT filename, is_cover FROM product_images WHERE product_id=? ORDER BY is_cover DESC, id ASC");
$imgStmt->bind_param("i", $id);
$imgStmt->execute();
$imgRes = $imgStmt->get_result();
while($r = $imgRes->fetch_assoc()){
  if (!empty($r['filename'])) $gallery[] = 'assets/img/'.$r['filename'];
}
$imgStmt->close();
if (empty($gallery)) {
  $gallery[] = $product['image'] ? 'assets/img/'.$product['image'] : 'assets/img/default.png';
}
$hasDiscount = $product['discount_price'] && $product['discount_price'] < $product['price'];
$price = $hasDiscount ? $product['discount_price'] : $product['price'];

/* ------------ can review? ------------ */
$canReview = false;
if ($loggedIn) {
  $st = $conn->prepare("
    SELECT COUNT(*) AS ok
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = ? 
      AND o.status = 'completed'
      AND oi.product_id = ?
    LIMIT 1
  ");
  $st->bind_param("ii", $_SESSION['user_id'], $id);
  $st->execute();
  $canReview = (int)($st->get_result()->fetch_assoc()['ok'] ?? 0) > 0;
  $st->close();
}

/* ------------ CSRF ------------ */
if (empty($_SESSION['csrf_review'])) {
  $_SESSION['csrf_review'] = bin2hex(random_bytes(32));
}
$csrfReview = $_SESSION['csrf_review'];

/* ------------ POST review actions ------------ */
$reviewErrors = [];
$action = null; 
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['review_action'])) {
  if (empty($_POST['csrf_review']) || $_POST['csrf_review'] !== $_SESSION['csrf_review']) {
    $reviewErrors[] = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
  } else {
    $action   = $_POST['review_action'];
    $content  = trim($_POST['content'] ?? '');
    $rating   = (int)($_POST['rating'] ?? 0);
    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if ($action === 'add_review') {
      if (!$loggedIn || !$canReview) { $reviewErrors[] = 'คุณยังไม่มีสิทธิแสดงความคิดเห็นสำหรับสินค้านี้'; }
      if ($rating < 1 || $rating > 5) { $reviewErrors[] = 'กรุณาให้คะแนน 1-5 ดาว'; }
      if ($content === '' || mb_strlen($content) > 2000) { $reviewErrors[] = 'กรุณากรอกความคิดเห็น (ไม่เกิน 2000 ตัวอักษร)'; }
      if (!$reviewErrors) {
        $st = $conn->prepare("
          INSERT INTO product_reviews (product_id, user_id, rating, content, parent_id, is_admin)
          VALUES (?, ?, ?, ?, NULL, 0)
        ");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param("iiis", $id, $uid, $rating, $content);
        $st->execute();
        $st->close();
        header("Location: product.php?id=".$id."#reviews");
        exit;
      }
    }

    if ($action === 'edit_review') {
      if (!$loggedIn) { $reviewErrors[] = 'กรุณาเข้าสู่ระบบ'; }
      if ($rating < 1 || $rating > 5) { $reviewErrors[] = 'กรุณาให้คะแนน 1-5 ดาว'; }
      if ($content === '' || mb_strlen($content) > 2000) { $reviewErrors[] = 'กรุณากรอกความคิดเห็น (ไม่เกิน 2000 ตัวอักษร)'; }
      $reviewId = (int)($_POST['review_id'] ?? 0);
      if (!$reviewErrors && $reviewId>0) {
        $st = $conn->prepare("
          UPDATE product_reviews
          SET rating=?, content=?, updated_at=NOW()
          WHERE id=? AND user_id=? AND parent_id IS NULL
          LIMIT 1
        ");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param("isii", $rating, $content, $reviewId, $uid);
        $st->execute();
        $ok = $st->affected_rows > 0;
        $st->close();
        if ($ok) {
          header("Location: product.php?id=".$id."#review-".$reviewId);
          exit;
        } else {
          $reviewErrors[] = 'ไม่สามารถแก้ไขรีวิวได้';
        }
      }
    }

    if ($action === 'admin_reply') {
      if (($userRole ?? 'user') !== 'admin') { $reviewErrors[] = 'เฉพาะผู้ดูแลระบบเท่านั้น'; }
      if ($content === '' || mb_strlen($content) > 2000) { $reviewErrors[] = 'กรุณากรอกข้อความตอบกลับ'; }
      if (!$parentId) { $reviewErrors[] = 'ไม่พบรีวิวต้นทาง'; }

      if (!$reviewErrors) {
        $ownerId = 0;
        $chk = $conn->prepare("SELECT user_id FROM product_reviews WHERE id=? AND parent_id IS NULL LIMIT 1");
        $chk->bind_param("i", $parentId);
        $chk->execute();
        $ownerId = (int)($chk->get_result()->fetch_assoc()['user_id'] ?? 0);
        $chk->close();

        $st = $conn->prepare("
          INSERT INTO product_reviews (product_id, user_id, rating, content, parent_id, is_admin)
          VALUES (?, ?, 0, ?, ?, 1)
        ");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param("iisi", $id, $uid, $content, $parentId);
        $st->execute();
        $st->close();

        if ($ownerId > 0 && $ownerId !== $uid) {
          $title = "ผู้ดูแลตอบกลับรีวิวของคุณ";
          $msg   = "สินค้า: " . ($product['name'] ?? ('#'.$id)) . " • คลิกเพื่อดูคำตอบ";
          add_notify($conn, $ownerId, 'review_reply', (int)$parentId, $title, $msg);
        }

        header("Location: product.php?id=".$id."#review-".$parentId);
        exit;
      }
    }

    if ($action === 'delete_review') {
      if (!$loggedIn) { $reviewErrors[] = 'กรุณาเข้าสู่ระบบ'; }
      $reviewId = (int)($_POST['review_id'] ?? 0);

      if (!$reviewErrors && $reviewId > 0) {
        $chk = $conn->prepare("SELECT id FROM product_reviews WHERE id=? AND user_id=? AND parent_id IS NULL LIMIT 1");
        $uid = (int)$_SESSION['user_id'];
        $chk->bind_param("ii", $reviewId, $uid);
        $chk->execute();
        $owned = (bool)$chk->get_result()->fetch_row();
        $chk->close();

        if (!$owned) {
          $reviewErrors[] = 'ไม่พบรีวิวของคุณหรือไม่สามารถลบได้';
        } else {
          $conn->begin_transaction();
          try {
            $d1 = $conn->prepare("DELETE FROM product_reviews WHERE parent_id=?");
            $d1->bind_param("i", $reviewId);
            $d1->execute();
            $d1->close();

            $d2 = $conn->prepare("DELETE FROM product_reviews WHERE id=? AND user_id=? AND parent_id IS NULL LIMIT 1");
            $d2->bind_param("ii", $reviewId, $uid);
            $d2->execute();
            $d2->close();

            $conn->commit();
            header("Location: product.php?id=".$id."#reviews");
            exit;
          } catch (Throwable $e) {
            $conn->rollback();
            $reviewErrors[] = 'ลบไม่ได้ กรุณาลองใหม่';
          }
        }
      }
    }
  }
}

/* ------------ load reviews ------------ */
$reviews = [];
$st = $conn->prepare("
  SELECT pr.*, u.username, u.full_name, u.avatar, u.profile_pic
  FROM product_reviews pr
  JOIN users u ON u.id = pr.user_id
  WHERE pr.product_id=? AND pr.parent_id IS NULL
  ORDER BY pr.created_at DESC
");
$st->bind_param("i", $id);
$st->execute();
$res = $st->get_result();
while($row = $res->fetch_assoc()){ $reviews[] = $row; }
$st->close();

$repliesMap = [];
if ($reviews) {
  $ids = implode(',', array_map(fn($r)=> (int)$r['id'], $reviews));
  $sqlRep = "
    SELECT pr.*, u.username, u.full_name, u.avatar, u.profile_pic
    FROM product_reviews pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.product_id={$id} AND pr.parent_id IN ($ids)
    ORDER BY pr.created_at ASC
  ";
  if ($sub = $conn->query($sqlRep)) {
    while($r = $sub->fetch_assoc()){
      $pid = (int)$r['parent_id'];
      $repliesMap[$pid][] = $r;
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title><?=h($product['name'])?> | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body{background:#f6f8fb;}

    /* ---------- gallery shell + auto slide on hover ---------- */
    .gallery-shell{
      position:relative;
      border-radius:18px;
      overflow:hidden;
      background:radial-gradient(circle at top,#e0f2ff,#f9fafb 55%);
      box-shadow:0 16px 40px rgba(15,23,42,.12);
    }
    #mainImg{
      width:100%;
      height:420px;
      object-fit:cover;
      display:block;
      transition:transform .35s ease, opacity .25s ease;
    }
    .gallery-shell:hover #mainImg{
      transform:scale(1.03);
    }
    @media (max-width: 576px){
      #mainImg{height:300px;}
    }

    .thumbs{
      display:flex;
      gap:.5rem;
      margin-top:.75rem;
      flex-wrap:wrap;
    }
    .thumbs .thumb{
      width:72px;
      height:72px;
      border-radius:.6rem;
      overflow:hidden;
      border:2px solid transparent;
      cursor:pointer;
      padding:0;
      background:#fff;
      box-shadow:0 4px 14px rgba(15,23,42,.08);
      transition:border-color .2s, transform .15s, box-shadow .15s;
    }
    .thumbs .thumb img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }
    .thumbs .thumb.active{
      border-color:#0d6efd;
      box-shadow:0 6px 18px rgba(37,99,235,.35);
      transform:translateY(-1px);
    }

    /* dots indicator ในกล่องรูปหลัก */
    .img-dots{
      position:absolute;
      left:50%;
      bottom:10px;
      transform:translateX(-50%);
      display:flex;
      gap:6px;
      padding:4px 10px;
      border-radius:999px;
      background:rgba(15,23,42,.30);
      backdrop-filter:blur(10px);
    }
    .img-dot{
      width:8px;
      height:8px;
      border-radius:999px;
      background:rgba(255,255,255,.45);
      transition:all .2s ease;
    }
    .img-dot.active{
      width:18px;
      background:#ffffff;
    }

    .rv-replies{
      margin-top:.75rem;
      padding-left:1rem;
      border-left:3px solid #eef2f6;
    }

    /* ---------- ปุ่มเพิ่มรถเข็นแบบเด่นๆ ---------- */
    .btn-cart-main{
      position:relative;
      overflow:hidden;
      border-radius:999px;
      padding:.65rem 1.5rem;
      font-weight:700;
      letter-spacing:.02em;
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      background:linear-gradient(135deg,#2563eb,#4f46e5);
      border:0;
      color:#fff;
      box-shadow:0 14px 30px rgba(37,99,235,.45);
      transform:translateY(0);
      transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
    }
    .btn-cart-main .cart-pulse{
      width:8px;
      height:8px;
      border-radius:999px;
      background:#fff;
      box-shadow:0 0 0 0 rgba(255,255,255,.7);
      animation:cartPulse 1.4s infinite;
    }
    .btn-cart-main:hover{
      filter:brightness(1.04);
      box-shadow:0 18px 40px rgba(37,99,235,.55);
      transform:translateY(-1px);
    }
    .btn-cart-main:active{
      transform:translateY(1px) scale(.98);
      box-shadow:0 8px 20px rgba(15,23,42,.35);
    }
    @keyframes cartPulse{
      0%{box-shadow:0 0 0 0 rgba(255,255,255,.8);}
      70%{box-shadow:0 0 0 10px rgba(255,255,255,0);}
      100%{box-shadow:0 0 0 0 rgba(255,255,255,0);}
    }
  </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="container py-5">
  <div class="row g-4">
    <!-- Gallery -->
    <div class="col-md-5">
      <div class="gallery-shell">
        <img id="mainImg" src="<?=h($gallery[0])?>" alt="<?=h($product['name'])?>" class="shadow-sm w-100">
        <?php if(count($gallery) > 1): ?>
          <div class="img-dots">
            <?php foreach($gallery as $i => $_): ?>
              <span class="img-dot <?= $i===0 ? 'active' : '' ?>"></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="mt-2 text-muted small">
        รหัสสินค้า: <?= h($product['product_code'] ?? 'PRD'.$product['id']) ?>
      </div>

      <?php if (count($gallery) > 1): ?>
        <div class="thumbs mt-2">
          <?php foreach($gallery as $i => $src): ?>
            <button type="button" class="thumb <?= $i===0 ? 'active' : '' ?>" data-src="<?= h($src) ?>" aria-label="ภาพที่ <?= $i+1 ?>">
              <img src="<?= h($src) ?>" alt="thumb <?= $i+1 ?>">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Details -->
    <div class="col-md-7">
      <h3 class="mb-1"><?=h($product['name'])?></h3>
      <div class="text-muted mb-3">หมวดหมู่: <?=h($product['category_name'] ?? 'ทั่วไป')?></div>

      <div class="mb-3">
        <?php if ($hasDiscount): ?>
          <div class="fs-3 text-danger fw-bold"><?=baht($product['discount_price'])?> ฿</div>
          <div class="text-muted text-decoration-line-through"><?=baht($product['price'])?> ฿</div>
        <?php else: ?>
          <div class="fs-3 fw-bold"><?=baht($product['price'])?> ฿</div>
        <?php endif; ?>
      </div>

      <div class="mb-3">
        <span class="badge bg-<?= ($product['stock']>0 ? 'success' : 'secondary') ?>">
          <?= $product['stock']>0 ? 'คงเหลือ: '.(int)$product['stock'] : 'หมดสต็อก' ?>
        </span>
      </div>

      <p class="mb-4"><?= nl2br(h($product['description'])) ?></p>

      <?php if ($product['stock'] > 0): ?>
        <div class="d-flex align-items-center gap-2 mb-3">
          <button class="btn btn-outline-secondary btn-sm" id="btnMinus">−</button>
          <input type="number" id="qty" class="form-control text-center" style="width:90px" min="1" max="<?= (int)$product['stock'] ?>" value="1">
          <button class="btn btn-outline-secondary btn-sm" id="btnPlus">+</button>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button class="btn-cart-main" id="btnAdd" data-product-id="<?=$product['id']?>">
            <span class="cart-pulse"></span>
            <i class="bi bi-cart-plus"></i>
            <span>เพิ่มรถเข็น</span>
          </button>
          <a href="#" id="btnBuy" class="btn btn-success">
            <i class="bi bi-bag-check"></i> ซื้อเลย
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Reviews -->
  <hr class="my-5" id="reviews">
  <div class="row">
    <div class="col-lg-7">
      <h4 class="mb-3">รีวิวจากผู้ใช้</h4>

      <?php if ($reviewErrors): ?>
        <div class="alert alert-danger"><?php foreach($reviewErrors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>

      <?php if ($loggedIn && $canReview): ?>
        <form class="card card-body mb-4" method="post">
          <input type="hidden" name="csrf_review" value="<?= h($csrfReview) ?>">
          <input type="hidden" name="review_action" value="add_review">
          <div class="row g-3 align-items-center">
            <div class="col-sm-3">
              <label class="form-label mb-1">ให้คะแนน</label>
              <select class="form-select" name="rating" required>
                <option value="5">★★★★★ (5)</option>
                <option value="4">★★★★☆ (4)</option>
                <option value="3">★★★☆☆ (3)</option>
                <option value="2">★★☆☆☆ (2)</option>
                <option value="1">★☆☆☆☆ (1)</option>
              </select>
            </div>
            <div class="col-sm-9">
              <label class="form-label mb-1">ความคิดเห็น</label>
              <textarea class="form-control" name="content" rows="3" required placeholder="บอกเล่าประสบการณ์ใช้งานจริง..."></textarea>
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-primary"><i class="bi bi-send"></i> ส่งรีวิว</button>
          </div>
          <div class="small text-muted mt-2">* เฉพาะลูกค้าที่สั่งซื้อสินค้าและสถานะ “เสร็จสิ้น” แล้วเท่านั้นจึงจะรีวิวได้</div>
        </form>
      <?php elseif($loggedIn): ?>
        <div class="alert alert-info">สั่งซื้อให้ “เสร็จสิ้น” แล้วจึงจะรีวิวสินค้าได้</div>
      <?php else: ?>
        <div class="alert alert-warning">กรุณาเข้าสู่ระบบเพื่อเขียนรีวิว</div>
      <?php endif; ?>

      <?php if (!$reviews): ?>
        <div class="alert alert-light">ยังไม่มีรีวิวสำหรับสินค้านี้</div>
      <?php endif; ?>

      <?php foreach($reviews as $rv): ?>
        <?php $av = avatarPathFromRow($rv); ?>
        <div id="review-<?= (int)$rv['id'] ?>" class="card mb-3">
          <div class="card-body">
            <div class="d-flex gap-3">
              <img src="<?= h($av) ?>" class="rounded-circle" width="48" height="48" style="object-fit:cover" alt="avatar">
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <strong><?= h($rv['full_name'] ?: $rv['username']) ?></strong>
                    <span class="ms-2"><?= starHtml($rv['rating']) ?></span>
                    <?php if(!empty($rv['is_admin'])): ?><span class="badge bg-danger ms-1">ผู้ดูแล</span><?php endif; ?>
                  </div>
                  <div class="text-muted small"><?= h(date('d/m/Y H:i', strtotime($rv['created_at']))) ?></div>
                </div>
                <div class="mt-2"><?= nl2br(h($rv['content'])) ?></div>

                <?php if ($loggedIn && (int)$rv['user_id'] === (int)($_SESSION['user_id'] ?? 0)): ?>
                  <div class="mt-2">
                    <a class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" href="#edit-<?= (int)$rv['id'] ?>">
                      <i class="bi bi-pencil-square"></i> แก้ไขรีวิวของฉัน
                    </a>
                    <div id="edit-<?= (int)$rv['id'] ?>" class="collapse mt-2">
                      <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_review" value="<?= h($csrfReview) ?>">
                        <input type="hidden" name="review_action" value="edit_review">
                        <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                        <div class="col-md-3">
                          <select name="rating" class="form-select form-select-sm" required>
                            <?php for($i=5;$i>=1;$i--): ?>
                              <option value="<?=$i?>" <?= $i==(int)$rv['rating']?'selected':''?>><?=str_repeat('★',$i)?></option>
                            <?php endfor; ?>
                          </select>
                        </div>
                        <div class="col-md-9">
                          <textarea name="content" class="form-control form-control-sm" rows="2" required><?= h($rv['content']) ?></textarea>
                        </div>
                        <div class="col-12 text-end">
                          <button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> บันทึก</button>
                        </div>
                      </form>

                      <form method="post" class="mt-2"
                            onsubmit="return confirm('ยืนยันลบรีวิวของคุณ? การลบนี้ไม่สามารถย้อนกลับได้');">
                        <input type="hidden" name="csrf_review" value="<?= h($csrfReview) ?>">
                        <input type="hidden" name="review_action" value="delete_review">
                        <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                        <button class="btn btn-outline-danger btn-sm">
                          <i class="bi bi-trash"></i> ลบรีวิวของฉัน
                        </button>
                      </form>

                    </div>
                  </div>
                <?php endif; ?>

                <?php if (!empty($repliesMap[(int)$rv['id']])): ?>
                  <div class="rv-replies">
                    <?php foreach($repliesMap[(int)$rv['id']] as $rep): ?>
                      <?php $av2 = avatarPathFromRow($rep); ?>
                      <div class="d-flex gap-2 mb-2">
                        <img src="<?= h($av2) ?>" class="rounded-circle" width="36" height="36" style="object-fit:cover" alt="avatar">
                        <div>
                          <div>
                            <strong><?= h($rep['full_name'] ?: $rep['username']) ?></strong>
                            <?php if(!empty($rep['is_admin'])): ?><span class="badge bg-danger ms-1">ผู้ดูแล</span><?php endif; ?>
                            <span class="text-muted small ms-2"><?= h(date('d/m/Y H:i', strtotime($rep['created_at']))) ?></span>
                          </div>
                          <div><?= nl2br(h($rep['content'])) ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if (($userRole ?? 'user') === 'admin'): ?>
                  <form class="mt-3" method="post">
                    <input type="hidden" name="csrf_review" value="<?= h($csrfReview) ?>">
                    <input type="hidden" name="review_action" value="admin_reply">
                    <input type="hidden" name="parent_id" value="<?= (int)$rv['id'] ?>">
                    <div class="input-group">
                      <input name="content" class="form-control" placeholder="ตอบกลับในนามผู้ดูแล...">
                      <button class="btn btn-outline-primary"><i class="bi bi-reply"></i> ตอบกลับ</button>
                    </div>
                  </form>
                <?php endif; ?>

              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php include 'assets/html/footer.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ---------- gallery: thumbnails + auto slide on hover ----------
(function(){
  const main   = document.getElementById('mainImg');
  const shell  = document.querySelector('.gallery-shell');
  const thumbs = Array.from(document.querySelectorAll('.thumbs .thumb'));
  const dots   = Array.from(document.querySelectorAll('.img-dot'));
  if(!main || !shell || thumbs.length <= 1) return;

  let idx = 0;
  let timer = null;

  function show(i){
    idx = i;
    const btn = thumbs[idx];
    const src = btn.dataset.src;
    if(src){
      main.style.opacity = '0';
      setTimeout(()=>{
        main.src = src;
        main.style.opacity = '1';
      }, 120);
    }
    thumbs.forEach((b,j)=>b.classList.toggle('active', j===idx));
    dots.forEach((d,j)=>d.classList.toggle('active', j===idx));
  }

  thumbs.forEach((btn,i)=>{
    btn.addEventListener('click', ()=>{ show(i); });
  });

  shell.addEventListener('mouseenter', ()=>{
    if(timer || thumbs.length <= 1) return;
    timer = setInterval(()=>{ show((idx+1) % thumbs.length); }, 2000);
  });
  shell.addEventListener('mouseleave', ()=>{
    if(timer){
      clearInterval(timer);
      timer = null;
      show(0); // กลับไปภาพแรก
    }
  });
})();

// ---------- qty ----------
const qtyInput = document.getElementById('qty');
document.getElementById('btnMinus')?.addEventListener('click', ()=>{
  const v = Math.max(1, parseInt(qtyInput.value||'1') - 1);
  qtyInput.value = v;
});
document.getElementById('btnPlus')?.addEventListener('click', ()=>{
  const max = parseInt(qtyInput.max);
  const v = Math.min(max, parseInt(qtyInput.value||'1') + 1);
  qtyInput.value = v;
});

// ---------- add to cart ----------
document.getElementById('btnAdd')?.addEventListener('click', async (e)=>{
  const pid = e.currentTarget.dataset.productId;
  const qty = Math.max(1, parseInt(qtyInput?.value || '1'));
  try{
    const res = await fetch('cart_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'add', product_id: pid, qty })
    });
    const data = await res.json();
    if(data.status === 'success'){
      Swal.fire({
        toast:true,
        position:'bottom-end',
        icon:'success',
        title:'เพิ่มลงรถเข็นแล้ว',
        showConfirmButton:false,
        timer:1600,
        timerProgressBar:true
      });
      const cc = document.getElementById('cart-count');
      if(cc && typeof data.cart_count !== 'undefined'){
        cc.textContent = data.cart_count;
        cc.classList.remove('d-none');
      }
    }else{
      if (/login|เข้าสู่ระบบ/i.test(data.message||'')) {
        location.href = "login.php?redirect=" + encodeURIComponent(location.pathname + location.search);
        return;
      }
      Swal.fire({icon:'error', title:'เกิดข้อผิดพลาด', text:data.message || 'ไม่สามารถเพิ่มได้'});
    }
  }catch(err){
    Swal.fire({icon:'error', title:'เกิดข้อผิดพลาด', text:'เชื่อมต่อไม่สำเร็จ'});
  }
});

// ---------- buy now ----------
document.getElementById('btnBuy')?.addEventListener('click', (e)=>{
  e.preventDefault();
  const qty = Math.max(1, parseInt(qtyInput?.value || '1'));
  location.href = `checkout.php?product_id=<?= (int)$product['id'] ?>&qty=${qty}`;
});
</script>
</body>
</html>
