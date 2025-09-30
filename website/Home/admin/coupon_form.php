<?php
// Home/admin/coupon_form.php  (styled like product_add header)
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role']??'')!=='admin') { header("Location: ../login.php?redirect=admin/coupon_form.php"); exit; }

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$id  = (int)($_GET['id'] ?? 0);
$row = [];
if ($id>0){
  $st = $conn->prepare("SELECT * FROM coupons WHERE id=?");
  $st->bind_param("i",$id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc() ?: [];
  $st->close();
}

// admin name (optional nice touch for topbar)
$admin_name='admin';
if ($st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1")){
  $uid=(int)($_SESSION['user_id']??0);
  $st->bind_param('i',$uid); $st->execute();
  $admin_name = $st->get_result()->fetch_assoc()['username'] ?? 'admin';
  $st->close();
}

// helpers
$ends_val = !empty($row['ends_at']) ? date('Y-m-d\TH:i', strtotime($row['ends_at'])) : '';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $id>0?'แก้ไข':'สร้าง' ?>คูปอง | Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8fb; --panel:#fff; --border:#e9eef5; --muted:#6b7280;
      --brand:#4f46e5; --brand-2:#0ea5e9;
    }
    body{background:var(--bg)}
    .topbar{background:linear-gradient(180deg,#ffffff,#fafcff); border-bottom:1px solid var(--border)}
    .page-head{background:linear-gradient(135deg,#f6f8ff 0%,#ffffff 70%); border:1px solid var(--border); border-radius:14px; padding:12px 16px}
    .card-elev{border:1px solid var(--border); border-radius:18px; box-shadow:0 18px 48px rgba(2,6,23,.06)}
    .hint{color:var(--muted)}
    .req::after{content:" *"; color:#ef4444}
    .unit{position:absolute; right:.75rem; top:50%; transform:translateY(-50%); color:#6b7280}
  </style>
</head>
<body>

<!-- topbar (match product_add) -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-speedometer2 me-1"></i> Admin</span>
      <span class="fw-semibold d-none d-md-inline"><?= $id>0?'แก้ไข':'สร้าง' ?>คูปอง • @<?= h($admin_name) ?></span>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="coupons_list.php"><i class="bi bi-ticket-detailed"></i> รายการคูปอง</a>
      <a class="btn btn-outline-secondary btn-sm" href="../index.php"><i class="bi bi-house"></i> หน้าร้าน</a>
      <a class="btn btn-outline-danger btn-sm" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <!-- page head toolbar -->
  <div class="page-head d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-light border" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> ย้อนกลับ
      </button>
      <a href="coupons_list.php" class="btn btn-outline-secondary">
        <i class="bi bi-card-list"></i> รายการคูปอง
      </a>
      <?php if ($id>0): ?>
        <a href="coupon_form.php" class="btn btn-outline-primary">
          <i class="bi bi-plus-lg"></i> สร้างคูปองใหม่
        </a>
      <?php endif; ?>
    </div>
    <div class="text-end">
      <div class="fw-bold text-primary mb-0"><?= $id>0?'แก้ไขคูปอง':'สร้างคูปองใหม่' ?></div>
      <div class="small hint">กำหนดรูปแบบส่วนลด เงื่อนไข และช่วงเวลาใช้งาน</div>
    </div>
  </div>

  <div class="card card-elev">
    <div class="card-body">
      <form method="post" action="coupon_save.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="row g-4">
          <!-- left -->
          <div class="col-lg-7">
            <div class="mb-3">
              <label class="form-label req">โค้ดคูปอง</label>
              <input type="text" name="code" class="form-control" required
                     value="<?= h($row['code'] ?? '') ?>" placeholder="เช่น WELCOME10">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label req">ประเภท</label>
                <select name="type" id="type" class="form-select">
                  <option value="fixed"   <?= ($row['type']??'')==='fixed'?'selected':'' ?>>Fixed (ลดเป็นจำนวนเงิน)</option>
                  <option value="percent" <?= ($row['type']??'')==='percent'?'selected':'' ?>>Percent (%)</option>
                </select>
              </div>
              <div class="col-md-6 position-relative">
                <label class="form-label req">มูลค่า</label>
                <input type="number" step="0.01" name="value" id="value" class="form-control"
                       value="<?= h($row['value'] ?? '') ?>" required>
                <span id="unit" class="unit">฿</span>
                <div id="valueFeedback" class="invalid-feedback"></div>
                <div id="valueHint" class="form-text"></div>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">ขั้นต่ำการสั่งซื้อ</label>
                <input type="number" step="0.01" name="min_order_total" class="form-control"
                       value="<?= h($row['min_order_total'] ?? 0) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">วันหมดอายุ</label>
                <input type="datetime-local" name="ends_at" class="form-control" value="<?= h($ends_val) ?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">จำกัดจำนวนการใช้รวม</label>
                <input type="number" name="uses_limit" class="form-control" value="<?= h($row['uses_limit'] ?? 0) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">จำกัดต่อผู้ใช้</label>
                <input type="number" name="per_user_limit" class="form-control" value="<?= h($row['per_user_limit'] ?? 0) ?>">
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label">หมายเหตุ (แสดงเฉพาะแอดมิน)</label>
              <input type="text" name="note" class="form-control" value="<?= h($row['note'] ?? '') ?>">
            </div>
          </div>

          <!-- right -->
          <div class="col-lg-5">
            <div class="mb-3">
              <label class="form-label">สถานะ</label>
              <select name="status" class="form-select">
                <option value="active"   <?= ($row['status']??'')==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= ($row['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">ใช้กับ</label>
              <select name="applies_to" class="form-select" id="applies_to">
                <option value="all"      <?= ($row['applies_to']??'')==='all'?'selected':'' ?>>ทั้งหมด</option>
                <option value="products" <?= ($row['applies_to']??'')==='products'?'selected':'' ?>>เฉพาะสินค้า</option>
                <option value="services" <?= ($row['applies_to']??'')==='services'?'selected':'' ?>>เฉพาะบริการ</option>
                <option value="tradein"  <?= ($row['applies_to']??'')==='tradein'?'selected':'' ?>>เทิร์น/เครดิต</option>
              </select>
              <div class="form-text">กำหนดขอบเขตการใช้งานของคูปองนี้</div>
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="allow_stack_with_discount_price" value="1"
                     id="allowStack" <?= !empty($row['allow_stack_with_discount_price'])?'checked':'' ?>>
              <label class="form-check-label" for="allowStack">อนุญาตซ้อนกับ “ราคาลดหน้าเว็บ”</label>
            </div>

            <?php if ($id>0): ?>
            <div class="border rounded p-2 bg-light">
              <div class="d-flex align-items-center justify-content-between">
                <div class="small text-muted">การทำงานอื่นๆ</div>
                <a href="coupon_delete.php?id=<?= (int)$id ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('ลบคูปองนี้?')">
                  <i class="bi bi-trash"></i> ลบคูปอง
                </a>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
          <a href="coupons_list.php" class="btn btn-outline-secondary">ยกเลิก</a>
        </div>
      </form>
    </div>
  </div>

  <div class="text-muted small mt-3">
    เคล็ดลับ: หากเลือกประเภท <b>Percent</b> ให้กำหนดมูลค่า 1–100 (เช่น 10 คือ 10%)<br>
    หากเลือก <b>Fixed</b> มูลค่าต้อง &gt; 0 และไม่เกินยอดคำสั่งซื้อหลังหักเงื่อนไข
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* live unit + validation like product_add feel */
(()=> {
  const typeEl = document.getElementById('type');
  const valEl  = document.getElementById('value');
  const unitEl = document.getElementById('unit');
  const fb     = document.getElementById('valueFeedback');
  const hint   = document.getElementById('valueHint');

  function fmt(n){ try{ return Number(n).toLocaleString(undefined,{maximumFractionDigits:2}); }catch(_){ return n; } }

  function applyUnit(){
    const t = typeEl.value;
    unitEl.textContent = (t==='percent') ? '%' : '฿';
  }

  function validate(){
    const t = typeEl.value;
    const v = parseFloat(valEl.value);
    valEl.classList.remove('is-valid','is-invalid');
    fb.textContent=''; hint.textContent='';

    if (isNaN(v)){ valEl.classList.add('is-invalid'); fb.textContent='กรุณากรอกตัวเลข'; return; }

    if (t==='percent'){
      if (v<=0 || v>100){ valEl.classList.add('is-invalid'); fb.textContent='เปอร์เซ็นต์ต้องอยู่ระหว่าง 1 ถึง 100'; }
      else { valEl.classList.add('is-valid'); hint.textContent=`ลดประมาณ ${fmt(v)}% ของยอดที่เข้าเงื่อนไข`; }
    } else {
      if (v<=0){ valEl.classList.add('is-invalid'); fb.textContent='มูลค่าคงที่ต้องมากกว่า 0 บาท'; }
      else { valEl.classList.add('is-valid'); hint.textContent=`ลดคงที่ ${fmt(v)} บาท`; }
    }
  }

  ['change','input','blur'].forEach(ev=>{
    typeEl.addEventListener(ev, ()=>{ applyUnit(); validate(); });
    valEl.addEventListener(ev, validate);
  });

  applyUnit(); validate();
})();
</script>
</body>
</html>
