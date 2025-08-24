<?php
// Home/checkout.php
session_start();
require __DIR__.'/includes/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php?redirect=checkout.php");
  exit;
}

$user_id = (int)$_SESSION['user_id'];

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function pickPrice($row){
  return ($row['discount_price'] && $row['discount_price'] < $row['price'])
          ? (float)$row['discount_price'] : (float)$row['price'];
}

/* ---------------- 1) สร้างตะกร้าที่จะชำระ ---------------- */
$items = [];
$total = 0.0;

if (isset($_GET['product_id']) && isset($_GET['qty'])) {
  // ซื้อเลย
  $pid = (int)$_GET['product_id'];
  $qty = max(1, (int)$_GET['qty']);

  $st = $conn->prepare("SELECT id,name,price,discount_price,image,stock FROM products WHERE id=? AND status='active' LIMIT 1");
  $st->bind_param("i",$pid);
  $st->execute();
  if ($p = $st->get_result()->fetch_assoc()){
    $price = pickPrice($p);
    $qty   = min($qty, (int)$p['stock']);
    if ($qty > 0) {
      $sum = $price * $qty;
      $items[] = ['id'=>$p['id'],'name'=>$p['name'],'image'=>$p['image'],'price'=>$price,'qty'=>$qty,'sum'=>$sum];
      $total += $sum;
    }
  }
  $st->close();
} else {
  // จากรถเข็น
  $sql = "SELECT ci.quantity, p.id pid, p.name, p.price, p.discount_price, p.image, p.stock
          FROM cart_items ci JOIN products p ON p.id = ci.product_id
          WHERE ci.user_id=?";
  $st = $conn->prepare($sql);
  $st->bind_param("i",$user_id);
  $st->execute();
  $rs = $st->get_result();
  while($row = $rs->fetch_assoc()){
    $price = pickPrice($row);
    $qty   = min((int)$row['quantity'], (int)$row['stock']);
    if ($qty <= 0) continue;
    $sum = $price * $qty;
    $items[] = ['id'=>$row['pid'],'name'=>$row['name'],'image'=>$row['image'],'price'=>$price,'qty'=>$qty,'sum'=>$sum];
    $total += $sum;
  }
  $st->close();
}

/* ---------------- 2) ดึงที่อยู่ผู้ใช้ไว้เติมอัตโนมัติ ---------------- */
$profile = [
  'full_name' => '', 'phone' => '',
  'address_line1' => '', 'address_line2' => '',
  'district' => '', 'province' => '', 'postcode' => ''
];
$st = $conn->prepare("SELECT COALESCE(full_name,'') full_name, COALESCE(phone,'') phone,
                             COALESCE(address_line1,'') address_line1, COALESCE(address_line2,'') address_line2,
                             COALESCE(district,'') district, COALESCE(province,'') province, COALESCE(postcode,'') postcode
                      FROM users WHERE id=? LIMIT 1");
$st->bind_param("i",$user_id);
$st->execute();
if ($u = $st->get_result()->fetch_assoc()) { $profile = $u; }
$st->close();

/* สมุดที่อยู่ */
$book = [];
$st = $conn->prepare("SELECT id, COALESCE(full_name,'') full_name, COALESCE(phone,'') phone,
                             COALESCE(address_line1,'') a1, COALESCE(address_line2,'') a2,
                             COALESCE(district,'') dist, COALESCE(province,'') prov, COALESCE(postcode,'') pc,
                             is_default
                      FROM user_addresses
                      WHERE user_id=?
                      ORDER BY is_default DESC, id DESC");
$st->bind_param("i",$user_id);
$st->execute();
$book_rs = $st->get_result();
while($r = $book_rs->fetch_assoc()){
  $book[] = $r;
}
$st->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ชำระเงิน | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb}
    .card{border-radius:16px;border:1px solid #e9eef3}
    .addr-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
    @media(max-width:992px){.addr-grid{grid-template-columns:1fr}}
    .hint{color:#6b7280}
  </style>
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <h3 class="mb-3"><i class="bi bi-cash-coin"></i> ชำระเงิน</h3>

  <?php if(empty($items)): ?>
    <div class="alert alert-warning">ไม่มีสินค้าในคำสั่งซื้อ</div>
  <?php else: ?>
  <div class="row g-3">
    <!-- สรุปรายการสินค้า -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header fw-semibold">สินค้า</div>
        <ul class="list-group list-group-flush">
          <?php foreach($items as $it):
            $img = $it['image'] ? 'assets/img/'.$it['image'] : 'assets/img/default.png';
          ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
              <img src="<?=h($img)?>" width="56" height="56" class="rounded" style="object-fit:cover">
              <div>
                <div class="fw-semibold"><?=h($it['name'])?></div>
                <div class="small text-muted">x <?= (int)$it['qty'] ?></div>
              </div>
            </div>
            <div class="fw-semibold"><?=baht($it['sum'])?> ฿</div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer text-end fw-bold">รวมทั้งหมด: <?=baht($total)?> ฿</div>
      </div>
    </div>

    <!-- ฟอร์มที่อยู่ + วิธีชำระ -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header fw-semibold">ข้อมูลการจัดส่ง</div>
        <div class="card-body">
          <form action="place_order.php" method="post" id="checkoutForm">
            <!-- เลือกแหล่งที่อยู่ -->
            <div class="mb-3">
              <label class="form-label">เลือกที่อยู่</label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="address_source" id="addrProfile" value="profile" checked>
                <label class="form-check-label" for="addrProfile">ใช้ที่อยู่จากโปรไฟล์</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="address_source" id="addrBook" value="book" <?=empty($book)?'disabled':'';?>>
                <label class="form-check-label" for="addrBook">เลือกจากสมุดที่อยู่</label>
              </div>
              <div class="ms-4 mt-2" id="bookPicker" style="display:none">
                <select class="form-select" name="address_id" id="address_id" <?=empty($book)?'disabled':'';?>>
                  <?php foreach($book as $b): 
                    $label = trim($b['full_name'].' | '.$b['phone'].' | '.$b['a1'].' '.$b['a2'].' '.$b['dist'].' '.$b['prov'].' '.$b['pc']);
                  ?>
                    <option value="<?=$b['id']?>"><?=$b['is_default']?'[ค่าเริ่มต้น] ':''?><?=h($label)?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="address_source" id="addrCustom" value="custom">
                <label class="form-check-label" for="addrCustom">กรอกที่อยู่ใหม่</label>
              </div>
            </div>

            <!-- ฟิลด์ที่อยู่ (จะใส่ค่าอัตโนมัติเมื่อเลือกโปรไฟล์/สมุดที่อยู่) -->
            <div class="mb-2">
              <label class="form-label">ชื่อ-นามสกุล</label>
              <input type="text" name="fullname" id="fullname" class="form-control" required>
            </div>

            <div class="addr-grid">
              <div>
                <label class="form-label">เบอร์โทร</label>
                <input type="text" name="phone" id="phone" class="form-control" required>
              </div>
              <div>
                <label class="form-label">รหัสไปรษณีย์</label>
                <input type="text" name="postcode" id="postcode" class="form-control">
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 1)</label>
              <input type="text" name="address_line1" id="address_line1" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">ที่อยู่ (บรรทัดที่ 2)</label>
              <input type="text" name="address_line2" id="address_line2" class="form-control" placeholder="ตึก/หมู่บ้าน/ชั้น/ห้อง ฯลฯ (ถ้ามี)">
            </div>

            <div class="addr-grid">
              <div>
                <label class="form-label">เขต/อำเภอ</label>
                <input type="text" name="district" id="district" class="form-control">
              </div>
              <div>
                <label class="form-label">จังหวัด</label>
                <input type="text" name="province" id="province" class="form-control">
              </div>
            </div>

            <hr class="my-3">

            <!-- วิธีชำระ -->
            <div class="mb-3">
              <label class="form-label">วิธีการชำระเงิน</label>
              <select name="payment_method" class="form-select" required>
                <option value="cod" selected>เก็บเงินปลายทาง</option>
                <option value="bank">โอนเงินธนาคาร</option>
              </select>
              <div class="form-text hint">
                * เลือกเก็บชำระผ่านธนาคาร ต้องรอการตรวจสอบจาก ผู้ดูแลระบบก่อน
              </div>
            </div>

            <!-- ส่งข้อมูลสินค้าไปด้วย -->
            <?php foreach($items as $it): ?>
              <input type="hidden" name="product_id[]" value="<?=$it['id']?>">
              <input type="hidden" name="qty[]"        value="<?=$it['qty']?>">
            <?php endforeach; ?>

            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle"></i> ยืนยันการสั่งซื้อ (รวม <?=baht($total)?> ฿)
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// เติมค่าจากโปรไฟล์/สมุดที่อยู่อัตโนมัติ + toggle การแสดงตัวเลือก
const profile = <?=json_encode($profile, JSON_UNESCAPED_UNICODE)?>;
const book    = <?=json_encode($book, JSON_UNESCAPED_UNICODE)?>;

const el = (id)=>document.getElementById(id);
const inputs = ['fullname','phone','address_line1','address_line2','district','province','postcode'];

function fillFromProfile(){
  el('fullname').value      = profile.full_name || '';
  el('phone').value         = profile.phone || '';
  el('address_line1').value = profile.address_line1 || '';
  el('address_line2').value = profile.address_line2 || '';
  el('district').value      = profile.district || '';
  el('province').value      = profile.province || '';
  el('postcode').value      = profile.postcode || '';
}

function fillFromBook(){
  const sel = document.getElementById('address_id');
  const id  = parseInt(sel?.value || 0, 10);
  const a = book.find(x => x.id == id) || {};
  el('fullname').value      = a.full_name || '';
  el('phone').value         = a.phone || '';
  el('address_line1').value = a.a1 || '';
  el('address_line2').value = a.a2 || '';
  el('district').value      = a.dist || '';
  el('province').value      = a.prov || '';
  el('postcode').value      = a.pc || '';
}

function clearCustom(){
  inputs.forEach(id => el(id).value = '');
}

function toggleBookPicker(show){
  document.getElementById('bookPicker').style.display = show ? 'block' : 'none';
}

document.getElementById('addrProfile')?.addEventListener('change', e=>{
  if (!e.target.checked) return;
  toggleBookPicker(false);
  fillFromProfile();
});
document.getElementById('addrBook')?.addEventListener('change', e=>{
  if (!e.target.checked) return;
  toggleBookPicker(true);
  fillFromBook();
});
document.getElementById('addrCustom')?.addEventListener('change', e=>{
  if (!e.target.checked) return;
  toggleBookPicker(false);
  clearCustom();
});
document.getElementById('address_id')?.addEventListener('change', fillFromBook);

// เริ่มต้นด้วยโปรไฟล์
fillFromProfile();
</script>
</body>
</html>
