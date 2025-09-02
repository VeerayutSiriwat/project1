<?php
// Home/service_track.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__.'/includes/db.php';

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function digits4($phone){ return substr(preg_replace('/\D/','',$phone??''), -4); }

$ticket_in = trim($_GET['ticket'] ?? '');
$phone_in  = trim($_GET['phone'] ?? '');

$tid = (int)preg_replace('/\D/','',$ticket_in); // รองรับ ST-1001
$ticket = null;

if($tid>0){
  if ($st=$conn->prepare("SELECT * FROM service_tickets WHERE id=?")) {
    $st->bind_param("i",$tid); $st->execute();
    $ticket = $st->get_result()->fetch_assoc();
    $st->close();
  }
}

$ok_access = false;
if($ticket){
  $ok_access = (digits4($ticket['phone']??'') === digits4($phone_in));
}

// โหลด logs
$logs = [];
if($ok_access){
  if ($st=$conn->prepare("SELECT status,note,created_at FROM service_status_logs WHERE ticket_id=? ORDER BY id ASC")){
    $st->bind_param("i",$tid); $st->execute();
    $logs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
  }
}

// map ชื่อสถานะให้อ่านง่าย (ปรับตาม enum จริงของคุณ)
$labels = [
  'queue'          => 'เข้าคิว',
  'confirm'        => 'ยืนยันคิว',
  'checking'       => 'ตรวจเช็ค',
  'waiting_parts'  => 'รออะไหล่',
  'repairing'      => 'กำลังซ่อม',
  'done'           => 'เสร็จพร้อมรับ',
  'cancelled'      => 'ยกเลิก',
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>สถานะงานซ่อม | WEB APP</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__.'/includes/header.php'; ?>

<div class="container py-4">
  <h4 class="fw-bold mb-3"><i class="bi bi-clipboard-check me-1"></i> สถานะงานซ่อม</h4>

  <?php if(!$ticket): ?>
    <div class="alert alert-warning">ไม่พบใบงานหมายเลข <b><?=h($ticket_in)?></b></div>
    <a href="service.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับไปหน้า บริการซ่อม</a>

  <?php elseif(!$ok_access): ?>
    <div class="alert alert-danger">
      หมายเลขโทรศัพท์ที่กรอกไม่ตรงกับข้อมูลในใบงาน ลงท้าย 4 ตัวต้องตรงกัน
    </div>
    <a href="service.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> ลองใหม่</a>

  <?php else: ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="border rounded-4 p-3 bg-white">
          <div class="d-flex justify-content-between">
            <div>
              <div class="small text-muted">หมายเลขใบงาน</div>
              <div class="h5 mb-0">ST-<?= (int)$ticket['id'] ?></div>
            </div>
            <div class="text-end">
              <div class="small text-muted">สถานะปัจจุบัน</div>
              <?php
                $status = $ticket['status'] ?? 'queue';
                $label  = $labels[$status] ?? $status;
              ?>
              <div class="badge text-bg-primary fs-6"><?= h($label) ?></div>
            </div>
          </div>
          <hr>
          <div class="row g-2">
            <div class="col-md-6"><div class="small text-muted">ประเภท</div><div><?=h($ticket['device_type'])?></div></div>
            <div class="col-md-3"><div class="small text-muted">ยี่ห้อ</div><div><?=h($ticket['brand'])?></div></div>
            <div class="col-md-3"><div class="small text-muted">รุ่น</div><div><?=h($ticket['model'])?></div></div>
            <div class="col-md-6"><div class="small text-muted">โทร</div><div><?=h($ticket['phone'])?></div></div>
            <div class="col-md-6"><div class="small text-muted">LINE</div><div><?=h($ticket['line_id'])?></div></div>
            <div class="col-md-6"><div class="small text-muted">นัดวันที่</div><div><?=h($ticket['desired_date'])?></div></div>
            <div class="col-md-6"><div class="small text-muted">ความเร่งด่วน</div><div><?=h($ticket['urgency'])?></div></div>
            <div class="col-12"><div class="small text-muted">อาการ</div><div><?=nl2br(h($ticket['issue']))?></div></div>
          </div>
          <?php if(!empty($ticket['image_path'])): ?>
            <hr>
            <div>
              <div class="small text-muted mb-1">รูปแนบ</div>
              <img src="<?=h($ticket['image_path'])?>" class="img-fluid rounded" alt="attach">
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="border rounded-4 p-3 bg-white">
          <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1"></i> ไทม์ไลน์สถานะ</h6>
          <?php if(empty($logs)): ?>
            <div class="text-muted">ยังไม่มีบันทึกสถานะ</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach($logs as $log): ?>
                <li class="list-group-item px-0">
                  <div class="d-flex justify-content-between">
                    <div>
                      <div class="fw-semibold"><?= h($labels[$log['status']] ?? $log['status']) ?></div>
                      <?php if(!empty($log['note'])): ?>
                        <div class="small text-muted"><?= nl2br(h($log['note'])) ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="small text-muted"><?= h($log['created_at']) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <a href="service.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> กลับ</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>
</body>
</html>
