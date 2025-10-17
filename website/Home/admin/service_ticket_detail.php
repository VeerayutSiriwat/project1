<?php
// Home/admin/service_ticket_detail.php (stabilized + UX improved)
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
$statuses = [
  'queued'=>'เข้าคิว','confirm'=>'ยืนยันคิว','checking'=>'ตรวจเช็ค','waiting_parts'=>'รออะไหล่',
  'repairing'=>'กำลังซ่อม','done'=>'เสร็จพร้อมรับ','cancelled'=>'ยกเลิก'
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
$spBadge = ['pending'=>'warning text-dark','accepted'=>'success','declined'=>'danger','cancelled'=>'secondary'];

?><!doctype html>
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
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="cardx p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h5 class="mb-0">ใบงาน <span class="badge text-bg-dark">ST-<?= (int)$ticket['id'] ?></span></h5>
          <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="badge text-bg-<?= $urgClass ?>"><i class="bi bi-lightning-charge"></i> <?= $urgLabel ?></span>
            <span>สถานะปัจจุบัน:
              <span class="badge text-bg-primary"><?= h($statuses[$ticket['status']] ?? $ticket['status']) ?></span>
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

          <div class="col-md-4"><div class="small text-muted">ค่าส่วนเพิ่มจากตัวเลือก</div>
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
          <button class="btn btn-outline-success btn-sm js-quick" data-status="confirm">ยืนยันคิว</button>
          <button class="btn btn-outline-primary btn-sm js-quick" data-status="repairing">กำลังซ่อม</button>
          <button class="btn btn-outline-success btn-sm js-quick" data-status="done">เสร็จ</button>
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
        <div class="small text-muted mt-2">* “ยืนยันคิว” จะตั้งเวลานัดอัตโนมัติ (จากข้อเสนอ/วันที่ลูกค้าเสนอ/เวลาปัจจุบัน)</div>
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
                    <span class="badge bg-<?= $spBadge[$p['status']] ?? 'secondary' ?> ms-1"><?= h($p['status']) ?></span>
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
  const toastEl = $('#toast'); const toastBody = $('#toastBody');
  const showToast = (msg)=>{ if(!toastEl) return; toastBody.textContent = msg||'เสร็จ'; new bootstrap.Toast(toastEl).show(); };

  /* Lightbox */
  const lb = new bootstrap.Modal(document.getElementById('lightbox'));
  const imgEl = document.getElementById('lightboxImg');
  document.addEventListener('click', (e)=>{
    const a = e.target.closest('[data-src]');
    if(!a) return;
    e.preventDefault();
    if(imgEl){ imgEl.src = a.getAttribute('data-src'); lb.show(); }
  });

  /* Copy phone */
  document.addEventListener('click', async (e)=>{
    const el = e.target.closest('.copy');
    if(!el) return;
    const txt = el.dataset.copy||'';
    try{ await navigator.clipboard.writeText(txt); showToast('คัดลอกแล้ว'); }catch(_){ /* ignore */ }
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
      if(j.ok){ showToast('ส่งข้อเสนอแล้ว'); location.reload(); }
      else { alert('ส่งข้อเสนอไม่สำเร็จ: '+(j.error||'unknown')); }
    }catch(_){ alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
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
      if(j.ok){ showToast('ดำเนินการเสร็จ'); location.reload(); }
      else { alert('ทำรายการไม่สำเร็จ: '+(j.error||'unknown')); }
    }catch(_){ alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
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
      if(j.ok){ showToast('ล้างนัดแล้ว'); location.reload(); } else { alert('ทำรายการไม่สำเร็จ: '+(j.error||'unknown')); }
    }catch(_){ alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
  });
})();
</script>
</body>
</html>
