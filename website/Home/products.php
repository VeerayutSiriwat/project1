<?php
// Home/products.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__.'/includes/db.php';

function h($s){return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}
function baht($n){return number_format((float)$n,2);}
function img($row){return $row['image']? 'assets/img/'.$row['image'] : 'assets/img/default.png';}
function short_desc($html, $limit = 120){
  $plain = trim(strip_tags($html ?? ''));
  if (mb_strlen($plain,'UTF-8') > $limit){
    return mb_substr($plain, 0, $limit, 'UTF-8').'…';
  }
  return $plain;
}

$loggedIn = isset($_SESSION['user_id']);

$q       = trim($_GET['q'] ?? '');
$cat     = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$special = $_GET['special'] ?? 'all'; // all | deals | new
if (!in_array($special, ['all','deals','new'], true)) $special = 'all';

/* ================= Pagination ================= */
$per_page = 12;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* หมวดหมู่ */
$cats = [];
if ($res = $conn->query("SELECT id,name FROM categories ORDER BY name ASC")) {
  $cats = $res->fetch_all(MYSQLI_ASSOC);
}

/* นับจำนวนสินค้าทั้งหมดตามตัวกรอง */
$count_sql = "SELECT COUNT(*) AS c
              FROM products p
              WHERE p.status='active'";
$count_params=[]; $count_types='';

if ($cat>0){ $count_sql.=" AND p.category_id=?"; $count_params[]=$cat; $count_types.='i'; }
if ($q!==''){ $count_sql.=" AND (p.name LIKE ? OR p.description LIKE ?)"; $like="%$q%"; $count_params[]=$like; $count_params[]=$like; $count_types.='ss'; }

if ($special==='deals'){
  $count_sql.=" AND p.discount_price IS NOT NULL AND p.discount_price>0 AND p.discount_price<p.price";
}
if ($special==='new'){
  $count_sql.=" AND p.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)";
}

$stc = $conn->prepare($count_sql);
if($count_params) $stc->bind_param($count_types, ...$count_params);
$stc->execute();
$total_rows = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
$stc->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages){ $page = $total_pages; $offset = ($page-1)*$per_page; }

/* helper ลิงก์หน้าถัดไป (คงพารามิเตอร์เดิม) */
function page_link($p){
  $qs = $_GET; $qs['page'] = $p;
  return 'products.php?' . http_build_query($qs);
}

/* สินค้า (ตามหน้า) */
$sql = "SELECT p.id,p.name,p.description,p.price,p.discount_price,p.stock,p.image,
               c.name AS category_name, p.created_at
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE p.status='active'";
$params=[]; $types="";

if ($cat>0){ $sql.=" AND p.category_id=?"; $params[]=$cat; $types.="i"; }
if ($q!==''){ $sql.=" AND (p.name LIKE ? OR p.description LIKE ?)"; $like="%$q%"; $params[]=$like; $params[]=$like; $types.="ss"; }

if ($special==='deals'){
  $sql.=" AND p.discount_price IS NOT NULL AND p.discount_price>0 AND p.discount_price<p.price
          ORDER BY (p.price - p.discount_price)/p.price DESC, p.created_at DESC";
} elseif ($special==='new'){
  $sql.=" AND p.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
          ORDER BY p.created_at DESC";
} else {
  $sql.=" ORDER BY p.created_at DESC";
}

$sql.=" LIMIT ? OFFSET ?";
$params[]=$per_page; $types.="i";
$params[]=$offset;   $types.="i";

$stmt=$conn->prepare($sql);
if($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>สินค้า | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- Filter Bar -->
<div class="py-3">
  <div class="container">
    <form class="filter-bar row g-2 align-items-center" method="get">
      <div class="col-md-3">
        <select name="cat" class="form-select">
          <option value="0">หมวดหมู่ทั้งหมด</option>
          <?php foreach($cats as $c): ?>
            <option value="<?=$c['id']?>" <?=$cat==$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-7">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control border-start-0" placeholder="ค้นหาชื่อสินค้า / คำอธิบาย..." value="<?=h($q)?>">
        </div>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> ค้นหา</button>
      </div>
    </form>
  </div>
</div>

<div class="py-3">
  <div class="container">
    <div class="row">
      <!-- Sidebar -->
      <aside class="col-lg-3 mb-3 sidebar">

        <!-- ปุ่มตัวกรองหลัก -->
        <div class="d-grid gap-2 mb-3">
          <a href="products.php?<?= http_build_query(array_merge($_GET,['special'=>'all','page'=>1])) ?>"
             class="btn <?= $special==='all'?'btn-primary':'btn-outline-primary' ?>">
            ทั้งหมด
          </a>
          <a href="products.php?<?= http_build_query(array_merge($_GET,['special'=>'deals','page'=>1])) ?>"
             class="btn <?= $special==='deals'?'btn-danger':'btn-outline-danger' ?>">
            <i class="bi bi-lightning-charge-fill"></i> ดีลพิเศษ
          </a>
          <a href="products.php?<?= http_build_query(array_merge($_GET,['special'=>'new','page'=>1])) ?>"
             class="btn <?= $special==='new'?'btn-success':'btn-outline-success' ?>">
            <i class="bi bi-stars"></i> มาใหม่ (3 วัน)
          </a>
        </div>

        <!-- หมวดหมู่ -->
        <div class="list-group">
          <?php
            // คงค่าพารามิเตอร์อื่น ๆ ไว้เสมอเวลาเปลี่ยนหมวด
            $qsAll = array_merge($_GET, ['cat'=>0,'page'=>1]);
          ?>
          <a href="products.php?<?= http_build_query($qsAll) ?>"
             class="list-group-item list-group-item-action <?= $cat==0?'active':'' ?>">หมวดหมู่ทั้งหมด</a>

          <?php foreach($cats as $c): ?>
            <?php $qs = array_merge($_GET, ['cat'=>$c['id'],'page'=>1]); ?>
            <a href="products.php?<?= http_build_query($qs) ?>"
               class="list-group-item list-group-item-action <?= $cat==$c['id']?'active':'' ?>">
              <?=h($c['name'])?>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="mt-3 small text-muted">แสดงผล: <?=count($products)?> / <?= (int)$total_rows ?> รายการ</div>
      </aside>

      <!-- Grid -->
      <div class="col-lg-9">
        <?php if(empty($products)): ?>
          <div class="col-12">
            <div class="alert alert-info text-center">ยังไม่มีสินค้าตามเงื่อนไข</div>
          </div>
        <?php else: ?>
          <div class="row g-3">
            <?php foreach($products as $p):
              $hasDiscount = $p['discount_price'] && $p['discount_price'] < $p['price']; ?>
              <div class="col-6 col-md-4">
                <div class="product-card h-100">
                  <div class="product-media">
                    <img src="<?=h(img($p))?>" alt="<?=h($p['name'])?>">
                    <span class="badge text-bg-dark badge-float"><?=h($p['category_name'] ?? 'ทั่วไป')?></span>
                    <?php if((int)$p['stock']<=0): ?>
                      <span class="badge text-bg-secondary badge-stock">หมดสต็อก</span>
                    <?php else: ?>
                      <span class="badge text-bg-success badge-stock">คงเหลือ: <?= (int)$p['stock'] ?></span>
                    <?php endif; ?>
                  </div>

                  <div class="product-body d-flex flex-column">
                    <h6 class="product-title"><?=h($p['name'])?></h6>
                    <div class="product-desc"><?= h(short_desc($p['description'], 120)) ?></div>

                    <div class="price-row">
                      <?php if($hasDiscount): ?>
                        <div class="price"><?=baht($p['discount_price'])?> ฿</div>
                        <div class="price-old"><?=baht($p['price'])?> ฿</div>
                      <?php else: ?>
                        <div class="price"><?=baht($p['price'])?> ฿</div>
                      <?php endif; ?>
                    </div>

                    <div class="card-actions mt-auto d-grid gap-2">
                      <a href="product.php?id=<?=$p['id']?>" class="btn btn-outline-secondary">
                        <i class="bi bi-eye"></i> รายละเอียด
                      </a>
                      <button class="btn btn-primary" <?=$p['stock']<=0?'disabled':''?>
                              data-product-id="<?=$p['id']?>" data-action="add">
                        <i class="bi bi-cart-plus"></i> เพิ่มรถเข็น
                      </button>
                      <button class="btn btn-success" <?=$p['stock']<=0?'disabled':''?>
                              data-product-id="<?=$p['id']?>" data-action="buy">
                        ซื้อเลย
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($total_pages > 1): ?>
  <nav aria-label="pagination" class="mt-4 mb-4">
    <ul class="pagination justify-content-center flex-wrap">
      <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= ($page <= 1) ? '#' : h(page_link($page-1)) ?>">ก่อนหน้า</a>
      </li>
      <?php
        $start = max(1, $page-2);
        $end   = min($total_pages, $page+2);
        if ($start > 1){
          echo '<li class="page-item"><a class="page-link" href="'.h(page_link(1)).'">1</a></li>';
          if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        for ($p=$start; $p<=$end; $p++){
          $active = ($p==$page) ? ' active' : '';
          echo '<li class="page-item'.$active.'"><a class="page-link" href="'.h(page_link($p)).'">'.$p.'</a></li>';
        }
        if ($end < $total_pages){
          if ($end < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          echo '<li class="page-item"><a class="page-link" href="'.h(page_link($total_pages)).'">'.$total_pages.'</a></li>';
        }
      ?>
      <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= ($page >= $total_pages) ? '#' : h(page_link($page+1)) ?>">ถัดไป</a>
      </li>
    </ul>
    <div class="text-center text-muted small">
      หน้า <?= (int)$page ?> / <?= (int)$total_pages ?> • ทั้งหมด <?= (int)$total_rows ?> รายการ
    </div>
  </nav>
<?php endif; ?>


        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include 'assets/html/footer.html'; ?>

<!-- Login Modal (เหมือนเดิม) -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-arrow-in-right"></i> กรุณาเข้าสู่ระบบ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        คุณต้องเข้าสู่ระบบก่อนจึงจะสามารถดำเนินการได้<br>
        ต้องการไปหน้า Login เลยหรือไม่?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <a id="goLogin" class="btn btn-primary" href="#">ไป Login</a>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-product-id]');
  if(!btn) return;

  const pid = parseInt(btn.dataset.productId,10);
  const action = btn.dataset.action;
  const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;

  if(!loggedIn){
    const redirectUrl = "login.php?redirect=" + encodeURIComponent(location.pathname + location.search);
    document.getElementById("goLogin").setAttribute("href", redirectUrl);
    const modal = new bootstrap.Modal(document.getElementById('loginModal'));
    modal.show();
    return;
  }

  if(action === 'add'){
    try {
      const r = await fetch('cart_api.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'add', product_id: pid, qty: 1 })
      });
      const d = await r.json();
      if (d.status === 'success') {
        if (typeof d.cart_count !== 'undefined') {
          const bc = document.getElementById('cart-count');
          if (bc) { bc.textContent = d.cart_count; bc.classList.remove('d-none'); }
        }
        Swal.fire({toast:true, position:'bottom-end', icon:'success', title:'เพิ่มลงตะกร้าแล้ว', showConfirmButton:false, timer:1500, timerProgressBar:true});
      } else {
        Swal.fire({title:'ไม่สำเร็จ', text:d.message || 'ไม่สามารถเพิ่มได้', icon:'error', confirmButtonText:'ตกลง'});
      }
    } catch {
      Swal.fire({title:'เกิดข้อผิดพลาด', text:'กรุณาลองใหม่อีกครั้ง', icon:'error'});
    }
  }

  if(action === 'buy'){
    const url = new URL('checkout.php', location.href);
    url.searchParams.set('product_id', pid);
    url.searchParams.set('qty', 1);
    location.href = url.toString();
  }
});
</script>

</body>
</html>
