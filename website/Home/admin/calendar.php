<?php 
// Home/admin/calendar.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';

// ตรวจสิทธิ์ admin เท่านั้น
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: ../login.php?redirect=admin/calendar.php');
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ปฏิทินนัดหมาย | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap core -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <!-- FullCalendar -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.css" rel="stylesheet">

  <style>
    body{
      background:#f6f8fb;
    }
    .page-title{
      font-weight:700;
    }
    .card-calendar{
      border-radius:18px;
      border:1px solid #e5ebf4;
      box-shadow:0 20px 60px rgba(15,23,42,.06);
      background:#fff;
      padding:1rem 1rem 1.25rem;
      position:relative;
    }
    .toolbar .btn{
      border-radius:999px;
    }

    /* legend */
    .legend{
      display:flex;
      gap:.5rem;
      align-items:center;
      flex-wrap:wrap;
      font-size:.9rem;
    }
    .legend .badge-custom{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.3rem .6rem;
      border-radius:.5rem;
      font-weight:600;
      box-shadow:0 1px 0 rgba(0,0,0,0.04);
      border:1px solid rgba(15,23,42,0.05);
    }
    .legend .swatch{
      width:12px;
      height:12px;
      border-radius:3px;
      display:inline-block;
      box-shadow:inset 0 -1px 0 rgba(0,0,0,0.06);
    }

    /* fullcalendar tweaks */
    .fc .fc-toolbar-title{
      font-size:1.15rem;
      font-weight:600;
    }
    .fc .fc-button{
      border-radius:999px !important;
      padding:.25rem .7rem;
      font-size:.85rem;
    }
    .fc .fc-daygrid-day-number{
      font-size:.85rem;
      font-weight:500;
    }
    .fc .fc-today{
      background:#eef4ff !important;
    }

    /* events */
    .fc .fc-event{
      border-radius:6px;
      box-shadow:0 1px 0 rgba(0,0,0,0.04);
      padding:0.18rem 0.35rem;
      margin:0.12rem 0;
      border:0;
      color:inherit !important;
    }
    .fc .fc-event-title a{
      color:inherit !important;
      text-decoration:none !important;
    }
    .fc .fc-daygrid-event .fc-event-main-frame{
      display:flex;
      align-items:center;
      gap:.35rem;
    }
    .fc .fc-daygrid-event .dot-custom{
      width:8px;
      height:8px;
      border-radius:50%;
      flex:0 0 8px;
      margin-left:2px;
    }
    .fc .fc-event-title{
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width:180px;
      display:inline-block;
      vertical-align:middle;
      font-size:.8rem;
    }
    .fc .fc-event-time{
      font-size:.8rem;
    }

    /* loading overlay */
    .calendar-loading{
      position:absolute;
      inset:0;
      background:linear-gradient(to bottom right, rgba(255,255,255,0.9), rgba(246,248,251,0.95));
      display:flex;
      align-items:center;
      justify-content:center;
      z-index:5;
      opacity:0;
      pointer-events:none;
      transition:opacity .18s ease;
    }
    .calendar-loading.active{
      opacity:1;
      pointer-events:auto;
    }

    @media (max-width: 768px){
      .fc .fc-toolbar.fc-header-toolbar{
        flex-direction:column;
        align-items:flex-start;
        gap:.35rem;
      }
      .fc .fc-toolbar-title{
        font-size:1rem;
      }
      .fc .fc-button{
        font-size:.8rem;
        padding:.2rem .55rem;
      }
      .fc .fc-daygrid-event .fc-event-main-frame{
        gap:.25rem;
      }
      .fc .fc-event-title{
        max-width:120px;
      }
    }
  </style>
</head>
<body class="py-3">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-0 page-title">ปฏิทินนัดหมาย</h4>
      <div class="text-muted small">
        ดูคิวซ่อม / เทิร์น / ข้อเสนอเวลานัด ทั้งหมดในมุมมองเดียว
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">
        <i class="bi bi-arrow-left"></i> กลับ Dashboard
      </a>
    </div>
  </div>

  <!-- แถบตัวช่วยด้านบน + ปฏิทิน -->
  <div class="card-calendar mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 toolbar mb-3">
      <!-- legend -->
      <div class="legend">
        <span class="badge-custom" style="background:#0d6efd;color:#fff">
          <span class="swatch" style="background:#0b5ed7"></span>ซ่อม
        </span>
        <span class="badge-custom" style="background:#dc3545;color:#fff">
          <span class="swatch" style="background:#bb2d3b"></span>ซ่อม (เร่งด่วน)
        </span>
        <span class="badge-custom" style="background:#198754;color:#fff">
          <span class="swatch" style="background:#157347"></span>ซ่อม (ยืนยัน)
        </span>
        <span class="badge-custom" style="background:#ffc107;color:#000">
          <span class="swatch" style="background:#ffca2c"></span>ข้อเสนอเวลา (รอยืนยัน)
        </span>
        <span class="badge-custom" style="background:#0dcaf0;color:#000">
          <span class="swatch" style="background:#0bb3d9"></span>เทิร์น
        </span>
        <span class="badge-custom" style="background:#6c757d;color:#fff">
          <span class="swatch" style="background:#5c636a"></span>เสร็จสิ้น
        </span>
      </div>

      <!-- quick view buttons -->
      <div class="d-flex gap-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-jump="today">
          วันนี้
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-view="timeGridDay">
          วัน
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-view="timeGridWeek">
          สัปดาห์
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-view="dayGridMonth">
          เดือน
        </button>
      </div>
    </div>

    <!-- ปฏิทิน -->
    <div id="calendar"></div>

    <!-- overlay ตอนกำลังโหลด event -->
    <div class="calendar-loading" id="calendarLoading">
      <div class="text-center">
        <div class="spinner-border mb-2" role="status" aria-hidden="true"></div>
        <div class="small text-muted">กำลังโหลดข้อมูลปฏิทิน...</div>
      </div>
    </div>
  </div>

  <!-- toast แสดง error -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div id="calendarErrorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="calendarErrorToastBody">
          เกิดข้อผิดพลาดในการโหลดข้อมูลปฏิทิน
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const calEl     = document.getElementById('calendar');
  const loadingEl = document.getElementById('calendarLoading');
  const toastEl   = document.getElementById('calendarErrorToast');
  const toastBody = document.getElementById('calendarErrorToastBody');
  const errorToast = toastEl ? new bootstrap.Toast(toastEl, { delay: 5000 }) : null;

  function showLoading(isLoading){
    if (!loadingEl) return;
    loadingEl.classList.toggle('active', !!isLoading);
  }

  function showToastError(message){
    if (!errorToast || !toastBody) {
      alert(message || 'เกิดข้อผิดพลาด');
      return;
    }
    toastBody.textContent = message || 'เกิดข้อผิดพลาดในการทำงาน';
    errorToast.show();
  }

  function fetchEvents(info, success, failure){
    const qs = new URLSearchParams({
      start: info.startStr,
      end: info.endStr
    });

    showLoading(true);

    fetch('calendar_events.php?' + qs.toString(), {
      method: 'GET',
      headers: { 'Accept': 'application/json, text/plain, */*' }
    })
      .then(async r => {
        const txt = await r.text();
        try {
          const data = JSON.parse(txt);
          success(data);
        } catch (err) {
          console.error('calendar_events response (not valid JSON):', r.status, txt);
          showToastError('โหลดข้อมูลปฏิทินไม่สำเร็จ (ข้อมูลไม่ถูกต้อง)');
          if (failure) failure(err);
        }
      })
      .catch((err) => {
        console.error('fetch error', err);
        showToastError('โหลดข้อมูลปฏิทินไม่สำเร็จ (การเชื่อมต่อล้มเหลว)');
        if (failure) failure(err);
      })
      .finally(() => {
        showLoading(false);
      });
  }

  async function dragUpdate(info){
    const ev = info.event;
    const p  = ev.extendedProps || {};

    const payload = new URLSearchParams({
      kind:  p.kind   || 'repair',
      id:    p.data_id || ev.id,
      start: ev.start ? ev.start.toISOString().slice(0,19).replace('T',' ') : '',
      end:   ev.end   ? ev.end.toISOString().slice(0,19).replace('T',' ')   : ''
    });

    try{
      const r = await fetch('calendar_drag_update.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
        body: payload.toString()
      });

      const txt = await r.text();
      let j = null;
      try{
        j = JSON.parse(txt);
      }catch(parseErr){
        console.error('calendar_drag_update not json:', r.status, txt);
        throw new Error('invalid_json');
      }

      if (!j.ok){
        throw new Error(j.error || 'update_failed');
      }

      // reload events เพื่อ sync ข้อมูลล่าสุดจาก server
      calendar.refetchEvents();
    }catch(e){
      console.error('dragUpdate error:', e);
      showToastError('ย้าย/ปรับเวลาไม่สำเร็จ');
      info.revert(); // ย้อนกลับตำแหน่งเดิม
    }
  }

  const calendar = new FullCalendar.Calendar(calEl, {
    initialView: 'dayGridMonth',
    locale: 'th',
    firstDay: 0,
    height: 'auto',
    aspectRatio: 1.5,
    nowIndicator: true,
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
    },
    buttonText: {
      today: 'วันนี้',
      month: 'เดือน',
      week: 'สัปดาห์',
      day: 'วัน',
      list: 'รายการ'
    },
    slotMinTime: '00:00:00',
    slotMaxTime: '24:00:00',

    events: fetchEvents,
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    editable: true,

    // คลิก event → เปิดหน้าใบงาน/เทิร์นในแท็บใหม่ (ทุก view รวมถึง list)
    eventClick: function(info){
      if (info.event.url){
        window.open(info.event.url, '_blank');
      }
      if (info.jsEvent){
        info.jsEvent.preventDefault();
      }
    },

    eventDrop:   (info)=>dragUpdate(info),
    eventResize: (info)=>dragUpdate(info),

    // custom render: จุดสี + เวลา + ชื่อ
    eventContent: function(arg){
      // *** สำคัญ: ให้ view แบบ list ใช้ renderer เดิมของ FullCalendar ***
      if (arg.view && arg.view.type && arg.view.type.indexOf('list') === 0) {
        return true; // ไม่ custom → คลิกแถวรายการได้ปกติ
      }

      const dot = document.createElement('span');
      dot.className = 'dot-custom';
      dot.style.backgroundColor = arg.event.backgroundColor
        || (arg.event.extendedProps && arg.event.extendedProps.backgroundColor)
        || 'transparent';
      dot.style.border = '1px solid rgba(0,0,0,0.05)';

      const time = document.createElement('span');
      time.className = 'fc-event-time';
      time.innerText = arg.timeText ? arg.timeText + ' ' : '';

      const title = document.createElement('span');
      title.className = 'fc-event-title';
      title.innerText = arg.event.title;

      const wrap = document.createElement('div');
      wrap.style.display = 'inline-flex';
      wrap.style.alignItems = 'center';
      wrap.appendChild(dot);
      wrap.appendChild(time);
      wrap.appendChild(title);

      return { domNodes: [wrap] };
    },

    eventDidMount: function(info){
      try{
        const bg = info.event.backgroundColor
          || (info.event.extendedProps && info.event.extendedProps.backgroundColor);
        const border = info.event.borderColor
          || (info.event.extendedProps && info.event.extendedProps.borderColor)
          || bg;
        const txt = info.event.textColor
          || (info.event.extendedProps && info.event.extendedProps.textColor);

        if (bg) {
          info.el.style.backgroundColor = bg;
          info.el.style.borderColor = border || bg;
          info.el.style.color = txt || getContrastColor(bg);

          const dot = info.el.querySelector('.dot-custom, .fc-daygrid-event-dot, .fc-event-dot');
          if (dot) {
            dot.style.backgroundColor = bg;
            dot.style.borderColor = border || bg;
          }
          const a = info.el.querySelector('a');
          if (a) a.style.color = txt || getContrastColor(bg);
        }

        // tooltip title จาก description (ถ้ามี)
        if (info.event.extendedProps && info.event.extendedProps.description){
          info.el.title = info.event.extendedProps.description;
        }
      }catch(e){
        console.error('eventDidMount error', e);
      }
    },

    loading: function(isLoading){
      showLoading(isLoading);
    }
  });

  function getContrastColor(hex){
    try{
      if (!hex) return '#000';
      if (hex[0] === '#') hex = hex.slice(1);
      if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
      const r = parseInt(hex.slice(0,2),16),
            g = parseInt(hex.slice(2,4),16),
            b = parseInt(hex.slice(4,6),16);
      const yiq = ((r*299)+(g*587)+(b*114))/1000;
      return yiq >= 128 ? '#000' : '#fff';
    }catch(e){
      return '#000';
    }
  }

  calendar.render();

  // quick buttons (วันนี้ / วัน / สัปดาห์ / เดือน)
  document.querySelectorAll('[data-jump]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const t = btn.getAttribute('data-jump');
      if (t === 'today') calendar.today();
    });
  });
  document.querySelectorAll('[data-view]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const v = btn.getAttribute('data-view');
      calendar.changeView(v);
    });
  });

});
</script>
</body>
</html>
