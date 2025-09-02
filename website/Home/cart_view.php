<?php
session_start();
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=cart_view.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$sql = "SELECT ci.id, ci.product_id, ci.quantity, p.name, p.price, p.discount_price, p.image, p.stock
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        WHERE ci.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function baht($n){ return number_format((float)$n, 2); }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>รถเข็นสินค้า | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100"><!-- ✅ flex layout -->

<?php include __DIR__.'/includes/header.php'; ?>

<main class="flex-grow-1"><!-- ✅ main ครอบคอนเทนต์ -->
<div class="container py-5">
  <h3 class="mb-4"><i class="bi bi-cart3"></i> รถเข็นของฉัน</h3>

  <?php if (empty($items)): ?>
    <div class="alert alert-info">ยังไม่มีสินค้าในรถเข็น</div>
  <?php else: ?>
    <table class="table align-middle">
      <thead>
        <tr>
          <th>สินค้า</th>
          <th class="text-center" style="width:220px">จำนวน</th>
          <th class="text-end">ราคา/ชิ้น</th>
          <th class="text-end">รวม</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php $total = 0; foreach ($items as $it):
        $price = ($it['discount_price'] && $it['discount_price'] < $it['price']) ? $it['discount_price'] : $it['price'];
        $sum = $price * $it['quantity']; $total += $sum;
        $img = $it['image'] ? 'assets/img/'.$it['image'] : 'assets/img/default.png';
      ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-3">
              <img src="<?= htmlspecialchars($img) ?>" class="rounded" width="60" height="60" style="object-fit:cover">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($it['name']) ?></div>
                <div class="text-muted small">คงเหลือในสต็อก: <?= (int)$it['stock'] ?></div>
              </div>
            </div>
          </td>

          <!-- จำนวน -->
          <td class="text-center">
            <div class="d-inline-flex align-items-center">
              <!-- − -->
              <form action="cart_update.php" method="post" class="me-1">
                <input type="hidden" name="id"  value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="qty" value="<?= max(1, (int)$it['quantity']-1) ?>">
                <button class="btn btn-outline-secondary btn-sm" <?= $it['quantity']<=1 ? 'disabled':'' ?>>−</button>
              </form>

              <!-- input -->
              <form action="cart_update.php" method="post" class="d-inline-flex">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="number" name="qty"
                       class="form-control form-control-sm text-center"
                       style="width:70px"
                       min="1" max="<?= max(1,(int)$it['stock']) ?>"
                       value="<?= (int)$it['quantity'] ?>">
                <button class="btn btn-sm btn-primary ms-1">อัปเดต</button>
              </form>

              <!-- + -->
              <form action="cart_update.php" method="post" class="ms-1">
                <input type="hidden" name="id"  value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="qty" value="<?= min((int)$it['stock'], (int)$it['quantity']+1) ?>">
                <button class="btn btn-outline-secondary btn-sm" <?= $it['quantity']>=$it['stock'] ? 'disabled':'' ?>>+</button>
              </form>
            </div>
          </td>

          <td class="text-end"><?= baht($price) ?> ฿</td>
          <td class="text-end"><?= baht($sum) ?> ฿</td>
          <td class="text-end">
            <a href="javascript:void(0)" class="btn btn-sm btn-outline-danger btn-remove" data-product-id="<?= (int)$it['product_id'] ?>">
              <i class="bi bi-trash"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="3" class="text-end">รวมทั้งหมด</th>
          <th class="text-end"><?= baht($total) ?> ฿</th>
          <th></th>
        </tr>
      </tfoot>
    </table>

    <div class="text-end">
      <a href="products.php" class="btn btn-outline-secondary">เลือกซื้อสินค้าต่อ</a>
      <a href="checkout.php" class="btn btn-success">ชำระเงิน</a>
    </div>
  <?php endif; ?>
</div>
</main><!-- ✅ ปิด main -->

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-remove');
  if(!btn) return;
  const pid = btn.dataset.productId;

  try {
    const res = await fetch('cart_api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action:'remove', product_id: pid })
    });
    const data = await res.json();

    if(data.status === 'success'){
      const row = btn.closest('tr');
      row.remove();

      const tbody = document.querySelector('table tbody');
      if(tbody && tbody.children.length === 0){
        document.querySelector('table').remove();
        document.querySelector('.container').insertAdjacentHTML('beforeend',
          '<div class="alert alert-info">ยังไม่มีสินค้าในรถเข็น</div>'
        );
      } else {
        let total = 0;
        document.querySelectorAll('table tbody tr').forEach(tr=>{
          const sumCell = tr.querySelector('td:nth-child(4)');
          if(sumCell){
            const val = parseFloat(sumCell.textContent.replace(/[^\d.-]/g,'')) || 0;
            total += val;
          }
        });
        document.querySelector('tfoot th.text-end').textContent = total.toLocaleString()+' ฿';
      }

      Swal.fire({toast:true,position:'bottom-end',icon:'error',title:'ลบสินค้าออกแล้ว',showConfirmButton:false,timer:2000,timerProgressBar:true});
      if(document.getElementById('cart-count')){
        document.getElementById('cart-count').textContent = data.cart_count;
      }
    }
  } catch(err){
    console.error(err);
    alert('เกิดข้อผิดพลาด');
  }
});
</script>
</body>
</html>
