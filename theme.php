<?php
function getThemeCSS() {
ob_start();
?>
:root{
  --bg:#0f1117;--surface:#1a1d27;--surface2:#22263a;
  --border:#2e3452;--border2:#3d4468;
  --text:#e8eaf6;--text2:#9ba3c7;--text3:#5c6494;
  --green:#3ecf8e;--green-bg:rgba(62,207,142,.12);
  --red:#e25b5b;--red-bg:rgba(226,91,91,.12);
  --amber:#f5a623;--amber-bg:rgba(245,166,35,.12);
  --blue:#4f8ef7;--blue-bg:rgba(79,142,247,.12);
  --gold:#f0c040;--gold-bg:rgba(240,192,64,.12);
  --radius:14px;--radius-sm:8px;
  --safe-top:env(safe-area-inset-top,0px);
  --safe-bot:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;margin:0;padding:0}
html{height:100%}
body{font-family:"Noto Kufi Arabic",Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);direction:rtl;min-height:100dvh;font-size:15px}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:calc(var(--safe-top)+10px) 16px 10px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:10px}
.brand-icon{width:36px;height:36px;background:#1a56db;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff;flex-shrink:0}
.brand-name{font-size:15px;font-weight:700;color:var(--text)}
.brand-sub{font-size:10px;color:var(--text3);margin-top:1px}
.top-right{display:flex;align-items:center;gap:8px}
.logout-btn{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:var(--text2);border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:.2s}
.nav-toggle{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:var(--text2);border-radius:8px;padding:6px 10px;font-size:16px;cursor:pointer;line-height:1;display:none}
.nav-drawer{display:none;position:fixed;top:0;right:0;width:220px;height:100%;background:var(--surface);border-left:1px solid var(--border);z-index:9999;flex-direction:column;padding:16px 12px;gap:6px;box-shadow:-4px 0 20px rgba(0,0,0,.5)}
.nav-drawer.open{display:flex}
.nav-drawer a{color:var(--text2);font-size:14px;text-decoration:none;padding:10px 12px;border-radius:8px;border:1px solid var(--border);font-weight:600;transition:.15s}
.nav-drawer a:active{background:var(--surface2);color:var(--text)}
.nav-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.45)}
.nav-overlay.open{display:block}
@media(max-width:767px){.nav-toggle{display:block}}
.page-wrap{padding:14px 14px calc(var(--safe-bot)+14px);max-width:540px;margin:0 auto;width:100%}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:12px}
.card-title{font-size:13px;font-weight:700;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;gap:6px}
.btn{width:100%;padding:14px;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:.18s;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px;text-decoration:none;border:1px solid transparent}
.btn:active{transform:scale(.97)}
.btn:last-child{margin-bottom:0}
.btn-green{background:var(--green);color:#0a1f14;box-shadow:0 4px 14px rgba(62,207,142,.2)}
.btn-red{background:var(--red);color:#fff;box-shadow:0 4px 14px rgba(226,91,91,.2)}
.btn-amber{background:var(--amber);color:#1a0f00;box-shadow:0 4px 14px rgba(245,166,35,.2)}
.btn-blue{background:var(--blue);color:#fff;box-shadow:0 4px 14px rgba(79,142,247,.2)}
.btn-gold{background:var(--gold);color:#111;box-shadow:0 4px 14px rgba(240,192,64,.2)}
.btn-ghost{background:rgba(255,255,255,.06);border-color:var(--border);color:var(--text2)}
.btn-dis{background:var(--surface2);color:var(--text3);cursor:not-allowed;border-color:var(--border)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700}
.b-green{background:var(--green-bg);color:var(--green);border:1px solid rgba(62,207,142,.25)}
.b-red{background:var(--red-bg);color:var(--red);border:1px solid rgba(226,91,91,.25)}
.b-amber{background:var(--amber-bg);color:var(--amber);border:1px solid rgba(245,166,35,.25)}
.b-blue{background:var(--blue-bg);color:var(--blue);border:1px solid rgba(79,142,247,.25)}
.b-gold{background:var(--gold-bg);color:var(--gold);border:1px solid rgba(240,192,64,.25)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.sbox{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;text-align:center}
.slabel{font-size:11px;color:var(--text3);margin-bottom:4px}
.sval{font-size:20px;font-weight:800}
.sval.g{color:var(--green)}.sval.r{color:var(--red)}.sval.a{color:var(--amber)}.sval.gd{color:var(--gold)}
.notice{border-radius:10px;padding:11px 14px;font-size:13px;font-weight:600;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.n-red{background:var(--red-bg);color:var(--red);border:1px solid rgba(226,91,91,.25)}
.n-amber{background:var(--amber-bg);color:var(--amber);border:1px solid rgba(245,166,35,.25)}
.n-green{background:var(--green-bg);color:var(--green);border:1px solid rgba(62,207,142,.25)}
.n-blue{background:var(--blue-bg);color:var(--blue);border:1px solid rgba(79,142,247,.25)}
.dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;display:inline-block}
.dg{background:var(--green);box-shadow:0 0 6px var(--green);animation:pulse 1.5s infinite}
.dr{background:var(--red)}
.da{background:var(--amber);animation:pulse 1.2s infinite}
.dgr{background:var(--text3)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.status-row{display:flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px;margin:10px 0;font-size:14px;font-weight:700;background:var(--surface2);border:1px solid var(--border)}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;align-items:flex-end;justify-content:center}
.modal-bg.open{display:flex}
.modal-box{background:var(--surface);border-radius:20px 20px 0 0;border:1px solid var(--border2);padding:24px 18px calc(var(--safe-bot)+24px);width:100%;max-width:540px}
.modal-box h3{font-size:16px;font-weight:700;color:var(--gold);margin-bottom:14px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;font-weight:600}
.field input,.field textarea,.field select{width:100%;padding:11px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text);outline:none;transition:border .2s}
.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--blue)}
.field textarea{resize:none;height:90px}
.mbtns{display:flex;gap:10px;margin-top:12px}
.mbtn{flex:1;padding:13px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}
.mc{background:var(--surface2);color:var(--text2)}
.ms{background:var(--green);color:#0a1f14}
@media(min-width:768px){.page-wrap{padding:20px;max-width:700px}.card{padding:20px}}
<?php
return ob_get_clean();
}

function getThemeHead($title='مؤسسة فلسطين') {
    return '
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#0f1117">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icon-192.png">
<title>'.htmlspecialchars($title).' — مؤسسة فلسطين</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Kufi+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>'.getThemeCSS().'</style>
';}

function getTopbar($title='', $showLogout=true, $backUrl='admin.php') {
    $logout       = $showLogout ? '<a href="api/logout.php" class="logout-btn">خروج</a>' : '';
    $back         = $backUrl    ? '<a href="'.$backUrl.'" class="logout-btn">← رجوع</a>' : '';
    $hub          = '<a href="/dashboard/index.html" class="logout-btn" style="border-color:var(--gold);color:var(--gold)">🏛️ الأنظمة</a>';
    $badge        = $title      ? '<span class="badge b-gold">'.$title.'</span>' : '';
    $backDrawer   = $backUrl    ? '<a href="'.$backUrl.'">← رجوع للوحة التحكم</a>' : '';
    $hubDrawer    = '<a href="/dashboard/index.html">🏛️ لوحة الأنظمة</a>';
    $logoutDrawer = $showLogout ? '<a href="api/logout.php" style="color:var(--red);border-color:rgba(226,91,91,.3)">🚪 خروج</a>' : '';
    $fleetDrawer  = '<a href="fleet.php" style="color:var(--blue);border-color:rgba(79,142,247,.3)">🚗 إدارة الأسطول</a>';
    $html = <<<HTML
<div class="topbar">
  <div class="brand">
    <div class="brand-icon">ف</div>
    <div><div class="brand-name">مؤسسة فلسطين</div><div class="brand-sub">قطع غيار المنزل</div></div>
  </div>
  <div class="top-right">{$badge}{$back}{$hub}{$logout}
    <button class="nav-toggle" onclick="document.getElementById('ndrawer').classList.add('open');document.getElementById('noverlay').classList.add('open')">☰</button>
  </div>
</div>
<div class="nav-overlay" id="noverlay" onclick="this.classList.remove('open');document.getElementById('ndrawer').classList.remove('open')"></div>
<div class="nav-drawer" id="ndrawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <span style="font-weight:700;color:var(--text);font-size:14px">القائمة</span>
    <button onclick="document.getElementById('noverlay').classList.remove('open');document.getElementById('ndrawer').classList.remove('open')" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:0">✕</button>
  </div>
  {$backDrawer}
  {$hubDrawer}
  {$fleetDrawer}
  {$logoutDrawer}
</div>
HTML;
    return $html;
}
