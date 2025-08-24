<?php
// Home/admin/users.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/../includes/db.php';

// auth: admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/users.php'); exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash($msg=null){
  if ($msg !== null){ $_SESSION['flash']=$msg; return; }
  if (!empty($_SESSION['flash'])){ $m=$_SESSION['flash']; unset($_SESSION['flash']); return $m; }
  return '';
}

// ========= CSRF =========
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];

/* =========================================
   ACTIONS (POST) : update_role
========================================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $act   = $_POST['action'] ?? '';
  $token = $_POST['csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400); exit('Bad CSRF');
  }

  if ($act === 'update_role') {
    $uid  = (int)($_POST['id'] ?? 0);
    $role = $_POST['role'] ?? 'user';
    if (!in_array($role, ['user','admin'], true)) $role = 'user';

    // ป้องกันเปลี่ยนบทบาทตัวเองเป็น user จนล็อกตัวเองออก (ยังอนุญาต แต่เตือน)
    $st = $conn->prepare("UPDATE users SET role=? WHERE id=? LIMIT 1");
    $st->bind_param("si", $role, $uid);
    $st->execute();
    $st->close();

    // เพิ่มแจ้งเตือนให้ผู้ใช้ (ถ้ามีตาราง notifications)
    if ($nt = $conn->prepare("INSERT INTO notifications(user_id,type,ref_id,title,message,is_read) VALUES(?, 'user_role', 0, 'เปลี่ยนบทบาทผู้ใช้', ?, 0)")) {
      $msg = "สิทธิ์ของคุณถูกปรับเป็น '{$role}' โดยผู้ดูแลระบบ";
      $nt->bind_param("is", $uid, $msg);
      $nt->execute();
      $nt->close();
    }

    flash('อัปเดตบทบาทผู้ใช้เรียบร้อย');
    // redirect กลับหน้าเดิม (ป้องกันรีเฟรชแล้วโพสต์ซ้ำ)
    $back = $_POST['return'] ?? 'users.php';
    header('Location: '.$back); exit;
  }
}

/* =========================================
   Filters + Pagination
========================================= */
$q_user = trim($_GET['q_user'] ?? '');
$role   = $_GET['role'] ?? 'all';
$start  = trim($_GET['start'] ?? '');
$end    = trim($_GET['end'] ?? '');
$end_dt = $end ? ($end.' 23:59:59') : '';

$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* Count */
$count_sql = "SELECT COUNT(*) AS c FROM users WHERE 1=1";
$c_params=[]; $c_types='';
if ($role !== 'all'){ $count_sql.=" AND role=? ";     $c_params[]=$role;   $c_types.='s'; }
if ($q_user!=='')   { $count_sql.=" AND username LIKE ? "; $c_params[]='%'.$q_user.'%'; $c_types.='s'; }
if ($start!=='')    { $count_sql.=" AND created_at >= ? "; $c_params[]=$start.' 00:00:00'; $c_types.='s'; }
if ($end_dt!=='')   { $count_sql.=" AND created_at <= ? "; $c_params[]=$end_dt; $c_types.='s'; }

$stc = $conn->prepare($count_sql);
if($c_params){ $stc->bind_param($c_types, ...$c_params); }
$stc->execute();
$total_rows = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
$stc->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

/* Query list (รวมจำนวนออเดอร์) */
$sql = "
  SELECT
    u.id, u.username, u.role, u.created_at
    ,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) AS orders_count
    ,(SELECT COUNT(*) FROM notifications n WHERE n.user_id=u.id AND n.is_read=0) AS unread_notis
  FROM users u
  WHERE 1=1
";
$params=[]; $types='';
if ($role !== 'all'){ $sql.=" AND u.role=? ";          $params[]=$role;   $types.='s'; }
if ($q_user!=='')   { $sql.=" AND u.username LIKE ? "; $params[]='%'.$q_user.'%'; $types.='s'; }
if ($start!=='')    { $sql.=" AND u.created_at >= ? "; $params[]=$start.' 00:00:00'; $types.='s'; }
if ($end_dt!=='')   { $sql.=" AND u.created_at <= ? "; $params[]=$end_dt; $types.='s'; }

$sql .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ? ";
$params[] = $per_page; $types .= 'i';
$params[] = $offset;   $types .= 'i';

$st = $conn->prepare($sql);
if ($params){ $st->bind_param($types, ...$params); }
$st->execute();
$users = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

function page_link($p){
  $qs = $_GET; $qs['page']=$p;
  return 'users.php?'.http_build_query($qs);
}
$returnQS = 'users.php?'.http_build_query(['q_user'=>$q_user,'role'=>$role,'start'=>$start,'end'=>$end,'page'=>$page]);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ผู้ใช้ | แอดมิน</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body{background:#f7fafc}
    .toolbar-card{border:1px solid #e9eef5;border-radius:14px;padding:12px;background:#fff}
    .card{border-radius:16px;border:1px solid #e9eef5}
    th,td{vertical-align:middle}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold text-primary" href="dashboard.php"><i class="bi bi-speedometer2"></i> Admin</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container-fluid my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-light border" href="dashboard.php"><i class="bi bi-arrow-left"></i> ย้อนกลับ</a>
      <h3 class="mb-0"><i class="bi bi-people"></i> ผู้ใช้</h3>
    </div>
  </div>

  <?php if($m = flash()): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= h($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- ตัวกรอง -->
  <div class="toolbar-card mb-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label">บทบาท</label>
        <select name="role" class="form-select">
          <option value="all"   <?= $role==='all'?'selected':'' ?>>ทั้งหมด</option>
          <option value="user"  <?= $role==='user'?'selected':'' ?>>ผู้ใช้</option>
          <option value="admin" <?= $role==='admin'?'selected':'' ?>>แอดมิน</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">ชื่อลูกค้า (username)</label>
        <input type="text" name="q_user" class="form-control" value="<?= h($q_user) ?>" placeholder="พิมพ์ชื่อลูกค้า...">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">วันที่เริ่ม</label>
        <input type="date" name="start" class="form-control" value="<?= h($start) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">วันที่สิ้นสุด</label>
        <input type="date" name="end" class="form-control" value="<?= h($end) ?>">
      </div>
      <div class="col-12 d-grid d-md-flex gap-2 mt-2">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> กรอง</button>
        <a class="btn btn-outline-secondary" href="users.php"><i class="bi bi-x-circle"></i> ล้างตัวกรอง</a>
      </div>
    </form>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>ชื่อผู้ใช้</th>
            <th>บทบาท</th>
            <th>ออเดอร์</th>
            <th>แจ้งเตือนยังไม่อ่าน</th>
            <th>วันที่สร้าง</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($users)): ?>
            <tr><td colspan="7" class="text-center text-muted">ไม่พบผู้ใช้</td></tr>
          <?php else: foreach($users as $u): 
            $badge = $u['role']==='admin' ? 'danger' : 'secondary';
          ?>
            <tr id="row-<?= (int)$u['id'] ?>">
              <td><?= (int)$u['id'] ?></td>
              <td class="fw-semibold"><?= h($u['username'] ?? ('UID '.$u['id'])) ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-<?= $badge ?>"><?= h($u['role']) ?></span>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="return" value="<?= h($returnQS) ?>">
                    <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                      <option value="user"  <?= $u['role']==='user'?'selected':'' ?>>user</option>
                      <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
                    </select>
                  </form>
                </div>
              </td>
              <td>
                <a class="btn btn-sm btn-outline-primary" href="orders.php?status=all&q_user=<?= urlencode($u['username'] ?? '') ?>">
                  <?= (int)($u['orders_count'] ?? 0) ?> รายการ
                </a>
              </td>
              <td>
                <?php $unr = (int)($u['unread_notis'] ?? 0); ?>
                <?php if($unr>0): ?>
                  <span class="badge bg-warning text-dark"><?= $unr ?></span>
                <?php else: ?>
                  <span class="text-muted">0</span>
                <?php endif; ?>
              </td>
              <td><?= h($u['created_at'] ?? '-') ?></td>
              <td class="text-nowrap">
                <a href="orders.php?q_user=<?= urlencode($u['username'] ?? '') ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-receipt"></i> ดูออเดอร์
                </a>
                <a href="support.php?uid=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-chat-dots"></i> พูดคุย
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if($total_pages>1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $page<=1?'#':h(page_link($page-1)) ?>">ก่อนหน้า</a>
        </li>
        <?php
          $window=2; $from=max(1,$page-$window); $to=min($total_pages,$page+$window);
          if($from>1){
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link(1)).'">1</a></li>';
            if($from>2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for($i=$from;$i<=$to;$i++){
            echo '<li class="page-item '.($i==$page?'active':'').'"><a class="page-link" href="'.h(page_link($i)).'">'.$i.'</a></li>';
          }
          if($to<$total_pages){
            if($to<$total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="'.h(page_link($total_pages)).'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $page>=$total_pages?'#':h(page_link($page+1)) ?>">ถัดไป</a>
        </li>
      </ul>
      <div class="text-center text-muted small">หน้า <?= (int)$page ?> / <?= (int)$total_pages ?> • ทั้งหมด <?= (int)$total_rows ?> คน</div>
    </nav>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
