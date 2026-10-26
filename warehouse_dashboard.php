<?php
// ===================================================
// warehouse_dashboard.php — الداشبورد المالي للمستودع
// ===================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();
if (!isAdmin()) { header('Location: employee.php'); exit; }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الداشبورد المالي') ?>
<style>
.top-link{display:none}
@media(min-width:600px){.top-link{display:inline-flex}}
.kpi-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
@media(min-width:600px){.top-link{display:none}
@media(min-width:600px){.top-link{display:inline-flex}}
.kpi-grid{grid-template-columns:repeat(4,1fr)}}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px;text-align:center}
.kpi-label{font-size:11px;color:var(--text3);margin-bottom:5px}
.kpi-val{font-size:22px;font-weight:800}
.kpi-sub{font-size:11px;color:var(--text3);margin-top:3px}
.kpi-delta{font-size:11px;font-weight:700;margin-top:3px}
.delta-up{color:var(--green)}.delta-dn{color:var(--red)}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:9px 10px;font-size:11px;font-weight:700;color:var(--text3);text-align:right;border-bottom:1px solid var(--border);background:var(--surface2)}
.tbl td{padding:10px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:rgba(255,255,255,.025)}
.bar{height:6px;border-radius:3px;background:var(--border);overflow:hidden;margin-top:4px}
.bar-fill{height:100%;border-radius:3px;background:var(--green);transition:width .5s}
.tab-row{display:flex;gap:6px;margin-bottom:12px;overflow-x:auto;padding-bottom:4px}
.tab-btn{padding:7px 14px;border-radius:20px;border:1px solid var(--border);background:var(--surface2);color:var(--text2);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit;transition:.2s}
.tab-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.chart-canvas{width:100%;height:160px;background:var(--surface2);border-radius:10px;overflow:hidden}
.alert-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;margin-bottom:7px;font-size:13px}
.alert-out{background:var(--red-bg);border:1px solid rgba(226,91,91,.3);color:var(--red)}
.alert-low{background:var(--amber-bg);border:1px solid rgba(245,166,35,.3);color:var(--amber)}
.alert-name{font-weight:700;flex:1}
.alert-qty{font-size:12px}
.margin-bar{height:8px;border-radius:4px;background:var(--border);overflow:hidden;display:inline-block;width:60px;vertical-align:middle;margin-right:4px}
.margin-fill{height:100%;border-radius:4px;transition:width .4s}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <div class="brand-icon">📊</div>
    <div><div class="brand-name">الداشبورد المالي</div><div class="brand-sub">مستودع المؤسسة</div></div>
  </div>
  <div class="top-right">
    <a href="inventory.php"  class="logout-btn top-link">📦 المستودع</a>
    <a href="suppliers.php"  class="logout-btn top-link">🏭 الموردون</a>
    <a href="api/logout.php" class="logout-btn top-link">خروج</a>
    <button class="nav-toggle" onclick="document.getElementById('wdrawer').classList.add('open');document.getElementById('woverlay').classList.add('open')">☰</button>
  </div>
</div>
<div class="nav-overlay" id="woverlay" onclick="this.classList.remove('open');document.getElementById('wdrawer').classList.remove('open')"></div>
<div class="nav-drawer" id="wdrawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <span style="font-weight:700;color:var(--text);font-size:14px">القائمة</span>
    <button onclick="document.getElementById('woverlay').classList.remove('open');document.getElementById('wdrawer').classList.remove('open')" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:0">✕</button>
  </div>
  <a href="inventory.php">📦 المستودع</a>
  <a href="suppliers.php">🏭 الموردون</a>
  <a href="admin.php">← رجوع للوحة التحكم</a>
  <a href="api/logout.php" style="color:var(--red);border-color:rgba(226,91,91,.3)">🚪 خروج</a>
</div>

<div class="page-wrap" style="max-width:900px">

  <!-- KPI Cards -->
  <div class="kpi-grid" id="kpi-row">
    <div class="kpi"><div class="kpi-label">إيرادات الشهر</div><div class="kpi-val gd" id="kpi-rev">—</div><div class="kpi-delta" id="kpi-rev-delta"></div></div>
    <div class="kpi"><div class="kpi-label">ربح الشهر</div><div class="kpi-val g" id="kpi-profit">—</div><div class="kpi-delta" id="kpi-profit-delta"></div></div>
    <div class="kpi"><div class="kpi-label">قيمة المخزون</div><div class="kpi-val a" id="kpi-inv">—</div><div class="kpi-sub">بسعر الشراء</div></div>
    <div class="kpi"><div class="kpi-label">هامش الربح</div><div class="kpi-val" id="kpi-margin" style="color:var(--blue)">—</div><div class="kpi-sub">متوسط الشهر</div></div>
  </div>

  <!-- تنبيهات نفاد المخزون -->
  <div class="card" id="alerts-card" style="display:none">
    <div class="card-title">🔴 تنبيهات المخزون</div>
    <div id="alerts-list"></div>
    <a href="inventory.php?stock=low" style="font-size:12px;color:var(--blue);text-decoration:none">عرض كل القطع المنخفضة ←</a>
  </div>

  <!-- تبويبات -->
  <div class="tab-row">
    <button class="tab-btn active" onclick="switchTab('chart')">📈 المبيعات اليومية</button>
    <button class="tab-btn" onclick="switchTab('top')">🏆 أعلى القطع مبيعاً</button>
    <button class="tab-btn" onclick="switchTab('margins')">💰 هوامش الربح</button>
    <button class="tab-btn" onclick="switchTab('suppliers')">🏭 حسب المورد</button>
    <button class="tab-btn" onclick="switchTab('log')">📋 سجل المبيعات</button>
  </div>

  <!-- مبيعات يومية -->
  <div id="tab-chart" class="card" style="padding:14px">
    <div class="card-title">المبيعات اليومية — آخر 30 يوم</div>
    <canvas id="daily-chart" style="width:100%;height:180px"></canvas>
    <div id="chart-empty" style="text-align:center;color:var(--text3);padding:30px;display:none">لا توجد مبيعات بعد</div>
  </div>

  <!-- أعلى القطع -->
  <div id="tab-top" class="card" style="display:none;padding:0;overflow:hidden">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch"><table class="tbl">
      <thead><tr>
        <th>القطعة</th><th>الكمية المباعة</th><th>الإيراد</th><th>الربح</th>
      </tr></thead>
      <tbody id="top-tbody"></tbody>
    </table></div>
  </div>

  <!-- هوامش الربح -->
  <div id="tab-margins" class="card" style="display:none;padding:0;overflow:hidden">
    <div style="padding:10px 12px;display:flex;gap:6px;border-bottom:1px solid var(--border)">
      <select id="margin-filter" onchange="loadMargins()" style="flex:1;padding:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:13px">
        <option value="all">كل القطع</option>
        <option value="no_price">بدون سعر محدد</option>
        <option value="low_margin">هامش أقل من 20%</option>
      </select>
    </div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch"><table class="tbl">
      <thead><tr>
        <th>القطعة</th><th>شراء</th><th>بيع</th><th>الهامش</th>
      </tr></thead>
      <tbody id="margins-tbody"></tbody>
    </table></div>
  </div>

  <!-- حسب المورد -->
  <div id="tab-suppliers" class="card" style="display:none;padding:0;overflow:hidden">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch"><table class="tbl">
      <thead><tr>
        <th>المورد</th><th>قطع</th><th>الإيراد</th><th>الربح</th>
      </tr></thead>
      <tbody id="suppliers-tbody"></tbody>
    </table></div>
  </div>

  <!-- سجل المبيعات -->
  <div id="tab-log" class="card" style="display:none;padding:0;overflow:hidden">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch"><table class="tbl">
      <thead><tr>
        <th>القطعة</th><th>الموظف</th><th>الكمية</th><th>الإيراد</th><th>الربح</th><th>التاريخ</th>
      </tr></thead>
      <tbody id="log-tbody"></tbody>
    </table></div>
  </div>

</div>

<script>
const fmt = n => n == null ? '—' : (+n).toLocaleString('ar-JO', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' د.أ';
const fmtN = n => n == null ? '—' : (+n).toFixed(1) + '%';

function switchTab(name) {
  ['chart','top','margins','suppliers','log'].forEach(t => {
    document.getElementById('tab-' + t).style.display = t === name ? '' : 'none';
  });
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    b.classList.toggle('active', ['chart','top','margins','suppliers','log'][i] === name);
  });
  if (name === 'top' && !document.getElementById('top-tbody').innerHTML) loadTop();
  if (name === 'margins')   loadMargins();
  if (name === 'suppliers') loadBySupplier();
  if (name === 'log')       loadLog();
}

// ── KPI ────────────────────────────────────────────
async function loadSummary() {
  const res = await fetch('api/finance_api.php?action=summary');
  const d   = await res.json();
  if (d.error) return;
  document.getElementById('kpi-rev').textContent    = fmt(d.sales_month?.revenue);
  document.getElementById('kpi-profit').textContent = fmt(d.sales_month?.profit);
  document.getElementById('kpi-inv').textContent    = fmt(d.inventory?.inventory_cost);

  // هامش
  const rev = +d.sales_month?.revenue || 0;
  const profit = +d.sales_month?.profit || 0;
  document.getElementById('kpi-margin').textContent = rev > 0 ? (profit / rev * 100).toFixed(1) + '%' : '—';

  // delta
  const prevRev    = +d.sales_last_month?.revenue || 0;
  const prevProfit = +d.sales_last_month?.profit  || 0;
  setDelta('kpi-rev-delta',    rev, prevRev);
  setDelta('kpi-profit-delta', profit, prevProfit);

  // تنبيهات
  if (d.low_stock > 0 || d.out_of_stock > 0) {
    document.getElementById('alerts-card').style.display = '';
    loadAlerts();
  }
}

function setDelta(id, curr, prev) {
  const el = document.getElementById(id);
  if (!prev) { el.textContent = ''; return; }
  const pct = ((curr - prev) / prev * 100).toFixed(1);
  el.textContent   = (pct > 0 ? '▲ +' : '▼ ') + pct + '% عن الشهر الماضي';
  el.className     = 'kpi-delta ' + (pct >= 0 ? 'delta-up' : 'delta-dn');
}

// ── تنبيهات المخزون ──────────────────────────────
async function loadAlerts() {
  const res  = await fetch('api/finance_api.php?action=low_stock');
  const rows = await res.json();
  const el   = document.getElementById('alerts-list');
  if (!rows.length) { el.innerHTML = ''; return; }
  el.innerHTML = rows.slice(0,8).map(r => `
    <div class="alert-row ${r.qty==0?'alert-out':'alert-low'}">
      <span>${r.qty==0?'🔴':'🟡'}</span>
      <span class="alert-name">${r.name}</span>
      <span class="alert-qty">متبقي: <b>${r.qty}</b> / الحد: ${r.min_qty}</span>
      ${r.supplier_name ? `<span style="font-size:11px;color:inherit;opacity:.7">📞 ${r.supplier_name} ${r.supplier_phone||''}</span>` : ''}
    </div>
  `).join('');
}

// ── رسم بياني يومي ───────────────────────────────
async function loadDailyChart() {
  const res  = await fetch('api/finance_api.php?action=daily_sales&days=30');
  const rows = await res.json();
  const canvas = document.getElementById('daily-chart');
  const ctx    = canvas.getContext('2d');
  if (!rows.length) {
    canvas.style.display = 'none';
    document.getElementById('chart-empty').style.display = '';
    return;
  }
  canvas.width  = canvas.offsetWidth  || 600;
  canvas.height = 180;
  const W = canvas.width, H = canvas.height;
  const PAD = {t:20, r:10, b:30, l:55};
  const maxRev = Math.max(...rows.map(r => +r.revenue), 1);

  ctx.clearRect(0,0,W,H);
  // Grid lines
  ctx.strokeStyle = 'rgba(255,255,255,.06)';
  ctx.lineWidth = 1;
  [0.25, 0.5, 0.75, 1].forEach(f => {
    const y = PAD.t + (1-f) * (H - PAD.t - PAD.b);
    ctx.beginPath(); ctx.moveTo(PAD.l, y); ctx.lineTo(W-PAD.r, y); ctx.stroke();
    ctx.fillStyle = 'rgba(156,163,199,.5)';
    ctx.font = '10px Tahoma';
    ctx.textAlign = 'right';
    ctx.fillText(fmt(maxRev * f).replace(' د.أ',''), PAD.l - 4, y + 3);
  });

  const barW = Math.max(4, Math.floor((W - PAD.l - PAD.r) / rows.length) - 2);
  rows.forEach((r, i) => {
    const x   = PAD.l + i * (W - PAD.l - PAD.r) / rows.length;
    const revH = (+r.revenue / maxRev) * (H - PAD.t - PAD.b);
    const proH = (+r.profit  / maxRev) * (H - PAD.t - PAD.b);

    // إيراد
    ctx.fillStyle = 'rgba(79,142,247,.5)';
    ctx.fillRect(x, H - PAD.b - revH, barW, revH);
    // ربح
    ctx.fillStyle = 'rgba(62,207,142,.7)';
    ctx.fillRect(x, H - PAD.b - proH, barW, proH);
  });

  // Legend
  ctx.fillStyle = 'rgba(79,142,247,.8)'; ctx.fillRect(W-120, 4, 12, 10);
  ctx.fillStyle = 'rgba(156,163,199,.8)'; ctx.font = '10px Tahoma'; ctx.textAlign = 'right';
  ctx.fillText('إيراد', W-104, 13);
  ctx.fillStyle = 'rgba(62,207,142,.8)'; ctx.fillRect(W-70, 4, 12, 10);
  ctx.fillStyle = 'rgba(156,163,199,.8)';
  ctx.fillText('ربح', W-54, 13);
}

// ── أعلى القطع ───────────────────────────────────
async function loadTop() {
  const res  = await fetch('api/finance_api.php?action=top_parts&days=30&limit=10');
  const rows = await res.json();
  const maxR = Math.max(...rows.map(r => +r.revenue), 1);
  document.getElementById('top-tbody').innerHTML = rows.length
    ? rows.map((r, i) => `
      <tr>
        <td><b>${i+1}.</b> ${r.name} <span style="color:var(--text3);font-size:11px">${r.part_code}</span></td>
        <td><b>${r.units_sold}</b></td>
        <td>
          ${fmt(r.revenue)}
          <div class="bar"><div class="bar-fill" style="width:${(r.revenue/maxR*100).toFixed(0)}%"></div></div>
        </td>
        <td style="color:${+r.profit>0?'var(--green)':'var(--red)'}">${fmt(r.profit)}</td>
      </tr>
    `).join('')
    : '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">لا توجد مبيعات</td></tr>';
}

// ── هوامش الربح ──────────────────────────────────
async function loadMargins() {
  const filter = document.getElementById('margin-filter').value;
  const res    = await fetch('api/finance_api.php?action=margins&filter=' + filter);
  const d      = await res.json();
  const rows   = d.rows || [];
  document.getElementById('margins-tbody').innerHTML = rows.length
    ? rows.map(r => {
        const m     = r.margin_pct != null ? +r.margin_pct : null;
        const color = m == null ? 'var(--text3)' : m < 10 ? 'var(--red)' : m < 20 ? 'var(--amber)' : 'var(--green)';
        const fill  = m != null ? Math.min(m, 100) : 0;
        return `<tr>
          <td>${r.name} <span style="color:var(--text3);font-size:11px">${r.part_code}</span></td>
          <td>${r.cost_price != null ? fmt(r.cost_price) : '<span style="color:var(--text3)">—</span>'}</td>
          <td>${r.sell_price != null ? fmt(r.sell_price) : '<span style="color:var(--text3)">—</span>'}</td>
          <td style="color:${color}">
            <span class="margin-bar"><span class="margin-fill" style="width:${fill}%;background:${color}"></span></span>
            ${m != null ? m + '%' : '— غير محدد'}
          </td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">لا توجد بيانات</td></tr>';
}

// ── حسب المورد ───────────────────────────────────
async function loadBySupplier() {
  const res  = await fetch('api/finance_api.php?action=by_supplier');
  const rows = await res.json();
  document.getElementById('suppliers-tbody').innerHTML = rows.length
    ? rows.map(r => `
      <tr>
        <td><b>${r.supplier_name}</b></td>
        <td>${r.parts_count}</td>
        <td>${fmt(r.revenue)}</td>
        <td style="color:${+r.profit>0?'var(--green)':'var(--red)'}">${fmt(r.profit)}</td>
      </tr>
    `).join('')
    : '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">لا توجد مبيعات هذا الشهر</td></tr>';
}

// ── سجل المبيعات ─────────────────────────────────
async function loadLog() {
  const res  = await fetch('api/inventory_api.php?action=sales_log');
  const rows = await res.json();
  document.getElementById('log-tbody').innerHTML = rows.length
    ? rows.map(r => {
        const d = new Date(r.sold_at);
        const dateStr = d.toLocaleDateString('ar-JO', {day:'2-digit',month:'2-digit'})
                      + ' ' + d.toLocaleTimeString('ar-JO', {hour:'2-digit',minute:'2-digit'});
        return `<tr>
          <td>${r.part_name}</td>
          <td style="color:var(--text3)">${r.emp_name}</td>
          <td><b>${r.qty}</b></td>
          <td>${r.total_revenue != null ? fmt(r.total_revenue) : '—'}</td>
          <td style="color:${+r.total_profit>0?'var(--green)':'var(--text3)'}">${r.total_profit != null ? fmt(r.total_profit) : '—'}</td>
          <td style="color:var(--text3);font-size:12px">${dateStr}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="6" style="text-align:center;color:var(--text3);padding:20px">لا توجد مبيعات بعد</td></tr>';
}

// ── تحميل أولي ───────────────────────────────────
loadSummary();
loadDailyChart();
</script>
</body>
</html>
