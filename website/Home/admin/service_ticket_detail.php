<?php
// Home/admin/service_ticket_detail.php (stabilized + UX improved + payment + items)
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php?redirect=admin/service_tickets.php'); exit;
}
function h($s){ return htmlspecialchars((string)($s??''),ENT_QUOTES,'UTF-8'); }
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows > 0;
}

/* Helpers */
function img_url(string $p): string {
  $p = trim($p);
  if ($p==='') return '';
  if (preg_match('#^https?://#',$p) || str_starts_with($p,'/')) return $p;
  return '../'.ltrim($p,'/'); // จากโฟลเดอร์ admin ออกไปหา root Home
}
function parse_images(?string $raw): array {
  $raw = trim((string)($raw ?? ''));
  if ($raw==='') return [];
  if ($raw[0]==='[') {
    $arr = json_decode($raw, true);
    if (is_array($arr)) return array_values(array_filter(array_map('strval',$arr), fn($x)=>$x!==''));
  }
  $parts = preg_split('/[;,]+/',$raw);
  return array_values(array_filter(array_map('trim',$parts), fn($x)=>$x!==''));
}

/* รับใบงาน */
$id = (int)($_GET['id'] ?? 0);
if($id<=0){ header('Location: service_tickets.php'); exit; }

$ticket=null;
if($st=$conn->prepare("SELECT * FROM service_tickets WHERE id=? LIMIT 1")){
  $st->bind_param("i",$id); $st->execute();
  $ticket=$st->get_result()->fetch_assoc(); $st->close();
}
if(!$ticket){ header('Location: service_tickets.php'); exit; }

/* ประวัติสถานะ */
$logs=[];
if($st=$conn->prepare("SELECT id,ticket_id,status,note,created_at FROM service_status_logs WHERE ticket_id=? ORDER BY id DESC")){
  $st->bind_param("i",$id); $st->execute();
  $logs=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* ดึงรูปจากใบงาน (image_path) — รองรับหลายรูป */
$ticketImages = parse_images($ticket['image_path'] ?? '');

/* mapping สถานะหลักของใบงาน (ฝั่งตารางหลัก) */
/* ใช้ชุดสถานะใหม่ตามที่ต้องการ */
$statuses = [
  'queued'        => 'เข้าคิว',
 'confirm'     => 'ยืนยันคิว',
  'waiting_parts' => 'รออะไหล่',
  'repairing'     => 'กำลังซ่อม',
  'done'          => 'เสร็จพร้อมรับ',
  'returned'      => 'ส่งคืนลูกค้าแล้ว',
  'cancelled'     => 'ยกเลิก',
];

/* --- ความเร่งด่วน (normalize) --- */
$urgRaw = strtolower(trim((string)($ticket['urgency'] ?? 'normal')));
if ($urgRaw === '1' || $urgRaw === 'ด่วน' || $urgRaw === 'เร่งด่วน')      $urgKey='urgent';
elseif ($urgRaw === '0' || $urgRaw === ''  || $urgRaw === 'ปกติ')          $urgKey='normal';
elseif (in_array($urgRaw,['urgent','normal'],true))                         $urgKey=$urgRaw;
else                                                                        $urgKey='normal';
$urgLabel = $urgKey==='urgent' ? 'ด่วน' : 'ปกติ';
$urgClass = $urgKey==='urgent' ? 'danger' : 'secondary';

$gradeMap = ['used'=>'มือสอง','standard'=>'ปานกลาง','premium'=>'ดีมาก'];

/* ===== นัดหมาย (อ่านตามคอลัมน์ที่มีจริง) ===== */
$startCol  = has_col($conn,'service_tickets','appointment_start') ? 'appointment_start'
          : (has_col($conn,'service_tickets','scheduled_at') ? 'scheduled_at' : null);
$endCol    = has_col($conn,'service_tickets','appointment_end')   ? 'appointment_end'   : null;
$statusCol = has_col($conn,'service_tickets','appointment_status')? 'appointment_status'
          : (has_col($conn,'service_tickets','schedule_status') ? 'schedule_status' : null);

$apptStart   = $startCol  ? ($ticket[$startCol] ?? null) : null;
$apptEnd     = $endCol    ? ($ticket[$endCol]   ?? null) : null;
$apptStatus  = $statusCol ? ($ticket[$statusCol]?? 'none') : 'none';
$apptBadgeMap= ['none'=>'secondary','pending'=>'warning text-dark','confirmed'=>'success','declined'=>'danger','proposed'=>'info text-dark'];
$apptTextMap = ['none'=>'—','pending'=>'รอยืนยัน','confirmed'=>'ยืนยันแล้ว','declined'=>'ปฏิเสธแล้ว','proposed'=>'เสนอเวลาแล้ว'];

/* ===== ข้อเสนอเวลา (schedule_proposals) ===== */
$proposals=[]; $hasProposals = $conn->query("SHOW TABLES LIKE 'schedule_proposals'")->num_rows>0;
if($hasProposals && $st=$conn->prepare("SELECT id,ticket_type,ticket_id,slot_start,slot_end,duration_minutes,status,note,created_at FROM schedule_proposals WHERE ticket_type='repair' AND ticket_id=? ORDER BY id DESC")){
  $st->bind_param('i',$id); $st->execute();
  $proposals=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}
$spBadge = [
  'pending'   => 'warning text-dark',
  'accepted'  => 'success',
  'declined'  => 'danger',
  'cancelled' => 'secondary'
];

$spText = [
  'pending'   => 'รอลูกค้ายืนยัน',
  'accepted'  => 'ลูกค้ายืนยันแล้ว',
  'declined'  => 'ลูกค้าปฏิเสธ',
  'cancelled' => 'ยกเลิกแล้ว',
];


/* ===== รายการซ่อม / ค่าบริการ (service_ticket_items) ===== */
$items = [];
$itemsTotal = 0.0;
$itemTypeLabel = [
  'part'   => 'อะไหล่',
  'labor'  => 'ค่าแรง',
  'service'=> 'ค่าบริการ',
  'fee'    => 'ค่าธรรมเนียมอื่นๆ',
  'other'  => 'อื่น ๆ'
];
$hasItemsTable = $conn->query("SHOW TABLES LIKE 'service_ticket_items'")->num_rows>0;

if ($hasItemsTable && $st = $conn->prepare("SELECT id,item_type,description,qty,unit_price,created_at FROM service_ticket_items WHERE ticket_id=? ORDER BY id ASC")) {
  $st->bind_param('i',$id);
  $st->execute();
  $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  foreach($items as $it){
    $itemsTotal += (float)$it['qty'] * (float)$it['unit_price'];
  }
}

/* ===== ข้อมูลการชำระเงินค่าบริการซ่อม ===== */
$hasServicePriceCol  = has_col($conn,'service_tickets','service_price');
$hasPaymentStatusCol = has_col($conn,'service_tickets','payment_status');
$hasPaymentSlipCol   = has_col($conn,'service_tickets','payment_slip');
$hasPaidAtCol        = has_col($conn,'service_tickets','paid_at');
$hasPayMethodCol     = has_col($conn,'service_tickets','pay_method');
$payMethod           = $hasPayMethodCol ? ($ticket['pay_method'] ?? '') : '';

$servicePrice = (float)($ticket['estimate_total'] ?? 0);
if ($hasServicePriceCol && array_key_exists('service_price',$ticket) && $ticket['service_price'] !== null) {
  $servicePrice = (float)$ticket['service_price'];
} elseif($hasItemsTable && $itemsTotal > 0){
  $servicePrice = $itemsTotal;
}

$paymentStatus = 'unpaid';
if ($hasPaymentStatusCol && array_key_exists('payment_status',$ticket) && $ticket['payment_status']!=='') {
  $paymentStatus = $ticket['payment_status'];
}

$PAY_LABEL = [
  'unpaid'  => 'ยังไม่ชำระ',
  'pending' => 'รอตรวจสอบการชำระ',
  'paid'    => 'ชำระแล้ว',
];
$PAY_BADGE = [
  'unpaid'  => 'danger',
  'pending' => 'warning text-dark',
  'paid'    => 'success',
];
$PAY_METHOD = [
  'bank'   => 'โอนธนาคาร / พร้อมเพย์',
  'cash'   => 'เงินสดหน้าร้าน',
  'wallet' => 'วอลเล็ท',
  'cod'    => 'เก็บเงินปลายทาง',
];

$curStatusLabel = $statuses[$ticket['status']] ?? $ticket['status'];
if ($ticket['status']==='done' && $paymentStatus!=='paid') {
  $curStatusLabel .= ' (รอชำระเงิน)';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ใบงาน ST-<?= (int)$id ?> | Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --border:#e9eef5; --muted:#64748b; }
    body{background:#f6f8fb}
    .cardx{border:1px solid var(--border); border-radius:16px; background:#fff}
    .grid{display:grid; grid-template-columns:repeat(3,1fr); gap:10px}
    @media (max-width: 992px){ .grid{grid-template-columns:repeat(2,1fr)} }
    .thumb{border:1px solid var(--border); border-radius:12px; overflow:hidden; background:#fff; cursor:pointer}
    .thumb img{width:100%; aspect-ratio:1; object-fit:cover; display:block}
    .smallmuted{font-size:.9rem;color:var(--muted)}
    .quick-btns .btn{min-width:120px}
    .copy{cursor:pointer}
  </style>
</head>
<body>
<nav class="navbar navbar-light bg-white border-bottom sticky-top">
  <div class="container-fluid px-3">
    <a href="service_tickets.php" class="navbar-brand fw-bold"><i class="bi bi-arrow-left"></i> กลับรายการ</a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="badge text-bg-<?= $urgClass ?>"><i class="bi bi-lightning-charge"></i> <?= $urgLabel ?></span>
      <a href="../index.php" class="btn btn-outline-secondary btn-sm">หน้าร้าน</a>
      <a href="../logout.php" class="btn btn-outline-danger btn-sm">ออกจากระบบ</a>
    </div>
  </div>
</nav>

<div class="container py-3">
  
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
      <?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="cardx p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h5 class="mb-0">ใบงาน <span class="badge text-bg-dark">ST-<?= (int)$ticket['id'] ?></span></h5>
          <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="badge text-bg-<?= $urgClass ?>"><i class="bi bi-lightning-charge"></i> <?= $urgLabel ?></span>
            <span>สถานะปัจจุบัน:
              <span class="badge text-bg-primary"><?= h($curStatusLabel) ?></span>
            </span>
          </div>
        </div>
        <hr class="mt-3 mb-2">

        <!-- ข้อมูลหลัก -->
        <div class="row g-2">
          <div class="col-md-4"><div class="small text-muted">ประเภท</div><div><?=h($ticket['device_type'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">ยี่ห้อ</div><div><?=h($ticket['brand'])?></div></div>
          <div class="col-md-4"><div class="small text-muted">รุ่น</div><div><?=h($ticket['model'])?></div></div>

          <div class="col-md-4">
            <div class="small text-muted">โทร</div>
            <div class="d-flex align-items-center gap-2">
              <?php $tel = trim((string)($ticket['phone']??'')); ?>
              <a href="<?= $tel? 'tel:'.h($tel):'#' ?>" class="text-decoration-none"><?= h($tel ?: '-') ?></a>
              <?php if($tel): ?><span class="text-muted small copy" data-copy="<?= h($tel) ?>" title="คัดลอก"><i class="bi bi-clipboard"></i></span><?php endif; ?>
            </div>
          </div>

          <div class="col-md-4">
            <div class="small text-muted">LINE</div>
            <div class="d-flex align-items-center gap-2">
              <?php $line = trim((string)($ticket['line_id']??'')); ?>
              <span><?= h($line ?: '-') ?></span>
              <?php if($line): ?><a class="btn btn-outline-success btn-sm" target="_blank" href="https://line.me/ti/p/~<?= urlencode($line) ?>"><i class="bi bi-line"></i> แชต</a><?php endif; ?>
            </div>
          </div>

          <div class="col-md-4"><div class="small text-muted">นัดหมาย (ลูกค้าเสนอ)</div><div><?= h($ticket['desired_date'] ?: '-') ?></div></div>

          <div class="col-md-4">
            <div class="small text-muted">ความเร่งด่วน</div>
            <div><span class="badge text-bg-<?= $urgClass ?>"><?= $urgLabel ?></span></div>
          </div>

          <div class="col-md-4">
            <div class="small text-muted">เกรดวัสดุ</div>
            <div>
              <?= h($gradeMap[$ticket['parts_grade'] ?? 'standard'] ?? '-') ?>
              <?php if(($ticket['parts_grade_surcharge'] ?? 0) > 0): ?>
                <span class="badge text-bg-secondary ms-2">+<?= number_format((float)$ticket['parts_grade_surcharge'],2) ?> ฿</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-md-4"><div class="small text-muted">ค่าส่วนเพิ่มจากตัวเลือก (ประมาณ)</div>
            <div><?= number_format((float)($ticket['estimate_total'] ?? 0),2) ?> ฿</div>
          </div>

          <div class="col-md-8"><div class="small text-muted">สร้างเมื่อ</div><div><?= h($ticket['created_at']) ?></div></div>
          <div class="col-12"><div class="small text-muted">อาการลูกค้าแจ้ง</div><div><?= nl2br(h($ticket['issue'])) ?></div></div>
        </div>

        <!-- รูปแนบจากใบงาน -->
        <hr>
        <div class="fw-semibold mb-2">รูปที่ลูกค้าแนบ</div>
        <?php if (empty($ticketImages)): ?>
          <div class="text-muted small">ไม่มีรูปที่แนบ</div>
        <?php else: ?>
          <div class="grid" id="gridTicket">
            <?php foreach($ticketImages as $p): $u = img_url($p); ?>
              <a class="thumb" data-src="<?= h($u) ?>" href="javascript:void(0)"><img src="<?= h($u) ?>" alt=""></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- รายการซ่อม / ค่าบริการ -->
      <div class="cardx p-3 mt-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0">รายการซ่อม / ค่าบริการ</h6>
          <?php if($hasItemsTable): ?>
            <div class="small text-muted">
              รวม <?= count($items) ?> รายการ | ยอดรวมโดยประมาณ: <?= number_format($itemsTotal,2) ?> ฿
            </div>
          <?php endif; ?>
        </div>

        <?php if(!$hasItemsTable): ?>
          <div class="text-muted small">
            ยังไม่มีตาราง <code>service_ticket_items</code> ในฐานข้อมูล กรุณาสร้างตารางก่อน (ดู SQL ที่เพิ่มให้)
          </div>
        <?php else: ?>
          <?php if(empty($items)): ?>
            <div class="text-muted small mb-2">ยังไม่มีรายการ แอดมินสามารถเพิ่มรายการด้านล่าง</div>
          <?php else: ?>
            <div class="table-responsive mb-2">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:5%">#</th>
                    <th style="width:18%">ประเภท</th>
                    <th>รายละเอียด</th>
                    <th style="width:10%" class="text-end">จำนวน</th>
                    <th style="width:15%" class="text-end">ราคา/หน่วย</th>
                    <th style="width:15%" class="text-end">รวม</th>
                    <th style="width:8%"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php $i=1; foreach($items as $it):
                    $lineTotal = (float)$it['qty'] * (float)$it['unit_price'];
                  ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= h($itemTypeLabel[$it['item_type']] ?? $it['item_type']) ?></td>
                    <td>
                      <?= nl2br(h($it['description'])) ?>
                      <div class="small text-muted"><?= h($it['created_at']) ?></div>
                    </td>
                    <td class="text-end">
                      <?= rtrim(rtrim(number_format((float)$it['qty'],2), '0'),'.') ?>
                    </td>
                    <td class="text-end"><?= number_format((float)$it['unit_price'],2) ?></td>
                    <td class="text-end"><?= number_format($lineTotal,2) ?></td>
                    <td class="text-end">
                      <form action="service_ticket_items_save.php" method="post"
                            onsubmit="return confirm('ลบรายการนี้?');">
                        <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <input type="hidden" name="do" value="delete">
                        <button class="btn btn-sm btn-outline-danger" type="submit">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th colspan="5" class="text-end">รวม</th>
                    <th class="text-end"><?= number_format($itemsTotal,2) ?> ฿</th>
                    <th></th>
                  </tr>
                </tfoot>
              </table>
            </div>
          <?php endif; ?>

          <!-- ฟอร์มเพิ่มรายการ -->
          <hr>
          <form action="service_ticket_items_save.php" method="post" class="row g-2 align-items-end">
            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
            <input type="hidden" name="do" value="add">
            <div class="col-md-3">
              <label class="form-label smallmuted">ประเภท</label>
              <select name="item_type" class="form-select form-select-sm">
                <option value="part">อะไหล่</option>
                <option value="labor">ค่าแรง</option>
                <option value="service">ค่าบริการ</option>
                <option value="fee">ค่าธรรมเนียมอื่นๆ</option>
                <option value="other">อื่น ๆ</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label smallmuted">รายละเอียด</label>
              <input type="text" name="description" class="form-control form-control-sm" required
                     placeholder="เช่น เปลี่ยนจอ, เปลี่ยนแบต, ล้างเครื่อง">
            </div>
            <div class="col-md-2">
              <label class="form-label smallmuted">จำนวน</label>
              <input type="number" name="qty" step="0.01" min="0" value="1"
                     class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2">
              <label class="form-label smallmuted">ราคา/หน่วย</label>
              <input type="number" name="unit_price" step="0.01" min="0" value="0"
                     class="form-control form-control-sm" required>
            </div>
            <div class="col-12 d-grid mt-1">
              <button class="btn btn-sm btn-primary" type="submit">
                <i class="bi bi-plus-circle"></i> เพิ่มรายการ
              </button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <!-- ประวัติสถานะ -->
      <div class="cardx p-3 mt-3">
        <div class="d-flex align-items-center justify-content-between">
          <h6 class="fw-bold mb-0">ประวัติสถานะ</h6>
          <div class="text-muted small">รวม <?= number_format(count($logs)) ?> รายการ</div>
        </div>
        <?php if(empty($logs)): ?>
          <div class="text-muted mt-2">ยังไม่มีบันทึก</div>
        <?php else: ?>
          <div class="list-group list-group-flush mt-2">
            <?php foreach($logs as $lg): ?>
              <div class="list-group-item px-0">
                <div class="d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?= h($statuses[$lg['status']] ?? $lg['status']) ?></div>
                    <?php if(!empty($lg['note'])): ?>
                      <div class="small text-muted"><?= nl2br(h($lg['note'])) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="small text-muted"><?= h($lg['created_at']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

   <!-- แผงควบคุมขวา -->
    <div class="col-lg-4">
      <!-- อัปเดตสถานะ -->
      <div class="cardx p-3">
        <h6 class="fw-bold">อัปเดตสถานะ</h6>
        <div class="quick-btns d-flex flex-wrap gap-2 mb-2">
          <button class="btn btn-outline-secondary btn-sm js-quick" data-status="queued">เข้าคิว</button>
          <button class="btn btn-outline-info btn-sm js-quick" data-status="confirm">ยืนยันคิว</button>
          <button class="btn btn-outline-warning btn-sm js-quick" data-status="waiting_parts">รออะไหล่</button>
          <button class="btn btn-outline-primary btn-sm js-quick" data-status="repairing">กำลังซ่อม</button>
          <button class="btn btn-outline-success btn-sm js-quick" data-status="done">เสร็จพร้อมรับ</button>
          <button class="btn btn-outline-dark btn-sm js-quick" data-status="returned">ส่งคืนลูกค้าแล้ว</button>
          <button class="btn btn-outline-danger btn-sm js-quick" data-status="cancelled">ยกเลิก</button>
        </div>

        <form id="statusForm" action="service_update_status.php" method="post" class="d-grid gap-2">
          <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
          <select class="form-select" name="status" required id="statusSel">
            <?php foreach($statuses as $k=>$v): ?>
              <option value="<?= h($k) ?>" <?= $ticket['status']===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea class="form-control" name="note" rows="3" placeholder="โน้ตถึงลูกค้า/ภายใน (บันทึกลงไทม์ไลน์)"></textarea>
          <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
        </form>
        <div class="small text-muted mt-2">
          * “ยืนยันคิว” หรือ “ส่งข้อเสนอเวลา” จะตั้งเวลานัดอัตโนมัติ (จากข้อเสนอ/วันที่ลูกค้าเสนอ/เวลาปัจจุบัน)
        </div>
      </div>

      <!-- การชำระเงินค่าบริการ -->
      <div class="cardx p-3 mt-3">
        <h6 class="fw-bold">การชำระเงินค่าบริการ</h6>

        <?php if(!$hasPaymentStatusCol): ?>
          <div class="alert alert-danger mb-2">
            ยังไม่พบคอลัมน์ <code>payment_status</code> ในตาราง <code>service_tickets</code><br>
            กรุณารัน ALTER TABLE ก่อนใช้งานฟีเจอร์นี้
          </div>
        <?php else: ?>
          <div class="mb-2">
            <div class="smallmuted">สถานะการชำระเงิน</div>
            <div>
              <span class="badge bg-<?= $PAY_BADGE[$paymentStatus] ?? 'secondary' ?>">
                <?= h($PAY_LABEL[$paymentStatus] ?? $paymentStatus) ?>
              </span>
            </div>
          </div>
          <?php if($hasPayMethodCol): ?>
          <div class="mb-2">
            <div class="smallmuted">ช่องทางที่ลูกค้าเลือก</div>
            <div>
              <?php if($payMethod === 'cash'): ?>
                <span class="badge bg-secondary">
                  <?= h($PAY_METHOD[$payMethod] ?? $payMethod) ?>
                </span>
                <div class="small text-muted">
                  ลูกค้าเลือกชำระเงินสดหน้าร้าน (ไม่มีสลิปแนบ)
                </div>
              <?php elseif($payMethod !== ''): ?>
                <span class="badge bg-info text-dark">
                  <?= h($PAY_METHOD[$payMethod] ?? $payMethod) ?>
                </span>
              <?php else: ?>
                <span class="text-muted small">ยังไม่ระบุช่องทางชำระ</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

          <div class="mb-2">
            <div class="smallmuted">ยอดค่าบริการที่คิดกับลูกค้า</div>
            <div class="fs-5 fw-semibold"><?= number_format($servicePrice, 2) ?> ฿</div>
            <?php if($hasItemsTable && $itemsTotal>0): ?>
              <div class="small text-muted">
                * ยอดรวมจากรายการซ่อม: <?= number_format($itemsTotal,2) ?> ฿
                <?php if($hasServicePriceCol && $ticket['service_price']!==null && (float)$ticket['service_price'] !== (float)$itemsTotal): ?>
                  (service_price ในฐานข้อมูล: <?= number_format((float)$ticket['service_price'],2) ?> ฿)
                <?php endif; ?>
              </div>
            <?php elseif(!$hasServicePriceCol): ?>
              <div class="small text-muted">* ยังไม่มีคอลัมน์ <code>service_price</code> ใช้ค่า estimate_total แทน</div>
            <?php endif; ?>
          </div>

          <?php if($hasPaidAtCol && !empty($ticket['paid_at'])): ?>
            <div class="mb-2">
              <div class="smallmuted">ชำระเมื่อ</div>
              <div><?= h($ticket['paid_at']) ?></div>
            </div>
          <?php endif; ?>

          <?php if($hasPaymentSlipCol && !empty($ticket['payment_slip'])): ?>
  <?php $slipUrl = img_url($ticket['payment_slip']); ?>
  <div class="mb-2">
    <div class="smallmuted mb-1">
      สลิป/หลักฐานการชำระ
      <span class="text-muted">(คลิกที่รูปเพื่อขยาย)</span>
    </div>
    <img
      src="<?= h($slipUrl) ?>"
      data-src="<?= h($slipUrl) ?>"
      alt="Slip"
      class="img-fluid rounded border slip-thumb"
      style="max-height:220px;object-fit:contain;cursor:pointer;">
  </div>
<?php endif; ?>

          <form action="service_payment_update.php" method="post" class="d-flex flex-wrap gap-2 mt-2">
            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
            <button type="submit" name="action" value="mark_paid" class="btn btn-sm btn-success">
              <i class="bi bi-check2-circle"></i> ยืนยันรับชำระแล้ว
            </button>
            <button type="submit" name="action" value="mark_pending" class="btn btn-sm btn-outline-warning">
              <i class="bi bi-hourglass-split"></i> ตั้งเป็นรอตรวจสอบ
            </button>
            <button type="submit" name="action" value="mark_unpaid" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-x-circle"></i> ตั้งเป็นยังไม่ชำระ
            </button>
          </form>
        <?php endif; ?>
      </div>

      <!-- เสนอเวลานัด -->
      <div class="cardx p-3 mt-3">
        <h6 class="fw-bold">เสนอเวลานัดให้ลูกค้า</h6>

        <!-- สถานะนัดปัจจุบัน -->
        <div class="mb-2">
          <div class="smallmuted">สถานะนัดหมาย</div>
          <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="badge bg-<?= $apptBadgeMap[$apptStatus] ?? 'secondary' ?>">
              <?= h($apptTextMap[$apptStatus] ?? $apptStatus) ?>
            </span>
            <?php if($apptStart): ?>
              <span class="small"><?= h($apptStart) ?><?= $apptEnd? ' — '.h($apptEnd):'' ?></span>
              <button class="btn btn-sm btn-outline-danger" id="btnClearAppt"><i class="bi bi-x-circle"></i> ล้างนัด</button>
            <?php endif; ?>
          </div>
        </div>

        <!-- ฟอร์มเสนอ -->
        <form id="proposalForm" class="row gy-2 align-items-end">
          <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
          <div class="col-12">
            <label class="form-label smallmuted">วันเวลาเริ่ม</label>
            <input type="datetime-local" class="form-control" name="slot_start" required>
          </div>
          <div class="col-6">
            <label class="form-label smallmuted">ระยะเวลา (นาที)</label>
            <select class="form-select" name="duration_minutes">
              <option value="30">30</option>
              <option value="60" selected>60</option>
              <option value="90">90</option>
              <option value="120">120</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label smallmuted">บันทึก/หมายเหตุ (ไม่บังคับ)</label>
            <input type="text" class="form-control" name="note" placeholder="ระบุเช่น รับเครื่องหน้าร้าน">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-primary" id="btnPropose"><i class="bi bi-calendar-plus"></i> ส่งข้อเสนอเวลา</button>
          </div>
          <div class="col-12 small text-muted">
            * ระบบจะอัปเดตสถานะนัดเป็น “รอยืนยัน” และส่งแจ้งเตือนไปยังลูกค้า
          </div>
        </form>

        <hr>

        <!-- รายการข้อเสนอ -->
        <div class="smallmuted mb-1">ข้อเสนอเวลาที่ส่งไป</div>
        <?php if(!$hasProposals): ?>
          <div class="text-muted">ไม่มีตารางข้อเสนอในฐานข้อมูล</div>
        <?php elseif(empty($proposals)): ?>
          <div class="text-muted">ยังไม่มีข้อเสนอ</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach($proposals as $p): ?>
              <div class="list-group-item px-0 d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold">
                    <?= h($p['slot_start']) ?><?php if(!empty($p['slot_end'])): ?> — <?= h($p['slot_end']) ?><?php endif; ?>
                    <span class="badge bg-<?= $spBadge[$p['status']] ?? 'secondary' ?> ms-1">
                       <?= h($spText[$p['status']] ?? $p['status']) ?>
                    </span>
                  </div>
                  <?php if(!empty($p['note'])): ?>
                    <div class="small text-muted"><?= h($p['note']) ?></div>
                  <?php endif; ?>
                  <div class="small text-muted">ส่งเมื่อ: <?= h($p['created_at'] ?? '') ?></div>
                </div>
                <div class="ms-2 d-flex gap-2">
                  <?php if(($p['status'] ?? '')==='pending'): ?>
                    <button class="btn btn-sm btn-outline-danger" data-act="cancel-prop" data-prop="<?= (int)$p['id'] ?>">
                      <i class="bi bi-x"></i> ยกเลิก
                    </button>
                    <button class="btn btn-sm btn-outline-success" title="บังคับยืนยัน (ลูกค้ายืนยันผ่านช่องทางอื่น)" data-act="force-confirm" data-prop="<?= (int)$p['id'] ?>">
                      <i class="bi bi-check2-circle"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">ดำเนินการเสร็จ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$= (sel, root=document) => Array.from(root.querySelectorAll(sel));
  const toastEl = $('#toast'); 
  const toastBody = $('#toastBody');
  const showToast = (msg)=>{ 
    if(!toastEl) return; 
    toastBody.textContent = msg||'เสร็จ'; 
    new bootstrap.Toast(toastEl).show(); 
  };

  /* Lightbox (รูปที่ลูกค้าแนบ + สลิป) */
  const lightboxEl = document.getElementById('lightbox');
  const imgEl = document.getElementById('lightboxImg');
  let lb = null;
  if (lightboxEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
    lb = new bootstrap.Modal(lightboxEl);
  }

  document.addEventListener('click', (e)=>{
    const a = e.target.closest('[data-src]');
    if(!a || !lb || !imgEl) return;
    e.preventDefault();
    imgEl.src = a.getAttribute('data-src');
    lb.show();
  });

  /* Copy phone */
  document.addEventListener('click', async (e)=>{
    const el = e.target.closest('.copy');
    if(!el) return;
    const txt = el.dataset.copy||'';
    try{ 
      await navigator.clipboard.writeText(txt); 
      showToast('คัดลอกแล้ว'); 
    }catch(_){ /* ignore */ }
  });

  /* ปุ่มสถานะลัด */
  $$('.js-quick').forEach(b=>{
    b.addEventListener('click', ()=>{
      const v=b.dataset.status||'';
      const sel = $('#statusSel');
      if(sel){ sel.value = v; }
      const f = $('#statusForm');
      if(f){ f.requestSubmit(); }
    });
  });

  /* ส่งข้อเสนอเวลา */
  const f = document.getElementById('proposalForm');
  f?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(f);
    const btn = document.getElementById('btnPropose');
    if(btn) btn.disabled = true;
    try{
      const r = await fetch('schedule_propose.php', { method:'POST', body:fd });
      const j = await r.json();
      if(j.ok){ 
        showToast('ส่งข้อเสนอแล้ว'); 
        location.reload(); 
      } else { 
        alert('ส่งข้อเสนอไม่สำเร็จ: '+(j.error||'unknown')); 
      }
    }catch(_){ 
      alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); 
    }
    if(btn) btn.disabled = false;
  });

  /* ปุ่มยกเลิก/บังคับยืนยันข้อเสนอ */
  document.addEventListener('click', async (e)=>{
    const b = e.target.closest('[data-act]');
    if(!b) return;
    const act = b.dataset.act;
    const pid = b.dataset.prop;
    if(act==='cancel-prop' && !confirm('ยกเลิกข้อเสนอนี้?')) return;
    if(act==='force-confirm' && !confirm('ยืนยันใช้เวลานี้เป็นนัดหมาย?')) return;
    try{
      const r = await fetch('schedule_action.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action:act, prop_id:pid, ticket_id:'<?= (int)$ticket['id'] ?>' })
      });
      const j = await r.json();
      if(j.ok){ 
        showToast('ดำเนินการเสร็จ'); 
        location.reload(); 
      } else { 
        alert('ทำรายการไม่สำเร็จ: '+(j.error||'unknown')); 
      }
    }catch(_){ 
      alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); 
    }
  });

  /* ล้างนัดที่ยืนยันแล้ว */
  const btnClear = document.getElementById('btnClearAppt');
  btnClear?.addEventListener('click', async ()=>{
    if(!confirm('ล้างนัดหมายที่ตั้งไว้?')) return;
    try{
      const r = await fetch('schedule_action.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action:'clear-appointment', ticket_id:'<?= (int)$ticket['id'] ?>' })
      });
      const j = await r.json();
      if(j.ok){ 
        showToast('ล้างนัดแล้ว'); 
        location.reload(); 
      } else { 
        alert('ทำรายการไม่สำเร็จ: '+(j.error||'unknown')); 
      }
    }catch(_){ 
      alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); 
    }
  });
})();
</script>
</body>
</html>
