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

/** รวม path helper: รองรับทั้งที่เก็บแบบ 'uploads/avatars/a.png' หรือ 'a.png' */
function resolve_upload_path(string $p): array {
  $p = trim($p);
  if ($p === '') return ['', ''];
  // ถ้ามี uploads/ นำหน้าแล้วก็ใช้เลย
  if (preg_match('~^uploads/~', $p)) {
    $rel = $p;
  } else {
    $rel = 'uploads/' . ltrim($p, '/');
  }
  $abs = __DIR__ . '/' . $rel;
  return [$rel, $abs];
}

/** แปลงข้อมูลผู้ใช้ในแถว -> URL รูปโปรไฟล์จริง (มี fallback) */
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

/* ------------ can review? (must have completed order) ------------ */
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

/* ------------ POST: add / edit / admin reply ------------ */
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
      // หาเจ้าของรีวิวต้นทางไว้ใช้แจ้งเตือน
      $ownerId = 0;
      $chk = $conn->prepare("SELECT user_id FROM product_reviews WHERE id=? AND parent_id IS NULL LIMIT 1");
      $chk->bind_param("i", $parentId);
      $chk->execute();
      $ownerId = (int)($chk->get_result()->fetch_assoc()['user_id'] ?? 0);
      $chk->close();

      // บันทึกคำตอบของแอดมิน
      $st = $conn->prepare("
        INSERT INTO product_reviews (product_id, user_id, rating, content, parent_id, is_admin)
        VALUES (?, ?, 0, ?, ?, 1)
      ");
      $uid = (int)$_SESSION['user_id'];
      $st->bind_param("iisi", $id, $uid, $content, $parentId);
      $st->execute();
      $st->close();

      // สร้างการแจ้งเตือนให้เจ้าของรีวิว (ถ้าไม่ใช่คนเดียวกับคนตอบ)
      if ($ownerId > 0 && $ownerId !== $uid) {
        $title = "ผู้ดูแลตอบกลับรีวิวของคุณ";
        $msg   = "สินค้า: " . ($product['name'] ?? ('#'.$id)) . " • คลิกเพื่อดูคำตอบ";
        // type = review_reply, ref_id = id ของรีวิวต้นทาง (จะใช้ anchor #review-<id> ได้)
        add_notify($conn, $ownerId, 'review_reply', (int)$parentId, $title, $msg);
      }

      header("Location: product.php?id=".$id."#review-".$parentId);
      exit;
    }
  }
  }
}

    if ($action === 'delete_review') {
      if (!$loggedIn) { $reviewErrors[] = 'กรุณาเข้าสู่ระบบ'; }
      $reviewId = (int)($_POST['review_id'] ?? 0);

      if (!$reviewErrors && $reviewId > 0) {
        // ยืนยันว่ารีวิวนี้เป็นของผู้ใช้ และเป็นรีวิวหลักเท่านั้น
        $chk = $conn->prepare("SELECT id FROM product_reviews WHERE id=? AND user_id=? AND parent_id IS NULL LIMIT 1");
        $uid = (int)$_SESSION['user_id'];
        $chk->bind_param("ii", $reviewId, $uid);
        $chk->execute();
        $owned = (bool)$chk->get_result()->fetch_row();
        $chk->close();

        if (!$owned) {
          $reviewErrors[] = 'ไม่พบรีวิวของคุณหรือไม่สามารถลบได้';
        } else {
          // ลบ replies ก่อน แล้วค่อยลบรีวิวหลัก
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

/* ------------ load reviews & replies ------------ */
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
    .thumbs { display:flex; gap:.5rem; margin-top:.75rem; flex-wrap:wrap; }
    .thumbs .thumb { width:72px; height:72px; border-radius:.5rem; overflow:hidden; border:2px solid transparent; cursor:pointer; }
    .thumbs .thumb.active { border-color:#0d6efd; }
    .thumbs .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    #mainImg { width:100%; height:420px; object-fit:cover; border-radius:.75rem; }
    @media (max-width: 576px){ #mainImg{height:300px} .thumbs .thumb{width:64px;height:64px} }
    .rv-replies { margin-top:.75rem; padding-left:1rem; border-left:3px solid #eef2f6; }
  </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="container py-5">
  <div class="row g-4">
    <!-- Gallery -->
    <div class="col-md-5">
      <img id="mainImg" src="<?=h($gallery[0])?>" alt="<?=h($product['name'])?>" class="shadow-sm w-100">

      <!-- แสดงรหัสสินค้า -->
  <div class="mt-2 text-muted small">
    รหัสสินค้า: <?= h($product['product_code'] ?? 'PRD'.$product['id']) ?>
  </div>

      <?php if (count($gallery) > 1): ?>
        <div class="thumbs">
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

        <div class="d-flex gap-2">
          <button class="btn btn-primary" id="btnAdd" data-product-id="<?=$product['id']?>">
            <i class="bi bi-cart-plus"></i> เพิ่มรถเข็น
          </button>
          <a href="#" id="btnBuy" class="btn btn-success">ซื้อเลย</a>
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
                  <!-- ปุ่ม/ฟอร์มแก้ไขรีวิวของตัวเอง -->
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
                                        <!-- ปุ่มลบรีวิวของฉัน -->
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
// thumbs
document.querySelectorAll('.thumbs .thumb')?.forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const src = btn.dataset.src;
    const main = document.getElementById('mainImg');
    if (src && main){ main.src = src; }
    document.querySelectorAll('.thumbs .thumb').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
  });
});

// qty
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

// add to cart
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
      Swal.fire({toast:true, position:'bottom-end', icon:'success', title:'เพิ่มสินค้าในรถเข็นแล้ว', showConfirmButton:false, timer:1500});
      const cc = document.getElementById('cart-count');
      if(cc && typeof data.cart_count !== 'undefined'){ cc.textContent = data.cart_count; cc.classList.remove('d-none'); }
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

// buy now
document.getElementById('btnBuy')?.addEventListener('click', (e)=>{
  e.preventDefault();
  const qty = Math.max(1, parseInt(qtyInput?.value || '1'));
  location.href = `checkout.php?product_id=<?= (int)$product['id'] ?>&qty=${qty}`;
});
</script>
</body>
</html>
