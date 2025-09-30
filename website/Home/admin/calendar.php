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
      <span class="badge text-bg-primary">ซ่อม</span>
      <span class="badge text-bg-danger">ซ่อม (เร่งด่วน)</span>
      <span class="badge text-bg-success">ซ่อม (ยืนยัน)</span>
      <span class="badge text-bg-warning text-dark">ข้อเสนอเวลา (รอยืนยัน)</span>
      <span class="badge text-bg-info text-dark">เทิร์น</span>
    </div>
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
      .then(r => r.json())
      .then(data => success(data))
      .catch(() => { alert('โหลดข้อมูลปฏิทินไม่สำเร็จ'); failure(); });
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
  });

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
      // รีโหลดเพื่อให้สี/สถานะอัปเดต
      calendar.refetchEvents();
    }catch(e){
      alert('ย้าย/ปรับเวลาไม่สำเร็จ');
      info.revert();
    }
  }

  calendar.render();
})();
</script>
</body>
</html>
