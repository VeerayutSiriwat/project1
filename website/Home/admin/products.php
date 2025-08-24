<?php
/* File: Home/admin/products.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php?redirect=admin/products.php"); exit;
}
require __DIR__ . '/../includes/db.php';

/* CSRF token สำหรับปุ่มลบ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ===== ฟิลเตอร์ & ค้นหา ===== */
$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? 'all';
$allowed_status = ['all','active','inactive'];
if (!in_array($status, $allowed_status, true)) $status = 'all';

/* ดึงรายการหมวดหมู่ สำหรับ dropdown */
$cats_rs = $conn->query("SELECT id,name FROM categories ORDER BY name ASC");
$cats = $cats_rs ? $cats_rs->fetch_all(MYSQLI_ASSOC) : [];
$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

/* ===== Pagination ===== */
$per_page = 10;                                      // แสดง 10 รายการ/หน้า
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* ===== COUNT ทั้งหมดตามตัวกรอง ===== */
$count_sql = "SELECT COUNT(*) AS c
              FROM products p
              LEFT JOIN categories c ON c.id = p.category_id
              WHERE 1=1";
$c_params = []; $c_types = "";

/* กรองสถานะ */
if ($status !== 'all') {
    $count_sql .= " AND p.status = ?";
    $c_params[] = $status;
    $c_types   .= "s";
}
/* กรองหมวด */
if ($cat_id > 0) {
    $count_sql .= " AND p.category_id = ?";
    $c_params[] = $cat_id;
    $c_types   .= "i";
}
/* ค้นหา (ชื่อสินค้า) */
if ($q !== '') {
    $count_sql .= " AND (p.name LIKE ?)";
    $c_params[] = "%{$q}%";
    $c_types   .= "s";
}

$stc = $conn->prepare($count_sql);
if ($c_params) { $stc->bind_param($c_types, ...$c_params); }
$stc->execute();
$total_rows = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
$stc->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

/* ===== ดึงรายการสินค้าตามตัวกรอง + แบ่งหน้า ===== */
$sql = "SELECT p.id, p.name, p.price, p.discount_price, p.stock, p.status, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE 1=1";
$params = []; $types = "";

/* กรองสถานะ */
if ($status !== 'all') {
    $sql    .= " AND p.status = ?";
    $params[] = $status;
    $types   .= "s";
}
/* กรองหมวด */
if ($cat_id > 0) {
    $sql    .= " AND p.category_id = ?";
    $params[] = $cat_id;
    $types   .= "i";
}
/* ค้นหา (ชื่อสินค้า) */
if ($q !== '') {
    $sql    .= " AND (p.name LIKE ?)";
    $params[] = "%{$q}%";
    $types   .= "s";
}

$sql .= " ORDER BY p.id DESC LIMIT ? OFFSET ?";
$params[] = $per_page; $types .= "i";
$params[] = $offset;   $types .= "i";

$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }

function page_link($p){
  $qs = $_GET; $qs['page'] = $p;
  return 'products.php?'.http_build_query($qs);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการสินค้า (Admin)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .toolbar-card{border:1px solid #e9eef5;border-radius:14px;padding:12px;background:#fff}
    .table thead th { white-space: nowrap; }
    .badge-status{font-weight:600}
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold text-primary" href="dashboard.php"><i class="bi bi-speedometer2"></i> Admin</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <!-- แถวหัวเรื่อง + ปุ่ม -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-light border" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> ย้อนกลับ
      </button>
      <h1 class="h4 mb-0">รายการสินค้า</h1>
    </div>
    <div class="d-flex gap-2">
      <a href="dashboard.php" class="btn btn-outline-secondary">
        <i class="bi bi-speedometer2"></i> แดชบอร์ด
      </a>
      <a href="product_add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> เพิ่มสินค้า
      </a>
    </div>
  </div>

  <!-- Flash -->
  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- Toolbar ค้นหา/กรอง -->
  <div class="toolbar-card mb-3">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-12 col-md-3">
        <input type="text" class="form-control" name="q" placeholder="ค้นหาชื่อสินค้า..."
               value="<?= h($q) ?>">
      </div>
      <div class="col-6 col-md-3">
        <select name="status" class="form-select">
          <option value="all"      <?= $status==='all'?'selected':'' ?>>สถานะทั้งหมด</option>
          <option value="active"   <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select name="cat" class="form-select">
          <option value="0">ทุกหมวดหมู่</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat_id===(int)$c['id']?'selected':'' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3 d-grid d-md-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> กรอง</button>
        <a class="btn btn-outline-secondary" href="products.php"><i class="bi bi-x-circle"></i> ล้างตัวกรอง</a>
      </div>
    </form>
  </div>

  <!-- ตารางสินค้า -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>เลขสินค้า</th>
              <th>ชื่อสินค้า</th>
              <th>หมวดหมู่</th>
              <th class="text-end">ราคา</th>
              <th class="text-end">สต็อก</th>
              <th>สถานะ</th>
              <th style="width:210px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($res && $res->num_rows > 0): ?>
              <?php while($row = $res->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$row['id'] ?></td>
                  <td class="fw-semibold"><?= h($row['name']) ?></td>
                  <td><?= h($row['category_name'] ?? '-') ?></td>
                  <td class="text-end">
                    <?php if (!empty($row['discount_price']) && $row['discount_price'] < $row['price']): ?>
                      <span class="text-muted text-decoration-line-through me-1"><?= baht($row['price']) ?></span>
                      <span class="fw-bold text-danger"><?= baht($row['discount_price']) ?></span>
                    <?php else: ?>
                      <span class="fw-bold"><?= baht($row['price']) ?></span>
                    <?php endif; ?> ฿
                  </td>
                  <td class="text-end"><?= (int)$row['stock'] ?></td>
                  <td>
                    <?php
                      $isActive = ($row['status']==='active');
                      $badge = $isActive ? 'success' : 'secondary';
                      $label = $isActive ? 'active' : 'inactive';
                    ?>
                    <span class="badge badge-status bg-<?= $badge ?>"><?= $label ?></span>
                  </td>
                  <td>
                    <div class="d-flex gap-2 flex-wrap">
                      <a class="btn btn-sm btn-outline-primary" href="../product.php?id=<?= (int)$row['id'] ?>" target="_blank">
                        <i class="bi bi-eye"></i> ดูหน้าเว็บ
                      </a>
                      <a class="btn btn-sm btn-outline-secondary" href="product_edit.php?id=<?= (int)$row['id'] ?>">
                        <i class="bi bi-pencil-square"></i> แก้ไข
                      </a>
                      <form method="post" action="product_delete.php"
                            onsubmit="return confirm('ยืนยันลบสินค้า #<?= (int)$row['id'] ?> ?');" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                          <i class="bi bi-trash"></i> ลบ
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">ยังไม่มีสินค้า</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $page<=1?'#':h(page_link($page-1)) ?>">ก่อนหน้า</a>
        </li>
        <?php
          $window = 2; // ระยะรอบหน้า
          $start_p = max(1, $page-$window);
          $end_p   = min($total_pages, $page+$window);

          if ($start_p > 1){
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link(1)).'">1</a></li>';
            if ($start_p > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for($p=$start_p; $p<=$end_p; $p++){
            echo '<li class="page-item '.($p==$page?'active':'').'"><a class="page-link" href="'.h(page_link($p)).'">'.$p.'</a></li>';
          }
          if ($end_p < $total_pages){
            if ($end_p < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link($total_pages)).'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $page>=$total_pages?'#':h(page_link($page+1)) ?>">ถัดไป</a>
        </li>
      </ul>
      <div class="text-center text-muted small">
        หน้า <?= $page ?> / <?= $total_pages ?> • ทั้งหมด <?= $total_rows ?> รายการ
      </div>
    </nav>
  <?php endif; ?>

</div>

<!-- Back-to-top -->
<button type="button" class="btn btn-primary position-fixed" style="right:16px; bottom:16px; display:none;" id="btnTop">
  <i class="bi bi-arrow-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const btnTop = document.getElementById('btnTop');
  window.addEventListener('scroll', () => {
    btnTop.style.display = (window.scrollY > 300) ? 'block' : 'none';
  });
  btnTop.addEventListener('click', () => window.scrollTo({top:0, behavior:'smooth'}));
</script>
</body>
</html>
