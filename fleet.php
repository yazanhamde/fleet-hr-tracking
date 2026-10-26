<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireLogin();
requireAdmin(); // صفحة الأسطول للإداريين فقط

$db     = getDB();
$today  = date('Y-m-d');
$month  = date('Y-m');

// ── بيانات المركبات مع السائق المسند ──────────────────────────────
$_vStmt = $db->prepare("
    SELECT v.*,
           e.name  AS driver_name,
           e.phone AS driver_phone,
           (SELECT lat         FROM vehicle_tracking WHERE vehicle_id=v.id ORDER BY recorded_at DESC LIMIT 1) AS last_lat,
           (SELECT lng         FROM vehicle_tracking WHERE vehicle_id=v.id ORDER BY recorded_at DESC LIMIT 1) AS last_lng,
           (SELECT recorded_at FROM vehicle_tracking WHERE vehicle_id=v.id ORDER BY recorded_at DESC LIMIT 1) AS last_seen,
           (SELECT COUNT(*) FROM driver_tasks WHERE vehicle_id=v.id AND status NOT IN ('completed','cancelled')) AS active_tasks,
           CASE
               WHEN v.next_service_date IS NOT NULL AND v.next_service_date < CURDATE()      THEN 'overdue'
               WHEN v.next_service_date IS NOT NULL AND v.next_service_date <= DATE_ADD(CURDATE(),INTERVAL 14 DAY) THEN 'soon'
               ELSE 'ok'
           END AS service_status,
           (SELECT COALESCE(SUM(fuel_cost),0)   FROM vehicle_fuel_log   WHERE vehicle_id=v.id AND log_date    LIKE CONCAT(?,'%')) AS month_fuel,
           (SELECT COALESCE(SUM(cost),0)         FROM vehicle_maintenance WHERE vehicle_id=v.id AND performed_at LIKE CONCAT(?,'%')) AS month_maint,
           (SELECT COALESCE(SUM(distance_km),0)  FROM trips              WHERE vehicle_id=v.id AND DATE(start_time) LIKE CONCAT(?,'%')) AS month_km
    FROM vehicles v
    LEFT JOIN employees e ON e.id = v.assigned_to
    ORDER BY v.name
");
$_vStmt->execute([$month, $month, $month]);
$vehicles = $_vStmt->fetchAll();

// ── تنبيهات الأسطول غير المُعالجة ─────────────────────────────────
$fleetAlerts = $db->query("
    SELECT a.*, v.name AS vname, e.name AS ename
    FROM alerts a
    LEFT JOIN vehicles  v ON v.id = a.vehicle_id
    LEFT JOIN employees e ON e.id = a.employee_id
    WHERE a.type IN ('maintenance_due','geofence_exit','low_fuel','task_overdue')
      AND a.dismissed_at IS NULL
    ORDER BY a.created_at DESC
    LIMIT 20
")->fetchAll();

// ── إجمالي الشهر (كل الأسطول) ──────────────────────────────────
$_tStmt = $db->prepare("
    SELECT
      (SELECT COALESCE(SUM(fuel_cost),0)   FROM vehicle_fuel_log   WHERE log_date      LIKE CONCAT(?,'%')) AS total_fuel,
      (SELECT COALESCE(SUM(cost),0)         FROM vehicle_maintenance WHERE performed_at  LIKE CONCAT(?,'%')) AS total_maint,
      (SELECT COALESCE(SUM(distance_km),0)  FROM trips              WHERE DATE(start_time) LIKE CONCAT(?,'%')) AS total_km,
      (SELECT COUNT(*)                      FROM driver_tasks        WHERE status NOT IN ('completed','cancelled')) AS open_tasks,
      (SELECT COUNT(*)                      FROM vehicles            WHERE status='active') AS active_vehicles
");
$_tStmt->execute([$month, $month, $month]);
$totals = $_tStmt->fetch();

// ── أحدث صيانة لكل مركبة ──────────────────────────────────────────
$lastMaint = [];
foreach ($db->query("
    SELECT vehicle_id, MAX(performed_at) AS last_date
    FROM vehicle_maintenance GROUP BY vehicle_id
") as $r) {
    $lastMaint[$r['vehicle_id']] = $r['last_date'];
}

// ── المهام المفتوحة حسب السائق ────────────────────────────────────
$openTasks = $db->query("
    SELECT t.*, v.name AS vname, e.name AS ename
    FROM driver_tasks t
    LEFT JOIN vehicles  v ON v.id = t.vehicle_id
    LEFT JOIN employees e ON e.id = t.driver_id
    WHERE t.status NOT IN ('completed','cancelled')
    ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.due_date ASC
    LIMIT 30
")->fetchAll();

// ── سجل الوقود — آخر 15 سجل ──────────────────────────────────────
$fuelLog = $db->query("
    SELECT f.*, v.name AS vname, e.name AS ename
    FROM vehicle_fuel_log f
    LEFT JOIN vehicles  v ON v.id = f.vehicle_id
    LEFT JOIN employees e ON e.id = f.driver_id
    ORDER BY f.log_date DESC, f.id DESC
    LIMIT 15
")->fetchAll();

// ── سجل الصيانة — آخر 10 ─────────────────────────────────────────
$maintLog = $db->query("
    SELECT m.*, v.name AS vname
    FROM vehicle_maintenance m
    LEFT JOIN vehicles v ON v.id = m.vehicle_id
    ORDER BY m.performed_at DESC, m.id DESC
    LIMIT 10
")->fetchAll();

// ── دالة لون حالة الجيوفنس ────────────────────────────────────────
function serviceColor(string $s): string {
    return match($s) {
        'overdue' => 'red',
        'soon'    => 'amber',
        default   => 'green',
    };
}
function serviceLabel(string $s): string {
    return match($s) {
        'overdue' => 'صيانة متأخرة',
        'soon'    => 'صيانة قريباً',
        default   => 'بحالة جيدة',
    };
}
function taskColor(string $p): string {
    return match($p) {
        'urgent' => 'red',
        'high'   => 'amber',
        'normal' => 'blue',
        default  => 'green',
    };
}
function taskPriorityAr(string $p): string {
    return match($p) {
        'urgent' => 'عاجل',
        'high'   => 'عالي',
        'normal' => 'عادي',
        default  => 'منخفض',
    };
}
function taskStatusAr(string $s): string {
    return match($s) {
        'pending'     => 'معلّق',
        'accepted'    => 'مقبول',
        'in_progress' => 'قيد التنفيذ',
        'completed'   => 'مكتمل',
        'cancelled'   => 'ملغي',
        default       => $s,
    };
}
function maintTypeAr(string $t): string {
    return match($t) {
        'oil_change'  => 'تغيير زيت',
        'tire'        => 'إطارات',
        'brake'       => 'فرامل',
        'filter'      => 'فلتر',
        'battery'     => 'بطارية',
        'inspection'  => 'فحص دوري',
        default       => 'أخرى',
    };
}

// ── موظفون ذوو دور driver لقائمة المهام ──────────────────────────
$drivers = $db->query("SELECT id,name FROM employees WHERE role='driver' ORDER BY name")->fetchAll();
$allVehicles = $db->query("SELECT id,name,plate_number FROM vehicles ORDER BY name")->fetchAll();

$GMAPS = defined('GMAPS_API_KEY') ? GMAPS_API_KEY : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الأسطول') ?>
<style>
<?= getThemeCSS() ?>

/* ── تبويبات ── */
.tabs{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin-bottom:14px;padding-bottom:4px}
.tabs::-webkit-scrollbar{display:none}
.tab{flex-shrink:0;padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid var(--border);background:var(--surface2);color:var(--text2);font-family:inherit;transition:.15s}
.tab.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* ── خريطة ── */
#map-frame{width:100%;height:340px;border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;background:var(--surface2)}

/* ── بطاقة مركبة ── */
.v-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:10px}
.v-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.v-icon{width:40px;height:40px;border-radius:10px;background:var(--gold-bg);border:1px solid rgba(240,192,64,.3);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.v-name{font-size:15px;font-weight:800;color:var(--text)}
.v-plate{font-size:11px;color:var(--text3);margin-top:2px;font-variant-numeric:tabular-nums}
.v-badges{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px}
.v-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}

/* ── صف الوقود ── */
.fuel-row{display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);padding:10px 0;font-size:13px}
.fuel-row:last-child{border-bottom:none;padding-bottom:0}
.fuel-ico{font-size:20px;flex-shrink:0}
.fuel-info{flex:1}
.fuel-title{font-weight:700;color:var(--text)}
.fuel-sub{font-size:11px;color:var(--text3);margin-top:2px}
.fuel-cost{font-size:15px;font-weight:800;color:var(--amber);white-space:nowrap}

/* ── صف صيانة ── */
.maint-row{display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);padding:10px 0;font-size:13px}
.maint-row:last-child{border-bottom:none;padding-bottom:0}

/* ── صف مهمة ── */
.task-row{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:8px}
.task-row:last-child{margin-bottom:0}
.task-head{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.task-title{font-weight:700;font-size:14px;flex:1}
.task-meta{font-size:12px;color:var(--text3);display:flex;gap:8px;flex-wrap:wrap}

/* ── تنبيهات ── */
.alert-row{display:flex;align-items:flex-start;gap:10px;border-bottom:1px solid var(--border);padding:10px 0;font-size:13px}
.alert-row:last-child{border-bottom:none;padding-bottom:0}
.alert-ico{font-size:20px;flex-shrink:0;margin-top:1px}

/* ── أزرار إضافة ── */
.fab{position:fixed;bottom:calc(var(--safe-bot)+20px);left:18px;width:52px;height:52px;border-radius:50%;background:var(--blue);color:#fff;font-size:24px;border:none;cursor:pointer;box-shadow:0 4px 18px rgba(79,142,247,.45);display:flex;align-items:center;justify-content:center;z-index:50}
.fab:active{transform:scale(.93)}

/* ── شبكة الإجماليات ── */
.total-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.total-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.total-val{font-size:24px;font-weight:800;line-height:1.1}
.total-lbl{font-size:11px;color:var(--text3);margin-top:4px}

/* ── حقل الموقع ── */
.loc-row{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text3);margin-top:6px}
.loc-row a{color:var(--blue);text-decoration:none;font-weight:600}

@media(min-width:768px){
  #map-frame{height:460px}
  .total-grid{grid-template-columns:repeat(4,1fr)}
  .v-stats{grid-template-columns:repeat(5,1fr)}
}
</style>
</head>
<body>
<?= getTopbar('الأسطول') ?>

<div class="page-wrap">

  <!-- ── إجماليات الشهر ── -->
  <div class="total-grid">
    <div class="total-item">
      <div class="total-val g"><?= count($vehicles) ?></div>
      <div class="total-lbl">المركبات</div>
    </div>
    <div class="total-item">
      <div class="total-val gd"><?= $totals['active_vehicles'] ?></div>
      <div class="total-lbl">نشطة الآن</div>
    </div>
    <div class="total-item">
      <div class="total-val a"><?= number_format($totals['total_fuel'] + $totals['total_maint']) ?> ش</div>
      <div class="total-lbl">مصروفات الشهر</div>
    </div>
    <div class="total-item">
      <div class="total-val b"><?= number_format($totals['total_km'], 0) ?> كم</div>
      <div class="total-lbl">كيلومترات الشهر</div>
    </div>
  </div>

  <?php if($fleetAlerts): ?>
  <div class="notice n-red" style="margin-bottom:10px">
    🚨 يوجد <?= count($fleetAlerts) ?> تنبيه غير معالج في الأسطول
  </div>
  <?php endif; ?>

  <!-- ── تبويبات ── -->
  <div class="tabs">
    <button class="tab active" onclick="switchTab('map')">🗺️ الخريطة</button>
    <button class="tab" onclick="switchTab('vehicles')">🚗 المركبات</button>
    <button class="tab" onclick="switchTab('tasks')">📋 المهام <span id="tasks-badge" style="background:var(--red);color:#fff;border-radius:9px;padding:1px 7px;font-size:11px"><?= count($openTasks) ?></span></button>
    <button class="tab" onclick="switchTab('fuel')">⛽ الوقود</button>
    <button class="tab" onclick="switchTab('maintenance')">🔧 الصيانة</button>
    <button class="tab" onclick="switchTab('alerts')">🔔 التنبيهات</button>
  </div>

  <!-- ══ تبويب الخريطة ══ -->
  <div id="tab-map" class="tab-panel active">
    <div id="map-frame">
      <div id="gmap" style="width:100%;height:100%"></div>
    </div>
    <div style="margin-top:8px;font-size:12px;color:var(--text3);text-align:center">تحديث تلقائي كل 30 ثانية — الموقع الأخير المسجّل لكل مركبة</div>

    <!-- قائمة سريعة للمركبات على الخريطة -->
    <div style="margin-top:12px">
      <?php foreach($vehicles as $v): ?>
        <?php if($v['last_lat']): ?>
        <div class="v-card" style="padding:10px 14px;display:flex;align-items:center;gap:10px;cursor:pointer" onclick="panTo(<?= $v['last_lat'] ?>, <?= $v['last_lng'] ?>)">
          <span style="font-size:22px">🚗</span>
          <div style="flex:1">
            <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($v['name']) ?> <span style="color:var(--text3);font-size:11px"><?= $v['plate_number'] ?></span></div>
            <div style="font-size:11px;color:var(--text3)">
              <?= $v['driver_name'] ? 'السائق: '.htmlspecialchars($v['driver_name']).' — ' : '' ?>
              آخر ظهور: <?= $v['last_seen'] ? date('h:i a', strtotime($v['last_seen'])) : 'غير معروف' ?>
            </div>
          </div>
          <span class="dot <?= $v['status']==='active' ? 'dg' : ($v['status']==='alert' ? 'da' : 'dgr') ?>"></span>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if(!array_filter($vehicles, fn($v)=>$v['last_lat'])): ?>
        <div style="text-align:center;padding:20px;color:var(--text3);font-size:13px">لم يُسجَّل موقع لأي مركبة بعد</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ تبويب المركبات ══ -->
  <div id="tab-vehicles" class="tab-panel">
    <?php foreach($vehicles as $v): ?>
    <div class="v-card">
      <div class="v-head">
        <div class="v-icon">🚗</div>
        <div style="flex:1">
          <div class="v-name"><?= htmlspecialchars($v['name']) ?></div>
          <div class="v-plate">📋 <?= htmlspecialchars($v['plate_number'] ?? 'بدون لوحة') ?> <?= $v['model'] ? '· '.htmlspecialchars($v['model']) : '' ?> <?= $v['year'] ? $v['year'] : '' ?></div>
        </div>
        <span class="badge b-<?= $v['status']==='active' ? 'green' : ($v['status']==='alert' ? 'red' : 'gold') ?>">
          <?= $v['status']==='active' ? 'نشطة' : ($v['status']==='alert' ? 'تنبيه' : 'خاملة') ?>
        </span>
      </div>

      <div class="v-badges">
        <span class="badge b-<?= serviceColor($v['service_status']) ?>"><?= serviceLabel($v['service_status']) ?></span>
        <?php if($v['geofence_active']): ?>
          <span class="badge b-blue">📍 جيوفنس نشط</span>
        <?php endif; ?>
        <?php if($v['active_tasks'] > 0): ?>
          <span class="badge b-amber">📋 <?= $v['active_tasks'] ?> مهمة</span>
        <?php endif; ?>
        <?php if($v['driver_name']): ?>
          <span class="badge b-gold">👤 <?= htmlspecialchars($v['driver_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="v-stats">
        <div class="sbox">
          <div class="slabel">عداد الكم</div>
          <div class="sval g" style="font-size:16px"><?= number_format($v['odometer_km']) ?></div>
        </div>
        <div class="sbox">
          <div class="slabel">وقود الشهر</div>
          <div class="sval a" style="font-size:15px"><?= number_format($v['month_fuel']) ?></div>
        </div>
        <div class="sbox">
          <div class="slabel">صيانة الشهر</div>
          <div class="sval r" style="font-size:15px"><?= number_format($v['month_maint']) ?></div>
        </div>
        <div class="sbox">
          <div class="slabel">كم الشهر</div>
          <div class="sval b" style="font-size:15px"><?= number_format($v['month_km'], 0) ?></div>
        </div>
        <div class="sbox">
          <div class="slabel">آخر صيانة</div>
          <div class="sval gd" style="font-size:13px"><?= isset($lastMaint[$v['id']]) ? date('d/m', strtotime($lastMaint[$v['id']])) : '—' ?></div>
        </div>
      </div>

      <!-- خط الصيانة القادمة -->
      <?php if($v['next_service_date'] || $v['next_service_km']): ?>
      <div class="notice n-<?= serviceColor($v['service_status']) ?>" style="margin-top:10px;margin-bottom:0">
        🔧 الصيانة القادمة:
        <?= $v['next_service_date'] ? date('Y/m/d', strtotime($v['next_service_date'])) : '' ?>
        <?= $v['next_service_km']  ? ' أو عند '.number_format($v['next_service_km']).' كم' : '' ?>
      </div>
      <?php endif; ?>

      <!-- تأمين/ترخيص -->
      <?php
        $insExpired = $v['insurance_expiry'] && $v['insurance_expiry'] < $today;
        $licExpired = $v['license_expiry']   && $v['license_expiry']  < $today;
        $insSoon    = $v['insurance_expiry'] && !$insExpired && $v['insurance_expiry'] <= date('Y-m-d', strtotime('+30 days'));
      ?>
      <?php if($insExpired || $licExpired): ?>
        <div class="notice n-red" style="margin-top:8px;margin-bottom:0">
          ❌ <?= $insExpired ? 'التأمين منتهي ' : '' ?><?= $licExpired ? 'الترخيص منتهي' : '' ?>
        </div>
      <?php elseif($insSoon): ?>
        <div class="notice n-amber" style="margin-top:8px;margin-bottom:0">
          ⚠️ التأمين ينتهي <?= date('d/m/Y', strtotime($v['insurance_expiry'])) ?>
        </div>
      <?php endif; ?>

      <!-- أزرار الإجراءات -->
      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="btn btn-ghost" style="padding:8px 0;font-size:12px"
                onclick="openAddFuel(<?= $v['id'] ?>, '<?= addslashes($v['name']) ?>')">⛽ وقود</button>
        <button class="btn btn-ghost" style="padding:8px 0;font-size:12px"
                onclick="openAddMaint(<?= $v['id'] ?>, '<?= addslashes($v['name']) ?>')">🔧 صيانة</button>
        <button class="btn btn-ghost" style="padding:8px 0;font-size:12px"
                onclick="openGeofence(<?= $v['id'] ?>, '<?= addslashes($v['name']) ?>', <?= $v['geofence_lat']?:0 ?>, <?= $v['geofence_lng']?:0 ?>, <?= $v['geofence_radius_m']?:1000 ?>, <?= $v['geofence_active'] ?>)">📍 جيوفنس</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(!$vehicles): ?>
      <div style="text-align:center;padding:30px;color:var(--text3)">لا توجد مركبات مسجّلة</div>
    <?php endif; ?>
  </div>

  <!-- ══ تبويب المهام ══ -->
  <div id="tab-tasks" class="tab-panel">
    <button class="btn btn-blue" onclick="openAddTask()" style="margin-bottom:14px">+ إضافة مهمة جديدة</button>
    <?php foreach($openTasks as $t): ?>
    <div class="task-row">
      <div class="task-head">
        <span class="badge b-<?= taskColor($t['priority']) ?>" style="font-size:11px"><?= taskPriorityAr($t['priority']) ?></span>
        <div class="task-title"><?= htmlspecialchars($t['title']) ?></div>
        <span class="badge b-<?= $t['status']==='in_progress'?'green':($t['status']==='accepted'?'blue':'gold') ?>" style="font-size:10px">
          <?= taskStatusAr($t['status']) ?>
        </span>
      </div>
      <div class="task-meta">
        <?php if($t['ename']): ?><span>👤 <?= htmlspecialchars($t['ename']) ?></span><?php endif; ?>
        <?php if($t['vname']): ?><span>🚗 <?= htmlspecialchars($t['vname']) ?></span><?php endif; ?>
        <?php if($t['destination']): ?><span>📍 <?= htmlspecialchars($t['destination']) ?></span><?php endif; ?>
        <?php if($t['due_date']): ?><span>📅 <?= date('d/m', strtotime($t['due_date'])) ?><?= $t['due_time'] ? ' '.date('h:i a', strtotime($t['due_time'])) : '' ?></span><?php endif; ?>
      </div>
      <?php if($t['description']): ?>
        <div style="font-size:12px;color:var(--text3);margin-top:6px"><?= htmlspecialchars($t['description']) ?></div>
      <?php endif; ?>
      <?php if($t['driver_note']): ?>
        <div class="notice n-blue" style="margin-top:6px;margin-bottom:0;font-size:12px">💬 ملاحظة السائق: <?= htmlspecialchars($t['driver_note']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if(!$openTasks): ?>
      <div style="text-align:center;padding:30px;color:var(--text3)">لا توجد مهام مفتوحة حالياً ✓</div>
    <?php endif; ?>
  </div>

  <!-- ══ تبويب الوقود ══ -->
  <div id="tab-fuel" class="tab-panel">
    <div class="total-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
      <div class="total-item">
        <div class="total-val a"><?= number_format($totals['total_fuel']) ?> ش</div>
        <div class="total-lbl">إجمالي وقود الشهر</div>
      </div>
      <div class="total-item">
        <div class="total-val b"><?= number_format($totals['total_km'], 0) ?> كم</div>
        <div class="total-lbl">كيلومترات الشهر</div>
      </div>
    </div>
    <div class="card">
      <div class="card-title">⛽ سجل التزويد — آخر 15 سجل</div>
      <?php foreach($fuelLog as $f): ?>
      <div class="fuel-row">
        <div class="fuel-ico">⛽</div>
        <div class="fuel-info">
          <div class="fuel-title"><?= htmlspecialchars($f['vname'] ?? '—') ?></div>
          <div class="fuel-sub">
            <?= $f['fuel_liters'] ? $f['fuel_liters'].' لتر' : '' ?>
            <?= $f['station_name'] ? ' · '.htmlspecialchars($f['station_name']) : '' ?>
            <?= $f['ename'] ? ' · '.htmlspecialchars($f['ename']) : '' ?>
            — <?= date('d/m/Y', strtotime($f['log_date'])) ?>
          </div>
        </div>
        <div class="fuel-cost"><?= number_format($f['fuel_cost']) ?> ش</div>
      </div>
      <?php endforeach; ?>
      <?php if(!$fuelLog): ?><div style="text-align:center;padding:18px;color:var(--text3);font-size:13px">لا يوجد سجل وقود بعد</div><?php endif; ?>
    </div>
  </div>

  <!-- ══ تبويب الصيانة ══ -->
  <div id="tab-maintenance" class="tab-panel">
    <div class="total-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
      <div class="total-item">
        <div class="total-val r"><?= number_format($totals['total_maint']) ?> ش</div>
        <div class="total-lbl">مصروفات صيانة الشهر</div>
      </div>
      <div class="total-item">
        <div class="total-val a"><?= array_sum(array_column(array_filter($vehicles, fn($v)=>$v['service_status']!=='ok'), 'id')) ? count(array_filter($vehicles, fn($v)=>$v['service_status']!=='ok')) : 0 ?></div>
        <div class="total-lbl">مركبات تحتاج صيانة</div>
      </div>
    </div>

    <!-- تحذيرات الصيانة -->
    <?php foreach($vehicles as $v): ?>
      <?php if($v['service_status'] !== 'ok'): ?>
      <div class="notice n-<?= serviceColor($v['service_status']) ?>" style="margin-bottom:8px">
        🔧 <?= htmlspecialchars($v['name']) ?> — <?= serviceLabel($v['service_status']) ?>
        <?= $v['next_service_date'] ? ' ('+date('d/m/Y', strtotime($v['next_service_date'])).')' : '' ?>
        <button style="margin-right:auto;background:none;border:none;cursor:pointer;color:inherit;font-size:12px;font-weight:700;font-family:inherit"
                onclick="openAddMaint(<?=$v['id']?>,'<?=addslashes($v['name'])?>')">تسجيل ←</button>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <div class="card">
      <div class="card-title">🔧 سجل الصيانة — آخر 10</div>
      <?php foreach($maintLog as $m): ?>
      <div class="maint-row">
        <span style="font-size:20px">🔧</span>
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($m['vname']??'—') ?> — <?= maintTypeAr($m['maintenance_type']) ?></div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">
            <?= date('d/m/Y', strtotime($m['performed_at'])) ?>
            <?= $m['odometer_km'] ? ' · '.number_format($m['odometer_km']).' كم' : '' ?>
            <?= $m['performed_by'] ? ' · '.htmlspecialchars($m['performed_by']) : '' ?>
          </div>
          <?php if($m['description']): ?>
          <div style="font-size:12px;color:var(--text2);margin-top:3px"><?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>
        <div style="font-size:15px;font-weight:800;color:var(--red);white-space:nowrap"><?= number_format($m['cost']) ?> ش</div>
      </div>
      <?php endforeach; ?>
      <?php if(!$maintLog): ?><div style="text-align:center;padding:18px;color:var(--text3);font-size:13px">لا يوجد سجل صيانة بعد</div><?php endif; ?>
    </div>
  </div>

  <!-- ══ تبويب التنبيهات ══ -->
  <div id="tab-alerts" class="tab-panel">
    <?php if(!$fleetAlerts): ?>
      <div style="text-align:center;padding:30px;color:var(--text3)">✅ لا توجد تنبيهات غير معالجة</div>
    <?php else: ?>
    <div class="card">
      <?php foreach($fleetAlerts as $a): ?>
      <?php
        $ico = match($a['type']) {
          'geofence_exit'  => '🚨',
          'maintenance_due'=> '🔧',
          'low_fuel'       => '⛽',
          'task_overdue'   => '📋',
          default          => '⚠️',
        };
        $color = match($a['type']) {
          'geofence_exit'  => 'red',
          'maintenance_due'=> 'amber',
          'low_fuel'       => 'amber',
          'task_overdue'   => 'red',
          default          => 'amber',
        };
      ?>
      <div class="alert-row">
        <div class="alert-ico"><?= $ico ?></div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px;color:var(--<?=$color?>)"><?= htmlspecialchars($a['message']) ?></div>
          <div style="font-size:11px;color:var(--text3);margin-top:3px">
            <?= $a['vname'] ? '🚗 '.htmlspecialchars($a['vname']).' ' : '' ?>
            <?= $a['ename'] ? '👤 '.htmlspecialchars($a['ename']).' ' : '' ?>
            — <?= date('d/m h:i a', strtotime($a['created_at'])) ?>
          </div>
        </div>
        <button style="background:none;border:1.5px solid var(--border);border-radius:8px;padding:6px 10px;color:var(--text3);font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;flex-shrink:0"
                onclick="dismissAlert(<?= $a['id'] ?>, this)">تجاهل</button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /page-wrap -->

<!-- ══ مودال: إضافة وقود ══ -->
<div class="modal-bg" id="modal-fuel">
  <div class="modal-box">
    <h3>⛽ تسجيل وقود</h3>
    <input type="hidden" id="fuel-vid">
    <div class="field"><label>المركبة</label><input type="text" id="fuel-vname" readonly></div>
    <div class="field"><label>التاريخ</label><input type="date" id="fuel-date" value="<?= $today ?>"></div>
    <div class="field"><label>الكميّة (لتر)</label><input type="number" id="fuel-liters" placeholder="مثال: 40"></div>
    <div class="field"><label>التكلفة (شيكل)</label><input type="number" id="fuel-cost" placeholder="مثال: 250"></div>
    <div class="field"><label>محطة الوقود</label><input type="text" id="fuel-station" placeholder="اختياري"></div>
    <div class="field"><label>عداد الكيلومترات</label><input type="number" id="fuel-odometer" placeholder="اختياري"></div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeMod('modal-fuel')">إلغاء</button>
      <button class="mbtn ms" onclick="saveFuel()">حفظ</button>
    </div>
  </div>
</div>

<!-- ══ مودال: إضافة صيانة ══ -->
<div class="modal-bg" id="modal-maint">
  <div class="modal-box">
    <h3>🔧 تسجيل صيانة</h3>
    <input type="hidden" id="maint-vid">
    <div class="field"><label>المركبة</label><input type="text" id="maint-vname" readonly></div>
    <div class="field"><label>نوع الصيانة</label>
      <select id="maint-type">
        <option value="oil_change">تغيير زيت</option>
        <option value="tire">إطارات</option>
        <option value="brake">فرامل</option>
        <option value="filter">فلتر</option>
        <option value="battery">بطارية</option>
        <option value="inspection">فحص دوري</option>
        <option value="other">أخرى</option>
      </select>
    </div>
    <div class="field"><label>الوصف</label><textarea id="maint-desc" placeholder="ما الذي تم؟"></textarea></div>
    <div class="field"><label>التكلفة (شيكل)</label><input type="number" id="maint-cost" placeholder="0"></div>
    <div class="field"><label>عداد الكيلومترات</label><input type="number" id="maint-odo" placeholder="اختياري"></div>
    <div class="field"><label>تاريخ الصيانة القادمة</label><input type="date" id="maint-next-date"></div>
    <div class="field"><label>كم الصيانة القادمة</label><input type="number" id="maint-next-km" placeholder="اختياري"></div>
    <div class="field"><label>المنفّذ (ورشة / ميكانيكي)</label><input type="text" id="maint-by" placeholder="اختياري"></div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeMod('modal-maint')">إلغاء</button>
      <button class="mbtn ms" onclick="saveMaint()">حفظ</button>
    </div>
  </div>
</div>

<!-- ══ مودال: جيوفنس ══ -->
<div class="modal-bg" id="modal-geo">
  <div class="modal-box">
    <h3>📍 إعداد الجيوفنس</h3>
    <input type="hidden" id="geo-vid">
    <div class="field"><label>المركبة</label><input type="text" id="geo-vname" readonly></div>
    <div class="field"><label>خط العرض (lat)</label><input type="number" id="geo-lat" step="0.000001" placeholder="مثال: 31.5"></div>
    <div class="field"><label>خط الطول (lng)</label><input type="number" id="geo-lng" step="0.000001" placeholder="مثال: 35.0"></div>
    <div class="field"><label>نصف القطر (متر)</label><input type="number" id="geo-radius" placeholder="مثال: 1000"></div>
    <div class="field" style="display:flex;align-items:center;gap:10px">
      <input type="checkbox" id="geo-active" style="width:18px;height:18px">
      <label for="geo-active" style="margin-bottom:0;font-size:13px">تفعيل الجيوفنس</label>
    </div>
    <div class="notice n-blue" style="font-size:12px">💡 اضغط على الخريطة لتحديد المركز تلقائياً</div>
    <div id="geo-mini-map" style="width:100%;height:200px;border-radius:10px;border:1px solid var(--border);margin:8px 0;overflow:hidden"></div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeMod('modal-geo')">إلغاء</button>
      <button class="mbtn ms" onclick="saveGeo()">حفظ</button>
    </div>
  </div>
</div>

<!-- ══ مودال: إضافة مهمة ══ -->
<div class="modal-bg" id="modal-task">
  <div class="modal-box">
    <h3>📋 إضافة مهمة</h3>
    <div class="field"><label>العنوان</label><input type="text" id="task-title" placeholder="وصف المهمة"></div>
    <div class="field"><label>السائق</label>
      <select id="task-driver">
        <option value="">— اختر سائق —</option>
        <?php foreach($drivers as $d): ?>
          <option value="<?=$d['id']?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>المركبة</label>
      <select id="task-vehicle">
        <option value="">— اختياري —</option>
        <?php foreach($allVehicles as $v): ?>
          <option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?> (<?= $v['plate_number'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>الوجهة</label><input type="text" id="task-dest" placeholder="العنوان أو الموقع"></div>
    <div class="field"><label>الأولوية</label>
      <select id="task-priority">
        <option value="normal">عادي</option>
        <option value="high">عالي</option>
        <option value="urgent">عاجل</option>
        <option value="low">منخفض</option>
      </select>
    </div>
    <div class="field"><label>تاريخ الاستحقاق</label><input type="date" id="task-date" value="<?= $today ?>"></div>
    <div class="field"><label>الوقت</label><input type="time" id="task-time"></div>
    <div class="field"><label>ملاحظات</label><textarea id="task-desc" placeholder="تفاصيل إضافية"></textarea></div>
    <div class="mbtns">
      <button class="mbtn mc" onclick="closeMod('modal-task')">إلغاء</button>
      <button class="mbtn ms" onclick="saveTask()">إضافة</button>
    </div>
  </div>
</div>

<?php if($GMAPS): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($GMAPS) ?>&callback=initMap" async defer></script>
<?php endif; ?>

<script>
// ── بيانات المركبات ────────────────────────────────────────────────
const vehicles = <?= json_encode(array_values($vehicles)) ?>;

// ── الخريطة ───────────────────────────────────────────────────────
let map, markers = {}, geoMap, geoCircle, geoMarker;

function initMap() {
  // وسّط الخريطة على الموقع الأول المتاح أو المنطقة الافتراضية
  let center = {lat: 31.5, lng: 35.0};
  vehicles.forEach(v => { if(v.last_lat) center = {lat: parseFloat(v.last_lat), lng: parseFloat(v.last_lng)}; });

  map = new google.maps.Map(document.getElementById('gmap'), {
    center, zoom: 12,
    styles: darkMapStyle(),
    disableDefaultUI: true,
    zoomControl: true
  });
  vehicles.forEach(addMarker);

  // تحديث كل 30 ثانية
  setInterval(refreshLocations, 30000);
}

function addMarker(v) {
  if (!v.last_lat) return;
  const icon = v.status === 'active'
    ? 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
    : (v.status === 'alert' ? 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
                             : 'http://maps.google.com/mapfiles/ms/icons/yellow-dot.png');
  const m = new google.maps.Marker({
    position: {lat: parseFloat(v.last_lat), lng: parseFloat(v.last_lng)},
    map,
    title: v.name + (v.plate_number ? ' ('+v.plate_number+')' : ''),
    icon
  });
  const info = new google.maps.InfoWindow({
    content: `<div dir="rtl" style="font-family:Tahoma;font-size:12px;min-width:140px">
      <b>${v.name}</b><br>
      🚗 ${v.plate_number||'بدون لوحة'}<br>
      ${v.driver_name ? '👤 '+v.driver_name+'<br>' : ''}
      ${v.last_seen ? '⏱️ '+new Date(v.last_seen).toLocaleTimeString('ar') : ''}
    </div>`
  });
  m.addListener('click', () => info.open(map, m));
  markers[v.id] = m;
}

function panTo(lat, lng) {
  if (!map) return;
  map.panTo({lat: parseFloat(lat), lng: parseFloat(lng)});
  map.setZoom(15);
  switchTab('map');
}

async function refreshLocations() {
  try {
    const r = await fetch('api/live_data.php?fleet=1');
    const d = await r.json();
    if (d.vehicles) {
      d.vehicles.forEach(v => {
        if (!v.lat || !markers[v.id]) return;
        markers[v.id].setPosition({lat: parseFloat(v.lat), lng: parseFloat(v.lng)});
      });
    }
  } catch(e){}
}

function darkMapStyle() {
  return [
    {elementType:'geometry',stylers:[{color:'#1d2c3e'}]},
    {elementType:'labels.text.fill',stylers:[{color:'#8ec3b9'}]},
    {elementType:'labels.text.stroke',stylers:[{color:'#1a3646'}]},
    {featureType:'road',elementType:'geometry',stylers:[{color:'#304a57'}]},
    {featureType:'water',elementType:'geometry',stylers:[{color:'#0e1626'}]},
    {featureType:'poi',stylers:[{visibility:'off'}]}
  ];
}

// ── تبويبات ──────────────────────────────────────────────────────
function switchTab(id) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  // زر التبويب: يتطابق من خلال onclick
  const btns = document.querySelectorAll('.tab');
  btns.forEach(b => { if(b.getAttribute('onclick')===`switchTab('${id}')`) b.classList.add('active'); });
  if(id==='map' && typeof initMap==='function' && !map) initMap();
}

// ── فتح/إغلاق المودالات ──────────────────────────────────────────
function openMod(id)  { document.getElementById(id).classList.add('open'); }
function closeMod(id) { document.getElementById(id).classList.remove('open'); }

// ── وقود ─────────────────────────────────────────────────────────
function openAddFuel(vid, vname) {
  document.getElementById('fuel-vid').value   = vid;
  document.getElementById('fuel-vname').value = vname;
  document.getElementById('fuel-liters').value = '';
  document.getElementById('fuel-cost').value   = '';
  document.getElementById('fuel-station').value= '';
  document.getElementById('fuel-odometer').value='';
  openMod('modal-fuel');
}

async function saveFuel() {
  const fd = new FormData();
  fd.append('action','add_fuel');
  fd.append('vehicle_id', document.getElementById('fuel-vid').value);
  fd.append('log_date',   document.getElementById('fuel-date').value);
  fd.append('fuel_liters',document.getElementById('fuel-liters').value);
  fd.append('fuel_cost',  document.getElementById('fuel-cost').value);
  fd.append('station_name',document.getElementById('fuel-station').value);
  fd.append('odometer_km',document.getElementById('fuel-odometer').value);
  try {
    const r = await fetch('api/fleet_api.php?action=add_fuel', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){ closeMod('modal-fuel'); showToast('✅ تم تسجيل الوقود'); setTimeout(()=>location.reload(),800); }
    else showToast('❌ '+d.error, true);
  } catch(e){ showToast('خطأ في الاتصال', true); }
}

// ── صيانة ────────────────────────────────────────────────────────
function openAddMaint(vid, vname) {
  document.getElementById('maint-vid').value   = vid;
  document.getElementById('maint-vname').value = vname;
  document.getElementById('maint-desc').value  = '';
  document.getElementById('maint-cost').value  = '';
  document.getElementById('maint-odo').value   = '';
  document.getElementById('maint-next-date').value='';
  document.getElementById('maint-next-km').value='';
  document.getElementById('maint-by').value    = '';
  openMod('modal-maint');
}

async function saveMaint() {
  const fd = new FormData();
  fd.append('action',           'add_maintenance');
  fd.append('vehicle_id',       document.getElementById('maint-vid').value);
  fd.append('maintenance_type', document.getElementById('maint-type').value);
  fd.append('description',      document.getElementById('maint-desc').value);
  fd.append('cost',             document.getElementById('maint-cost').value);
  fd.append('odometer_km',      document.getElementById('maint-odo').value);
  fd.append('next_service_date',document.getElementById('maint-next-date').value);
  fd.append('next_service_km',  document.getElementById('maint-next-km').value);
  fd.append('performed_by',     document.getElementById('maint-by').value);
  try {
    const r = await fetch('api/fleet_api.php?action=add_maintenance', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){ closeMod('modal-maint'); showToast('✅ تم تسجيل الصيانة'); setTimeout(()=>location.reload(),800); }
    else showToast('❌ '+d.error, true);
  } catch(e){ showToast('خطأ في الاتصال', true); }
}

// ── جيوفنس ───────────────────────────────────────────────────────
function openGeofence(vid, vname, lat, lng, radius, active) {
  document.getElementById('geo-vid').value    = vid;
  document.getElementById('geo-vname').value  = vname;
  document.getElementById('geo-lat').value    = lat || '';
  document.getElementById('geo-lng').value    = lng || '';
  document.getElementById('geo-radius').value = radius || 1000;
  document.getElementById('geo-active').checked = !!active;
  openMod('modal-geo');
  // خريطة مصغّرة داخل المودال
  setTimeout(() => {
    const center = lat ? {lat: parseFloat(lat), lng: parseFloat(lng)} : {lat: 31.5, lng: 35.0};
    geoMap = new google.maps.Map(document.getElementById('geo-mini-map'), {
      center, zoom: 13, styles: darkMapStyle(),
      disableDefaultUI: true, zoomControl: true
    });
    geoMarker = new google.maps.Marker({position: center, map: geoMap});
    geoCircle = new google.maps.Circle({
      map: geoMap, center, radius: parseFloat(radius)||1000,
      fillColor:'#4f8ef7', fillOpacity:.15, strokeColor:'#4f8ef7', strokeWeight:2
    });
    geoMap.addListener('click', e => {
      const la = e.latLng.lat(), ln = e.latLng.lng();
      document.getElementById('geo-lat').value = la.toFixed(6);
      document.getElementById('geo-lng').value = ln.toFixed(6);
      geoMarker.setPosition(e.latLng);
      geoCircle.setCenter(e.latLng);
    });
    document.getElementById('geo-radius').addEventListener('input', e => {
      geoCircle.setRadius(parseFloat(e.target.value)||0);
    });
  }, 200);
}

async function saveGeo() {
  const fd = new FormData();
  fd.append('action',     'set_geofence');
  fd.append('vehicle_id', document.getElementById('geo-vid').value);
  fd.append('lat',        document.getElementById('geo-lat').value);
  fd.append('lng',        document.getElementById('geo-lng').value);
  fd.append('radius',     document.getElementById('geo-radius').value);
  fd.append('active',     document.getElementById('geo-active').checked ? 1 : 0);
  try {
    const r = await fetch('api/fleet_api.php?action=set_geofence', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){ closeMod('modal-geo'); showToast('✅ تم حفظ الجيوفنس'); setTimeout(()=>location.reload(),800); }
    else showToast('❌ '+d.error, true);
  } catch(e){ showToast('خطأ في الاتصال', true); }
}

// ── مهام ─────────────────────────────────────────────────────────
function openAddTask() { openMod('modal-task'); }

async function saveTask() {
  const fd = new FormData();
  fd.append('action',      'add_task');
  fd.append('title',       document.getElementById('task-title').value);
  fd.append('driver_id',   document.getElementById('task-driver').value);
  fd.append('vehicle_id',  document.getElementById('task-vehicle').value);
  fd.append('destination', document.getElementById('task-dest').value);
  fd.append('priority',    document.getElementById('task-priority').value);
  fd.append('due_date',    document.getElementById('task-date').value);
  fd.append('due_time',    document.getElementById('task-time').value);
  fd.append('description', document.getElementById('task-desc').value);
  try {
    const r = await fetch('api/fleet_api.php?action=add_task', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){ closeMod('modal-task'); showToast('✅ تمت إضافة المهمة'); setTimeout(()=>location.reload(),800); }
    else showToast('❌ '+d.error, true);
  } catch(e){ showToast('خطأ في الاتصال', true); }
}

// ── تجاهل تنبيه ──────────────────────────────────────────────────
async function dismissAlert(id, btn) {
  btn.disabled = true;
  const fd = new FormData(); fd.append('alert_id', id);
  await fetch('api/dismiss_alert.php', {method:'POST', body:fd});
  btn.closest('.alert-row').remove();
  showToast('تم تجاهل التنبيه');
}

// ── توست ─────────────────────────────────────────────────────────
function showToast(msg, err=false) {
  let t = document.createElement('div');
  t.textContent = msg;
  Object.assign(t.style, {
    position:'fixed', bottom:'80px', left:'50%', transform:'translateX(-50%)',
    background: err ? 'var(--red)' : 'var(--green)',
    color: err ? '#fff' : '#0a1f14',
    padding:'10px 20px', borderRadius:'20px', fontWeight:'700',
    fontSize:'13px', zIndex:'9999', boxShadow:'0 4px 14px rgba(0,0,0,.4)',
    whiteSpace:'nowrap'
  });
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2200);
}

// ── إغلاق المودال بالنقر خارجه ───────────────────────────────────
document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('click', e => { if(e.target===m) m.classList.remove('open'); });
});

// ── تهيئة الخريطة عند تحميل الصفحة إن لم تكن Google Maps متاحة ──
<?php if(!$GMAPS): ?>
window.initMap = function() {}; // placeholder
document.getElementById('gmap').innerHTML =
  '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text3);font-size:13px;flex-direction:column;gap:8px">' +
  '<span style="font-size:40px">🗺️</span>' +
  '<span>يحتاج عرض الخريطة إلى مفتاح Google Maps API</span>' +
  '<span style="font-size:11px">أضف GMAPS_API_KEY في ملف .env</span>' +
  '</div>';
<?php else: ?>
// Google Maps ستُهيَّأ عبر callback
<?php endif; ?>
</script>

</body>
</html>
