<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('مشاوير مباشر') ?>
<style>
.trips-grid{display:grid;grid-template-columns:1fr;gap:14px}
@media(min-width:700px){.trips-grid{grid-template-columns:1fr 1fr}}
.trip-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.trip-card.outside{border-color:rgba(226,91,91,.6);box-shadow:0 0 0 2px rgba(226,91,91,.15)}
.trip-card.inside{border-color:rgba(79,142,247,.4)}
.tc-head{padding:12px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border)}
.tc-body{padding:12px 14px}
.tc-row{display:flex;justify-content:space-between;padding:5px 0;font-size:12px;border-bottom:1px solid var(--border)}
.tc-row:last-child{border-bottom:none}
.tc-row span:first-child{color:var(--text3)}
.tc-row span:last-child{font-weight:700;color:var(--text)}
/* بطاقات الكيلو الكبيرة */
.km-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:12px;text-align:center}
.km-box{border-radius:8px;padding:8px 4px}
.km-box.out{background:rgba(226,91,91,.12);border:1px solid rgba(226,91,91,.3)}
.km-box.in{background:rgba(79,142,247,.10);border:1px solid rgba(79,142,247,.25)}
.km-box.tot{background:var(--surface2);border:1px solid var(--border)}
.km-val{font-size:18px;font-weight:800}
.km-val.out{color:var(--red)}
.km-val.in{color:var(--blue)}
.km-val.tot{color:var(--text)}
.km-lbl{font-size:9px;color:var(--text3);margin-top:2px}
/* إحصائيات علوية */
.stat-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
@media(min-width:600px){.stat-cards{grid-template-columns:repeat(4,1fr)}}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center}
.sc-n{font-size:22px;font-weight:800}
.sc-sub{font-size:10px;color:var(--text3);margin-top:2px}
.sc-l{font-size:11px;color:var(--text3);margin-top:4px}
.pulse{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--red);box-shadow:0 0 8px var(--red);animation:pu 1s infinite}
@keyframes pu{0%,100%{opacity:1}50%{opacity:.3}}
.section-title{font-size:14px;font-weight:800;color:var(--text2);margin:16px 0 10px;display:flex;align-items:center;gap:8px}
.empty-state{text-align:center;padding:32px;color:var(--text3);font-size:13px}
.map-mini{width:100%;height:150px;border-radius:8px;overflow:hidden;margin-top:10px;background:var(--surface2)}
.speed-bar{height:5px;background:var(--surface2);border-radius:3px;margin-top:4px;overflow:hidden}
.speed-fill{height:100%;border-radius:3px;transition:width .5s}
.top-links{display:flex;gap:4px;flex-wrap:wrap}
.top-links a{color:var(--text2);font-size:12px;text-decoration:none;padding:5px 8px;border-radius:6px;border:1px solid var(--border);font-weight:600;white-space:nowrap}
.top-links a:hover{background:var(--surface2)}
@media(max-width:480px){
  .top-links{width:100%;order:3}
  .top-links a{flex:1;text-align:center}
}
.tbl-wrap{overflow-x:auto}
.tbl-wrap table{width:100%;border-collapse:collapse;font-size:12px;min-width:540px}
.tbl-wrap th{padding:8px;text-align:right;background:var(--surface2);border-bottom:1px solid var(--border);font-size:11px;color:var(--text3)}
.tbl-wrap td{padding:8px;border-bottom:1px solid var(--border)}
</style>
</head>
<body>
<?= getTopbar('مشاوير') ?>
<div class="page-wrap">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:8px">
      <span class="pulse"></span>
      <span style="font-weight:700;font-size:15px">مشاوير مباشرة</span>
      <span style="font-size:11px;color:var(--text3)" id="last-refresh">—</span>
    </div>
    <div class="top-links">
      <a href="admin.php">🗺️ الخريطة</a>
      <a href="reports.php">📊 التقارير</a>
    </div>
  </div>

  <!-- إحصائيات اليوم -->
  <div class="stat-cards">
    <div class="sc">
      <div class="sc-n" id="s-active" style="color:var(--green)">—</div>
      <div class="sc-l">مشاوير اليوم</div>
    </div>
    <div class="sc">
      <div class="sc-n" id="s-outside" style="color:var(--red)">—</div>
      <div class="sc-l">🚨 خارج الدوام</div>
    </div>
    <div class="sc">
      <div class="sc-n" id="s-km-out" style="color:var(--red)">—</div>
      <div class="sc-sub">كم فعلي خارج الدوام</div>
      <div class="sc-l">كيلومترات خارج</div>
    </div>
    <div class="sc">
      <div class="sc-n" id="s-km-in" style="color:var(--blue)">—</div>
      <div class="sc-sub">كم فعلي داخل الدوام</div>
      <div class="sc-l">كيلومترات داخل</div>
    </div>
  </div>

  <!-- مشاوير خارج الدوام -->
  <div class="section-title">🚨 خارج الدوام اليوم <span id="badge-out" class="badge b-red" style="display:none"></span></div>
  <div class="trips-grid" id="grid-outside">
    <div class="empty-state">لا توجد مشاوير خارج الدوام الآن ✅</div>
  </div>

  <!-- مشاوير داخل الدوام -->
  <div class="section-title">✅ داخل الدوام — نشطة <span id="badge-in" class="badge b-blue" style="display:none"></span></div>
  <div class="trips-grid" id="grid-inside">
    <div class="empty-state">لا توجد مشاوير نشطة داخل الدوام</div>
  </div>

  <!-- مكتملة اليوم -->
  <div class="section-title">📋 مكتملة اليوم <span id="badge-done" class="badge b-gold" style="display:none"></span></div>
  <div id="completed-list"></div>

</div>

<script>
const GMAPS_KEY='<?= GMAPS_API_KEY ?>';
let maps={}, mapsReady=false;

function loadMaps(){
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key='+GMAPS_KEY+'&language=ar&callback=onMapsReady';
  document.head.appendChild(s);
}
function onMapsReady(){ mapsReady=true; refresh(); }
loadMaps();

function fmtDur(min){
  if(!min||min<0)return '—';
  const h=Math.floor(min/60),m=min%60;
  return h>0?h+'س '+m+'د':m+' د';
}
function fmtKm(v){ return parseFloat(v||0).toFixed(2); }

function tripCard(t, outside){
  const borderClass = outside?'outside':'inside';
  const badge = outside
    ? '<span class="badge b-red">🚨 خارج الدوام</span>'
    : '<span class="badge b-blue">✅ داخل الدوام</span>';
  const lastUpd = t.last_update ? t.last_update.substr(11,8) : '—';
  const speed = parseFloat(t.speed||0).toFixed(0);
  const speedPct = Math.min(speed/120*100,100);
  const speedColor = speed>80?'var(--red)':speed>50?'var(--amber)':'var(--green)';
  const mapId = 'map-'+t.trip_uid.replace(/[^a-z0-9]/gi,'');

  return `<div class="trip-card ${borderClass}">
    <div class="tc-head">
      <div style="font-size:26px">🚗</div>
      <div style="flex:1">
        <div style="font-weight:800;font-size:14px">${t.emp_name}</div>
        <div style="font-size:11px;color:var(--text3)">${t.veh_name} — ${t.plate_number||'—'}</div>
      </div>
      ${badge}
    </div>
    <div class="tc-body">
      <!-- كيلومترات مفصلة -->
      <div class="km-grid">
        <div class="km-box out">
          <div class="km-val out" id="km-out-${t.trip_uid}">${fmtKm(t.km_outside)}</div>
          <div class="km-lbl">كم خارج دوام</div>
        </div>
        <div class="km-box in">
          <div class="km-val in" id="km-in-${t.trip_uid}">${fmtKm(t.km_inside)}</div>
          <div class="km-lbl">كم داخل دوام</div>
        </div>
        <div class="km-box tot">
          <div class="km-val tot" id="km-tot-${t.trip_uid}">${fmtKm(t.distance_km)}</div>
          <div class="km-lbl">إجمالي كم</div>
        </div>
      </div>
      <div class="tc-row"><span>بدأ</span><span>${t.start_time?t.start_time.substr(11,5):'—'}</span></div>
      <div class="tc-row"><span>المدة</span><span>${fmtDur(t.duration_min)}</span></div>
      <div class="tc-row"><span>الهدف</span><span>${t.purpose||'—'}</span></div>
      <div class="tc-row">
        <span>السرعة</span>
        <span id="spd-${t.trip_uid}" style="color:${speedColor}">${speed} كم/س</span>
      </div>
      <div class="speed-bar">
        <div class="speed-fill" id="spdf-${t.trip_uid}" style="width:${speedPct}%;background:${speedColor}"></div>
      </div>
      <div class="tc-row" style="margin-top:4px">
        <span>آخر تحديث</span>
        <span id="upd-${t.trip_uid}">${lastUpd}</span>
      </div>
      ${t.lat&&t.lng?`<div class="map-mini" id="${mapId}"></div>`:'<div style="text-align:center;padding:12px;font-size:11px;color:var(--text3)">⏳ بانتظار إشارة GPS</div>'}
    </div>
  </div>`;
}

function initMiniMap(t){
  if(!mapsReady||!t.lat||!t.lng)return;
  const mapId='map-'+t.trip_uid.replace(/[^a-z0-9]/gi,'');
  const el=document.getElementById(mapId);
  if(!el)return;
  const pos={lat:+t.lat,lng:+t.lng};
  const carSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 38 38"><rect width="38" height="38" rx="9" fill="${t.outside_hours?'#e25b5b':'#4f8ef7'}"/><text x="19" y="26" font-size="20" text-anchor="middle">🚗</text></svg>`;
  const icon={url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent(carSvg),scaledSize:new google.maps.Size(38,38),anchor:new google.maps.Point(19,38)};
  if(maps[t.trip_uid]){
    maps[t.trip_uid].marker.setPosition(pos);
    maps[t.trip_uid].m.panTo(pos);
    maps[t.trip_uid].marker.setIcon(icon);
  }else{
    const m=new google.maps.Map(el,{center:pos,zoom:15,disableDefaultUI:true,gestureHandling:'cooperative'});
    const marker=new google.maps.Marker({position:pos,map:m,title:t.emp_name,icon});
    maps[t.trip_uid]={m,marker};
  }
}

// تحديث القيم بدون إعادة بناء الـ DOM (لو البطاقة موجودة)
function updateCardValues(t){
  const kmOut=document.getElementById('km-out-'+t.trip_uid);
  const kmIn=document.getElementById('km-in-'+t.trip_uid);
  const kmTot=document.getElementById('km-tot-'+t.trip_uid);
  const spd=document.getElementById('spd-'+t.trip_uid);
  const spdF=document.getElementById('spdf-'+t.trip_uid);
  const upd=document.getElementById('upd-'+t.trip_uid);
  if(kmOut) kmOut.textContent=parseFloat(t.km_outside||0).toFixed(2);
  if(kmIn)  kmIn.textContent=parseFloat(t.km_inside||0).toFixed(2);
  if(kmTot) kmTot.textContent=parseFloat(t.distance_km||0).toFixed(2);
  if(spd){
    const speed=parseFloat(t.speed||0).toFixed(0);
    const speedColor=speed>80?'var(--red)':speed>50?'var(--amber)':'var(--green)';
    spd.textContent=speed+' كم/س';
    spd.style.color=speedColor;
    if(spdF){spdF.style.width=Math.min(speed/120*100,100)+'%';spdF.style.background=speedColor;}
  }
  if(upd&&t.last_update) upd.textContent=t.last_update.substr(11,8);
  initMiniMap(t);
}

let prevOutsideIds=new Set(), prevInsideIds=new Set();

async function refresh(){
  try{
    const r=await fetch('api/trip_live.php');
    const d=await r.json();

    // إحصائيات اليوم الكاملة (نشطة + مكتملة)
    document.getElementById('s-active').textContent=d.stats.today_total;
    document.getElementById('s-outside').textContent=d.stats.today_outside;
    document.getElementById('s-km-out').textContent=d.stats.km_outside_today+' كم';
    document.getElementById('s-km-in').textContent=d.stats.km_inside_today+' كم';
    document.getElementById('last-refresh').textContent='آخر تحديث: '+new Date().toLocaleTimeString('ar-JO');

    const outside=d.active.filter(t=>t.outside_hours==1);
    const inside=d.active.filter(t=>!t.outside_hours||t.outside_hours==0);

    document.getElementById('badge-out').textContent=outside.length;
    document.getElementById('badge-out').style.display=outside.length?'':'none';
    document.getElementById('badge-in').textContent=inside.length;
    document.getElementById('badge-in').style.display=inside.length?'':'none';
    document.getElementById('badge-done').textContent=d.completed.length;
    document.getElementById('badge-done').style.display=d.completed.length?'':'none';

    // خارج الدوام
    const go=document.getElementById('grid-outside');
    const newOutIds=new Set(outside.map(t=>t.trip_uid));
    if([...newOutIds].join()!==[...prevOutsideIds].join()){
      go.innerHTML=outside.length?outside.map(t=>tripCard(t,true)).join(''):'<div class="empty-state">لا توجد مشاوير خارج الدوام الآن ✅</div>';
      prevOutsideIds=newOutIds;
      setTimeout(()=>outside.forEach(t=>initMiniMap(t)),100);
    }else{
      outside.forEach(t=>updateCardValues(t));
    }

    // داخل الدوام
    const gi=document.getElementById('grid-inside');
    const newInIds=new Set(inside.map(t=>t.trip_uid));
    if([...newInIds].join()!==[...prevInsideIds].join()){
      gi.innerHTML=inside.length?inside.map(t=>tripCard(t,false)).join(''):'<div class="empty-state">لا توجد مشاوير نشطة داخل الدوام</div>';
      prevInsideIds=newInIds;
      setTimeout(()=>inside.forEach(t=>initMiniMap(t)),100);
    }else{
      inside.forEach(t=>updateCardValues(t));
    }

    // مكتملة اليوم
    const cl=document.getElementById('completed-list');
    if(d.completed.length){
      let html='<div class="tbl-wrap"><table>';
      html+=`<tr><th>السائق</th><th>البدء</th><th>الانتهاء</th><th>المدة</th>
        <th style="color:var(--blue)">كم داخل</th>
        <th style="color:var(--red)">كم خارج</th>
        <th>الإجمالي</th><th>النوع</th></tr>`;
      d.completed.forEach(t=>{
        const isOut=t.outside_hours;
        html+=`<tr style="${isOut?'background:rgba(226,91,91,.05)':''}">
          <td style="font-weight:700">${t.emp_name}</td>
          <td>${t.start_time?t.start_time.substr(11,5):'—'}</td>
          <td>${t.end_time?t.end_time.substr(11,5):'—'}</td>
          <td>${fmtDur(t.duration_min)}</td>
          <td style="font-weight:800;color:var(--blue)">${fmtKm(t.km_inside)} كم</td>
          <td style="font-weight:800;color:var(--red)">${fmtKm(t.km_outside)} كم</td>
          <td style="font-weight:800">${fmtKm(t.distance_km)} كم</td>
          <td><span class="badge ${isOut?'b-red':'b-blue'}">${isOut?'خارج':'داخل'}</span></td>
        </tr>`;
      });
      html+='</table></div>';
      cl.innerHTML=html;
    }else{
      cl.innerHTML='<div class="empty-state">لا توجد مشاوير مكتملة اليوم بعد</div>';
    }
  }catch(e){console.error(e);}
}

setInterval(refresh,10000);
</script>
</body>
</html>
