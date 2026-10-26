<?php
// ===================================================
// suppliers.php — إدارة الموردين (المرحلة الرابعة)
// ===================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();
if (!isAdmin()) { header('Location: employee.php'); exit; }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الموردون') ?>
<style>
.sup-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:10px;cursor:pointer;transition:border-color .2s}
.sup-card:hover{border-color:var(--blue)}
.sup-name{font-size:15px;font-weight:700;color:var(--text);margin-bottom:4px}
.sup-meta{font-size:12px;color:var(--text3);display:flex;gap:12px;flex-wrap:wrap}
.sup-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700}
.empty-state{text-align:center;padding:40px 20px;color:var(--text3)}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <div class="brand-icon">م</div>
    <div><div class="brand-name">إدارة الموردين</div><div class="brand-sub">المرحلة الرابعة</div></div>
  </div>
  <div class="top-right">
    <button class="nav-toggle" onclick="document.getElementById('sup-drawer').classList.add('open');document.getElementById('sup-overlay').classList.add('open')" style="display:block">☰</button>
  </div>
</div>
<div class="nav-overlay" id="sup-overlay" onclick="this.classList.remove('open');document.getElementById('sup-drawer').classList.remove('open')"></div>
<div class="nav-drawer" id="sup-drawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <span style="font-weight:700;color:var(--text);font-size:14px">القائمة</span>
    <button onclick="document.getElementById('sup-overlay').classList.remove('open');document.getElementById('sup-drawer').classList.remove('open')" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer;padding:0">✕</button>
  </div>
  <a href="inventory.php">📦 المستودع</a>
  <a href="warehouse_dashboard.php" style="color:var(--gold);border-color:rgba(240,192,64,.3)">📊 الداشبورد المالي</a>
  <a href="admin.php">← رجوع للوحة التحكم</a>
  <a href="api/logout.php" style="color:var(--red);border-color:rgba(226,91,91,.3)">🚪 خروج</a>
</div>

<div class="page-wrap" style="max-width:640px">

  <!-- إحصائيات -->
  <div class="grid3" style="margin-bottom:12px" id="stats-row">
    <div class="sbox"><div class="slabel">الموردون</div><div class="sval gd" id="st-total">—</div></div>
    <div class="sbox"><div class="slabel">قطع مرتبطة</div><div class="sval g" id="st-parts">—</div></div>
    <div class="sbox"><div class="slabel">قيمة المخزون</div><div class="sval a" id="st-value">—</div></div>
  </div>

  <!-- زر إضافة -->
  <button class="btn btn-blue" onclick="openAdd()" style="margin-bottom:12px">
    ＋ إضافة مورد جديد
  </button>

  <!-- قائمة الموردين -->
  <div id="sup-list"><div class="sbox" style="text-align:center;color:var(--text3)">جار التحميل…</div></div>
</div>

<!-- Modal إضافة / تعديل -->
<div class="modal-bg" id="modal">
  <div class="modal-box">
    <h3 id="modal-title">إضافة مورد</h3>
    <input type="hidden" id="sup-id">
    <div class="field"><label>اسم المورد *</label><input id="sup-name" placeholder="شركة الخليج للتقنية"></div>
    <div class="field"><label>رقم الهاتف</label><input id="sup-phone" placeholder="07XXXXXXXX" dir="ltr"></div>
    <div class="field"><label>البريد الإلكتروني</label><input id="sup-email" type="email" placeholder="info@example.com" dir="ltr"></div>
    <div class="field"><label>العنوان</label><input id="sup-address" placeholder="المدينة — الحي"></div>
    <div class="field"><label>ملاحظة</label><textarea id="sup-note" rows="2" style="resize:none"></textarea></div>
    <div class="grid2" style="gap:8px;margin-top:4px">
      <button class="btn btn-green" onclick="saveSup()">💾 حفظ</button>
      <button class="btn btn-ghost" onclick="closeModal()">إلغاء</button>
    </div>
    <button id="del-btn" class="btn btn-red" style="display:none;margin-top:6px" onclick="deleteSup()">🗑️ حذف المورد</button>
  </div>
</div>

<script>
let suppliers = [];

async function loadSuppliers() {
  try {
    const res = await fetch('api/suppliers_api.php?action=list&active=0');
    const data = await res.json();
    // إذا رجع object بدل array (خطأ من الـ API)
    if (!Array.isArray(data)) {
      console.error('suppliers API error:', data.error || data);
      suppliers = [];
    } else {
      suppliers = data;
    }
    renderSuppliers();
    renderStats();
  } catch(e) {
    console.error('loadSuppliers error:', e);
    suppliers = [];
    renderSuppliers();
    renderStats();
  }
}

function renderStats() {
  const total = suppliers.length;
  const parts = suppliers.reduce((a, s) => a + (+s.parts_count || 0), 0);
  document.getElementById('st-total').textContent = total;
  document.getElementById('st-parts').textContent = parts;
  // قيمة المخزون: تُجلب من الداشبورد
  document.getElementById('st-value').textContent = '—';
}

function renderSuppliers() {
  const el = document.getElementById('sup-list');
  if (!suppliers.length) {
    el.innerHTML = '<div class="empty-state">📦 لا يوجد موردون حتى الآن</div>';
    return;
  }
  el.innerHTML = suppliers.map(s => `
    <div class="sup-card" onclick="openEdit(${s.id})">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
        <div class="sup-name">${s.name}</div>
        <span class="sup-badge ${s.is_active=='1' ? 'b-green' : 'b-red'}">
          ${s.is_active=='1' ? '✓ نشط' : '✗ معطل'}
        </span>
      </div>
      <div class="sup-meta">
        ${s.phone ? `<span>📞 ${s.phone}</span>` : ''}
        ${s.email ? `<span>✉ ${s.email}</span>` : ''}
        ${s.address ? `<span>📍 ${s.address}</span>` : ''}
        <span>📦 ${s.parts_count} قطعة</span>
      </div>
      ${s.note ? `<div style="font-size:12px;color:var(--text3);margin-top:4px">${s.note}</div>` : ''}
    </div>
  `).join('');
}

function openAdd() {
  document.getElementById('modal-title').textContent = 'إضافة مورد جديد';
  document.getElementById('sup-id').value    = '';
  document.getElementById('sup-name').value  = '';
  document.getElementById('sup-phone').value = '';
  document.getElementById('sup-email').value = '';
  document.getElementById('sup-address').value = '';
  document.getElementById('sup-note').value  = '';
  document.getElementById('del-btn').style.display = 'none';
  document.getElementById('modal').classList.add('open');
}

function openEdit(id) {
  const s = suppliers.find(x => x.id == id);
  if (!s) return;
  document.getElementById('modal-title').textContent = 'تعديل المورد';
  document.getElementById('sup-id').value    = s.id;
  document.getElementById('sup-name').value  = s.name;
  document.getElementById('sup-phone').value = s.phone || '';
  document.getElementById('sup-email').value = s.email || '';
  document.getElementById('sup-address').value = s.address || '';
  document.getElementById('sup-note').value  = s.note || '';
  document.getElementById('del-btn').style.display = '';
  document.getElementById('modal').classList.add('open');
}

function closeModal() {
  document.getElementById('modal').classList.remove('open');
}

async function saveSup() {
  const id   = document.getElementById('sup-id').value;
  const name = document.getElementById('sup-name').value.trim();
  if (!name) { alert('اسم المورد مطلوب'); return; }
  const body = {
    id: id ? +id : undefined,
    name,
    phone:   document.getElementById('sup-phone').value.trim()   || null,
    email:   document.getElementById('sup-email').value.trim()   || null,
    address: document.getElementById('sup-address').value.trim() || null,
    note:    document.getElementById('sup-note').value.trim()    || null,
    is_active: 1,
  };
  const action = id ? 'edit' : 'add';
  const res = await fetch('api/suppliers_api.php?action=' + action, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  const data = await res.json();
  if (data.error) { alert(data.error); return; }
  closeModal();
  loadSuppliers();
}

async function deleteSup() {
  const id = document.getElementById('sup-id').value;
  if (!id || !confirm('حذف المورد؟ سيتم إلغاء ربطه بالقطع.')) return;
  const res = await fetch('api/suppliers_api.php?action=delete', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id: +id})
  });
  const data = await res.json();
  if (data.error) { alert(data.error); return; }
  closeModal();
  loadSuppliers();
}

document.getElementById('modal').addEventListener('click', e => {
  if (e.target === document.getElementById('modal')) closeModal();
});

loadSuppliers();
</script>
</body>
</html>
