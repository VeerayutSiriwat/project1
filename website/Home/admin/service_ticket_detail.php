<?php
// Home/admin/service_ticket_detail.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/service_tickets.php'); exit;
}
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

/* Helpers */
function img_url(string $p): string {
  $p = trim($p);
  if ($p==='') return '';
  if (preg_match('#^https?://#',$p) || str_starts_with($p,'/')) return $p;
  return '../'.ltrim($p,'/'); // จากโฟลเดอร์ admin ออกไปหา root Home
}
function parse_images(?string $raw): array {
  $raw = trim($raw ?? '');
  if ($raw==='') return [];
  // JSON array?
  if ($raw[0]==='[') {
    $arr = json_decode($raw, true);
    if (is_array($arr)) {
      return array_values(array_filter(array_map('strval',$arr), fn($x)=>$x!==''));
    }
  }
  // แยกด้วย , หรือ ; 
  $parts = preg_split('/[;,]+/',$raw);
  return array_values(array_filter(array_map('trim',$parts), fn($x)=>$x!==''));
}

/* รับใบงาน */
$id = (int)($_GET['id'] ?? 0);
if($id<=0){ header('Location: service_tickets.php'); exit; }

$ticket=null;
if($st=$conn->prepare("SELECT * FROM service_tickets WHERE id=?")){
  $st->bind_param("i",$id); $st->execute();
  $ticket=$st->get_result()->fetch_assoc(); $st->close();
}
if(!$ticket){ header('Location: service_tickets.php'); exit; }

/* ประวัติสถานะ */
$logs=[];
if($st=$conn->prepare("SELECT * FROM service_status_logs WHERE ticket_id=? ORDER BY id DESC")){
  $st->bind_param("i",$id); $st->execute();
  $logs=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* ดึงรูปจากใบงาน (image_path) — รองรับหลายรูป */
$ticketImages = parse_images($ticket['image_path'] ?? '');

$statuses = [
  'queue'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค','waiting_parts'=>'รออะไหล่',
  'repairing'=>'กำลังซ่อม','done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก'
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ใบงาน ST-<?=$id?> | Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb}
    .cardx{border:1px solid #e9eef5; border-radius:16px; background:#fff}
    .grid{display:grid; grid-template-columns:repeat(3,1fr); gap:10px}
    @media (max-width: 992px){ .grid{grid-template-columns:repeat(2,1fr)} }
    .thumb{border:1px solid #e9eef5; border-radius:12px; overflow:hidden; background:#fff; cursor:pointer}
    .thumb img{width:100%; aspect-ratio:1; object-fit:cover; display:block}
    .log-thumb{width:84px; height:84px; object-fit:cover; border-radius:8px; border:1px solid #e9eef5; cursor:pointer}
  </style>
</head>
<body>
<nav class="navbar navbar-light bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a href="service_tickets.php" class="navbar-brand fw-bold"><i class="bi bi-arrow-left"></i> กลับรายการ</a>
    <div class="ms-auto d-flex gap-2">
      <a href="../index.php" class="btn btn-outline-secondary btn-sm">หน้าร้าน</a>
      <a href="../logout.php" class="btn btn-outline-danger btn-sm">ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-3">
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="cardx p-3">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-0">ใบงาน <span class="badge text-bg-dark">ST-<?=$ticket['id']?></span></h5>
          <div>สถานะปัจจุบัน: <span class="badge text-bg-primary">
            <?=h($statuses[$ticket['status']] ?? $ticket['status'])?>
          </span></div>
        </div>
        <hr>
        <div class="row g-2">
          <div class="col-md-4"><div class="small text-muted">ประเภท</div><div><?=h($ticket['device_type'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">ยี่ห้อ</div><div><?=h($ticket['brand'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">รุ่น</div><div><?=h($ticket['model'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">โทร</div><div><?=h($ticket['phone'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">LINE</div><div><?=h($ticket['line_id'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">นัดหมาย</div><div><?=h($ticket['desired_date'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">ความเร่งด่วน</div><div><?=h($ticket['urgency'])?></div></div>
          <div class="col-md-8"><div class="small text-muted">สร้างเมื่อ</div><div><?=h($ticket['created_at'])?></div></div>
          <div class="col-12"><div class="small text-muted">อาการลูกค้าแจ้ง</div><div><?=nl2br(h($ticket['issue']))?></div></div>
        </div>

        <!-- รูปแนบจากใบงาน -->
        <hr>
        <div class="fw-semibold mb-2">รูปที่ลูกค้าแนบ</div>
        <?php if (empty($ticketImages)): ?>
          <div class="text-muted small">ไม่มีรูปที่แนบ</div>
        <?php else: ?>
          <div class="grid" id="gridTicket">
            <?php foreach($ticketImages as $p): $u = img_url($p); ?>
              <a class="thumb" data-src="<?=h($u)?>" href="javascript:void(0)"><img src="<?=h($u)?>" alt=""></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="cardx p-3 mt-3">
        <h6 class="fw-bold">ประวัติสถานะ</h6>
        <?php if(empty($logs)): ?>
          <div class="text-muted">ยังไม่มีบันทึก</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach($logs as $lg): ?>
              <div class="list-group-item px-0">
                <div class="d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?=h($statuses[$lg['status']] ?? $lg['status'])?></div>
                    <?php if(!empty($lg['note'])): ?>
                      <div class="small text-muted"><?=nl2br(h($lg['note']))?></div>
                    <?php endif; ?>
                    <!-- หมายเหตุ: ตาราง log ของคุณไม่มีคอลัมน์รูปภาพ จึงไม่แสดงรูปจาก log -->
                  </div>
                  <div class="small text-muted"><?=h($lg['created_at'])?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="cardx p-3">
        <h6 class="fw-bold">อัปเดตสถานะ</h6>
        <form action="service_update_status.php" method="post" class="d-grid gap-2">
          <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
          <select class="form-select" name="status" required>
            <?php foreach($statuses as $k=>$v): ?>
              <option value="<?=$k?>" <?=$ticket['status']===$k?'selected':''?>><?=$v?></option>
            <?php endforeach; ?>
          </select>
          <textarea class="form-control" name="note" rows="3" placeholder="โน้ตถึงลูกค้า/ภายใน (บันทึกลงไทม์ไลน์)"></textarea>
          <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
        </form>
      </div>

      <div class="cardx p-3 mt-3">
        <h6 class="fw-bold">เพิ่มบันทึก (ไม่เปลี่ยนสถานะ)</h6>
        <form action="service_add_log.php" method="post" class="d-grid gap-2">
          <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
          <select class="form-select" name="status" required>
            <?php foreach($statuses as $k=>$v): ?>
              <option value="<?=$k?>"><?=$v?></option>
            <?php endforeach; ?>
          </select>
          <textarea class="form-control" name="note" rows="3" placeholder="รายละเอียด"></textarea>
          <button class="btn btn-outline-secondary"><i class="bi bi-journal-plus"></i> เพิ่มบันทึก</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="modal fade" id="lightbox" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0">
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center">
        <img id="lightboxImg" src="" alt="" class="img-fluid">
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const lb = new bootstrap.Modal(document.getElementById('lightbox'));
  const imgEl = document.getElementById('lightboxImg');
  document.addEventListener('click', (e)=>{
    const a = e.target.closest('[data-src]');
    if(!a) return;
    e.preventDefault();
    imgEl.src = a.getAttribute('data-src');
    lb.show();
  });
})();
</script>
</body>
</html>
