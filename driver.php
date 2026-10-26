<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();
if($_SESSION['role']!=='driver'){header('Location: '.(isAdmin()?'admin.php':'employee.php'));exit;}

$db=getDB();
ensureOvertimeColumns($db);
$empId=$_SESSION['employee_id'];$today=date('Y-m-d');
$chk=$db->prepare("SELECT * FROM employees WHERE id=?");$chk->execute([$empId]);
$empData=$chk->fetch();
if(!$empData){session_destroy();header('Location: '.SITE_URL.'/login.php');exit;}

$workStart   = $empData['work_start'] ?? '10:00:00';
$workEnd     = $empData['work_end']   ?? '21:00:00';
$workStartTs = strtotime(date('Y-m-d').' '.$workStart);
$workEndTs   = strtotime(date('Y-m-d').' '.$workEnd);
$nowTs       = time();
$beforeWork  = $nowTs < $workStartTs;

$currentHour = (int)date('H', $nowTs);


$tripAllowed = true; // متاح 24 ساعة
// الوقت الإضافي يعمل فقط بين 9 مساءً و 10 صباحاً (قبل بداية الدوام التالي)
$inOvertimeWindow = ($currentHour >= 21 || $currentHour < 10);
// داخل/خارج الدوام لتلوين الزر
$workStartParts = explode(':', $workStart);
$workEndParts = explode(':', $workEnd);
$workStartH = (int)$workStartParts[0];
$workEndH = (int)$workEndParts[0];
$tripInsideHours = ($currentHour >= $workStartH && $currentHour < $workEndH);

$stmt=$db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=?");
$stmt->execute([$empId,$today]);$todayRecord=$stmt->fetch()?:null;

$stmt=$db->prepare("SELECT * FROM vehicles WHERE assigned_to=? LIMIT 1");$stmt->execute([$empId]);$myVehicle=$stmt->fetch()?:null;
$activeTrip=null;
if($myVehicle){
  $stmt=$db->prepare("SELECT * FROM trips WHERE employee_id=? AND status='active' LIMIT 1");$stmt->execute([$empId]);$activeTrip=$stmt->fetch()?:null;
}
$mStmt=$db->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND date LIKE ?");
$mStmt->execute([$empId,date('Y-m').'%']);$daysMonth=$mStmt->fetchColumn();

$earlyLeaveMin=0;
if($todayRecord&&$todayRecord['check_out']){
  $co=strtotime($todayRecord['check_out']);
  if($co<$workEndTs) $earlyLeaveMin=round(($workEndTs-$co)/60);
}
$firstName=explode(' ',$empData['name'])[0];

// إحصائيات مشاوير اليوم للسائق
$tripStmt=$db->prepare("
    SELECT 
        COUNT(*) as total_trips,
        COALESCE(SUM(distance_km),0) as total_km,
        COALESCE(SUM(km_inside),0) as total_km_inside,
        COALESCE(SUM(km_outside),0) as total_km_outside,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as done_trips,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_trips
    FROM trips 
    WHERE employee_id=? AND DATE(start_time)=?
");
$tripStmt->execute([$empId, $today]);
$todayTrips      = $tripStmt->fetch();
$totalKmToday    = round($todayTrips['total_km'] ?? 0, 2);
$totalTripsToday = (int)($todayTrips['total_trips'] ?? 0);
$doneTripsToday  = (int)($todayTrips['done_trips'] ?? 0);
$activeTripsCount= (int)($todayTrips['active_trips'] ?? 0);

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
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><?= getThemeHead('سائق') ?></head>
<body>
<?= getTopbar('سائق') ?>
<div class="page-wrap">

  <!-- بطاقة المعلومات -->
  <div class="card" style="text-align:center;padding:20px 16px">
    <div style="font-size:48px;font-weight:800;letter-spacing:2px;font-variant-numeric:tabular-nums;line-height:1" id="clock">--:--</div>
    <div style="font-size:12px;color:var(--text3);margin:5px 0 12px" id="dt"></div>
    <div style="font-size:17px;font-weight:700;margin-bottom:6px">أهلاً <?=htmlspecialchars($firstName)?> 👋</div>
    <span class="badge b-gold">الدوام <?=date('h:i a',$workStartTs)?> — <?=date('h:i a',$workEndTs)?></span>
  </div>

  <!-- حالة الحضور -->
  <?php if(!$todayRecord||!$todayRecord['check_in']): ?>
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
  <?php if($todayRecord&&$todayRecord['late_minutes']>0): ?>
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

  <!-- إحصائيات مشاوير اليوم -->
  <?php if($myVehicle): ?>
  <div class="card" id="trips-today-card">
    <div class="card-title">🚗 مشاوير اليوم</div>
    <?php if($totalTripsToday > 0): ?>
    <div class="grid3">
      <div class="sbox">
        <div class="slabel">عدد المشاوير</div>
        <div class="sval g" style="font-size:22px"><?=$totalTripsToday?></div>
      </div>
      <div class="sbox">
        <div class="slabel">المكتملة</div>
        <div class="sval g" style="font-size:22px"><?=$doneTripsToday?></div>
      </div>
      <div class="sbox">
        <div class="slabel">إجمالي الكم</div>
        <div class="sval b" style="font-size:18px" id="today-km-val"><?=$totalKmToday?> كم</div>
      </div>
    </div>
    <?php if($activeTripsCount > 0): ?>
    <div class="notice n-green" style="margin-top:8px">🟢 مشوار نشط الآن — يتحدث تلقائياً</div>
    <?php endif; ?>
    <?php else: ?>
    <div style="text-align:center;padding:14px;color:var(--text3);font-size:13px">لم تبدأ أي مشوار اليوم بعد</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- إحصائيات الشهر -->
  <div class="card">
    <div class="card-title">📅 هذا الشهر</div>
    <div class="grid3">
      <div class="sbox"><div class="slabel">أيام الحضور</div><div class="sval g"><?=$daysMonth?></div></div>
      <div class="sbox"><div class="slabel">مجموع التأخير</div><div class="sval r" id="late-total" style="display:none"></div><div class="sval g" id="late-ok">0</div></div>
      <div class="sbox"><div class="slabel">الدور</div><div class="sval gd">سائق</div></div>
    </div>
  </div>

  <!-- أزرار الحضور -->
  <div class="card">
    <div class="card-title">✅ تسجيل الحضور</div>
    <?php if(!$todayRecord||!$todayRecord['check_in']): ?>
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

  <!-- حالة التتبع -->
  <div class="card">
    <div class="status-row" id="trk-row">
      <span class="dot dgr" id="ldot"></span>
      <span id="ltxt">جارٍ الاتصال...</span>
    </div>
    <?php if($myVehicle): ?>
    <div style="background:var(--gold-bg);border:1px solid rgba(240,192,64,.25);border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px;margin-top:8px">
      <span style="font-size:24px">🚛</span>
      <div>
        <div style="font-size:14px;font-weight:700"><?=htmlspecialchars($myVehicle['name'])?></div>
        <div style="font-size:11px;color:var(--text3)"><?=htmlspecialchars($myVehicle['plate_number']??'')?><?=$myVehicle['model']?' — '.htmlspecialchars($myVehicle['model']):''?></div>
      </div>
      <div id="cpulse" style="width:10px;height:10px;border-radius:50%;background:var(--text3);margin-right:auto;flex-shrink:0"></div>
    </div>
    <?php else: ?>
    <div class="notice n-amber">⚠️ لم تُحدَّد سيارة لك بعد</div>
    <?php endif; ?>
  </div>

  <!-- زر بدء/انهاء مشوار — يظهر إذا عنده سيارة -->
  <?php if($myVehicle): ?>
  <div class="card" id="trip-card">
    <div class="card-title">🗺️ المشوار الخارجي</div>
    
    <?php if(!$activeTrip): ?>
      <?php $tripBtnColor = $tripInsideHours ? 'btn-blue' : 'btn-red'; ?>
        <button class="btn <?=$tripBtnColor?>" id="start-trip-btn" onclick="startTrip()">
          <?= $tripInsideHours ? '🔵 بدء مشوار (داخل الدوام)' : '🔴 بدء مشوار (خارج الدوام)' ?>
        </button>
    <?php else: ?>
      <div style="background:var(--surface2);border-radius:10px;padding:10px;margin-bottom:10px;font-size:12px;color:var(--text2)">
        <div>🟢 مشوار نشط منذ: <strong><?=date('h:i a',strtotime($activeTrip['start_time']))?></strong></div>
        <?php if($activeTrip['purpose']): ?><div style="margin-top:4px">📌 <?=htmlspecialchars($activeTrip['purpose'])?></div><?php endif; ?>
        <div style="margin-top:6px;display:flex;align-items:center;gap:6px">
          🛣️ المسافة: <strong id="trip-km" style="color:var(--blue);font-size:14px"><?=round($activeTrip['distance_km']??0,2)?> كم</strong>
          <span style="color:var(--text3);font-size:10px">(تحديث كل 10 ث)</span>
        </div>
      </div>
      <button class="btn btn-red" onclick="endTrip('<?=htmlspecialchars($activeTrip['trip_uid'])?>')">🏁 إنهاء المشوار</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- إحصائيات آخر إرسال -->
  <div class="card">
    <div class="card-title">📡 حالة التتبع</div>
    <div class="grid2">
      <div class="sbox"><div class="slabel">دخول اليوم</div><div class="sval g"><?=$todayRecord?date('h:i',strtotime($todayRecord['check_in'])):'--:--'?></div></div>
      <div class="sbox"><div class="slabel">آخر إرسال</div><div class="sval" id="last-min" style="font-size:13px">—</div></div>
    </div>
  </div>

  <div class="notice n-blue" id="offline-bar" style="display:none">📵 ما في إنترنت — المواقع محفوظة وسترسل تلقائياً</div>

  <!-- أزرار إضافية -->
  <div class="card">
    <button class="btn btn-amber" onclick="openNote()">📝 ملاحظة للمدير</button>
    <a href="inventory.php" class="btn btn-blue">📦 نظام المستودع</a>
  </div>

  <!-- روابط السائق -->
  <div class="card">
    <div class="card-title">🗂️ خدماتي</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <a href="leaves.php" class="btn btn-green" style="text-align:center;text-decoration:none">🌴 طلب إجازة</a>
      <a href="messages.php" class="btn btn-blue" style="text-align:center;text-decoration:none">💬 المحادثات <span id="drv-msg-badge" style="background:var(--red);color:#fff;border-radius:10px;padding:1px 5px;font-size:11px;display:none"></span></a>
      <a href="notifications.php" class="btn btn-amber" style="text-align:center;text-decoration:none">🔔 الإشعارات</a>
      <a href="employee_report.php" class="btn btn-ghost" style="text-align:center;text-decoration:none">📊 تقريري</a>
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

<!-- Modal ملاحظة -->
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

<!-- Modal بدء مشوار -->
<div class="modal-bg" id="trip-modal">
  <div class="modal-box">
    <h3>🚀 بدء مشوار خارجي</h3>
    <input type="text" id="trip-purpose" class="field" style="width:100%;padding:11px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text);outline:none;margin-bottom:8px" placeholder="وجهة / هدف المشوار (اختياري)">
    <div class="notice n-amber" id="trip-err" style="display:none"></div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeTripModal()">إلغاء</button>
      <button class="mbtn ms" onclick="confirmStartTrip()">بدء 🚀</button>
    </div>
  </div>
</div>

<script>
const TRIP_UID=<?=$activeTrip?"'".htmlspecialchars($activeTrip['trip_uid'])."'":'null'?>;
const VEH_ID=<?=$myVehicle?$myVehicle['id']:'null'?>;
const HAS_VEH=<?=$myVehicle?'true':'false'?>;
const TRIP_ALLOWED_NOW=true; // متاح دائماً
let curLat=null,curLng=null,lastSent=null,locDenied=false,activeTripUid=TRIP_UID;

function isInsideWorkHours(){
  const h=new Date().getHours();
  const ws=<?=$workStartH?>;
  const we=<?=$workEndH?>;
  return h>=ws&&h<we;
}

function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=n.toLocaleTimeString('ar-JO',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('dt').textContent=n.toLocaleDateString('ar-JO',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  // تحديث لون زر المشوار حسب الوقت (24 ساعة — داخل=أزرق، خارج=أحمر)
  const btn=document.getElementById('start-trip-btn');
  if(btn&&!activeTripUid){
    const inside=isInsideWorkHours();
    btn.className='btn '+(inside?'btn-blue':'btn-red');
    btn.textContent=inside?'🔵 بدء مشوار (داخل الدوام)':'🔴 بدء مشوار (خارج الدوام)';
  }
}
setInterval(tick,1000);tick();

window.addEventListener('online',()=>document.getElementById('offline-bar').style.display='none');
window.addEventListener('offline',()=>document.getElementById('offline-bar').style.display='flex');

if('serviceWorker' in navigator)
  navigator.serviceWorker.register('/tracking/sw.js').then(reg=>{
    navigator.serviceWorker.addEventListener('message',e=>{if(e.data.type==='GET_LOCATION')send(curLat,curLng);});
    // الـ SW للحضور العادي فقط (60 ثانية) — المشاوير تتكفل بها startIntensiveTracking
    if(!activeTripUid && reg.active) reg.active.postMessage({type:'START_TRACKING',interval:60000});
  });

// تحذير قبل إغلاق الصفحة أثناء مشوار نشط أو دوام شغال
window.addEventListener('beforeunload',e=>{
  if(activeTripUid || curLat){
    e.preventDefault();e.returnValue='';
  }
});

// منع زر "خروج" من قطع التتبع بدون تأكيد لو في مشوار نشط
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('a[href="api/logout.php"]').forEach(a=>{
    a.addEventListener('click',e=>{
      if(activeTripUid){
        e.preventDefault();
        if(confirm('⚠️ عندك مشوار نشط الآن!\nالخروج لن يوقف المشوار — سيستمر التتبع تلقائياً عند رجوعك لهذه الصفحة.\nهل تريد الخروج فعلاً؟')){
          window.location.href='api/logout.php';
        }
      }
    });
  });
});

function setStatus(c,t){
  const colors={g:'var(--green)',o:'var(--amber)',gr:'var(--text3)'};
  const dot=document.getElementById('ldot');
  dot.style.cssText='width:9px;height:9px;border-radius:50%;flex-shrink:0;display:inline-block;background:'+colors[c];
  if(c==='g')dot.style.boxShadow='0 0 6px var(--green)';
  document.getElementById('ltxt').textContent=t;
}

function notifyLocationOff(){
  if(locDenied)return;locDenied=true;
  fetch('api/location_alert.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({type:'location_off',employee_id:<?=$empId?>,vehicle_id:<?=$myVehicle?$myVehicle['id']:'null'?>})
  }).catch(()=>{});
}

let curSpeed=0, tripKm=0, tripInterval=null;

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

if(!navigator.geolocation){
  showGPSModal();
}else{
  navigator.geolocation.watchPosition(
    p=>{
      curLat=p.coords.latitude;curLng=p.coords.longitude;
      curSpeed=p.coords.speed?Math.round(p.coords.speed*3.6):0; // تحويل م/ث → كم/س
      locDenied=false;
      setStatus('g','✓ يتم تتبع موقعك'+(activeTripUid?' 🔴 مشوار نشط':''));
      if(HAS_VEH){const d=document.getElementById('cpulse');d.style.cssText='width:10px;height:10px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);flex-shrink:0';}
    },
    e=>{setStatus('o','⚠️ اسمح بالوصول للموقع');if(e.code===1||e.code===2){notifyLocationOff();showGPSModal();}},
    {enableHighAccuracy:true,maximumAge:3000,timeout:10000}
  );
}

// إرسال عادي كل 60 ثانية (للموظفين والسائقين داخل الدوام)
setInterval(()=>sendLocation(curLat,curLng),60000);
setTimeout(()=>sendLocation(curLat,curLng),3000);

// تتبع مكثف كل 10 ثواني فقط وقت المشوار الخارجي
function startIntensiveTracking(){
  if(tripInterval)return;
  tripInterval=setInterval(()=>{
    if(curLat&&activeTripUid) sendTripLocation(curLat,curLng,curSpeed);
  },10000);
  // أرسل فوراً
  if(curLat&&activeTripUid) sendTripLocation(curLat,curLng,curSpeed);
}
function stopIntensiveTracking(){
  if(tripInterval){clearInterval(tripInterval);tripInterval=null;}
}

// إذا في مشوار نشط عند تحميل الصفحة — ابدأ التتبع المكثف
if(activeTripUid) startIntensiveTracking();

function sendLocation(la,lo){
  if(!la)return;
  fetch('api/update_location.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({lat:la,lng:lo})}).catch(()=>{});
  lastSent=new Date();updMin();
}

function sendTripLocation(la,lo,spd){
  if(!la||!activeTripUid)return;
  fetch('api/update_trip_location.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({trip_uid:activeTripUid,lat:la,lng:lo,speed:spd})
  }).then(r=>r.json()).then(d=>{
    if(d.distance_km){
      tripKm=d.distance_km;
      const el=document.getElementById('trip-km');
      if(el)el.textContent=tripKm.toFixed(2)+' كم';
    }
  }).catch(()=>{});
  // أرسل أيضاً للموقع العام
  fetch('api/update_location.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({lat:la,lng:lo})}).catch(()=>{});
  lastSent=new Date();updMin();
}

function updMin(){if(!lastSent)return;const d=Math.floor((Date.now()-lastSent)/60000);document.getElementById('last-min').textContent=d===0?'الآن':d+' د';}
setInterval(updMin,10000);

/* ========= عداد الرسائل ========= */
async function checkMsgBadge(){
  try{
    const r=await fetch('api/notif_api.php?action=count');
    const d=await r.json();
    const b=document.getElementById('drv-msg-badge');
    if(b){b.textContent=d.msg_unread;b.style.display=d.msg_unread>0?'inline':'none';}
  }catch(e){}
}
checkMsgBadge();
setInterval(checkMsgBadge,30000);

/* ========= تسجيل حضور/انصراف ========= */
async function doCheckIn(){
  const btn=document.getElementById('ci-btn');btn.disabled=true;btn.textContent='...';
  if(!curLat){await waitForGPS();}
  const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_in',lat:curLat,lng:curLng})});
  const d=await r.json();
  if(d.error){alert('⚠️ '+d.error);btn.disabled=false;btn.textContent='✅ تسجيل الحضور';}
  else location.reload();
}
async function doCheckOut(){
  if(!confirm('تأكيد تسجيل الانصراف؟'))return;
  const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_out',lat:curLat,lng:curLng})});
  const d=await r.json();
  if(d.error)alert('⚠️ '+d.error);
  else location.reload();
}
function waitForGPS(){return new Promise(res=>{const t=setInterval(()=>{if(curLat){clearInterval(t);res();}},500);setTimeout(()=>{clearInterval(t);res();},5000);});}

/* ========= ملاحظات ========= */
function openNote(){document.getElementById('note-modal').style.display='flex';document.getElementById('note-ok').style.display='none';}
function closeNote(){document.getElementById('note-modal').style.display='none';}
async function sendNote(){
  const txt=document.getElementById('note-txt').value.trim();
  if(!txt)return;
  const r=await fetch('api/note.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({note:txt})});
  const d=await r.json();
  if(d.success){document.getElementById('note-ok').style.display='flex';document.getElementById('note-txt').value='';setTimeout(closeNote,1500);}
}

/* ========= المشاوير ========= */
function startTrip(){document.getElementById('trip-modal').style.display='flex';document.getElementById('trip-err').style.display='none';}
function closeTripModal(){document.getElementById('trip-modal').style.display='none';}
async function confirmStartTrip(){
  const purpose=document.getElementById('trip-purpose').value.trim();
  if(!curLat){alert('⚠️ انتظر حتى يتم تحديد موقعك');return;}
  const r=await fetch('api/trips.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'start',vehicle_id:VEH_ID,lat:curLat,lng:curLng,purpose})});
  const d=await r.json();
  if(d.error){const e=document.getElementById('trip-err');e.textContent='⚠️ '+d.error;e.style.display='flex';}
  else{activeTripUid=d.trip_uid;startIntensiveTracking();closeTripModal();location.reload();}
}
async function endTrip(uid){
  if(!confirm('تأكيد إنهاء المشوار؟'))return;
  if(!curLat){await waitForGPS();}
  const r=await fetch('api/trips.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'end',trip_uid:uid,lat:curLat,lng:curLng})});
  const d=await r.json();
  if(d.error)alert('⚠️ '+d.error);
  else{activeTripUid=null;stopIntensiveTracking();location.reload();}
}

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
  },15000);
}

async function doOvertimeCheckout(){
  if(!confirm('تأكيد تسجيل الانصراف من الوقت الإضافي؟'))return;
  const btn=document.getElementById('ot-checkout-btn');
  if(btn){btn.disabled=true;btn.textContent='⏳ جارٍ التسجيل...';}
  try{
    const r=await fetch('api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'overtime_checkout',lat:curLat||0,lng:curLng||0})});
    const d=await r.json();
    if(d.success){
      if(activeTripUid){activeTripUid=null;stopIntensiveTracking();}
      document.getElementById('ot-card').innerHTML='<div class="card-title">⏰ الوقت الإضافي</div><div class="notice n-green">✅ تم تسجيل انصرافك من الوقت الإضافي — إجمالي: <strong>'+(d.ot_minutes??0)+' دقيقة</strong></div>';
    } else {
      alert(d.error||'خطأ في التسجيل');
      if(btn){btn.disabled=false;btn.textContent='🚪 تسجيل انصراف الوقت الإضافي';}
    }
  }catch(e){alert('تحقق من الاتصال');if(btn){btn.disabled=false;btn.textContent='🚪 تسجيل انصراف الوقت الإضافي';}}
}

// تحديث إجمالي كم اليوم لو في مشوار نشط
<?php if($activeTripsCount > 0): ?>
setInterval(async()=>{
  try{
    const r=await fetch('api/trips.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'today_stats',employee_id:<?=$empId?>})});
    const d=await r.json();
    if(d.total_km!==undefined){
      const el=document.getElementById('today-km-val');
      if(el) el.textContent=parseFloat(d.total_km).toFixed(2)+' كم';
    }
  }catch(e){}
},15000);
<?php endif; ?>

<?php if($otRequest && $otRequest['status']==='pending'): ?>
startOtPolling();
<?php endif; ?>
</script>
</body>
</html>
