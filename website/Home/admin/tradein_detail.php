<?php
// Home/admin/tradein_detail.php  (revamped)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/tradein_requests.php'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ header('Location: tradein_requests.php'); exit; }

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function imgsrc_file($fn){ return '../assets/img/'.$fn; }
function imgsrc_any($v){
  $v = trim((string)$v);
  if ($v==='') return '../assets/img/no-image.png';
  if (preg_match('~^https?://|^data:image/~',$v)) return $v;
  return (strpos($v,'/')!==false) ? '../'.$v : '../assets/img/'.$v;
}
/* ---------- helpers: แจ้งเตือน ---------- */
function notify_user(mysqli $conn, int $userId, string $type, int $refId, string $title, string $message): void {
  $st = $conn->prepare("INSERT INTO notifications (user_id,type,ref_id,title,message,is_read) VALUES (?,?,?,?,?,0)");
  $st->bind_param("isiss", $userId, $type, $refId, $title, $message);
  $st->execute(); $st->close();
}

$map = [
  'submitted'=>'ส่งคำขอแล้ว',
  'review'=>'กำลังประเมิน',
  'offered'=>'มีราคาเสนอ',
  'accepted'=>'ยอมรับข้อเสนอ',
  'rejected'=>'ปฏิเสธข้อเสนอ',
  'cancelled'=>'ยกเลิก',
  'completed'=>'เสร็จสิ้น'
];
$badge = [
  'submitted'=>'secondary','review'=>'info',
  'offered'=>'primary','accepted'=>'success',
  'rejected'=>'danger','cancelled'=>'danger','completed'=>'success'
];

/* ===== Flash (PRG) ===== */
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* ===== Actions ===== */
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $do = $_POST['do'] ?? '';

  /* --- ราคา/สินค้า ที่เสนอ --- */
  if ($do === 'update_offer') {
    // ราคา: ว่าง = NULL
    $price = ($_POST['offer_price'] === '' ? null : (float)$_POST['offer_price']);

    // ตรวจ need
    $needRow = $conn->query("SELECT need FROM tradein_requests WHERE id={$id}")->fetch_assoc();
    $need = $needRow['need'] ?? '';

    // product id เฉพาะกรณี buy_new และต้องมีจริง
    $prod = null;
    $prodIn = trim($_POST['selected_product_id'] ?? '');
    if ($need === 'buy_new' && $prodIn !== '') {
      $pid = (int)$prodIn;
      if ($pid > 0) {
        if ($st = $conn->prepare("SELECT id FROM products WHERE id=? LIMIT 1")) {
          $st->bind_param('i', $pid);
          $st->execute();
          $exists = $st->get_result()->fetch_assoc()['id'] ?? null;
          $st->close();
          if ($exists) { $prod = $pid; } // ไม่เจอ = คงไว้เป็น NULL
        }
      }
    }
    // แจ้งลูกค้าว่ามีข้อเสนอใหม่
    $u = $conn->query("SELECT user_id, need FROM tradein_requests WHERE id={$id}")->fetch_assoc();
    if ($u) {
      $uid = (int)$u['user_id'];
      $needTxt = ($u['need']==='buy_new') ? 'เทิร์นซื้อใหม่' : 'ขายรับเงินสด';
      $bits = [];
      if (!is_null($price)) $bits[] = 'ราคาเสนอ ~'.number_format($price,2).'฿';
      if (!is_null($prod))  $bits[] = 'รหัสสินค้า #'.$prod;
      $msg = $needTxt.($bits?(' • '.implode(' • ',$bits)):'');
      notify_user($conn, $uid, 'tradein_status', $id, 'มีข้อเสนอเทิร์นใหม่', $msg);
    }

    // อัปเดต โดยจัดการ NULL ให้ถูกต้อง
    if (is_null($price) && is_null($prod)) {
      $st = $conn->prepare("UPDATE tradein_requests SET offer_price=NULL, selected_product_id=NULL, updated_at=NOW() WHERE id=?");
      $st->bind_param('i', $id);
    } elseif (is_null($price)) {
      $st = $conn->prepare("UPDATE tradein_requests SET offer_price=NULL, selected_product_id=?, updated_at=NOW() WHERE id=?");
      $st->bind_param('ii', $prod, $id);
    } elseif (is_null($prod)) {
      $st = $conn->prepare("UPDATE tradein_requests SET offer_price=?, selected_product_id=NULL, updated_at=NOW() WHERE id=?");
      $st->bind_param('di', $price, $id);
    } else {
      $st = $conn->prepare("UPDATE tradein_requests SET offer_price=?, selected_product_id=?, updated_at=NOW() WHERE id=?");
      $st->bind_param('dii', $price, $prod, $id);
    }
    $st->execute(); $st->close();

    $_SESSION['flash'] = 'บันทึก “ราคา/รหัสสินค้าที่เลือก” แล้ว';
    header("Location: tradein_detail.php?id=".$id); exit;
  }

  /* --- อัปโหลดรูป --- */
  if ($do==='upload_images'){
    $saved = save_tradein_images($_FILES['images'] ?? null, __DIR__.'/../assets/img', $conn, $id, 12);
    if ($saved && count($saved)>0){
      // ถ้ายังไม่มีรูปปก ให้ตั้งไฟล์แรกเป็นปก และ sync ไปที่ tradein_requests.image_path
      $hasCover = $conn->query("SELECT 1 FROM tradein_images WHERE request_id=$id AND is_cover=1 LIMIT 1")->fetch_row();
      if(!$hasCover){
        $fn = $saved[0]['filename'] ?? '';
        if($fn!==''){
          $conn->query("UPDATE tradein_images SET is_cover=0 WHERE request_id=$id");
          if ($st=$conn->prepare("UPDATE tradein_images SET is_cover=1 WHERE request_id=? AND filename=?")){
            $st->bind_param('is',$id,$fn); $st->execute(); $st->close();
          }
          if ($st=$conn->prepare("UPDATE tradein_requests SET image_path=?, updated_at=NOW() WHERE id=?")){
            $rel = 'assets/img/'.$fn; $st->bind_param('si',$rel,$id); $st->execute(); $st->close();
          }
        }
      }
      $_SESSION['flash'] = 'อัปโหลดรูปแล้ว: '.count($saved).' ไฟล์';
    }else{
      $_SESSION['flash'] = 'ไม่สามารถอัปโหลดรูปได้';
    }
    header("Location: tradein_detail.php?id=".$id); exit;
  }

  /* --- ตั้งรูปปก --- */
  if ($do==='set_cover'){
    $imgId = (int)($_POST['image_id'] ?? 0);
    if ($imgId>0){
      $conn->query("UPDATE tradein_images SET is_cover=0 WHERE request_id=".$id);
      if ($st=$conn->prepare("UPDATE tradein_images SET is_cover=1 WHERE id=? AND request_id=?")){
        $st->bind_param('ii',$imgId,$id); $st->execute(); $st->close();
      }
      $fn = $conn->query("SELECT filename FROM tradein_images WHERE id=$imgId")->fetch_assoc()['filename'] ?? '';
      if($fn!==''){
        if ($st=$conn->prepare("UPDATE tradein_requests SET image_path=?, updated_at=NOW() WHERE id=?")){
          $rel = 'assets/img/'.$fn; $st->bind_param('si',$rel,$id); $st->execute(); $st->close();
        }
      }
      $_SESSION['flash']='ตั้งรูปปกแล้ว';
    }
    header("Location: tradein_detail.php?id=".$id); exit;
  }

  /* --- ลบรูป --- */
  if ($do==='delete_image'){
    $imgId = (int)($_POST['image_id'] ?? 0);
    if ($imgId>0){
      $row = $conn->query("SELECT filename,is_cover FROM tradein_images WHERE id=$imgId AND request_id=$id")->fetch_assoc();
      if($row){
        @unlink(__DIR__.'/../assets/img/'.$row['filename']);
        $conn->query("DELETE FROM tradein_images WHERE id=$imgId AND request_id=$id");
        if((int)$row['is_cover']===1){
          $conn->query("UPDATE tradein_requests SET image_path=NULL, updated_at=NOW() WHERE id=$id");
        }
        $_SESSION['flash']='ลบรูปแล้ว';
      }
    }
    header("Location: tradein_detail.php?id=".$id); exit;
  }

  /* --- ✅ อัปเดตสถานะ + LOG (แก้บั๊กเดิม) --- */
  if ($do==='update_status'){
    $status = trim($_POST['status'] ?? '');
    $note   = trim($_POST['note'] ?? '');
    if (!array_key_exists($status, $map)) {
      $_SESSION['flash'] = 'สถานะไม่ถูกต้อง'; header("Location: tradein_detail.php?id=".$id); exit;
    }

    if ($st=$conn->prepare("UPDATE tradein_requests SET status=?, updated_at=NOW() WHERE id=?")){
      $st->bind_param('si', $status, $id); $st->execute(); $st->close();
    }
    if ($st=$conn->prepare("INSERT INTO tradein_status_logs (request_id, status, note, created_at) VALUES (?,?,?,NOW())")){
      $st->bind_param('iss', $id, $status, $note); $st->execute(); $st->close();
    }
// แจ้งลูกค้าตามสถานะล่าสุด
$row = $conn->query("SELECT user_id, need, offer_price FROM tradein_requests WHERE id={$id}")->fetch_assoc();
if ($row) {
  $uid = (int)$row['user_id'];
  $needTxt = ($row['need']==='buy_new') ? 'เทิร์นซื้อใหม่' : 'ขายรับเงินสด';
  $titleMap = [
    'submitted'=>'รับคำขอแล้ว','review'=>'กำลังประเมิน','offered'=>'มีราคาเสนอ',
    'accepted'=>'ยอมรับข้อเสนอแล้ว','rejected'=>'ปฏิเสธข้อเสนอ','cancelled'=>'คำขอถูกยกเลิก','completed'=>'ดำเนินการเสร็จสิ้น'
  ];
  $title = $titleMap[$status] ?? ('อัปเดตสถานะ: '.$status);
  $bits = [];
  if ($status==='offered' && $row['offer_price']!=='') $bits[] = 'ราคาเสนอ ~'.number_format((float)$row['offer_price'],2).'฿';
  if ($note!=='') $bits[] = 'หมายเหตุ: '.$note;
  $msg = $needTxt.($bits?(' • '.implode(' • ',$bits)):'');
  notify_user($conn, $uid, 'tradein_status', $id, $title, $msg);
}

    $_SESSION['flash'] = 'บันทึกสถานะเรียบร้อย';
    header("Location: tradein_detail.php?id=".$id); exit;
  }
}

/* ===== Load data ===== */
$req = $conn->query("SELECT * FROM tradein_requests WHERE id=$id")->fetch_assoc();
if(!$req){ header('Location: tradein_requests.php'); exit; }
$logs    = $conn->query("SELECT * FROM tradein_status_logs WHERE request_id=$id ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
$gallery = load_tradein_gallery($conn, $id);

// cover for preview
$cover = $req['image_path'] ?: ( ($gallery[0]['filename'] ?? '') ? 'assets/img/'.$gallery[0]['filename'] : '' );
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>จัดการเทิร์น TR-<?= (int)$req['id'] ?> | Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root{ --bg:#f6f8fb; --panel:#ffffffcc; --border:#e9eef5; --text:#0f172a; --muted:#64748b; --primary:#4f46e5; --card-shadow:0 18px 60px rgba(2,6,23,.06);}
  html[data-theme="dark"]{ --bg:#0b1220; --panel:rgba(17,24,39,.7); --border:#1f2a44; --text:#e5e7eb; --muted:#9aa4b2; --card-shadow:0 20px 70px rgba(2,6,23,.45);}
  body{background:var(--bg); color:var(--text);}
  .topbar{backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); background:linear-gradient(180deg,#ffffffcc,#ffffffaa); border-bottom:1px solid var(--border);}
  html[data-theme="dark"] .topbar{ background:linear-gradient(180deg,#0f172acc,#0f172aa6); }
  .glass{border:1px solid var(--border); background:var(--panel); border-radius:18px; box-shadow:var(--card-shadow);}
  .thumb{width:100%; height:140px; object-fit:cover; border-radius:12px; border:1px solid var(--border); background:#fff}
  .kv .k{color:var(--muted); font-size:.85rem}
  .kv .v{font-weight:600}
  .timeline{position:relative; padding-left:1rem}
  .timeline::before{content:""; position:absolute; left:.6rem; top:4px; bottom:4px; width:2px; background:#e6edf6}
  .tl-item{position:relative; padding-left:1.4rem; margin-bottom:.75rem}
  .tl-point{position:absolute; left:-.05rem; top:.35rem; width:12px; height:12px; border-radius:999px; background:#4f46e5; box-shadow:0 0 0 3px #e7f0ff}
  /* dark inputs */
  html[data-theme="dark"] .form-control, html[data-theme="dark"] .form-select{
    background:#0f172a; color:#e5e7eb; border-color:#1f2a44;
  }
</style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar topbar sticky-top py-2">
  <div class="container-fluid px-3">
    <div class="d-flex align-items-center gap-2">
      <a href="tradein_requests.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> ย้อนกลับ
      </a>
      <span class="badge bg-primary rounded-pill px-3 py-2">Trade-in</span>
      <span class="fw-semibold d-none d-md-inline">จัดการคำขอ <b>TR-<?= (int)$req['id'] ?></b></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="dashboard.php"><i class="bi bi-speedometer2"></i></a>
      <a class="btn btn-outline-secondary btn-sm" href="tradein_requests.php"><i class="bi bi-card-list"></i></a>
      <button class="btn btn-outline-secondary btn-sm" id="themeToggle" title="สลับโหมด"><i class="bi bi-moon-stars"></i></button>
    </div>
  </div>
</nav>

<div class="container py-3">
  <?php if($flash): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= h($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- LEFT -->
    <div class="col-lg-7">
      <div class="glass p-3">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
          <div class="fw-semibold"><i class="bi bi-info-circle me-2"></i>รายละเอียดคำขอ</div>
          <span class="badge bg-<?= $badge[$req['status']] ?? 'secondary' ?>">
            <?= h($map[$req['status']] ?? $req['status']) ?>
          </span>
        </div>

        <div class="row g-3 kv">
          <div class="col-md-4"><div class="k">ประเภท</div><div class="v"><?=h($req['device_type'])?></div></div>
          <div class="col-md-4"><div class="k">ยี่ห้อ</div><div class="v"><?=h($req['brand'])?></div></div>
          <div class="col-md-4"><div class="k">รุ่น</div><div class="v"><?=h($req['model'])?></div></div>
          <div class="col-md-6"><div class="k">สภาพ</div><div class="v"><?=h($req['device_condition'])?></div></div>
          <div class="col-md-6"><div class="k">ความต้องการ</div><div class="v"><span class="badge text-bg-secondary"><?=h($req['need'])?></span></div></div>
        </div>

        <hr>
        <img class="img-fluid rounded border" src="<?=h(imgsrc_any($cover))?>" alt="">
        <div class="small text-muted mt-1">รูปปก</div>

        <hr>
        <h6 class="mb-2"><i class="bi bi-cash-coin me-1"></i>กำหนดราคา/สินค้า</h6>
        <form method="post" class="row g-2">
          <input type="hidden" name="do" value="update_offer">
          <div class="col-md-6">
            <label class="form-label">ราคาเสนอ (บาท)</label>
            <input class="form-control" type="number" step="0.01" name="offer_price" value="<?=h($req['offer_price'])?>">
          </div>
          <div class="col-12">
            <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
          </div>
        </form>

        <hr>
        <h6 class="mb-2"><i class="bi bi-images me-1"></i>อัปโหลดรูป (ได้หลายไฟล์)</h6>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <input type="hidden" name="do" value="upload_images">
          <div class="col-md-8">
            <label class="form-label">เลือกไฟล์</label>
            <input class="form-control" type="file" name="images[]" accept="image/*" multiple>
          </div>
          <div class="col-md-4 d-grid">
            <button class="btn btn-outline-primary"><i class="bi bi-cloud-arrow-up"></i> อัปโหลด</button>
          </div>
        </form>

        <div class="row g-2 mt-2">
          <?php if(!$gallery): ?>
            <div class="text-muted">ยังไม่มีรูปในแกลเลอรี</div>
          <?php else: foreach($gallery as $g): ?>
            <div class="col-4 col-md-3">
              <img class="thumb" src="<?=h(imgsrc_file($g['filename']))?>" alt="">
              <div class="d-flex gap-1 mt-1">
                <?php if((int)$g['is_cover']===1): ?>
                  <span class="badge text-bg-success w-100">รูปปก</span>
                <?php else: ?>
                  <form method="post" class="w-100 d-inline">
                    <input type="hidden" name="do" value="set_cover">
                    <input type="hidden" name="image_id" value="<?= (int)$g['id'] ?>">
                    <button class="btn btn-sm btn-outline-success w-100">ตั้งปก</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="w-100 d-inline" onsubmit="return confirm('ลบรูปนี้?')">
                  <input type="hidden" name="do" value="delete_image">
                  <input type="hidden" name="image_id" value="<?= (int)$g['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger w-100">ลบ</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <div class="col-lg-5">
      <div class="glass p-3">
        <div class="fw-semibold border-bottom pb-2 mb-3"><i class="bi bi-arrow-repeat me-2"></i>อัปเดตสถานะ</div>
        <form method="post" class="mb-3">
          <input type="hidden" name="do" value="update_status">
          <div class="mb-2">
            <select class="form-select" name="status" required>
              <?php foreach($map as $k=>$v): ?>
                <option value="<?=h($k)?>" <?= $k===($req['status'] ?? '') ? 'selected':'' ?>><?=h($v)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <textarea class="form-control" name="note" rows="2" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึกสถานะ</button>
            <a class="btn btn-outline-secondary" href="tradein_requests.php"><i class="bi bi-arrow-left"></i> กลับรายการ</a>
          </div>
        </form>

        <div class="fw-semibold border-top pt-3 mb-2"><i class="bi bi-clock-history me-2"></i>ไทม์ไลน์สถานะ</div>
        <div class="timeline">
          <?php if(!$logs): ?>
            <div class="text-muted">ยังไม่มีบันทึกสถานะ</div>
          <?php else: foreach($logs as $lg): ?>
            <div class="tl-item">
              <span class="tl-point"></span>
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold"><?=h($map[$lg['status']] ?? $lg['status'])?></div>
                  <?php if(!empty($lg['note'])): ?>
                    <div class="text-muted small"><?=nl2br(h($lg['note']))?></div>
                  <?php endif; ?>
                </div>
                <div class="text-muted small"><?=h($lg['created_at'])?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Theme toggle (remember)
  (function(){
    const html = document.documentElement;
    const saved = localStorage.getItem('admin-theme') || 'light';
    html.setAttribute('data-theme', saved);
    document.getElementById('themeToggle')?.addEventListener('click', ()=>{
      const cur = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', cur);
      localStorage.setItem('admin-theme', cur);
    });
  })();
  
</script>
</body>
</html>
