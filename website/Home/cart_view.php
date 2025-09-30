<?php 
// Home/cart_view.php — premium mobile-first + sticky footer + edit/select-all
session_start();
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=cart_view.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

$sql = "SELECT ci.id, ci.product_id, ci.quantity,
               p.name, p.price, p.discount_price, p.image, p.stock, COALESCE(p.status,'active') AS status
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        WHERE ci.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function baht($n){ return number_format((float)$n, 2); }
function imgsrc($v){
  $v = trim((string)$v);
  if ($v==='') return 'assets/img/default.png';
  if (preg_match('~^https?://|^data:image/~',$v)) return $v;
  return (strpos($v,'/')!==false) ? $v : 'assets/img/'.$v;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>รถเข็นสินค้า | WEB APP</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root{
    --bg:#f6f8fb; --card:#fff; --line:#e9eef5; --ink:#0b1a37; --muted:#6b7280;
    --pri:#2563eb; --pri2:#4f46e5;
    --safe: 96px;
  }
  html,body{height:100%}
  body{min-height:100vh; display:flex; flex-direction:column; background:linear-gradient(180deg,#f8fbff,#f6f8fb 50%,#f5f7fa);}
  main.page-content{flex:1 0 auto; padding-bottom: calc(var(--safe) + env(safe-area-inset-bottom, 0px));}
  .card-glass{ border:1px solid var(--line); border-radius:18px; box-shadow:0 18px 60px rgba(2,6,23,.06); overflow:visible; background:var(--card);}
  .page-head{ border-radius:20px; background:linear-gradient(135deg,var(--pri) 0%, var(--pri2) 55%, #0ea5e9 100%); color:#fff; padding:18px; box-shadow:0 8px 24px rgba(37,99,235,.15); }
  .stepper{display:flex; gap:12px; flex-wrap:wrap; margin-top:8px}
  .step{display:flex; align-items:center; gap:8px; color:#e6ecff; font-weight:600; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); padding:6px 12px; border-radius:999px;}
  .step .num{width:24px; height:24px; border-radius:999px; display:grid; place-items:center; background:#fff; color:#1f2a44; font-weight:800; font-size:.85rem;}
  .step.active{background:#fff; color:#0b1a37;}
  .step.active .num{background:var(--pri); color:#fff;}

  .table> :not(caption)>*>*{border-color:#e9eef5}
  .thumb{width:64px;height:64px;object-fit:cover;border-radius:12px;border:1px solid var(--line);background:#fff}

  .qty-wrap{display:inline-flex; align-items:center;}
  .qty-btn{width:38px; height:38px; line-height:1;}
  .qty-input{width:76px; height:38px; text-align:center}

  .cart-bar{
    position:sticky; bottom:max(env(safe-area-inset-bottom),0px); z-index:2;
    background:linear-gradient(180deg,#ffffff,#f9fbff); border:1px solid var(--line); border-radius:14px;
    box-shadow:0 14px 40px rgba(2,6,23,.08); padding:.75rem; margin:0 .5rem .75rem .5rem;
  }
  .edit-toggle.active{background:#e0e7ff;border-color:#c7d2fe}
  .col-select{ width:42px; }
  .select-cell, .select-all-cell{ display:none; }
  .editing .select-cell, .editing .select-all-cell{ display:table-cell; }

  .price-old{ text-decoration:line-through; color:#94a3b8; }
  .price-now{ color:#16a34a; font-weight:700; }

  /* Mobile layout */
  @media (max-width: 992px){
    .table thead{ display:none; }
    .table tbody tr{
      display:block; margin:12px 0;
      border:1px solid var(--line); border-radius:14px; padding:12px; background:#fff;
    }
    .table tbody td{ border:0; padding:.35rem 0; }
    .table tbody td[data-label="สินค้า"] .thumb{ width:56px;height:56px }
    .table tbody td[data-label="จำนวน"],
    .table tbody td[data-label="ราคา/ชิ้น"],
    .table tbody td[data-label="รวม"]{
      display:flex; justify-content:space-between; align-items:center;
    }
    .table tbody td:last-child{
      display:flex; justify-content:flex-end;
    }
    .editing .select-cell{ display:block; order:-1; margin-bottom:.25rem }
    .cart-bar{ padding:.65rem; border-radius:12px }
    .qty-btn{ width:36px; height:36px }
    .qty-input{ width:70px; height:36px }
  }
  @media (max-width:576px){
    .thumb{width:52px;height:52px}
    .qty-input{ width:64px }
  }
</style>
</head>
<body>

<?php include __DIR__.'/includes/header.php'; ?>

<main class="page-content">
  <div class="container py-4">

    <div class="page-head mb-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="m-0"><i class="bi bi-cart3 me-2"></i>รถเข็นของฉัน</h3>
        <div class="d-flex gap-2">
          <button id="btnEdit" class="btn btn-light edit-toggle">
            <i class="bi bi-pencil-square"></i> แก้ไข
          </button>
          <a href="products.php" class="btn btn-outline-light">
            <i class="bi bi-bag"></i> เลือกซื้อสินค้าต่อ
          </a>
        </div>
      </div>
      <div class="stepper">
        <div class="step active"><span class="num">1</span> รถเข็น</div>
        <div class="step"><span class="num">2</span> ชำระเงิน</div>
      </div>
    </div>

    <?php if (empty($items)): ?>
      <div class="card card-glass p-5 text-center text-muted">
        <div class="mb-2" style="font-size:2rem">🧺</div>
        ยังไม่มีสินค้าในรถเข็น
        <div class="mt-2"><a href="products.php" class="btn btn-primary">เริ่มช็อปเลย</a></div>
      </div>
    <?php else: ?>
      <div class="card card-glass">
        <div class="table-responsive">
          <table id="cartTable" class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="select-all-cell col-select text-center">
                  <input type="checkbox" id="checkAll">
                </th>
                <th>สินค้า</th>
                <th class="text-center" style="width:230px">จำนวน</th>
                <th class="text-end">ราคา/ชิ้น</th>
                <th class="text-end">รวม</th>
                <th class="text-end"></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $total = 0;
              foreach ($items as $it):
                if (($it['status'] ?? 'active') !== 'active') continue;
                $base = (float)$it['price'];
                $now  = ($it['discount_price'] && $it['discount_price'] < $it['price']) ? (float)$it['discount_price'] : $base;
                $sum   = $now * (int)$it['quantity'];
                $total += $sum;
                $img = imgsrc($it['image']);
              ?>
              <tr data-pid="<?= (int)$it['product_id'] ?>" data-ci="<?= (int)$it['id'] ?>">
                <td class="select-cell col-select text-center">
                  <input type="checkbox" class="row-check">
                </td>

                <!-- สินค้า -->
                <td data-label="สินค้า">
                  <div class="d-flex align-items-center gap-3">
                    <img src="<?= htmlspecialchars($img) ?>" class="thumb" loading="lazy" decoding="async" alt="">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($it['name']) ?></div>
                      <div class="small text-muted">คงเหลือ: <?= (int)$it['stock'] ?></div>
                      <?php if($now < $base): ?>
                        <div class="small"><span class="price-old"><?=baht($base)?> ฿</span>
                          <span class="price-now ms-1"><?=baht($now)?> ฿</span></div>
                      <?php else: ?>
                        <div class="small fw-semibold"><?=baht($now)?> ฿</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>

                <!-- จำนวน -->
                <td data-label="จำนวน" class="text-center">
                  <div class="qty-wrap">
                    <form action="cart_update.php" method="post" class="me-1">
                      <input type="hidden" name="id"  value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="qty" value="<?= max(1, (int)$it['quantity']-1) ?>">
                      <button class="btn btn-outline-secondary btn-sm qty-btn" <?= $it['quantity']<=1 ? 'disabled':'' ?> aria-label="ลดจำนวน">−</button>
                    </form>

                    <form action="cart_update.php" method="post" class="d-inline-flex">
                      <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                      <input type="number" name="qty" class="form-control form-control-sm qty-input"
                             min="1" max="<?= max(1,(int)$it['stock']) ?>"
                             value="<?= (int)$it['quantity'] ?>">
                      <button class="btn btn-sm btn-primary ms-1">อัปเดต</button>
                    </form>

                    <form action="cart_update.php" method="post" class="ms-1">
                      <input type="hidden" name="id"  value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="qty" value="<?= min((int)$it['stock'], (int)$it['quantity']+1) ?>">
                      <button class="btn btn-outline-secondary btn-sm qty-btn" <?= $it['quantity']>=$it['stock'] ? 'disabled':'' ?> aria-label="เพิ่มจำนวน">+</button>
                    </form>
                  </div>
                </td>

                <td data-label="ราคา/ชิ้น" class="text-end"><?= baht($now) ?> ฿</td>
                <td data-label="รวม" class="text-end sum-cell"><?= baht($sum) ?> ฿</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-danger btn-remove" title="ลบชิ้นนี้" aria-label="ลบชิ้นนี้">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th class="select-all-cell"></th>
                <th colspan="3" class="text-end">รวมทั้งหมด</th>
                <th class="text-end" id="grandTotal"><?= baht($total) ?> ฿</th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Sticky bar -->
        <div class="cart-bar" role="region" aria-label="สรุปรายการรถเข็น">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <div id="mobileSelectWrap" class="form-check d-none me-2">
              <input class="form-check-input" type="checkbox" id="mobileSelectAll">
              <label class="form-check-label small" for="mobileSelectAll">เลือกทั้งหมด</label>
            </div>

            <div class="me-auto small text-muted">
              <span id="selInfo">ยังไม่ได้เลือกสินค้า</span>
            </div>

            <button id="btnDeleteSel" class="btn btn-outline-danger" disabled>
              <i class="bi bi-trash3"></i> ลบที่เลือก
            </button>
            <a href="checkout.php" class="btn btn-success">
              ดำเนินการชำระเงิน <i class="bi bi-arrow-right-short"></i>
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <!-- safe area updater -->
  <script>
    (function(){
      const bar = document.querySelector('.cart-bar'); if(!bar) return;
      function setSafe(){
        const h = Math.ceil(bar.getBoundingClientRect().height) + 28;
        document.documentElement.style.setProperty('--safe', h + 'px');
      }
      setSafe();
      addEventListener('load', setSafe);
      addEventListener('resize', setSafe);
      if('ResizeObserver' in window){ new ResizeObserver(setSafe).observe(bar); }
    })();
  </script>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function(){
  const table   = document.getElementById('cartTable');
  if(!table) return;

  const btnEdit   = document.getElementById('btnEdit');
  const checkAll  = document.getElementById('checkAll');
  const btnDelete = document.getElementById('btnDeleteSel');
  const tbody     = table.querySelector('tbody');
  const totalCell = document.getElementById('grandTotal');
  const selInfo   = document.getElementById('selInfo');

  const mobileWrap = document.getElementById('mobileSelectWrap');
  const mobileAll  = document.getElementById('mobileSelectAll');

  const parseBaht = s => parseFloat(String(s).replace(/[^\d.-]/g,''))||0;
  const fmtBaht   = n => (Number(n)||0).toLocaleString('th-TH',{minimumFractionDigits:2, maximumFractionDigits:2})+' ฿';

  function recalcTotal(){
    let sum = 0;
    table.querySelectorAll('.sum-cell').forEach(td => sum += parseBaht(td.textContent));
    totalCell.textContent = fmtBaht(sum);
  }
  function updateDeleteBtn(){
    const sel = tbody.querySelectorAll('.row-check:checked').length;
    btnDelete.disabled = sel === 0;
    selInfo.textContent = sel>0 ? `เลือกไว้ ${sel} รายการ` : 'ยังไม่ได้เลือกสินค้า';
  }
  function syncSelectAll(){
    const all = tbody.querySelectorAll('.row-check').length;
    const ck  = tbody.querySelectorAll('.row-check:checked').length;
    const val = (all>0 && ck===all);
    if(checkAll)  checkAll.checked  = val;
    if(mobileAll) mobileAll.checked = val;
  }
  function setAllRows(val){
    tbody.querySelectorAll('.row-check').forEach(ch=> ch.checked = !!val);
    updateDeleteBtn(); syncSelectAll();
  }
  function toggleMobileAllVisibility(){
    const isEditing = table.classList.contains('editing');
    const isMobile  = window.innerWidth < 992;
    if(mobileWrap) mobileWrap.classList.toggle('d-none', !(isEditing && isMobile));
  }

  btnEdit?.addEventListener('click', ()=>{
    btnEdit.classList.toggle('active');
    table.classList.toggle('editing');
    if(!table.classList.contains('editing')) setAllRows(false);
    toggleMobileAllVisibility();
  });

  checkAll?.addEventListener('change', ()=> setAllRows(checkAll.checked));
  mobileAll?.addEventListener('change', ()=> setAllRows(mobileAll.checked));
  addEventListener('resize', toggleMobileAllVisibility);
  toggleMobileAllVisibility();

  tbody.addEventListener('change', (e)=>{
    if(e.target.classList.contains('row-check')){
      updateDeleteBtn(); syncSelectAll();
    }
  });

  /* ลบทีละชิ้น */
  tbody.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.btn-remove'); if(!btn) return;
    const tr  = btn.closest('tr');
    const pid = tr.dataset.pid;

    const ok = await Swal.fire({
      icon:'question', title:'ลบสินค้านี้?',
      showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก'
    }).then(r=>r.isConfirmed);
    if(!ok) return;

    await removeProducts([pid]);
    tr.remove();
    afterRowChange();
  });

  /* ลบหลายชิ้น */
  btnDelete?.addEventListener('click', async ()=>{
    const ids = Array.from(tbody.querySelectorAll('.row-check:checked')).map(ch => ch.closest('tr').dataset.pid);
    if(ids.length===0) return;

    const ok = await Swal.fire({
      icon:'warning', title:'ลบที่เลือกทั้งหมด?', text:`จำนวน ${ids.length} รายการ`,
      showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก'
    }).then(r=>r.isConfirmed);
    if(!ok) return;

    await removeProducts(ids);
    tbody.querySelectorAll('.row-check:checked').forEach(ch => ch.closest('tr').remove());
    setAllRows(false);
    afterRowChange();
  });

  function afterRowChange(){
    if(tbody.children.length===0){
      table.parentElement.innerHTML = `<div class="p-4 text-center text-muted">ยังไม่มีสินค้าในรถเข็น</div>`;
    }else{
      recalcTotal();
    }
    updateDeleteBtn(); syncSelectAll();
  }

  /* API: bulk_remove ถ้ามี -> ถ้าไม่มี fallback ยิง remove ทีละตัว */
  async function removeProducts(productIds){
    try{
      const r = await fetch('cart_api.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'bulk_remove', product_ids: productIds })
      });
      let j; try{ j = await r.json(); }catch(_){ j=null; }
      if(!j || j.status!=='success'){
        for(const pid of productIds){
          await fetch('cart_api.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'remove', product_id: pid })
          });
        }
      }
      // อัปเดตก้อนนับใน header ถ้ามี
      try{
        const rs = await fetch('cart_api.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ action:'count' })
        });
        const jj = await rs.json();
        const badge = document.getElementById('cart-count');
        if(badge) badge.textContent = jj.count ?? '';
      }catch(_){}
      Swal.fire({toast:true,position:'bottom-end',icon:'success',title:'ลบแล้ว',showConfirmButton:false,timer:1600});
    }catch(e){
      console.error(e);
      Swal.fire({icon:'error', title:'ลบไม่สำเร็จ'});
    }
  }
})();
</script>
</body>
</html>
