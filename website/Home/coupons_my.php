<?php
// Home/coupons_my.php
session_start();
require __DIR__.'/includes/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php?redirect=coupons_my.php'); exit; }

$uid = (int)$_SESSION['user_id'];
function h($s){ return htmlspecialchars((string)($s??''), ENT_QUOTES, 'UTF-8'); }
function baht($n){ return number_format((float)$n, 2); }
function has_col(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/','',$table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','',$col);
  $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $q && $q->num_rows>0;
}
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '$t'");
  return $q && $q->num_rows>0;
}

/* ----- โหลดคูปองของผู้ใช้ (รองรับสคีมาหลากหลาย) ----- */
$cols = [
  "id","code","type","value",
  "COALESCE(status,'active') AS status",
  "COALESCE(user_id, NULL) AS user_id",
  (has_col($conn,'coupons','starts_at') ? "starts_at" : "NULL AS starts_at"),
  (has_col($conn,'coupons','ends_at')   ? "ends_at"   : (has_col($conn,'coupons','expiry_date') ? "expiry_date AS ends_at" : "NULL AS ends_at")),
  (has_col($conn,'coupons','min_order_total') ? "COALESCE(min_order_total,0) AS min_order_total" : "0 AS min_order_total"),
  (has_col($conn,'coupons','uses_limit')      ? "COALESCE(uses_limit,0)      AS uses_limit"      : "0 AS uses_limit"),
  (has_col($conn,'coupons','per_user_limit')  ? "COALESCE(per_user_limit,0)  AS per_user_limit"  : "0 AS per_user_limit"),
  (has_col($conn,'coupons','used_count')      ? "COALESCE(used_count,0)      AS used_count"      : "0 AS used_count"),
  (has_col($conn,'coupons','segment')         ? "segment" : "'personal' AS segment"),
  (has_col($conn,'coupons','note')            ? "note"    : "NULL AS note"),
  (has_col($conn,'coupons','tradein_id')      ? "tradein_id" : "NULL AS tradein_id")
];

$hasSegment = has_col($conn,'coupons','segment');
$publicClause = $hasSegment ? "segment='all'" : "user_id IS NULL";

$sql = "SELECT ".implode(',', $cols)." FROM coupons
        WHERE ((user_id = ? OR {$publicClause}) AND COALESCE(status,'active')='active')
          AND (".(has_col($conn,'coupons','starts_at') ? "starts_at IS NULL OR starts_at<=NOW()" : "1=1").")
          AND (".(has_col($conn,'coupons','ends_at')   ? "ends_at   IS NULL OR ends_at>=NOW()"   : (has_col($conn,'coupons','expiry_date') ? "expiry_date IS NULL OR expiry_date>=NOW()" : "1=1")).")
        ORDER BY id DESC";
$st = $conn->prepare($sql);
$st->bind_param("i", $uid);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ----- โหลดจำนวนครั้งที่ผู้ใช้เคยใช้ต่อคูปอง (ถ้ามีตาราง coupon_usages) ----- */
$userUsed = [];
if (table_exists($conn,'coupon_usages') && !empty($rows)) {
  $ids = array_map(fn($r)=>(int)$r['id'], $rows);
  $ids = implode(',', array_map('intval', $ids));
  if ($ids !== '') {
    $q = $conn->query("SELECT coupon_id, COUNT(*) c FROM coupon_usages WHERE user_id={$uid} AND coupon_id IN ($ids) GROUP BY coupon_id");
    while($a=$q->fetch_assoc()){ $userUsed[(int)$a['coupon_id']] = (int)$a['c']; }
  }
}

/* ----- จัดหมวดหมู่: ใช้ได้ / หมดอายุ / ใช้ครบ ----- */
$now = date('Y-m-d H:i:s');
$usable = []; $expired = []; $exhausted = [];
foreach ($rows as $c) {
  $cid = (int)$c['id'];
  $myUsed = (int)($userUsed[$cid] ?? 0);
  $perUserLimit = (int)($c['per_user_limit'] ?? 0);
  $usesLimit    = (int)($c['uses_limit'] ?? 0);
  $usedCount    = (int)($c['used_count'] ?? 0);
  $active = (strtolower($c['status']) === 'active');

  $isExpired = (!empty($c['ends_at']) && $c['ends_at'] < $now);
  $hitTotal  = ($usesLimit>0 && $usedCount >= $usesLimit);
  $hitUser   = ($perUserLimit>0 && $myUsed   >= $perUserLimit);

  if ($isExpired || !$active)       { $expired[]   = $c; }
  elseif ($hitTotal || $hitUser)    { $exhausted[] = $c; }
  else                              { $usable[]    = $c; }
}

include __DIR__.'/includes/header.php';
?>

<style>
  :root{ --bg:#f7fafc; --card:#ffffff; --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --pri:#2563eb; --pri-weak:#e8efff; --good:#16a34a; --warn:#f59e0b; --bad:#ef4444; }
  body{ background:linear-gradient(180deg,#f8fbff,#f6f8fb 45%,#f5f7fa); }
  .page-head{ border-radius:20px; background:linear-gradient(135deg,#2563eb 0%,#4f46e5 45%,#0ea5e9 100%); color:#fff; padding:18px 18px 16px; box-shadow:0 8px 24px rgba(37,99,235,.15);}
  .page-head h3{ margin:0; display:flex; align-items:center; gap:.5rem; font-weight:700;}
  .page-head .sub{ opacity:.9; font-size:.925rem; margin-top:.25rem}

  .coupon-tabs{ margin-top:16px; background:#fff; border:1px solid var(--line); border-radius:14px; padding:6px; display:flex; gap:6px; flex-wrap:wrap; }
  .coupon-tab{ border:none; background:transparent; color:var(--muted); padding:8px 14px; border-radius:10px; font-weight:600; }
  .coupon-tab.active{ color:#0b1a37; background:var(--pri-weak); }

  .coupon-grid{ display:grid; grid-template-columns:repeat(1,minmax(0,1fr)); gap:14px; margin-top:14px; }
  @media(min-width:576px){ .coupon-grid{ grid-template-columns:repeat(2,1fr);} }
  @media(min-width:992px){ .coupon-grid{ grid-template-columns:repeat(3,1fr);} }

  .ticket{ position:relative; background:var(--card); border:1px solid var(--line); border-radius:16px; overflow:hidden; transition:.2s ease; }
  .ticket:hover{ transform:translateY(-2px); box-shadow:0 10px 30px rgba(17,24,39,.06); }
  .ticket .band{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 14px; background:linear-gradient(90deg,#0ea5e9 0%,#2563eb 100%); color:#fff; font-weight:700; }
  .ticket .band .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; background:rgba(255,255,255,.15); padding:6px 10px; border-radius:999px; font-size:.95rem; letter-spacing:.5px; cursor:copy; }
  .ticket .band .code:active{ transform:scale(.98); }
  .ticket .body{ padding:14px; color:var(--ink);}
  .ticket .value{ font-size:1.4rem; font-weight:800; line-height:1; }
  .ticket .meta{ margin-top:8px; color:var(--muted); font-size:.925rem; display:flex; flex-wrap:wrap; gap:8px; }
  .chip{ background:#f3f4f6; color:#374151; border-radius:999px; padding:4px 10px; font-weight:600; font-size:.8rem; }
  .ticket .foot{ display:flex; justify-content:space-between; align-items:center; border-top:1px dashed var(--line); padding:12px 14px; gap:8px; }
  .ticket .foot .btn{ border-radius:10px; padding:8px 12px; font-weight:700; }
  .btn-ghost{ background:#fff; border:1px solid var(--line); color:#111827; }
  .btn-ghost:hover{ border-color:#cfd6de; }
  .btn-pri{ background:var(--pri); color:#fff; border:0; }
  .btn-pri:hover{ filter:brightness(.96); }
  .ticket:before, .ticket:after{ content:""; position:absolute; top:58px; width:16px; height:16px; background:#f6f8fb; border:1px solid var(--line); border-radius:999px; }
  .ticket:before{ left:-8px; } .ticket:after{ right:-8px; }
  .cut{ position:relative; height:1px; background:repeating-linear-gradient(90deg, transparent 0 8px, var(--line) 8px 16px); margin:0; }
  .ribbon{ position:absolute; top:10px; right:-40px; transform:rotate(35deg); background:#111827; color:#fff; font-weight:800; font-size:.7rem; padding:6px 50px; box-shadow:0 8px 20px rgba(0,0,0,.08); opacity:.92; }
  .rb-usable{ background:var(--good);} .rb-exhaust{ background:var(--warn);} .rb-expired{ background:var(--bad);}
  .empty{ text-align:center; padding:38px; border:1px dashed var(--line); border-radius:16px; color:var(--muted); background:#fff; }
  .empty .emoji{ font-size:42px; }
  .fade-in{ animation:fade .25s ease; } @keyframes fade{ from{opacity:0; transform:translateY(4px)} to{opacity:1; transform:translateY(0)} }
  .small-muted{ color:var(--muted); font-size:.85rem;} .pointer{ cursor:pointer; }
</style>

<div class="container py-4">
  <div class="page-head">
    <h3><i class="bi bi-ticket-perforated"></i> คูปองของฉัน</h3>
    <div class="sub">รวมคูปองทั้งหมดของบัญชีคุณ แยกตามสถานะ • แตะรหัสเพื่อคัดลอก</div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty mt-3 fade-in">
      <div class="emoji mb-2">🎟️</div>
      <div class="h5 mb-1">ยังไม่มีคูปอง</div>
      <div class="small-muted">ถ้ามีคูปองสำหรับทุกคน จะแสดงอัตโนมัติที่นี่</div>
    </div>
  <?php else: ?>

    <div class="coupon-tabs mt-3" role="tablist" aria-label="Coupon filters">
      <button class="coupon-tab active" data-target="#tab-usable" role="tab" aria-selected="true"><i class="bi bi-check2-circle me-1"></i> พร้อมใช้งาน (<?=count($usable)?>)</button>
      <button class="coupon-tab" data-target="#tab-exhaust" role="tab" aria-selected="false"><i class="bi bi-graph-up-arrow me-1"></i> ใช้ครบแล้ว (<?=count($exhausted)?>)</button>
      <button class="coupon-tab" data-target="#tab-expired" role="tab" aria-selected="false"><i class="bi bi-x-circle me-1"></i> หมดอายุ/ปิด (<?=count($expired)?>)</button>
    </div>

    <?php
      function renderTicket($c, $status='usable'){
        $badgeType = (strtolower($c['type'])==='percent') ? 'เปอร์เซ็นต์' : 'มูลค่า';
        $valTxt    = (strtolower($c['type'])==='percent') ? rtrim(rtrim(number_format((float)$c['value'],2),'0'),'.').'%' : baht($c['value']).' ฿';
        $minTxt    = ((float)$c['min_order_total']>0) ? 'ขั้นต่ำ '.baht($c['min_order_total']).' ฿' : 'ไม่มีขั้นต่ำ';
        $expTxt    = !empty($c['ends_at']) ? date('d M Y H:i', strtotime($c['ends_at'])) : '-';
        $segTxt    = (!empty($c['segment']) && $c['segment']==='all') ? 'ทุกคน' : 'ส่วนบุคคล';
        $note      = trim((string)($c['note'] ?? ''));
        $isTrade   = !empty($c['tradein_id']);
        $rbClass   = $status==='usable' ? 'rb-usable' : ($status==='exhaust' ? 'rb-exhaust' : 'rb-expired');
        $rbText    = $status==='usable' ? 'พร้อมใช้' : ($status==='exhaust' ? 'ใช้ครบ' : 'หมดอายุ/ปิด');
        ?>
        <div class="ticket fade-in" data-code="<?=h($c['code'])?>">
          <div class="ribbon <?=$rbClass?>"><?=$rbText?></div>
          <div class="band">
            <div class="value">
              <?=$valTxt?> <span style="font-size:.95rem;font-weight:600"> (<?=$badgeType?>)</span>
              <?php if($isTrade): ?><span class="chip ms-2">เครดิตเทิร์น TR-<?= (int)$c['tradein_id'] ?></span><?php endif; ?>
            </div>
            <div class="code pointer" title="คัดลอกรหัส"><?=h($c['code'])?></div>
          </div>
          <div class="cut"></div>
          <div class="body">
            <div class="meta">
              <span class="chip"><i class="bi bi-people me-1"></i>ใช้กับ: <?=h($segTxt)?></span>
              <span class="chip"><i class="bi bi-cash-coin me-1"></i><?=$minTxt?></span>
              <span class="chip"><i class="bi bi-hourglass-split me-1"></i>หมดอายุ: <?=$expTxt?></span>
            </div>
            <?php if($note!==''): ?><div class="small-muted mt-2"><i class="bi bi-info-circle me-1"></i><?=h($note)?></div><?php endif; ?>
          </div>
          <div class="foot">
            <button class="btn btn-ghost btn-sm" data-copy="<?=h($c['code'])?>"><i class="bi bi-clipboard"></i> คัดลอกรหัส</button>
            <a class="btn btn-pri btn-sm" href="checkout.php?apply=<?=urlencode($c['code'])?>"><i class="bi bi-cart-check"></i> ใช้ที่ Checkout</a>
          </div>
        </div>
        <?php
      }
    ?>

    <div id="tab-usable" class="coupon-grid" role="tabpanel">
      <?php if(empty($usable)): ?><div class="empty"><div class="emoji">🕊️</div><div class="small-muted">ยังไม่มีคูปองที่พร้อมใช้งาน</div></div>
      <?php else: foreach($usable as $c){ renderTicket($c,'usable'); } endif; ?>
    </div>

    <div id="tab-exhaust" class="coupon-grid d-none" role="tabpanel" aria-hidden="true">
      <?php if(empty($exhausted)): ?><div class="empty"><div class="emoji">📈</div><div class="small-muted">ยังไม่มีคูปองที่ใช้ครบตามเงื่อนไข</div></div>
      <?php else: foreach($exhausted as $c){ renderTicket($c,'exhaust'); } endif; ?>
    </div>

    <div id="tab-expired" class="coupon-grid d-none" role="tabpanel" aria-hidden="true">
      <?php if(empty($expired)): ?><div class="empty"><div class="emoji">⏳</div><div class="small-muted">ยังไม่มีคูปองหมดอายุหรือปิดใช้งาน</div></div>
      <?php else: foreach($expired as $c){ renderTicket($c,'expired'); } endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php include __DIR__.'/assets/html/footer.html'; ?>

<script>
  document.querySelectorAll('.coupon-tab').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.coupon-tab').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      const target = btn.getAttribute('data-target');
      document.querySelectorAll('[role="tabpanel"]').forEach(p=>{
        if('#'+p.id === target){ p.classList.remove('d-none'); p.setAttribute('aria-hidden','false'); }
        else{ p.classList.add('d-none'); p.setAttribute('aria-hidden','true'); }
      });
    });
  });

  function copyText(txt){ return navigator.clipboard?.writeText(txt); }
  document.addEventListener('click', e=>{
    const copyBtn = e.target.closest('[data-copy]');
    const codePill = e.target.closest('.code');
    if(copyBtn){
      const v = copyBtn.getAttribute('data-copy');
      copyText(v).then(()=>{
        const old = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
        setTimeout(()=> copyBtn.innerHTML = old, 1200);
      });
    }else if(codePill){
      const card = codePill.closest('.ticket');
      const v = card.getAttribute('data-code');
      copyText(v).then(()=>{
        codePill.style.background='rgba(255,255,255,.25)';
        codePill.textContent='คัดลอกแล้ว!';
        setTimeout(()=>{ codePill.textContent=v; codePill.style.background='rgba(255,255,255,.15)'; }, 1000);
      });
    }
  }, {passive:true});
</script>
