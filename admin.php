<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('لوحة التتبع') ?>
<style>
html,body{height:100%;overflow:hidden}
body{display:flex;flex-direction:column}
.topbar{flex-shrink:0}
.stats-bar{display:flex;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto}
.stat{flex:1;min-width:56px;padding:8px 6px;text-align:center;border-left:1px solid var(--border)}
.stat:last-child{border-left:none}
.sn{font-size:20px;font-weight:800}
.sl{font-size:10px;color:var(--text3);margin-top:1px;white-space:nowrap}
.main{display:flex;flex:1;overflow:hidden;position:relative}
#map{flex:1;min-height:200px}
.sidebar{width:0;background:var(--surface);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;flex-shrink:0;transition:width .25s}
.sidebar.open{width:290px}
.tab-bar{display:flex;border-bottom:1px solid var(--border);flex-shrink:0}
.tab{flex:1;padding:9px 4px;font-size:11px;font-weight:700;text-align:center;cursor:pointer;color:var(--text3);background:none;border:none;border-bottom:2px solid transparent;transition:.15s;font-family:inherit}
.tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.list{flex:1;overflow-y:auto}
.item{display:flex;align-items:center;gap:8px;padding:9px 10px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s}
.item:hover,.item.sel{background:var(--surface2)}
.ava{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;background:var(--surface2);color:var(--text2)}
.ii{flex:1;min-width:0}
.in{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.ia{font-size:10px;color:var(--blue);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.is{font-size:10px;color:var(--text3);margin-top:1px}
.detail-panel{padding:12px;background:var(--surface2);border-top:1px solid var(--border);font-size:12px;flex-shrink:0;max-height:50%;overflow-y:auto}
.dr{display:flex;justify-content:space-between;padding:3px 0;color:var(--text3)}
.dr span:last-child{font-weight:700;color:var(--text)}
.force-btn{width:100%;margin-top:8px;padding:9px;background:var(--red-bg);color:var(--red);border:1px solid rgba(226,91,91,.3);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.alert-panel{background:rgba(245,166,35,.08);border-bottom:1px solid rgba(245,166,35,.2);max-height:90px;overflow-y:auto;flex-shrink:0;display:none}
.alert-item{display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px solid rgba(245,166,35,.1);font-size:11px;color:var(--amber)}
.sb-toggle{position:fixed;left:12px;bottom:20px;z-index:50;width:46px;height:46px;border-radius:50%;background:var(--blue);color:#fff;border:none;font-size:18px;cursor:pointer;box-shadow:0 4px 16px rgba(79,142,247,.4);display:flex;align-items:center;justify-content:center}
.top-links{display:flex;gap:4px;flex-wrap:wrap}
.top-links a{color:var(--text2);font-size:12px;text-decoration:none;padding:5px 8px;border-radius:6px;border:1px solid var(--border);transition:.15s;font-weight:600;white-space:nowrap}
.top-links a:hover{background:var(--surface2);color:var(--text)}
.nav-toggle{display:none;background:none;border:1px solid var(--border);color:var(--text2);border-radius:8px;padding:6px 10px;font-size:16px;cursor:pointer;line-height:1}
.nav-drawer{display:none;position:fixed;top:0;right:0;width:220px;height:100%;background:var(--surface);border-left:1px solid var(--border);z-index:200;flex-direction:column;padding:16px 12px;gap:6px;box-shadow:-4px 0 20px rgba(0,0,0,.4)}
.nav-drawer.open{display:flex}
.nav-drawer a{color:var(--text2);font-size:14px;text-decoration:none;padding:10px 12px;border-radius:8px;border:1px solid var(--border);font-weight:600;transition:.15s}
.nav-drawer a:hover,.nav-drawer a:active{background:var(--surface2);color:var(--text)}
.nav-overlay{display:none;position:fixed;inset:0;z-index:199;background:rgba(0,0,0,.4)}
.nav-overlay.open{display:block}
/* Popup على الخريطة */
.map-popup{position:absolute;top:12px;left:12px;z-index:100;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.45);padding:14px 14px 10px;min-width:210px;max-width:270px;display:none}
.map-popup.show{display:block}
.mp-close{position:absolute;top:8px;left:8px;background:none;border:none;color:var(--text3);font-size:16px;cursor:pointer;padding:2px 6px;border-radius:6px}
.mp-close:hover{background:var(--surface2);color:var(--text)}
.mp-name{font-size:14px;font-weight:800;color:var(--text);padding-left:22px;margin-bottom:2px}
.mp-role{font-size:10px;color:var(--text3);margin-bottom:8px}
.mp-row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border);font-size:12px;color:var(--text3)}
.mp-row:last-of-type{border-bottom:none}
.mp-row span:last-child{font-weight:700;color:var(--text)}
.mp-ot{display:inline-block;background:var(--green-bg);color:var(--green);border:1px solid rgba(76,175,80,.3);border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;margin:6px 0 4px}
.mp-actions{display:flex;gap:6px;margin-top:8px}
.mp-btn{flex:1;padding:8px 4px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;border:1px solid var(--border);background:var(--surface2);color:var(--text2);transition:.15s}
.mp-btn:hover{background:var(--surface);color:var(--text)}
.mp-btn.blue{background:rgba(79,142,247,.15);color:#4f8ef7;border-color:rgba(79,142,247,.3)}
.mp-btn.blue.active{background:#4f8ef7;color:#fff}
.mp-btn.red{background:var(--red-bg);color:var(--red);border-color:rgba(226,91,91,.3)}
@media(max-width:767px){.top-links{display:none}.nav-toggle{display:block}}
@media(min-width:768px){.sidebar{width:300px}.sb-toggle{display:none}.tab{font-size:12px;padding:10px 6px}.in{font-size:13px}.is{font-size:11px}.sn{font-size:22px}.sl{font-size:11px}}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <div class="brand-icon">ف</div>
    <div><div class="brand-name">مؤسسة فلسطين</div><div class="brand-sub">لوحة التتبع</div></div>
  </div>
  <div class="top-right">
    <div class="top-links">
      <a href="reports.php">📊 تقارير</a>
      <a href="employees.php">👥 موظفون</a>
      <a href="vehicles.php">🚗 سيارات</a>
      <a href="fleet.php">🚗 إدارة الأسطول</a>
      <a href="trips_live.php">🗺️ مشاوير مباشر</a>
      <a href="messages.php" style="position:relative">💬 المحادثات <span id="s-notif-badge" style="position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;border-radius:10px;font-size:9px;padding:1px 4px;display:inline-block;min-width:14px;text-align:center"></span></a>
      <a href="notifications.php">🔔 التنبيهات</a>
      <a href="inventory.php">📦 مستودع</a>
    </div>
    <span class="badge b-green" id="live-dot">● مباشر</span>
    <span style="font-size:13px;color:var(--text2);font-variant-numeric:tabular-nums" id="clock"></span>
    <a href="/dashboard/index.html" class="logout-btn" style="border-color:var(--gold);color:var(--gold)">🏛️ الأنظمة</a>
    <a href="api/logout.php" class="logout-btn">خروج</a>
    <button class="nav-toggle" onclick="openNav()">☰</button>
  </div>
</div>

<div class="nav-overlay" id="nav-overlay" onclick="closeNav()"></div>
<div class="nav-drawer" id="nav-drawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <span style="font-weight:700;color:var(--text);font-size:14px">القائمة</span>
    <button onclick="closeNav()" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:0">✕</button>
  </div>
  <a href="trips_live.php">🗺️ مشاوير مباشر</a>
  <a href="reports.php">📊 تقارير</a>
  <a href="employees.php">👥 موظفون</a>
  <a href="vehicles.php">🚗 سيارات</a>
  <a href="fleet.php">🚗 إدارة الأسطول</a>
  <a href="messages.php">💬 المحادثات الداخلية</a>
  <a href="notifications.php">🔔 التنبيهات</a>
  <a href="inventory.php">📦 مستودع</a>
  <div style="margin-top:auto;padding-top:12px;border-top:1px solid var(--border)">
    <a href="api/logout.php" style="color:var(--red);border-color:rgba(226,91,91,.3)">🚪 خروج</a>
  </div>
</div>

<div class="stats-bar">
  <div class="stat"><div class="sn" id="s-p" style="color:var(--green)">—</div><div class="sl">موظف حاضر</div></div>
  <div class="stat"><div class="sn" id="s-d" style="color:var(--blue)">—</div><div class="sl">سائق حاضر</div></div>
  <div class="stat"><div class="sn" id="s-l" style="color:var(--amber)">—</div><div class="sl">متأخر</div></div>
  <div class="stat"><div class="sn" id="s-a" style="color:var(--red)">—</div><div class="sl">غائب</div></div>
  <div class="stat"><div class="sn" id="s-c" style="color:var(--blue)">—</div><div class="sl">سيارة</div></div>
  <div class="stat"><div class="sn" id="s-al" style="color:var(--amber)">—</div><div class="sl">تنبيه</div></div>
  <div class="stat" style="cursor:pointer" onclick="location.href='messages.php'"><div class="sn" id="s-msg" style="color:var(--blue)">—</div><div class="sl">رسالة جديدة</div></div>
  <div class="stat" style="cursor:pointer" onclick="location.href='fleet.php'"><div class="sn" style="color:var(--red);font-size:18px">🚛</div><div class="sl">إدارة الأسطول</div></div>
</div>

<div class="alert-panel" id="alert-panel"></div>

<div class="main">
  <div id="map"></div>

  <!-- Popup يظهر فوق الخريطة -->
  <div class="map-popup" id="map-popup">
    <button class="mp-close" onclick="closePopup()">✕</button>
    <div class="mp-name" id="mp-name"></div>
    <div class="mp-role" id="mp-role"></div>
    <div id="mp-rows"></div>
    <div id="mp-ot"></div>
    <div class="mp-actions" id="mp-actions"></div>
  </div>

  <div class="sidebar" id="sidebar">
    <div class="tab-bar">
      <button class="tab active" onclick="swTab('emp',this)">👥 موظفون</button>
      <button class="tab" onclick="swTab('drv',this)">🚗 سائقون</button>
      <button class="tab" onclick="swTab('car',this)">🚙 سيارات</button>
      <button class="tab" onclick="swTab('alert',this)">🔔 تنبيهات</button>
      <button class="tab" id="tab-ot" onclick="swTab('ot',this)">⏰ إضافي</button>
    </div>
    <div id="le" class="list"></div>
    <div id="ld" class="list" style="display:none"></div>
    <div id="lc" class="list" style="display:none"></div>
    <div id="la" class="list" style="display:none"></div>
    <div id="lot" class="list" style="display:none"></div>
    <div id="dp" class="detail-panel" style="display:none"></div>
  </div>
</div>

<button class="sb-toggle" id="sb-toggle" onclick="toggleSidebar()">☰</button>

<script>
const GMAPS_KEY='<?= GMAPS_API_KEY ?>';
let map, empM={}, drvM={}, carM={}, curTab='emp', sbOpen=false;
let selTrail=null, selTrailEmpId=null, trailInterval=null, trailShown=false;
let popupTarget=null;

/* ===== أيقونات ===== */
function empIcon(color, name){
  const short=name.split(' ')[0];
  const w=Math.max(70,short.length*10+24);
  const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="62" viewBox="0 0 ${w} 62">
    <rect x="1" y="1" width="${w-2}" height="42" rx="10" fill="${color}" stroke="#fff" stroke-width="1.5"/>
    <circle cx="${w/2}" cy="15" r="8" fill="#fff" fill-opacity="0.25"/>
    <text x="${w/2}" y="19" font-size="11" font-family="Arial,sans-serif" font-weight="700" fill="#fff" text-anchor="middle">${short[0]||''}</text>
    <text x="${w/2}" y="36" font-size="11" font-family="Arial,sans-serif" font-weight="700" fill="#fff" text-anchor="middle">${short}</text>
    <polygon points="${w/2},56 ${w/2-7},44 ${w/2+7},44" fill="${color}"/>
  </svg>`;
  return {url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent(svg),scaledSize:new google.maps.Size(w,62),anchor:new google.maps.Point(w/2,62)};
}
function carIcon(color, name, kmIn, kmOut){
  const short=name.split(' ')[0];
  const kmText='IN:'+kmIn+'km  OUT:'+kmOut+'km';
  const w=Math.max(100,Math.max(short.length,kmText.length)*8+24);
  const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="76" viewBox="0 0 ${w} 76">
    <rect x="1" y="1" width="${w-2}" height="56" rx="10" fill="${color}" stroke="#fff" stroke-width="1.5"/>
    <text x="${w/2}" y="26" font-size="20" text-anchor="middle" dominant-baseline="middle">🚗</text>
    <text x="${w/2}" y="41" font-size="11" font-family="Arial,sans-serif" font-weight="700" fill="#fff" text-anchor="middle">${short}</text>
    <text x="${w/2}" y="53" font-size="9" font-family="Arial,sans-serif" fill="rgba(255,255,255,0.85)" text-anchor="middle" direction="ltr">${kmText}</text>
    <polygon points="${w/2},70 ${w/2-7},58 ${w/2+7},58" fill="${color}"/>
  </svg>`;
  return {url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent(svg),scaledSize:new google.maps.Size(w,76),anchor:new google.maps.Point(w/2,76)};
}

/* ===== خريطة ===== */
function initMap(){
  map=new google.maps.Map(document.getElementById('map'),{
    center:{lat:31.95,lng:35.93},zoom:10,
    mapTypeControl:false,streetViewControl:false,fullscreenControl:false,
    styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]
  });
  map.addListener('click',()=>closePopup());
}
const s=document.createElement('script');
s.src='https://maps.googleapis.com/maps/api/js?key='+GMAPS_KEY+'&language=ar&callback=initMap';
document.head.appendChild(s);

setInterval(()=>document.getElementById('clock').textContent=new Date().toLocaleTimeString('ar-JO'),1000);
if(window.innerWidth>=768){document.getElementById('sidebar').classList.add('open');sbOpen=true;}
function toggleSidebar(){sbOpen=!sbOpen;document.getElementById('sidebar').classList.toggle('open',sbOpen);document.getElementById('sb-toggle').textContent=sbOpen?'✕':'☰';}
function swTab(t,el){
  clearTrail();
  document.getElementById('dp').style.display='none';
  curTab=t;
  document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
  el.classList.add('active');
  ['le','ld','lc','la','lot'].forEach((id,i)=>document.getElementById(id).style.display=(['emp','drv','car','alert','ot'][i]===t?'block':'none'));
}

function stBadge(st){return{on_time:'b-green',late:'b-amber',absent:'b-red',out:'b-gold'}[st]||'b-gold';}
function stLabel(st){return{on_time:'حاضر',late:'متأخر',absent:'غائب',out:'خرج'}[st]||'—';}

/* ===== Trail المسار ===== */
function clearTrail(){
  if(selTrail){selTrail.setMap(null);selTrail=null;}
  if(trailInterval){clearInterval(trailInterval);trailInterval=null;}
  selTrailEmpId=null; trailShown=false;
}

// تثبيت النقاط على أقرب شارع فعلي عبر Roads API
async function snapToRoads(pts){
  if(pts.length<2) return pts;
  const chunks=[];
  for(let i=0;i<pts.length;i+=100) chunks.push(pts.slice(i,i+100));
  let snapped=[];
  for(const chunk of chunks){
    const path=chunk.map(p=>p.lat+','+p.lng).join('|');
    try{
      const r=await fetch('https://roads.googleapis.com/v1/snapToRoads?path='+path+'&interpolate=true&key='+GMAPS_KEY);
      const d=await r.json();
      console.log('[Roads API]', JSON.stringify(d).substring(0,400));
      if(d.snappedPoints&&d.snappedPoints.length){
        snapped=snapped.concat(d.snappedPoints.map(sp=>({lat:sp.location.latitude,lng:sp.location.longitude})));
      } else if(d.error){
        console.error('[Roads API Error]', d.error.status, d.error.message);
      }
    }catch(e){
      console.error('[Roads API fetch error]', e);
    }
  }
  return snapped.length>=2?snapped:pts;
}

async function loadTrail(empId){
  clearTrail(); selTrailEmpId=empId; trailShown=true;
  const btn=document.getElementById('mp-trail-btn');
  if(btn){btn.textContent='⏳ جاري...';btn.classList.add('active');}

  // فلترة النقاط المكررة والمتقاربة جداً (أقل من 5 أمتار)
  function dedupePoints(pts){
    if(!pts.length) return pts;
    const out=[pts[0]];
    for(let i=1;i<pts.length;i++){
      const prev=out[out.length-1];
      const dLat=pts[i].lat-prev.lat, dLng=pts[i].lng-prev.lng;
      const distM=Math.sqrt(dLat*dLat+dLng*dLng)*111000;
      if(distM>5) out.push(pts[i]);
    }
    return out;
  }

  // رسم المسار مع أسهم كبيرة واضحة
  function drawTrail(pts, color){
    const arrowIcon={
      path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
      scale: 5,
      strokeColor: '#fff',
      strokeWeight: 2,
      fillColor: color,
      fillOpacity: 1,
    };
    if(selTrail){ selTrail.setMap(null); }
    selTrail=new google.maps.Polyline({
      path: pts,
      geodesic: true,
      strokeColor: color,
      strokeOpacity: 0.95,
      strokeWeight: 5,
      map,
      icons:[
        {icon: arrowIcon, offset:'100%', repeat:'120px'},
      ]
    });
  }

  try{
    const r=await fetch('api/live_data.php?trail='+empId);
    const d=await r.json();
    if(!d.trail||d.trail.length<2){if(btn){btn.textContent='📍 لا توجد بيانات';}return;}
    const rawPts=dedupePoints(d.trail.map(p=>({lat:+p.lat,lng:+p.lng})));
    const pts=await snapToRoads(rawPts);
    const color=popupTarget&&popupTarget.type==='drv'?'#4f8ef7':'#4caf50';
    drawTrail(pts, color);
    // حرك الخريطة على المسار
    const bounds=new google.maps.LatLngBounds();
    pts.forEach(p=>bounds.extend(p));
    map.fitBounds(bounds,{padding:60});
    if(btn){btn.textContent='🚫 إخفاء المسار';}
    // تحديث تلقائي كل 15 ثانية - مع snapToRoads
    trailInterval=setInterval(async()=>{
      if(!selTrailEmpId)return;
      const r2=await fetch('api/live_data.php?trail='+selTrailEmpId);
      const d2=await r2.json();
      if(d2.trail&&d2.trail.length>=2){
        const rawPts2=dedupePoints(d2.trail.map(p=>({lat:+p.lat,lng:+p.lng})));
        const snapped2=await snapToRoads(rawPts2);
        if(selTrail) drawTrail(snapped2, color);
      }
    },15000);
  }catch(e){if(btn){btn.textContent='❌ خطأ';}}
}

function toggleTrail(){
  if(trailShown){clearTrail();const b=document.getElementById('mp-trail-btn');if(b){b.textContent='📍 عرض المسار';b.classList.remove('active');}}
  else if(popupTarget) loadTrail(popupTarget.id);
}

/* ===== Popup ===== */
function showPopup(d, type){
  popupTarget={id:d.id, type, data:d};

  // حساب وقت إضافي
  let otText='';
  if(d.check_out&&d.work_end){
    const outTs=new Date(d.check_out).getTime();
    const endTs=new Date(d.check_out.substr(0,10)+'T'+d.work_end).getTime();
    const otMin=Math.max(0,Math.round((outTs-endTs)/60000));
    if(otMin>0){const h=Math.floor(otMin/60),m=otMin%60;otText=(h?h+'س ':'')+m+'د';}
  }

  document.getElementById('mp-name').textContent=d.name;
  document.getElementById('mp-role').textContent=type==='drv'?'🚗 سائق':'👤 موظف';

  let rows='';
  rows+=`<div class="mp-row"><span>الدخول</span><span style="color:var(--green)">${d.check_in?d.check_in.substr(11,5):'—'}</span></div>`;
  rows+=`<div class="mp-row"><span>الخروج</span><span>${d.check_out?d.check_out.substr(11,5):'<span style="color:var(--amber)">لم يخرج</span>'}</span></div>`;
  rows+=`<div class="mp-row"><span>التأخير</span><span style="color:${d.late_minutes>0?'var(--red)':'var(--green)'}">${d.late_minutes>0?d.late_minutes+' دق':'في الوقت ✓'}</span></div>`;
  rows+=`<div class="mp-row"><span>آخر ظهور</span><span>${d.last_seen?d.last_seen.substr(11,5):'—'}</span></div>`;
  if(type==='drv'){
    rows+=`<div class="mp-row"><span>كم داخل</span><span style="color:var(--blue)">${parseFloat(d.km_inside||0).toFixed(1)} كم</span></div>`;
    rows+=`<div class="mp-row"><span>كم خارج ⚠</span><span style="color:var(--red)">${parseFloat(d.km_outside||0).toFixed(1)} كم</span></div>`;
    if(d.trip_uid) rows+=`<div class="mp-row"><span>مشوار نشط</span><span style="color:var(--green)">🟢 منذ ${d.trip_start?d.trip_start.substr(11,5):'—'}</span></div>`;
  }
  if(d.check_in_address) rows+=`<div class="mp-row" style="flex-direction:column;gap:2px"><span>مكان الدخول</span><span style="font-size:11px;color:var(--blue)">${d.check_in_address}</span></div>`;
  document.getElementById('mp-rows').innerHTML=rows;
  document.getElementById('mp-ot').innerHTML=otText?`<div class="mp-ot">⭐ وقت إضافي: +${otText}</div>`:'';

  // أزرار الأكشن
  const trailActive=trailShown&&selTrailEmpId==d.id;
  let actions=`<button id="mp-trail-btn" class="mp-btn blue${trailActive?' active':''}" onclick="toggleTrail()">${trailActive?'🚫 إخفاء المسار':'📍 عرض المسار'}</button>`;
  if(d.check_in&&!d.check_out) actions+=`<button class="mp-btn red" onclick="forceOutPopup()">🚪 إنهاء الدوام</button>`;
  document.getElementById('mp-actions').innerHTML=actions;

  document.getElementById('map-popup').classList.add('show');
}

function closePopup(){
  document.getElementById('map-popup').classList.remove('show');
  clearTrail();
  popupTarget=null;
}

/* ===== Refresh ===== */
async function refresh(){
  try{
    const r=await fetch('api/live_data.php'); const d=await r.json();
    document.getElementById('s-p').textContent=d.stats.present;
    document.getElementById('s-d').textContent=d.stats.drivers_present;
    document.getElementById('s-l').textContent=d.stats.late;
    document.getElementById('s-a').textContent=d.stats.absent;
    document.getElementById('s-c').textContent=d.stats.active_vehicles;
    document.getElementById('s-al').textContent=d.stats.alerts;

    // جلب عداد الرسائل غير المقروءة
    try {
      const nr=await fetch('api/notif_api.php?action=count');
      const nd=await nr.json();
      const msgEl=document.getElementById('s-msg');
      if(msgEl) msgEl.textContent=nd.msg_unread||0;
      const ntfEl=document.getElementById('s-notif-badge');
      if(ntfEl) ntfEl.textContent=nd.notif_unread>0?nd.notif_unread:'';
    } catch(e){}

    const ap=document.getElementById('alert-panel');
    ap.style.display=d.alerts.length?'block':'none';
    if(d.alerts.length)ap.innerHTML=d.alerts.map(a=>`<div class="alert-item">⚠ ${a.message}<small style="color:var(--text3);margin-right:auto">${a.created_at.substr(11,5)}</small><span style="cursor:pointer;color:var(--text3);margin-right:8px" onclick="dismissAlert(${a.id})">✕</span></div>`).join('');

    // تحديث popup إذا مفتوح
    if(popupTarget){
      const fresh=[...d.employees,...d.drivers].find(x=>x.id==popupTarget.id);
      if(fresh) showPopup(fresh,popupTarget.type);
    }

    /* موظفون */
    const le=document.getElementById('le'); le.innerHTML='';
    d.employees.forEach(emp=>{
      const st=!emp.check_in?'absent':emp.check_out?'out':emp.att_status==='late'?'late':'on_time';
      const div=document.createElement('div'); div.className='item';
      div.innerHTML=`<div class="ava">${emp.name.split(' ').map(w=>w[0]).slice(0,2).join('')}</div>
        <div class="ii"><div class="in">${emp.name}</div><div class="is">${emp.check_in?'⏰ '+emp.check_in.substr(11,5):'لم يسجل'}</div>${emp.check_in_address?'<div class="ia">📍 '+emp.check_in_address+'</div>':''}</div>
        <span class="badge ${stBadge(st)}">${stLabel(st)}</span>`;
      div.onclick=()=>{
        document.querySelectorAll('.item').forEach(i=>i.classList.remove('sel')); div.classList.add('sel');
        showPopup(emp,'emp');
        if(emp.lat&&emp.lng&&map) map.panTo({lat:+emp.lat,lng:+emp.lng});
      };
      le.appendChild(div);
      if(emp.lat&&emp.lng&&map){
        const pos={lat:+emp.lat,lng:+emp.lng};
        const color=st==='absent'?'#e25b5b':st==='late'?'#f5a623':st==='out'?'#888':'#4caf50';
        const icon=empIcon(color,emp.name);
        if(empM[emp.id]){empM[emp.id].setPosition(pos);empM[emp.id].setIcon(icon);}
        else{
          empM[emp.id]=new google.maps.Marker({position:pos,map,title:emp.name,icon,zIndex:10});
          empM[emp.id].addListener('click',()=>{
            document.querySelectorAll('.item').forEach(i=>i.classList.remove('sel'));
            showPopup(emp,'emp'); map.panTo(pos);
          });
        }
      }
    });

    /* سائقون */
    const ld=document.getElementById('ld'); ld.innerHTML='';
    d.drivers.forEach(drv=>{
      const st=!drv.check_in?'absent':drv.check_out?'out':drv.att_status==='late'?'late':'on_time';
      const div=document.createElement('div'); div.className='item';
      const tripBadge=drv.trip_uid?'<span class="badge b-blue" style="font-size:9px">مشوار</span>':'';
      div.innerHTML=`<div class="ava" style="font-size:18px">🚗</div>
        <div class="ii"><div class="in">${drv.name}</div><div class="is">${drv.check_in?'⏰ '+drv.check_in.substr(11,5):'لم يسجل'}${drv.vehicle_name?' · '+drv.vehicle_name:''}</div>${drv.check_in_address?'<div class="ia">📍 '+drv.check_in_address+'</div>':''}</div>
        ${tripBadge}<span class="badge ${stBadge(st)}">${stLabel(st)}</span>`;
      div.onclick=()=>{
        document.querySelectorAll('.item').forEach(i=>i.classList.remove('sel')); div.classList.add('sel');
        showPopup(drv,'drv');
        if(drv.lat&&drv.lng&&map) map.panTo({lat:+drv.lat,lng:+drv.lng});
      };
      ld.appendChild(div);
      if(drv.lat&&drv.lng&&map){
        const pos={lat:+drv.lat,lng:+drv.lng};
        const drvColor=drv.trip_uid?'#4f8ef7':st==='absent'?'#e25b5b':st==='out'?'#888':'#f5a623';
        const kmIn=parseFloat(drv.km_inside||0).toFixed(1);
        const kmOut=parseFloat(drv.km_outside||0).toFixed(1);
        const icon=carIcon(drvColor,drv.name,kmIn,kmOut);
        if(drvM[drv.id]){drvM[drv.id].setPosition(pos);drvM[drv.id].setIcon(icon);}
        else{
          drvM[drv.id]=new google.maps.Marker({position:pos,map,title:drv.name,icon,zIndex:10});
          drvM[drv.id].addListener('click',()=>{
            document.querySelectorAll('.item').forEach(i=>i.classList.remove('sel'));
            showPopup(drv,'drv'); map.panTo(pos);
          });
        }
      }
    });

    /* سيارات */
    const lc=document.getElementById('lc'); lc.innerHTML='';
    d.vehicles.forEach(car=>{
      const div=document.createElement('div'); div.className='item';
      div.innerHTML=`<div class="ava" style="font-size:18px">🚗</div>
        <div class="ii"><div class="in">${car.name}</div><div class="is">${car.driver_name?'👤 '+car.driver_name:'لا سائق'}</div></div>
        <span class="badge ${car.status==='active'?'b-blue':car.status==='alert'?'b-red':'b-gold'}">${car.status==='active'?'نشطة':car.status==='alert'?'تنبيه':'متوقفة'}</span>`;
      div.onclick=()=>{document.querySelectorAll('.item').forEach(i=>i.classList.remove('sel'));div.classList.add('sel');if(car.lat&&car.lng&&map)map.panTo({lat:+car.lat,lng:+car.lng});};
      lc.appendChild(div);
    });

    /* تنبيهات */
    const la=document.getElementById('la');
    la.innerHTML=d.alerts.length?d.alerts.map(a=>`<div class="item"><div style="font-size:18px">⚠️</div><div class="ii"><div class="in" style="color:var(--amber);font-size:11px">${a.message}</div><div class="is">${a.created_at.substr(0,16)}</div></div></div>`).join('')
      :'<div style="padding:24px;text-align:center;color:var(--text3)">لا توجد تنبيهات 👍</div>';

    /* طلبات الوقت الإضافي */
    const lot=document.getElementById('lot');
    const tabOt=document.getElementById('tab-ot');
    const otReqs=d.overtime_requests||[];
    if(otReqs.length){
      tabOt.innerHTML=`⏰ إضافي <span style="background:var(--amber);color:#000;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700;margin-right:4px">${otReqs.length}</span>`;
    } else {
      tabOt.innerHTML='⏰ إضافي';
    }
    lot.innerHTML=otReqs.length?otReqs.map(r=>{
      const role=r.role==='driver'?'سائق':'موظف';
      const t=r.requested_at.substr(11,5);
      return `<div class="item" style="flex-direction:column;align-items:stretch;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="font-size:22px">⏰</div>
          <div class="ii">
            <div class="in">${r.name}</div>
            <div class="is">${role} · طلب الساعة ${t}</div>
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button onclick="respondOvertime(${r.id},'approved')" style="flex:1;padding:8px;background:var(--green);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">✅ موافقة</button>
          <button onclick="respondOvertime(${r.id},'rejected')" style="flex:1;padding:8px;background:var(--red);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">❌ رفض</button>
        </div>
      </div>`;
    }).join('')
    :'<div style="padding:24px;text-align:center;color:var(--text3)">لا توجد طلبات وقت إضافي 👍</div>';

  }catch(e){console.error(e);}
}

async function dismissAlert(id){fetch('api/dismiss_alert.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});refresh();}

async function respondOvertime(reqId, response){
  const label=response==='approved'?'الموافقة على':'رفض';
  if(!confirm(`تأكيد ${label} طلب الوقت الإضافي؟`))return;
  try{
    const r=await fetch('api/overtime_request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'respond',request_id:reqId,response})});
    const d=await r.json();
    if(d.success){refresh();}
    else alert('خطأ: '+(d.error||''));
  }catch(e){alert('تحقق من الاتصال');}
}

async function forceOutPopup(){
  if(!popupTarget)return;
  const name=popupTarget.data.name;
  if(!confirm(`إنهاء دوام ${name}؟`))return;
  const r=await fetch('api/force_checkout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({employee_id:popupTarget.id})});
  const d=await r.json();
  if(d.success){let msg=`✅ تم إنهاء دوام ${name}`;if(d.trip_ended)msg+='\n🗺️ تم إنهاء المشوار أيضاً';alert(msg);closePopup();refresh();}
  else alert('خطأ: '+(d.error||''));
}

function openNav(){document.getElementById('nav-drawer').classList.add('open');document.getElementById('nav-overlay').classList.add('open');}
function closeNav(){document.getElementById('nav-drawer').classList.remove('open');document.getElementById('nav-overlay').classList.remove('open');}
refresh(); setInterval(refresh,15000);
</script>
<script>
if('serviceWorker' in navigator) navigator.serviceWorker.register('/tracking/sw.js').catch(()=>{});
</script>
</body>
</html>
