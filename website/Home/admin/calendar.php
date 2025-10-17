
<?php
// Home/admin/calendar.php
if (session_status()===PHP_SESSION_NONE){ session_start(); }
require __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_id']) || (($_SESSION['role']??'')!=='admin')) {
  header('Location: ../login.php?redirect=admin/calendar.php'); exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ปฏิทินนัดหมาย | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.css" rel="stylesheet">
  <style>
    body{ background:#f6f8fb }
    .toolbar .btn{ border-radius:999px }
    .legend .badge{ font-weight:600 }

    /* บังคับให้ลิงก์ใน event ใช้สีที่กำหนด (override default link color) */
    .fc .fc-event-title a { color: inherit !important; text-decoration: none !important; }
    .fc .fc-event { color: inherit !important; }
    .fc .fc-daygrid-event-dot { background-color: currentColor !important; border-color: currentColor !important; }

    /* เพิ่มความสวยงามให้ event (rounded + shadow) */
    .fc .fc-event {
      border-radius: 6px;
      box-shadow: 0 1px 0 rgba(0,0,0,0.04);
      padding: 0.18rem 0.35rem;
      margin: 0.12rem 0;
    }
    .fc .fc-daygrid-event .fc-event-main-frame { display:flex; align-items:center; gap:0.4rem; }
    .fc .fc-daygrid-event .dot-custom {
      width:8px; height:8px; border-radius:50%;
      flex:0 0 8px;
      margin-left:2px;
    }

    /* ensure text wraps nicely */
    .fc .fc-event-title { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; display:inline-block; vertical-align:middle; }

    /* Legend styles (ใหม่) */
    .legend { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
  .legend .badge-custom {
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    padding:.35rem .6rem;
    border-radius:.375rem;
    font-weight:600;
    box-shadow:0 1px 0 rgba(0,0,0,0.04);
    font-size:.9rem;
  }
  .legend .swatch {
    width:12px; height:12px; border-radius:3px; display:inline-block; margin-right:.35rem;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.06);
  }.legend { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
  .legend .badge-custom {
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    padding:.35rem .6rem;
    border-radius:.375rem;
    font-weight:600;
    box-shadow:0 1px 0 rgba(0,0,0,0.04);
    font-size:.9rem;
  }
  .legend .swatch {
    width:12px; height:12px; border-radius:3px; display:inline-block; margin-right:.35rem;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.06);
  }
  </style>
</head>
<body class="py-3">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0">ปฏิทินนัดหมาย</h4>
    <a class="btn btn-outline-secondary" href="dashboard.php">กลับ Dashboard</a>
  </div>

  <div class="d-flex gap-2 flex-wrap toolbar mb-2">
    <div class="legend me-3">
      <span class="badge-custom" style="background:#0d6efd;color:#fff"><span class="swatch" style="background:#0d6efd"></span>ซ่อม</span>
      <span class="badge-custom" style="background:#dc3545;color:#fff"><span class="swatch" style="background:#dc3545"></span>ซ่อม (เร่งด่วน)</span>
      <span class="badge-custom" style="background:#198754;color:#fff"><span class="swatch" style="background:#198754"></span>ซ่อม (ยืนยัน)</span>
      <span class="badge-custom" style="background:#ffc107;color:#000"><span class="swatch" style="background:#ffc107"></span>ข้อเสนอเวลา (รอยืนยัน)</span>
      <span class="badge-custom" style="background:#0dcaf0;color:#000"><span class="swatch" style="background:#0dcaf0"></span>เทิร์น</span>
      <!-- เพิ่มสถานะ 'เสร็จสิ้น' -->
      <span class="badge-custom" style="background:#6c757d;color:#fff"><span class="swatch" style="background:#6c757d"></span>เสร็จสิ้น</span>
    </div>

  <div id="calendar" class="bg-white rounded border p-2"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js"></script>
<script>
(function(){
  const calEl = document.getElementById('calendar');

  function fetchEvents(info, success, failure){
    const qs = new URLSearchParams({
      start: info.startStr,
      end: info.endStr
    });
    fetch('calendar_events.php?'+qs.toString())
      .then(async r => {
        const txt = await r.text();
        try {
          const data = JSON.parse(txt);
          success(data);
        } catch (err) {
          console.error('calendar_events response (not json):', r.status, txt);
          alert('โหลดข้อมูลปฏิทินไม่สำเร็จ — ดู console เพื่อดูรายละเอียด');
          failure();
        }
      })
      .catch((err) => { console.error('fetch error', err); alert('โหลดข้อมูลปฏิทินไม่สำเร็จ'); failure(); });
  }

  async function dragUpdate(info){
    const ev = info.event;
    const p  = ev.extendedProps || {};
    const payload = new URLSearchParams({
      kind: p.kind || 'repair',
      id:   p.data_id || ev.id,
      start: ev.start ? ev.start.toISOString().slice(0,19).replace('T',' ') : '',
      end:   ev.end   ? ev.end.toISOString().slice(0,19).replace('T',' ')   : ''
    });
    try{
      const r = await fetch('calendar_drag_update.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: payload.toString()
      });
      const j = await r.json();
      if(!j.ok){ throw new Error(j.error||'update_failed'); }
      calendar.refetchEvents();
    }catch(e){
      alert('ย้าย/ปรับเวลาไม่สำเร็จ');
      info.revert();
    }
  }

  const calendar = new FullCalendar.Calendar(calEl, {
    initialView: 'dayGridMonth',
    locale: 'th',
    firstDay: 0,
    height: 'auto',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
    },
    events: fetchEvents,
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    editable: true,
    eventClick: (info) => {
      if (info.event.url){ window.open(info.event.url, '_blank'); }
      info.jsEvent.preventDefault();
    },
    eventDrop:      (info)=>dragUpdate(info),
    eventResize:    (info)=>dragUpdate(info),

    // custom render: add small dot + title/time arrangement so color looks consistent
    eventContent: function(arg){
      // arg.event has backgroundColor/textColor from server
      const dot = document.createElement('span');
      dot.className = 'dot-custom';
      dot.style.backgroundColor = arg.event.backgroundColor || (arg.event.extendedProps && arg.event.extendedProps.backgroundColor) || 'transparent';
      dot.style.border = '1px solid rgba(0,0,0,0.05)';

      const time = document.createElement('span');
      time.className = 'fc-event-time';
      time.innerText = arg.timeText ? arg.timeText + ' ' : '';

      const title = document.createElement('span');
      title.className = 'fc-event-title';
      title.innerText = arg.event.title;

      const wrapper = document.createElement('div');
      wrapper.style.display = 'inline-flex';
      wrapper.style.alignItems = 'center';
      wrapper.appendChild(dot);
      wrapper.appendChild(time);
      wrapper.appendChild(title);

      return { domNodes: [wrapper] };
    },

    // ensure inline styles for elements (override CSS priority issues)
    eventDidMount: function(info){
      try{
        const bg = info.event.backgroundColor || (info.event.extendedProps && info.event.extendedProps.backgroundColor);
        const border = info.event.borderColor || (info.event.extendedProps && info.event.extendedProps.borderColor) || bg;
        const txt = info.event.textColor || (info.event.extendedProps && info.event.extendedProps.textColor);

        // set on root element and common inner wrappers
        if (bg) {
          info.el.style.backgroundColor = bg;
          info.el.style.borderColor = border;
          info.el.style.color = txt || getContrastColor(bg);
          // also colorize dot if present
          const dot = info.el.querySelector('.dot-custom, .fc-daygrid-event-dot, .fc-event-dot');
          if (dot) {
            dot.style.backgroundColor = bg;
            dot.style.borderColor = border || bg;
          }
          // links
          const a = info.el.querySelector('a');
          if (a) a.style.color = txt || getContrastColor(bg);
        }
      }catch(e){ console.error('eventDidMount error', e); }
    }
  });

  // helper to pick white/black text for contrast
  function getContrastColor(hex){
    try{
      if (!hex) return '#000';
      if (hex[0]==='#') hex = hex.slice(1);
      if (hex.length===3) hex = hex.split('').map(c=>c+c).join('');
      const r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
      const yiq = ((r*299)+(g*587)+(b*114))/1000;
      return yiq >= 128 ? '#000' : '#fff';
    }catch(e){ return '#000'; }
  }

  calendar.render();
})();
</script>
</body>
</html>