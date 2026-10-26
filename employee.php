<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();

$db    = getDB();
ensureOvertimeColumns($db);
$empId = $_SESSION['employee_id'];
$today = date('Y-m-d');
$nowTs = time();

$empStmt = $db->prepare("SELECT * FROM employees WHERE id=?");
$empStmt->execute([$empId]);
$empData = $empStmt->fetch();
if (!$empData) { session_destroy(); header('Location: '.SITE_URL.'/login.php'); exit; }
if ($empData['role']==='driver') { header('Location: '.SITE_URL.'/driver.php'); exit; }
if ($empData['role']==='admin')  { header('Location: '.SITE_URL.'/admin.php');  exit; }

$workStart   = $empData['work_start'] ?? '10:00:00';
$workEnd     = $empData['work_end']   ?? '21:00:00';
$workStartTs = strtotime(date('Y-m-d').' '.$workStart);
$workEndTs   = strtotime(date('Y-m-d').' '.$workEnd);
$beforeWork  = $nowTs < $workStartTs;

$currentHour = (int)date('H');

// الوقت الإضافي يعمل فقط بين 9 مساءً و 10 صباحاً (قبل بداية الدوام التالي)
$inOvertimeWindow = ($currentHour >= 21 || $currentHour < 10);

$stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=?");
$stmt->execute([$empId, $today]);
$todayRecord = $stmt->fetch() ?: null;

$earlyLeaveMin = 0;
if ($todayRecord && $todayRecord['check_out']) {
    $co = strtotime($todayRecord['check_out']);
    if ($co < $workEndTs) $earlyLeaveMin = round(($workEndTs - $co) / 60);
}
$firstName = explode(' ', $empData['name'])[0];

$otReqStmt = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND request_date=?");
$otReqStmt->execute([$empId, $today]);
$otRequest = $otReqStmt->fetch() ?: null;

// الوقت الإضافي الفعلي المحسوب لهذا اليوم (من لحظة موافقة المدير إلى الانصراف الفعلي فقط)
$overtimeMin = 0;
if ($otRequest && $otRequest['ot_check_in']) {
    $otEnd = $otRequest['ot_check_out'] ?: date('Y-m-d H:i:s');
    $overtimeMin = round((strtotime($otEnd) - strtotime($otRequest['ot_check_in'])) / 60);
}

// إظهار بطاقة الوقت الإضافي: ضمن النافذة الزمنية المسموحة، أو إذا كان هناك طلب قائم/موافق عليه لم يُغلق بعد
$afterWork = $inOvertimeWindow || ($otRequest && in_array($otRequest['status'], ['pending', 'approved']));

// إحصاء الشهر
$mStmt = $db->prepare("SELECT COUNT(*) as cnt, SUM(late_minutes) as total_late FROM attendance WHERE employee_id=? AND date LIKE ?");
$mStmt->execute([$empId, date('Y-m').'%']);
$mData = $mStmt->fetch();
$monthDays = $mData['cnt'] ?? 0;
$totalLate = $mData['total_late'] ?? 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><?= getThemeHead('دوامي') ?></head>
<body>
<?= getTopbar('موظف') ?>
<div class="page-wrap">

  <?php if (canViewReports()): ?>
  <a href="reports.php" class="card" style="display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:inherit;padding:14px 16px;margin-bottom:14px">
    <span style="font-weight:700">📊 التقارير (حضور وغياب)</span>
    <span style="color:var(--blue)">فتح ←</span>
  </a>
  <?php endif; ?>

  <!-- ترحيب -->
  <div class="card" style="text-align:center;padding:20px 16px">
    <div style="font-size:48px;font-weight:800;letter-spacing:2px;font-variant-numeric:tabular-nums;line-height:1" id="clock">--:--</div>
    <div style="font-size:12px;color:var(--text3);margin:5px 0 12px" id="dt"></div>
    <div style="font-size:17px;font-weight:700;margin-bottom:6px">أهلاً <?= htmlspecialchars($firstName) ?> 👋</div>
    <span class="badge b-gold">الدوام <?= date('h:i a',$workStartTs) ?> — <?= date('h:i a',$workEndTs) ?></span>
  </div>

  <!-- حالة الحضور -->
  <?php if (!$todayRecord || !$todayRecord['check_in']): ?>
    <?php if($beforeWork): ?>
      <div class="status-row"><span class="dot da"></span>لم يحن وقت الدوام بعد</div>
    <?php else: ?>
      <div class="status-row"><span class="dot dgr"></span>لم تسجل حضورك بعد</div>
    <?php endif; ?>
  <?php elseif(!$todayRecord['check_out']): ?>
    <div class="status-row"><span class="dot dg"></span>أنت في الدوام الآن</div>
  <?php else: ?>
    <div class="status-row"><span class="dot dr"></span>انتهى دوامك اليوم</div>
  <?php endif; ?>

  <!-- تنبيهات -->
  <?php if($todayRecord && $todayRecord['late_minutes']>0): ?>
    <div class="notice n-red">⚠️ تأخرت <?=$todayRecord['late_minutes']?> دقيقة اليوم</div>
  <?php endif; ?>
  <?php if($earlyLeaveMin>0): ?>
    <div class="notice n-amber">🕐 غادرت قبل نهاية الدوام بـ <?=$earlyLeaveMin?> دقيقة</div>
  <?php endif; ?>
  <?php if($overtimeMin>0): ?>
    <div class="notice n-green">⭐ وقت إضافي: <?=$overtimeMin?> دقيقة</div>
  <?php endif; ?>

  <!-- إحصائيات اليوم -->
  <?php if($todayRecord): ?>
  <div class="card">
    <div class="card-title">📊 اليوم</div>
    <div class="grid2">
      <div class="sbox"><div class="slabel">وقت الدخول</div><div class="sval g"><?=date('h:i a',strtotime($todayRecord['check_in']))?></div></div>
      <div class="sbox"><div class="slabel">وقت الخروج</div><div class="sval <?=$todayRecord['check_out']?'r':'a'?>"><?=$todayRecord['check_out']?date('h:i a',strtotime($todayRecord['check_out'])):'لم تخرج'?></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- إحصائيات الشهر -->
  <div class="card">
    <div class="card-title">📅 هذا الشهر</div>
    <div class="grid3">
      <div class="sbox"><div class="slabel">أيام الحضور</div><div class="sval g"><?=$monthDays?></div></div>
      <div class="sbox"><div class="slabel">مجموع التأخير</div><div class="sval <?=$totalLate>0?'r':'g'?>"><?=$totalLate>0?floor($totalLate/60).'س '.($totalLate%60).'د':'0'?></div></div>
      <div class="sbox"><div class="slabel">الدور</div><div class="sval gd">موظف</div></div>
    </div>
  </div>

  <!-- أزرار الحضور -->
  <div class="card">
    <div class="card-title">✅ تسجيل الحضور</div>
    <?php if(!$todayRecord || !$todayRecord['check_in']): ?>
      <?php if($beforeWork): ?>
        <button class="btn btn-dis" disabled>⏳ الدوام يبدأ <?=date('h:i a',$workStartTs)?></button>
      <?php else: ?>
        <button class="btn btn-green" id="ci-btn" onclick="doCheckIn()">✅ تسجيل الحضور</button>
      <?php endif; ?>
    <?php elseif(!$todayRecord['check_out']): ?>
      <button class="btn btn-red" onclick="doCheckOut()">🚪 تسجيل الانصراف</button>
    <?php else: ?>
      <button class="btn btn-dis" disabled>✓ تم تسجيل دوامك اليوم</button>
    <?php endif; ?>
  </div>

  <!-- خريطة موقع الدخول -->
  <div class="card" id="map-card" style="display:none">
    <div class="card-title">📍 موقعك عند الدخول</div>
    <div id="gmap" style="width:100%;height:160px;border-radius:10px;overflow:hidden"></div>
    <div id="loc-txt" style="font-size:11px;color:var(--text3);text-align:center;margin-top:6px"></div>
  </div>

  <!-- أزرار إضافية -->
  <div class="card">
    <button class="btn btn-amber" onclick="openNote()">📝 ملاحظة للمدير</button>
    <a href="inventory.php" class="btn btn-blue">📦 نظام المستودع</a>
  </div>

  <!-- روابط HR -->
  <div class="card">
    <div class="card-title">🗂️ خدمات الموظف</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <a href="leaves.php" class="btn btn-green" style="text-align:center;text-decoration:none">🌴 طلب إجازة</a>
      <a href="performance.php" class="btn btn-gold" style="text-align:center;text-decoration:none">⭐ تقييمي</a>
      <a href="shifts.php" class="btn btn-blue" style="text-align:center;text-decoration:none">🕐 جدول الدوام</a>
      <a href="employee_report.php" class="btn btn-amber" style="text-align:center;text-decoration:none">📊 تقريري الشهري</a>
    </div>
  </div>

  <!-- زر الوقت الإضافي — يعمل فقط بين 9 مساءً و 10 صباحاً، منفصل تماماً عن دوامك العادي -->
  <?php if ($afterWork): ?>
  <div class="card" id="ot-card">
    <div class="card-title">⏰ الوقت الإضافي</div>
    <?php if (!$otRequest): ?>
      <?php if ($inOvertimeWindow): ?>
        <p style="font-size:13px;color:var(--text3);margin:0 0 12px">يمكنك طلب وقت إضافي الآن (للعمل أو تعويض غياب/تأخير). أرسل طلباً وانتظر موافقة المدير.</p>
        <button class="btn btn-amber" id="ot-btn" onclick="requestOvertime()">📨 طلب وقت إضافي</button>
      <?php else: ?>
        <p style="font-size:13px;color:var(--text3);margin:0">الوقت الإضافي متاح فقط من الساعة 9 مساءً حتى 10 صباحاً.</p>
      <?php endif; ?>
    <?php elseif ($otRequest['status'] === 'pending'): ?>
      <div class="notice n-amber">⏳ طلبك قيد المراجعة عند المدير...</div>
    <?php elseif ($otRequest['status'] === 'approved'): ?>
      <div class="notice n-green">✅ الوقت الإضافي موافق عليه — يُحسب من <?=date('h:i a',strtotime($otRequest['ot_check_in']))?> حتى الآن: <strong><?=$overtimeMin?> دقيقة</strong></div>
      <button class="btn btn-red" style="margin-top:10px" id="ot-checkout-btn" onclick="doOvertimeCheckout()">🚪 تسجيل انصراف الوقت الإضافي</button>
    <?php elseif ($otRequest['status'] === 'rejected'): ?>
      <div class="notice n-red">❌ الوقت الإضافي مرفوض — تم إنهاء دوامك العادي ولا يُحسب أي وقت إضافي</div>
    <?php elseif ($otRequest['status'] === 'completed'): ?>
      <div class="notice n-green">✅ تم تسجيل انصرافك من الوقت الإضافي — إجمالي: <strong><?=$overtimeMin?> دقيقة</strong></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>


</div>

<!-- Modal ملاحظة -->
<!-- GPS Modal -->
<div id="gps-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--card);border-radius:16px;padding:24px;max-width:360px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.4)">
    <div style="font-size:2rem;text-align:center;margin-bottom:8px">📍</div>
    <h3 style="text-align:center;margin:0 0 4px;color:var(--text)">يجب تفعيل الموقع (GPS)</h3>
    <p id="gps-device" style="text-align:center;color:var(--muted);font-size:.85rem;margin:0 0 16px"></p>
    <ul id="gps-steps" style="list-style:none;padding:0;margin:0 0 20px;display:flex;flex-direction:column;gap:10px;color:var(--text);line-height:1.6"></ul>
    <button onclick="closeGPSModal();location.reload();" style="width:100%;padding:12px;background:var(--green);color:#fff;border:none;border-radius:10px;font-size:1rem;cursor:pointer;font-weight:600">✅ فعّلت الموقع، حاول مجدداً</button>
    <button onclick="closeGPSModal()" style="width:100%;padding:10px;background:transparent;color:var(--muted);border:none;border-radius:10px;font-size:.9rem;cursor:pointer;margin-top:6px">إغلاق</button>
  </div>
</div>

<div class="modal-bg" id="note-modal">
  <div class="modal-box">
    <h3>📝 ملاحظة للمدير</h3>
    <textarea id="note-txt" class="field" style="width:100%;padding:11px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text);outline:none;resize:none;height:90px" placeholder="اكتب ملاحظتك هنا..."></textarea>
    <div class="notice n-green" id="note-ok" style="display:none">✅ تم إرسال الملاحظة للمدير</div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeNote()">إلغاء</button>
      <button class="mbtn ms" onclick="sendNote()">إرسال ✉️</button>
    </div>
  </div>
</div>

<script>
const GMAPS_KEY = '<?= GMAPS_API_KEY ?>';
let lat=null,lng=null;

function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=n.toLocaleTimeString('ar-JO',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('dt').textContent=n.toLocaleDateString('ar-JO',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
setInterval(tick,1000);tick();

if('serviceWorker' in navigator) navigator.serviceWorker.register('/tracking/sw.js').catch(()=>{});

/* ===== GPS Modal ===== */
function detectDevice(){
  const ua=navigator.userAgent;
  if(/iPad|iPhone|iPod/.test(ua))return'ios';
  if(/Android/.test(ua))return'android';
  return'desktop';
}
function getGPSInstructions(){
  const dev=detectDevice();
  if(dev==='android')return[
    '1️⃣ اضغط على أيقونة القفل 🔒 بجانب رابط الموقع في المتصفح',
    '2️⃣ اضغط على <b>أذونات الموقع</b>',
    '3️⃣ اختر <b>السماح</b> ثم أعد تحميل الصفحة',
    '<small>أو: الإعدادات ← التطبيقات ← المتصفح ← الأذونات ← الموقع ← السماح</small>'
  ];
  if(dev==='ios')return[
    '1️⃣ افتح <b>الإعدادات</b> في جهازك',
    '2️⃣ اضغط على <b>Safari</b> (أو المتصفح الذي تستخدمه)',
    '3️⃣ اضغط على <b>الموقع</b>',
    '4️⃣ اختر <b>اسمح</b> ثم ارجع وأعد المحاولة'
  ];
  return[
    '1️⃣ انقر على أيقونة القفل 🔒 في شريط العنوان',
    '2️⃣ انقر على <b>أذونات الموقع</b> أو <b>إعدادات الموقع</b>',
    '3️⃣ غيّر الموقع الجغرافي إلى <b>السماح</b>',
    '4️⃣ أعد تحميل الصفحة وحاول مجدداً'
  ];
}
function showGPSModal(){
  document.getElementById('gps-modal').style.display='flex';
  const steps=getGPSInstructions();
  document.getElementById('gps-steps').innerHTML=steps.map(s=>`<li>${s}</li>`).join('');
  const icons={'android':'🤖 Android','ios':'🍎 iOS','desktop':'💻 الكمبيوتر'};
  document.getElementById('gps-device').textContent=icons[detectDevice()]||'';
}
function closeGPSModal(){document.getElementById('gps-modal').style.display='none';}

async function getPos(){
  return new Promise((res,rej)=>navigator.geolocation.getCurrentPosition(res,rej,{enableHighAccuracy:true,timeout:12000}));
}

async function doCheckIn(){
  if(!navigator.geolocation){showGPSModal();return;}
  const btn=document.getElementById('ci-btn');
  btn.disabled=true;btn.textContent='⏳ جارٍ تحديد موقعك...';
  try{
    const p=await getPos();
    lat=p.coords.latitude;lng=p.coords.longitude;
  }catch(e){
    showGPSModal();
    btn.disabled=false;btn.textContent='✅ تسجيل الحضور';return;
  }
  btn.textContent='⏳ جارٍ التسجيل...';
  try{
    const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_in',lat,lng})});
    const d=await r.json();
    if(d.success){showMap(lat,lng);setTimeout(()=>location.reload(),1500);}
    else{alert(d.error||'خطأ في التسجيل');btn.disabled=false;btn.textContent='✅ تسجيل الحضور';}
  }catch(e){alert('تحقق من الاتصال');btn.disabled=false;btn.textContent='✅ تسجيل الحضور';}
}

async function doCheckOut(){
  try{const p=await getPos();lat=p.coords.latitude;lng=p.coords.longitude;}catch(e){lat=0;lng=0;}
  if(!confirm('تأكيد تسجيل الانصراف؟'))return;
  try{
    const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_out',lat:lat||0,lng:lng||0})});
    const d=await r.json();
    if(d.success)location.reload();else alert(d.error||'خطأ');
  }catch(e){alert('تحقق من الاتصال');}
}

function showMap(la,lo){
  document.getElementById('map-card').style.display='block';
  document.getElementById('loc-txt').textContent='📍 '+la.toFixed(5)+' , '+lo.toFixed(5);
  if(window.google){initMap(la,lo);return;}
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key='+GMAPS_KEY+'&language=ar&callback=gmReady';
  window.gmReady=()=>initMap(la,lo);
  document.head.appendChild(s);
}
function initMap(la,lo){
  const m=new google.maps.Map(document.getElementById('gmap'),{center:{lat:la,lng:lo},zoom:16,mapTypeControl:false,streetViewControl:false,fullscreenControl:false});
  new google.maps.Marker({position:{lat:la,lng:lo},map:m});
}

function openNote(){document.getElementById('note-modal').classList.add('open');document.getElementById('note-ok').style.display='none';}
function closeNote(){document.getElementById('note-modal').classList.remove('open');document.getElementById('note-txt').value='';}
async function sendNote(){
  const t=document.getElementById('note-txt').value.trim();
  if(!t){alert('الرجاء كتابة ملاحظتك');return;}
  try{
    const r=await fetch('api/note.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({note:t,lat:lat||0,lng:lng||0})});
    const d=await r.json();
    if(d.success){document.getElementById('note-ok').style.display='flex';document.getElementById('note-txt').value='';setTimeout(closeNote,2000);}
    else alert(d.error||'خطأ');
  }catch(e){alert('تحقق من الاتصال');}
}
document.getElementById('note-modal').addEventListener('click',e=>{if(e.target===document.getElementById('note-modal'))closeNote();});

<?php if($todayRecord && $todayRecord['check_in_lat'] && $todayRecord['check_in_lat']!=0): ?>
showMap(<?=$todayRecord['check_in_lat']?>,<?=$todayRecord['check_in_lng']?>);
<?php endif; ?>

// ===== الوقت الإضافي =====
async function requestOvertime(){
  const btn=document.getElementById('ot-btn');
  if(!btn)return;
  btn.disabled=true;btn.textContent='⏳ جارٍ الإرسال...';
  try{
    const r=await fetch('api/overtime_request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'request'})});
    const d=await r.json();
    if(d.success){
      document.getElementById('ot-card').innerHTML='<div class="card-title">⏰ الوقت الإضافي</div><div class="notice n-amber">⏳ طلبك قيد المراجعة عند المدير...</div>';
      // تحقق دوري من الرد
      startOtPolling();
    } else {
      alert(d.error||'خطأ في إرسال الطلب');
      btn.disabled=false;btn.textContent='📨 طلب وقت إضافي';
    }
  }catch(e){alert('تحقق من الاتصال');btn.disabled=false;btn.textContent='📨 طلب وقت إضافي';}
}

function startOtPolling(){
  const interval=setInterval(async()=>{
    try{
      const r=await fetch('api/overtime_request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_status'})});
      const d=await r.json();
      const card=document.getElementById('ot-card');
      if(!card){clearInterval(interval);return;}
      if(d.status==='approved'){
        clearInterval(interval);
        location.reload(); // لإظهار وقت بداية الإضافي وبدء الحساب الفعلي من لحظة الموافقة
      } else if(d.status==='rejected'){
        card.innerHTML='<div class="card-title">⏰ الوقت الإضافي</div><div class="notice n-red">❌ الوقت الإضافي مرفوض — تم إنهاء دوامك العادي ولا يُحسب أي وقت إضافي</div>';
        clearInterval(interval);
        setTimeout(()=>location.reload(),2000);
      }
    }catch(e){}
  },15000); // كل 15 ثانية
}

async function doOvertimeCheckout(){
  if(!confirm('تأكيد تسجيل الانصراف من الوقت الإضافي؟'))return;
  const btn=document.getElementById('ot-checkout-btn');
  if(btn){btn.disabled=true;btn.textContent='⏳ جارٍ التسجيل...';}
  let la=0,lo=0;
  try{
    const pos=await new Promise((res,rej)=>navigator.geolocation.getCurrentPosition(res,rej,{timeout:5000}));
    la=pos.coords.latitude;lo=pos.coords.longitude;
  }catch(e){}
  try{
    const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'overtime_checkout',lat:la,lng:lo})});
    const d=await r.json();
    if(d.success){
      document.getElementById('ot-card').innerHTML='<div class="card-title">⏰ الوقت الإضافي</div><div class="notice n-green">✅ تم تسجيل انصرافك من الوقت الإضافي — إجمالي: <strong>'+(d.ot_minutes??0)+' دقيقة</strong></div>';
    } else {
      alert(d.error||'خطأ في التسجيل');
      if(btn){btn.disabled=false;btn.textContent='🚪 تسجيل انصراف الوقت الإضافي';}
    }
  }catch(e){alert('تحقق من الاتصال');if(btn){btn.disabled=false;btn.textContent='🚪 تسجيل انصراف الوقت الإضافي';}}
}

// إذا الطلب موجود وبانتظار رد — ابدأ الـ polling تلقائياً
<?php if($otRequest && $otRequest['status']==='pending'): ?>
startOtPolling();
<?php endif; ?>
</script>
</body>
</html>
