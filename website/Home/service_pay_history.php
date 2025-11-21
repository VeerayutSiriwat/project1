<?php  
// Home/service_pay_history.php — premium UI + pagination (soft & easy)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

if(!isset($_SESSION['user_id'])){
  header("Location: login.php?redirect=service_pay_history.php");
  exit;
}

function h($s){ return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8'); }
function baht($n){ return number_format((float)$n,2); }

/* ---------- pagination ---------- */
$uid = (int)$_SESSION['user_id'];
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$prev = max(1, $page - 1);

/* ---------- เช็คคอลัมน์ที่เกี่ยวข้อง ---------- */
$hasFinal = false;
$hasServicePrice = false;

$c = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'final_total'");
if ($c && $c->num_rows>0) $hasFinal = true;

$c2 = $conn->query("SHOW COLUMNS FROM service_tickets LIKE 'service_price'");
if ($c2 && $c2->num_rows>0) $hasServicePrice = true;

/*
 * base = ยอดก่อนหักคูปอง
 * ลำดับการใช้: final_total > service_price > estimate_total
 */
if ($hasFinal) {
  $baseField = "COALESCE(st.final_total, " . ($hasServicePrice ? "st.service_price," : "") . " st.estimate_total, 0)";
} elseif ($hasServicePrice) {
  $baseField = "COALESCE(st.service_price, st.estimate_total, 0)";
} else {
  $baseField = "COALESCE(st.estimate_total, 0)";
}

/*
 * discount = ยอดคูปองที่ใช้กับใบงานซ่อมนี้ (context = 'service')
 * เก็บไว้ใน coupon_usages.order_id = service_tickets.id
 */
$discountField = "(
  SELECT COALESCE(SUM(cu.amount),0)
  FROM coupon_usages cu
  WHERE cu.context = 'service'
    AND cu.order_id = st.id
)";

/* ยอดสุทธิ = base - discount (ถ้าไม่มีคูปอง discount = 0 ก็เท่าเดิม) */
$totalField = "GREATEST(0, {$baseField} - {$discountField})";

/* ---------- นับจำนวนทั้งหมด ---------- */
$st = $conn->prepare("
  SELECT COUNT(*) AS total
  FROM service_tickets
  WHERE user_id=? AND payment_status IS NOT NULL AND payment_status <> 'unpaid'
");
$st->bind_param("i",$uid);
$st->execute();
$total_rows = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();

$total_pages = max(1, ceil($total_rows / $per_page));
$next = min($total_pages, $page + 1);

/* ---------- ยอดสุทธิรวมทั้งหมด (ใช้ totalField เดียวกับในตาราง) ---------- */
$total_net = 0.0;
if ($st = $conn->prepare("
  SELECT COALESCE(SUM({$totalField}),0) AS net_sum
  FROM service_tickets st
  WHERE st.user_id=? AND st.payment_status IS NOT NULL AND st.payment_status <> 'unpaid'
")){
  $st->bind_param("i",$uid);
  $st->execute();
  $total_net = (float)($st->get_result()->fetch_assoc()['net_sum'] ?? 0);
  $st->close();
}

/* ---------- โหลดข้อมูลหน้าปัจจุบัน ---------- */
$sql = "
  SELECT 
    st.id,
    st.device_type, st.brand, st.model,
    {$totalField} AS total,
    st.payment_status,
    st.pay_method,
    st.paid_at,
    st.updated_at
  FROM service_tickets st
  WHERE st.user_id = ?
    AND st.payment_status IS NOT NULL
    AND st.payment_status <> 'unpaid'
  ORDER BY st.updated_at DESC
  LIMIT ? OFFSET ?
";

$rows=[];
if($st=$conn->prepare($sql)){
  $st->bind_param("iii",$uid,$per_page,$offset);
  $st->execute();
  $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

$PAY_BADGE = [
  'unpaid'  =>'danger',
  'pending' =>'warning text-dark',
  'paid'    =>'success'
];
$PAY_TEXT = [
  'unpaid'  =>'ยังไม่ชำระ',
  'pending' =>'รอตรวจสอบ',
  'paid'    =>'ชำระแล้ว'
];
$METHOD_TEXT = [
  'cash'   =>'เงินสด',
  'bank'   =>'โอนธนาคาร',
  'wallet' =>'วอลเล็ท'
];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ประวัติการชำระค่าบริการ | WEB APP</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

<style>
:root{
  --bg:#f6f8fb;
  --line:#e1ebf7;
  --card:#ffffff;
  --pri:#2563eb;
  --pri2:#4f46e5;
  --ink:#0b1a37;
  --muted:#6b7280;
}

/* head */
.page-head{
  border-radius:20px;
  color:#fff;
  padding:16px 18px 12px;
  background:linear-gradient(135deg,var(--pri)0%,var(--pri2)55%,#0ea5e9 100%);
  box-shadow:0 14px 36px rgba(37,99,235,.18);
}
.page-head h3{font-weight:700;letter-spacing:.01em;}
.page-head-sub{font-size:.85rem;opacity:.9;margin-top:2px;}
.chip{
  display:inline-flex;
  align-items:center;
  gap:.40rem;

  background:rgba(255,255,255,0.16);
  border:1px solid rgba(255,255,255,0.45);

  padding:.38rem .80rem;    
  min-height:34px;           /* ทำให้สูงเท่ากันเสมอ        */
  border-radius:999px;
  font-weight:600;
  font-size:.88rem;
  white-space:nowrap;        /* กันไม่ให้ตัดบรรทัด         */
}
.chip i{
  font-size:1rem; 
  opacity:.95;
}

/* shell */
.shell{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:18px;
  overflow:hidden;
  box-shadow:0 16px 40px rgba(2,6,23,.06);
}

/* toolbar */
.toolbar{
  padding:10px 14px;
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  border-bottom:1px solid #edf1f8;
  background:#fafbff;
}
.toolbar .form-control{
  font-size:.9rem;
}
.toolbar .btn{
  border-radius:999px;
  font-size:.9rem;
}

/* table */
.table-modern{
  margin:0;
  font-size:.9rem;
}
.table-modern thead th{
  background:#f5f7fc;
  border-bottom:1px solid #e3e8f5;
  font-weight:600;
  color:#111827;
}
.table-modern tbody tr{
  transition:background .12s;
}
.table-modern tbody tr:hover{
  background:#f9fbff;
}
.table-modern td,.table-modern th{
  vertical-align:middle;
}

.badge-pill{
  border-radius:999px;
  padding:.35rem .7rem;
  font-weight:600;
  font-size:.8rem;
}
.bg-primary-subtle{
  background:#e7f0ff!important;
  color:#2563eb!important;
  border:1px solid #bfd3ff!important;
}
.bg-success-subtle{
  background:#e8fdf3!important;
  color:#16a34a!important;
  border:1px solid #b6f2d4!important;
}
.small-muted{
  font-size:.8rem;
  color:#94a3b8;
}

/* mobile card style */
@media(max-width:992px){
  .table-modern thead{display:none}
  .table-modern tbody tr{
    display:block;
    margin:10px;
    border:1px solid var(--line);
    border-radius:14px;
    padding:.65rem .7rem;
    background:#fff;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
  }
  .table-modern tbody td{
    display:flex;
    justify-content:space-between;
    border:0;
    border-bottom:1px dashed #eef2f6;
    padding:.4rem 0;
  }
  .table-modern tbody td:last-child{
    border-bottom:0;
    padding-top:.45rem;
  }
  .table-modern tbody td::before{
    content:attr(data-label);
    font-weight:600;
    color:#6b7280;
    max-width:45%;
    padding-right:.75rem;
  }
}

/* pagination zone */
.sticky-pagination{
  background:linear-gradient(180deg,rgba(246,248,251,0),#f6f8fb 40%, #f6f8fb);
  padding-top:.4rem;
  margin-top: 1rem;
}
</style>
</head>

<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">

  <div class="page-head mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div>
        <h3 class="m-0">
          <i class="bi bi-cash-coin me-2"></i>ประวัติการชำระค่าบริการ
        </h3>
        <div class="page-head-sub">
          ดูสรุปการจ่ายค่าซ่อม / บริการทั้งหมดของคุณในหน้านี้
        </div>
      </div>
      <div class="chips">
        <div class="chip">
          <i class="bi bi-list-ul me-1"></i>
          ทั้งหมด <?= $total_rows ?> รายการ
        </div>
        <div class="chip">
          <i class="bi bi-cash-stack me-1"></i>
          ยอดสุทธิรวม <?= baht($total_net) ?> ฿
        </div>
      </div>
    </div>
  </div>

  <?php if(empty($rows)): ?>
    <div class="alert alert-info shadow-sm">
      <i class="bi bi-info-circle me-1"></i> ยังไม่มีประวัติการชำระ
    </div>
  <?php else: ?>

  <div class="shell">
    <div class="toolbar">
      <div class="input-group" style="max-width:420px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="q" class="form-control" placeholder="ค้นหา: ST-, อุปกรณ์, สถานะ, วิธีชำระ, ยอด">
      </div>
      <button class="btn btn-outline-primary ms-auto" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise me-1"></i> รีเฟรช
      </button>
    </div>

    <div class="table-responsive">
      <table class="table table-modern align-middle" id="payTable">
        <thead>
          <tr>
            <th>เลขงาน</th>
            <th>อุปกรณ์</th>
            <th>ยอดสุทธิ</th>
            <th>วิธีชำระ</th>
            <th>สถานะ</th>
            <th>วันที่ชำระ</th>
            <th></th>
          </tr>
        </thead>

        <tbody>
        <?php foreach($rows as $r):
          $tid = (int)$r['id'];
          $status = $r['payment_status'];
          $badge = $PAY_BADGE[$status] ?? 'secondary';

          $searchStr = strtolower("st-$tid ".
            ($r['device_type'] ?? '').' '.
            ($r['brand'] ?? '').' '.
            ($r['model'] ?? '').' '.
            ($status ?? '').' '.
            ($r['pay_method'] ?? '').' '.
            baht($r['total'])
          );
        ?>
          <tr data-search="<?= h($searchStr) ?>">
            <td data-label="เลขงาน">
              <span class="fw-bold">ST-<?= $tid ?></span>
            </td>
            <td data-label="อุปกรณ์">
              <?= h(trim(($r['device_type'].' '.$r['brand'].' '.$r['model']))) ?>
            </td>
            <td data-label="ยอดสุทธิ">
              <span class="fw-semibold"><?= baht($r['total']) ?> ฿</span>
            </td>
            <td data-label="วิธีชำระ">
              <span class="badge-pill bg-primary-subtle">
                <?= h($METHOD_TEXT[$r['pay_method']] ?? $r['pay_method']) ?>
              </span>
            </td>
            <td data-label="สถานะ">
              <span class="badge bg-<?= $badge ?>">
                <?= h($PAY_TEXT[$status] ?? $status) ?>
              </span>
            </td>
            <td data-label="วันที่ชำระ">
              <?= h($r['paid_at'] ?: '-') ?>
            </td>
            <td data-label="">
              <a href="service_my_detail.php?type=repair&id=<?= $tid ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-eye"></i> รายละเอียด
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="sticky-pagination">
      <nav class="mt-2">
        <ul class="pagination justify-content-center mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="?page=<?= $prev ?>">ก่อนหน้า</a>
          </li>

          <?php
          $window = 2;
          $from = max(1, $page - $window);
          $to   = min($total_pages, $page + $window);

          if ($from > 1) {
            echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
            if ($from > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for ($i=$from; $i<=$to; $i++){
            echo '<li class="page-item '.($i==$page?'active':'').'"><a class="page-link" href="?page='.$i.'">'.$i.'</a></li>';
          }
          if ($to < $total_pages) {
            if ($to < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.'">'.$total_pages.'</a></li>';
          }
          ?>

          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
            <a class="page-link" href="?page=<?= $next ?>">ถัดไป</a>
          </li>
        </ul>
      </nav>
    </div>

  </div>

  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
document.getElementById('q')?.addEventListener('input', function(){
  const val = this.value.trim().toLowerCase();
  document.querySelectorAll('#payTable tbody tr').forEach(tr=>{
    const s = tr.getAttribute('data-search') || '';
    tr.style.display = (val==='' || s.indexOf(val) > -1) ? '' : 'none';
  });
});
</script>

</body>
</html>
