<?php
// ===================================================
// inventory.php — المستودع (نفس بنية suppliers.php)
// ===================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();
if (!isAdmin()) { header('Location: employee.php'); exit; }
$isAdmin = true;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('المستودع') ?>
<style>

/* إجبار ظهور زر القائمة دائماً */
.nav-toggle{display:block !important}
/* modal z-index fix */
.modal-bg{z-index:400 !important}
/* فورم كامل الشاشة */
#form-modal,#detail-modal{
  align-items:stretch !important;
  justify-content:stretch !important;
}
#form-modal .modal-box,#detail-modal .modal-box{
  max-height:100vh !important;
  height:100vh !important;
  border-radius:0 !important;
  overflow-y:auto !important;
  -webkit-overflow-scrolling:touch !important;
  padding-top:calc(env(safe-area-inset-top,0px) + 16px) !important;
  padding-bottom:calc(env(safe-area-inset-bottom,0px) + 16px) !important;
}
/* grid2 on very small screens */
@media(max-width:380px){
  .grid2{grid-template-columns:1fr !important}
}
/* بطاقات المنتجات — نفس .sup-card */
.part-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:10px;cursor:pointer;transition:border-color .2s}
.part-card:hover{border-color:var(--blue)}
.part-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px}
.part-card-name{font-size:15px;font-weight:700;color:var(--text)}
.part-card-code{font-family:monospace;font-size:12px;color:var(--blue);margin-bottom:4px}
.part-card-meta{font-size:12px;color:var(--text3);display:flex;gap:12px;flex-wrap:wrap}
.part-card-img{width:52px;height:52px;border-radius:10px;object-fit:cover;flex-shrink:0;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--text3)}
.part-card-inner{display:flex;gap:12px;align-items:flex-start}
.part-card-body{flex:1;min-width:0}
.part-card-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
.sup-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700}
.empty-state{text-align:center;padding:40px 20px;color:var(--text3)}
.search-bar{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:0 12px;height:44px;transition:border-color .2s}
.search-bar:focus-within{border-color:var(--blue)}
.search-bar input{flex:1;border:none;background:transparent;font-size:15px;color:var(--text);outline:none;font-family:inherit}
.search-bar input::placeholder{color:var(--text3)}
.filter-chip{padding:5px 12px;border-radius:20px;font-size:12px;cursor:pointer;border:1px solid var(--border);background:var(--surface2);color:var(--text2);transition:.2s;font-family:inherit}
.filter-chip.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.sec-btn{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:.2s;width:100%}
.sec-btn.has-filter{border-color:var(--blue);color:var(--blue)}
.sec-panel{display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.5);align-items:flex-end}
.sec-panel.open{display:flex}
.sec-sheet{background:var(--surface);border-radius:16px 16px 0 0;width:100%;max-height:60vh;overflow-y:auto;padding:16px 14px 24px}
.sec-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.sec-item{padding:10px 4px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);color:var(--text2);font-size:13px;font-weight:600;cursor:pointer;text-align:center;transition:.2s;font-family:inherit}
.sec-item:hover,.sec-item.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.img-preview{max-width:100%;border-radius:10px;margin-top:8px;display:none}
.big-img{width:100%;max-height:200px;object-fit:contain;border-radius:10px;background:var(--surface2);padding:8px;margin-bottom:10px}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <div class="brand-icon">ف</div>
    <div><div class="brand-name">مؤسسة فلسطين</div><div class="brand-sub">نظام المستودع</div></div>
  </div>
  <div class="top-right">
    <button class="nav-toggle" onclick="document.getElementById('inv-drawer').classList.add('open');document.getElementById('inv-overlay').classList.add('open')">☰</button>
  </div>
</div>
<div class="nav-overlay" id="inv-overlay" onclick="this.classList.remove('open');document.getElementById('inv-drawer').classList.remove('open')"></div>
<div class="nav-drawer" id="inv-drawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <span style="font-weight:700;color:var(--text);font-size:14px">القائمة</span>
    <button onclick="document.getElementById('inv-overlay').classList.remove('open');document.getElementById('inv-drawer').classList.remove('open')" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:0">✕</button>
  </div>
  <a href="admin.php">← رجوع للوحة التحكم</a>
  <a href="/dashboard/index.html" style="color:var(--gold);border-color:rgba(240,192,64,.3)">🏛️ لوحة الأنظمة</a>
  <a href="warehouse_dashboard.php" style="color:var(--gold);border-color:rgba(240,192,64,.3)">📊 الداشبورد المالي</a>
  <a href="inventory_reports.php" style="color:var(--blue);border-color:rgba(79,142,247,.3)">📋 تقارير المستودع</a>
  <a href="suppliers.php">🏭 الموردون</a>
  <a href="api/logout.php" style="color:var(--red);border-color:rgba(226,91,91,.3)">🚪 خروج</a>
</div>

<div class="page-wrap" style="max-width:640px">

  <!-- بحث وفلاتر -->
  <div class="card" style="margin-bottom:12px">
    <div class="search-bar" style="margin-bottom:10px">
      <span style="font-size:18px;color:var(--text3)">🔍</span>
      <input type="text" id="q" placeholder="ابحث عن قطعة، كود، جهاز..." oninput="doSearch()" autocomplete="off">
      <button onclick="document.getElementById('q').value='';doSearch()" style="background:none;border:none;color:var(--text3);font-size:18px;cursor:pointer">✕</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
      <button class="filter-chip active" id="stock-all" onclick="filterStock('')">الكل</button>
      <button class="filter-chip" id="stock-low" onclick="filterStock('low')">🟡 منخفض</button>
      <button class="filter-chip" id="stock-out" onclick="filterStock('out')">🔴 نفد</button>
    </div>
    <button class="sec-btn" id="sec-btn" onclick="document.getElementById('sec-panel').classList.add('open')">
      <span id="sec-btn-label">📦 كل الأرفف</span><span>▾</span>
    </button>
  </div>

  <!-- إحصائيات -->
  <div class="grid3" style="margin-bottom:12px" id="stats-row">
    <div class="sbox"><div class="slabel">إجمالي القطع</div><div class="sval gd" id="st-total">—</div></div>
    <div class="sbox"><div class="slabel">كمية وفيرة</div><div class="sval g" id="st-ok">—</div></div>
    <div class="sbox"><div class="slabel">كمية منخفضة</div><div class="sval r" id="st-low">—</div></div>
  </div>

  <!-- زر إضافة -->
  <button class="btn btn-green" onclick="openAdd()" style="margin-bottom:12px">
    ＋ إضافة قطعة جديدة
  </button>

  <!-- قائمة القطع -->
  <div id="parts-list"><div class="sbox" style="text-align:center;color:var(--text3)">جار التحميل…</div></div>

</div>

<!-- Panel: اختيار الرف -->
<div class="sec-panel" id="sec-panel" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="sec-sheet">
    <div style="font-weight:700;font-size:15px;margin-bottom:14px;text-align:center">اختر الرف</div>
    <div class="sec-grid" id="sec-grid">
      <button class="sec-item active" onclick="filterSec('');document.getElementById('sec-panel').classList.remove('open')" data-sec="">الكل</button>
    </div>
  </div>
</div>

<!-- Modal: تفاصيل / بيع -->
<div class="modal-bg" id="detail-modal">
  <div class="modal-box" style="max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 id="d-name" style="margin:0">تفاصيل القطعة</h3>
    <button onclick="closeDetail()" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);border-radius:8px;width:36px;height:36px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">✕</button>
  </div>
    <img id="d-img" class="big-img" style="display:none">
    <div class="grid2" style="margin-bottom:10px">
      <div class="sbox"><div class="slabel">الكود</div><div class="sval" id="d-code" style="font-size:15px;font-family:monospace">—</div></div>
      <div class="sbox"><div class="slabel">الكمية</div><div class="sval" id="d-qty">—</div></div>
      <div class="sbox"><div class="slabel">الجهاز</div><div class="sval" id="d-device" style="font-size:13px">—</div></div>
      <div class="sbox"><div class="slabel">الرف</div><div class="sval" id="d-sec" style="font-size:13px">—</div></div>
    </div>
    <div id="d-note" style="font-size:13px;color:var(--text3);margin-bottom:10px"></div>
    <div class="field">
      <label>كمية البيع</label>
      <input type="number" id="sell-qty" min="1" value="1" style="direction:ltr">
    </div>
    <div class="notice n-red" id="sell-err" style="display:none"></div>
    <div class="grid2" style="gap:8px;margin-top:6px">
      <button class="btn btn-ghost" onclick="closeDetail()">إغلاق</button>
      <button class="btn btn-green" onclick="doSell()">✓ تأكيد البيع</button>
    </div>
    <div class="grid2" style="gap:8px;margin-top:8px">
      <button class="btn" onclick="openEditFromDetail()" style="background:var(--amber-bg);color:var(--amber);border:1px solid rgba(245,166,35,.3)">✏️ تعديل</button>
      <button class="btn btn-red" onclick="doDelete()">🗑 حذف</button>
    </div>
  </div>
</div>

<!-- Modal: إضافة / تعديل -->
<div class="modal-bg" id="form-modal">
  <div class="modal-box" style="max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 id="form-title" style="margin:0">إضافة قطعة</h3>
    <button onclick="closeForm()" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);border-radius:8px;width:36px;height:36px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">✕</button>
  </div>
    <input type="hidden" id="f-id">
    <input type="hidden" id="f-existing-image">
    <div class="field"><label>كود القطعة *</label><input id="f-code" placeholder="P-001" dir="ltr"></div>
    <div class="field"><label>الاسم *</label><input id="f-name" placeholder="اسم القطعة"></div>
    <div class="field"><label>الجهاز</label><input id="f-device" placeholder="سامسونج A52"></div>
    <div class="field"><label>الرف</label><select id="f-section"><option value="">— اختر الرف —</option></select></div>
    <div class="grid2">
      <div class="field"><label>سعر الشراء (د.أ)</label><input type="number" id="f-cost" step="0.01" min="0" style="direction:ltr" placeholder="0.00"></div>
      <div class="field"><label>سعر البيع (د.أ)</label><input type="number" id="f-sell" step="0.01" min="0" style="direction:ltr" placeholder="0.00"></div>
    </div>
    <div class="grid2">
      <div class="field"><label>الكمية *</label><input type="number" id="f-qty" min="0" value="0" style="direction:ltr"></div>
      <div class="field"><label>الحد الأدنى للتنبيه</label><input type="number" id="f-min-qty" min="0" value="5" style="direction:ltr"></div>
    </div>
    <div class="grid2">
      <div class="field"><label>المورد</label><select id="f-supplier"><option value="">— بدون مورد —</option></select></div>
      <div class="field"><label>الباركود</label><input id="f-barcode" placeholder="اختياري" dir="ltr"></div>
    </div>
    <div class="field"><label>ملاحظة</label><textarea id="f-note" rows="2" style="resize:none"></textarea></div>
    <div class="field">
      <label>صورة المنتج</label>
      <input type="file" id="f-image" accept="image/*" onchange="previewImg(this)" style="color:var(--text2)">
      <img id="img-preview" class="img-preview">
    </div>
    <div class="notice n-red" id="form-err" style="display:none"></div>
    <div class="grid2" style="gap:8px;margin-top:4px">
      <button class="btn btn-green" onclick="saveForm()">💾 حفظ</button>
      <button class="btn btn-ghost" onclick="closeForm()">إلغاء</button>
    </div>
  </div>
</div>

<script>
let parts = [], sections = [], suppliers = [], currentSec = '', currentQ = '', currentStock = '', currentPart = null;

// ── تحميل البيانات ────────────────────────────────
async function loadAll() {
  // تحميل الأرفف
  try {
    const r = await fetch('api/inventory_api.php?action=sections');
    sections = await r.json();
    buildSecGrid();
    buildSecSelect();
  } catch(e) { sections = []; }

  // تحميل الموردين
  try {
    const r = await fetch('api/inventory_api.php?action=suppliers');
    suppliers = await r.json();
    const sel = document.getElementById('f-supplier');
    suppliers.forEach(s => {
      const o = document.createElement('option');
      o.value = s.id;
      o.textContent = s.name + (s.phone ? ' (' + s.phone + ')' : '');
      sel.appendChild(o);
    });
  } catch(e) {}

  load();
}

function buildSecGrid() {
  const grid = document.getElementById('sec-grid');
  sections.forEach(s => {
    const b = document.createElement('button');
    b.className = 'sec-item';
    b.textContent = s.label || s.letter + s.number;
    b.dataset.sec = s.id;
    b.onclick = () => { filterSec(s.id); document.getElementById('sec-panel').classList.remove('open'); };
    grid.appendChild(b);
  });
}

function buildSecSelect() {
  const sel = document.getElementById('f-section');
  sections.forEach(s => {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = s.label || s.letter + s.number;
    sel.appendChild(o);
  });
}

async function load() {
  document.getElementById('parts-list').innerHTML = '<div class="sbox" style="text-align:center;color:var(--text3)">جار التحميل…</div>';
  try {
    const url = `api/inventory_api.php?action=list&q=${encodeURIComponent(currentQ)}&section=${currentSec}&stock=${currentStock}`;
    const r = await fetch(url);
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    parts = d.rows || [];
    document.getElementById('st-total').textContent = d.total || 0;
    document.getElementById('st-ok').textContent    = d.ok   || 0;
    document.getElementById('st-low').textContent   = d.low  || 0;
    renderParts();
  } catch(e) {
    document.getElementById('parts-list').innerHTML = `<div class="sbox" style="text-align:center;color:var(--red)">خطأ: ${e.message}</div>`;
  }
}

function renderParts() {
  const el = document.getElementById('parts-list');
  if (!parts.length) {
    el.innerHTML = '<div class="empty-state">📦 لا توجد قطع في المستودع</div>';
    return;
  }
  el.innerHTML = parts.map(p => `
    <div class="part-card" onclick="openDetail(${p.id})">
      <div class="part-card-inner">
        <div class="part-card-img">
          ${p.image ? `<img src="${p.image}" style="width:52px;height:52px;border-radius:10px;object-fit:cover">` : '📦'}
        </div>
        <div class="part-card-body">
          <div class="part-card-top">
            <div class="part-card-name">${p.name}</div>
            <span class="sup-badge ${p.qty == 0 ? 'b-red' : p.qty <= p.min_qty ? 'b-amber' : 'b-green'}">
              ${p.qty == 0 ? '✗ نفد' : p.qty <= p.min_qty ? '⚠ ' + p.qty : '✓ ' + p.qty}
            </span>
          </div>
          <div class="part-card-code">${p.part_code}</div>
          <div class="part-card-meta">
            ${p.device    ? `<span>📱 ${p.device}</span>` : ''}
            ${p.section_label ? `<span>📦 ${p.section_label}</span>` : ''}
            ${p.supplier_name ? `<span>🏭 ${p.supplier_name}</span>` : ''}
            ${p.sell_price ? `<span>💰 ${(+p.sell_price).toFixed(3)} د.أ</span>` : ''}
            ${p.margin_pct != null ? `<span style="color:${p.margin_pct<10?'var(--red)':p.margin_pct<20?'var(--amber)':'var(--green)'}">📈 ${p.margin_pct}%</span>` : ''}
          </div>
          ${p.note ? `<div style="font-size:12px;color:var(--text3);margin-top:4px">${p.note}</div>` : ''}
        </div>
        <div onclick="event.stopPropagation()">
          <button onclick="openEditById(${p.id})" class="filter-chip" style="font-size:13px">✏️</button>
        </div>
      </div>
    </div>
  `).join('');
}

// ── فلاتر ─────────────────────────────────────────
function filterStock(v) {
  currentStock = v;
  ['all','low','out'].forEach(x => {
    document.getElementById('stock-' + x)?.classList.toggle('active', x === (v || 'all'));
  });
  load();
}

function filterSec(id) {
  currentSec = id;
  document.querySelectorAll('.sec-item').forEach(b => b.classList.toggle('active', b.dataset.sec == id || (!id && b.dataset.sec === '')));
  const btn = document.getElementById('sec-btn');
  const lbl = document.getElementById('sec-btn-label');
  if (!id) { lbl.textContent = '📦 كل الأرفف'; btn.classList.remove('has-filter'); }
  else {
    const s = sections.find(x => x.id == id);
    lbl.textContent = '📦 ' + (s ? s.label || s.letter + s.number : 'رف');
    btn.classList.add('has-filter');
  }
  load();
}

function doSearch() { currentQ = document.getElementById('q').value; load(); }

// ── تفاصيل القطعة ────────────────────────────────
async function openDetail(id) {
  try {
    const r = await fetch('api/inventory_api.php?action=get&id=' + id);
    currentPart = await r.json();
    document.getElementById('d-name').textContent = currentPart.name;
    document.getElementById('d-code').textContent = currentPart.part_code;
    document.getElementById('d-device').textContent = currentPart.device || '—';
    document.getElementById('d-sec').textContent = currentPart.section_label || '—';
    document.getElementById('d-note').textContent = currentPart.note ? '📝 ' + currentPart.note : '';
    const qEl = document.getElementById('d-qty');
    qEl.textContent = currentPart.qty;
    qEl.className = 'sval ' + (currentPart.qty == 0 ? 'r' : currentPart.qty <= currentPart.min_qty ? 'a' : 'g');
    const img = document.getElementById('d-img');
    if (currentPart.image) { img.src = currentPart.image; img.style.display = 'block'; } else { img.style.display = 'none'; }
    document.getElementById('sell-qty').value = 1;
    document.getElementById('sell-err').style.display = 'none';
    document.getElementById('detail-modal').classList.add('open');
  } catch(e) { alert('تعذر تحميل البيانات'); }
}

function closeDetail() { document.getElementById('detail-modal').classList.remove('open'); currentPart = null; }

async function doSell() {
  const qty = parseInt(document.getElementById('sell-qty').value);
  if (!qty || qty < 1) { showErr('أدخل كمية صحيحة'); return; }
  if (qty > currentPart.qty) { showErr('الكمية المتاحة: ' + currentPart.qty); return; }
  if (!confirm(`تأكيد بيع ${qty} من "${currentPart.name}"؟`)) return;
  const r = await fetch('api/inventory_api.php?action=sell', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id: currentPart.id, qty})
  });
  const d = await r.json();
  if (d.success) { closeDetail(); load(); } else showErr(d.error || 'خطأ');
}
function showErr(m) { const e = document.getElementById('sell-err'); e.textContent = m; e.style.display = 'flex'; }

async function doDelete() {
  if (!currentPart || !confirm(`حذف "${currentPart.name}" نهائياً؟`)) return;
  const r = await fetch('api/inventory_api.php?action=delete', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id: currentPart.id})
  });
  const d = await r.json();
  if (d.success) { closeDetail(); load(); } else alert(d.error || 'خطأ');
}

// ── فورم الإضافة / التعديل ───────────────────────
function openAdd() {
  document.getElementById('form-title').textContent = 'إضافة قطعة جديدة';
  document.getElementById('f-id').value = '';
  document.getElementById('f-existing-image').value = '';
  ['f-code','f-name','f-device','f-note','f-barcode'].forEach(x => document.getElementById(x).value = '');
  document.getElementById('f-qty').value = 0;
  document.getElementById('f-min-qty').value = 5;
  document.getElementById('f-section').value = '';
  document.getElementById('f-supplier').value = '';
  document.getElementById('f-cost').value = '';
  document.getElementById('f-sell').value = '';
  document.getElementById('f-image').value = '';
  document.getElementById('img-preview').style.display = 'none';
  document.getElementById('form-err').style.display = 'none';
  document.getElementById('form-modal').classList.add('open');
}

function openEditFromDetail() {
  if (!currentPart) return;
  closeDetail();
  openEditById(currentPart.id);
}

async function openEditById(id) {
  try {
    const r = await fetch('api/inventory_api.php?action=get&id=' + id);
    const p = await r.json();
    if (!p || !p.id) { alert('تعذر تحميل البيانات'); return; }
    document.getElementById('form-title').textContent = '✏️ تعديل: ' + p.name;
    document.getElementById('f-id').value = p.id;
    document.getElementById('f-existing-image').value = p.image || '';
    document.getElementById('f-code').value    = p.part_code;
    document.getElementById('f-name').value    = p.name;
    document.getElementById('f-device').value  = p.device || '';
    document.getElementById('f-section').value = p.section_id || '';
    document.getElementById('f-qty').value     = p.qty;
    document.getElementById('f-min-qty').value = p.min_qty || 5;
    document.getElementById('f-cost').value    = p.cost_price || '';
    document.getElementById('f-sell').value    = p.sell_price || '';
    document.getElementById('f-supplier').value = p.supplier_id || '';
    document.getElementById('f-barcode').value  = p.barcode || '';
    document.getElementById('f-note').value    = p.note || '';
    document.getElementById('f-image').value   = '';
    const prev = document.getElementById('img-preview');
    if (p.image) { prev.src = p.image; prev.style.display = 'block'; } else { prev.style.display = 'none'; }
    document.getElementById('form-err').style.display = 'none';
    document.getElementById('form-modal').classList.add('open');
  } catch(e) { alert('تعذر تحميل البيانات: ' + e.message); }
}

function closeForm() { document.getElementById('form-modal').classList.remove('open'); }

function previewImg(input) {
  const prev = document.getElementById('img-preview');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  }
}

async function saveForm() {
  const id   = document.getElementById('f-id').value;
  const code = document.getElementById('f-code').value.trim();
  const name = document.getElementById('f-name').value.trim();
  if (!code || !name) { showFormErr('الكود والاسم مطلوبان'); return; }

  const fd = new FormData();
  if (id) fd.append('id', id);
  fd.append('part_code',  code);
  fd.append('name',       name);
  fd.append('device',     document.getElementById('f-device').value.trim());
  fd.append('section_id', document.getElementById('f-section').value);
  fd.append('qty',        document.getElementById('f-qty').value);
  fd.append('min_qty',    document.getElementById('f-min-qty').value);
  fd.append('cost_price', document.getElementById('f-cost').value);
  fd.append('sell_price', document.getElementById('f-sell').value);
  fd.append('supplier_id',document.getElementById('f-supplier').value);
  fd.append('barcode',    document.getElementById('f-barcode').value.trim());
  fd.append('note',       document.getElementById('f-note').value.trim());
  fd.append('existing_image', document.getElementById('f-existing-image').value);
  const imgFile = document.getElementById('f-image').files[0];
  if (imgFile) fd.append('image', imgFile);

  const action = id ? 'edit' : 'add';
  try {
    const r = await fetch('api/inventory_api.php?action=' + action, { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { closeForm(); load(); }
    else showFormErr(d.error || 'خطأ غير معروف');
  } catch(e) { showFormErr('تعذر الاتصال بالسيرفر'); }
}

function showFormErr(m) { const e = document.getElementById('form-err'); e.textContent = m; e.style.display = 'flex'; }

// ── إغلاق المودالز بالنقر خارجها ────────────────
document.getElementById('detail-modal').addEventListener('click', e => { if (e.target === document.getElementById('detail-modal')) closeDetail(); });
document.getElementById('form-modal').addEventListener('click',   e => { if (e.target === document.getElementById('form-modal'))   closeForm();   });

loadAll();
</script>
</body>
</html>
