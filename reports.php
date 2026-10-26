<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireReportsAccess();

$db = getDB();
ensureOvertimeColumns($db);

$from       = $_GET['from'] ?? date('Y-m-01');
$to         = $_GET['to']   ?? date('Y-m-d');
$emp_filter = $_GET['emp']  ?? '';
$report_tab = $_GET['tab']  ?? 'individual';

$employees = $db->query("SELECT * FROM employees WHERE role IN ('employee','driver','hr') ORDER BY role, name")->fetchAll();

$sql = "SELECT a.*, e.name as emp_name, e.role, e.work_start, e.work_end
        FROM attendance a JOIN employees e ON e.id = a.employee_id
        WHERE a.date BETWEEN ? AND ?";
$params = [$from, $to];
if ($emp_filter) { $sql .= " AND a.employee_id=?"; $params[] = $emp_filter; }
$sql .= " ORDER BY a.employee_id, a.date DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$allAttendance = $stmt->fetchAll();

$sqlOT = "SELECT employee_id, request_date, ot_check_in, ot_check_out
          FROM overtime_requests
          WHERE request_date BETWEEN ? AND ? AND ot_check_in IS NOT NULL AND ot_check_out IS NOT NULL";
$paramsOT = [$from, $to];
if ($emp_filter) { $sqlOT .= " AND employee_id=?"; $paramsOT[] = $emp_filter; }
$stmtOT = $db->prepare($sqlOT); $stmtOT->execute($paramsOT);
$otByEmpDate = [];
foreach ($stmtOT->fetchAll() as $ot) {
    $mins = round((strtotime($ot['ot_check_out']) - strtotime($ot['ot_check_in'])) / 60);
    $key = $ot['employee_id'] . '|' . $ot['request_date'];
    $otByEmpDate[$key] = ($otByEmpDate[$key] ?? 0) + max(0, $mins);
}

$sqlN = "SELECT n.*, e.name as emp_name FROM employee_notes n
         JOIN employees e ON e.id = n.employee_id
         WHERE DATE(n.created_at) BETWEEN ? AND ?";
$paramsN = [$from, $to];
if ($emp_filter) { $sqlN .= " AND n.employee_id=?"; $paramsN[] = $emp_filter; }
$sqlN .= " ORDER BY n.created_at DESC";
$stmtN = $db->prepare($sqlN); $stmtN->execute($paramsN);
$allNotes = $stmtN->fetchAll();
$notesByEmp = [];
foreach ($allNotes as $n) { $notesByEmp[$n['employee_id']][] = $n; }

$sql2 = "SELECT t.*, e.name as emp_name, v.name as veh_name, v.plate_number
         FROM trips t JOIN employees e ON e.id = t.employee_id JOIN vehicles v ON v.id = t.vehicle_id
         WHERE DATE(t.start_time) BETWEEN ? AND ?";
$params2 = [$from, $to];
if ($emp_filter) { $sql2 .= " AND t.employee_id=?"; $params2[] = $emp_filter; }
$sql2 .= " ORDER BY t.employee_id, t.start_time DESC";
$stmt2 = $db->prepare($sql2); $stmt2->execute($params2);
$allTrips = $stmt2->fetchAll();

$tripsByDriver = [];
foreach ($allTrips as $t) {
    $id = $t['employee_id'];
    if (!isset($tripsByDriver[$id])) $tripsByDriver[$id] = ['name'=>$t['emp_name'],'veh'=>$t['veh_name'],'plate'=>$t['plate_number'],'trips'=>[],'km_inside'=>0,'km_outside'=>0,'total'=>0];
    $tripsByDriver[$id]['trips'][] = $t;
    $tripsByDriver[$id]['total']++;
    $tripsByDriver[$id]['km_inside']  += $t['km_inside']  ?? 0;
    $tripsByDriver[$id]['km_outside'] += $t['km_outside'] ?? 0;
}

$attendanceByEmp = [];
foreach ($allAttendance as $a) { $attendanceByEmp[$a['employee_id']][] = $a; }

$totalDays    = count($allAttendance);
$lateDays     = count(array_filter($allAttendance, fn($a)=>$a['status']==='late'));
$totalLateMin = array_sum(array_column(array_filter($allAttendance, fn($a)=>$a['status']==='late'), 'late_minutes'));
$totalKm      = array_sum(array_column($allTrips, 'distance_km'));
$outsideKm    = array_sum(array_column(array_filter($allTrips, fn($t)=>$t['outside_hours']), 'distance_km'));

function fmtMin($m) {
    if ($m <= 0) return '—';
    $h = floor($m/60); $r = $m%60;
    return $h > 0 ? $h.'س '.$r.'د' : $r.' د';
}

function calcOvertime($a, $otByEmpDate) {
    $key = $a['employee_id'] . '|' . $a['date'];
    return $otByEmpDate[$key] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('التقارير') ?>
<style>
/* جداول موبايل */
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tbl-wrap table{min-width:580px}
/* بطاقات موظف */
.emp-report{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;overflow:hidden}
.emp-hdr{background:linear-gradient(135deg,#1a2e1f,#2d4a3f);padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.emp-hdr-name{font-size:16px;font-weight:800;color:#fff}
.emp-hdr-sub{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px}
.emp-stats-grid{display:grid;grid-template-columns:repeat(2,1fr);border-bottom:1px solid var(--border)}
.emp-stats-5{grid-template-columns:repeat(3,1fr)}
@media(min-width:480px){.emp-stats-5{grid-template-columns:repeat(5,1fr)}}
@media(min-width:500px){.emp-stats-grid{grid-template-columns:repeat(4,1fr)}}
.emp-stat{padding:12px;text-align:center;border-left:1px solid var(--border)}
.emp-stat:nth-child(2n){border-left:none}
@media(min-width:500px){.emp-stat:nth-child(2n){border-left:1px solid var(--border)}.emp-stat:last-child{border-left:none}}
.es-n{font-size:20px;font-weight:800}
.es-l{font-size:10px;color:var(--text3);margin-top:2px}
.sec-body{padding:14px}
.sub-title{font-size:13px;font-weight:700;color:var(--text2);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
/* فلتر */
.filter-wrap{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.filter-wrap .field{flex:1;min-width:120px}
/* تابات */
.rtabs{display:flex;background:var(--surface2);border-radius:10px;overflow-x:auto;margin-bottom:14px;-webkit-overflow-scrolling:touch}
.rtab{flex:1;padding:10px 6px;font-size:12px;font-weight:700;text-align:center;cursor:pointer;color:var(--text3);background:none;border:none;font-family:inherit;border-bottom:2px solid transparent;transition:.15s}
.rtab.active{color:var(--blue);border-bottom-color:var(--blue);background:var(--surface)}
/* الإحصاء الكلي */
.stats-top{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
@media(min-width:600px){.stats-top{grid-template-columns:repeat(5,1fr)}}
/* note */
.note-box{background:var(--amber-bg);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:10px 12px;margin-bottom:8px}
.note-text{font-size:13px;color:var(--text);margin-bottom:4px}
.note-meta{font-size:11px;color:var(--amber)}
.km-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
.km-card{border-radius:10px;padding:14px;text-align:center}
.km-in{background:var(--blue-bg);border:1px solid rgba(79,142,247,.2)}
.km-out{background:var(--red-bg);border:1px solid rgba(226,91,91,.2)}
.early-btn{padding:5px 12px;background:var(--amber-bg);color:var(--amber);border:1px solid rgba(245,166,35,.3);border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap}
.section-hidden{display:none}
tbl th{white-space:nowrap;font-size:11px}
td{font-size:13px}
@media print{.topbar,.filter-card,.rtabs,.early-btn{display:none!important}.emp-report{break-inside:avoid}}
</style>
</head>
<body>
<?= getTopbar('التقارير', true, isAdmin() ? 'admin.php' : 'employee.php') ?>
<div class="page-wrap" style="max-width:900px">

  <!-- فلاتر -->
  <div class="card">
    <form method="GET" class="filter-wrap">
      <div class="field"><label>من تاريخ</label><input type="date" name="from" value="<?=$from?>"></div>
      <div class="field"><label>إلى تاريخ</label><input type="date" name="to" value="<?=$to?>"></div>
      <div class="field" style="min-width:160px">
        <label>موظف محدد</label>
        <select name="emp">
          <option value="">الكل</option>
          <?php foreach($employees as $e): ?>
            <option value="<?=$e['id']?>" <?=$emp_filter==$e['id']?'selected':''?>><?=htmlspecialchars($e['name'])?> (<?=$e['role']==='driver'?'سائق':'موظف'?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="tab" id="tab-input" value="<?=htmlspecialchars($report_tab)?>">
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn btn-blue" style="width:auto;padding:10px 18px;margin:0">🔍 عرض</button>
        <button type="button" class="btn btn-ghost" style="width:auto;padding:10px 14px;margin:0" onclick="window.print()">🖨</button>
      </div>
    </form>
  </div>

  <!-- إحصاء كلي -->
  <div class="stats-top">
    <div class="sbox"><div class="slabel">إجمالي الحضور</div><div class="sval gd"><?=$totalDays?></div></div>
    <div class="sbox"><div class="slabel">أيام التأخير</div><div class="sval a"><?=$lateDays?></div></div>
    <div class="sbox"><div class="slabel">مجموع التأخير</div><div class="sval r"><?=fmtMin($totalLateMin)?></div></div>
    <div class="sbox"><div class="slabel">إجمالي الكيلومترات</div><div class="sval g"><?=round($totalKm,1)?> كم</div></div>
    <div class="sbox"><div class="slabel">كم خارج الدوام</div><div class="sval r"><?=round($outsideKm,1)?> كم</div></div>
  </div>

  <!-- تابات -->
  <div class="rtabs">
    <button class="rtab <?=$report_tab==='individual'?'active':''?>" onclick="swTab('individual',this)">👤 الموظفون</button>
    <button class="rtab <?=$report_tab==='drivers'?'active':''?>" onclick="swTab('drivers',this)">🚗 السائقون</button>
    <button class="rtab <?=$report_tab==='attendance'?'active':''?>" onclick="swTab('attendance',this)">📋 سجل كلي</button>
  </div>

  <!-- تقارير الموظفين -->
  <div id="sec-individual" class="<?=$report_tab!=='individual'?'section-hidden':''?>">
    <?php
    // ← الإصلاح: إذا فيه فلتر، اعرض بغض النظر عن الدور
    $empList = [];
    foreach($employees as $e){
      if($e['role']!=='employee' && $e['role']!=='hr') continue;
      if($emp_filter && $e['id']!=$emp_filter) continue;
      $empList[] = $e;
    }
    foreach($empList as $emp):
      $eid=$emp['id'];
      $aRows=$attendanceByEmp[$eid]??[];
      $notes=$notesByEmp[$eid]??[];
      $totalA=count($aRows);
      $lateA=count(array_filter($aRows,fn($a)=>$a['status']==='late'));
      $lateMin=array_sum(array_column(array_filter($aRows,fn($a)=>$a['status']==='late'),'late_minutes'));
      $daysInRange=(strtotime($to)-strtotime($from))/86400+1;
      $absent=max(0,$daysInRange-$totalA);
    ?>
    <div class="emp-report">
      <div class="emp-hdr">
        <div>
          <div class="emp-hdr-name">👤 <?=htmlspecialchars($emp['name'])?></div>
          <div class="emp-hdr-sub">الدوام: <?=WORK_START?> — <?=WORK_END?></div>
        </div>
        <span class="badge b-blue">موظف</span>
      </div>
      <?php $totalOT=array_sum(array_map(fn($a)=>calcOvertime($a,$otByEmpDate),$aRows)); ?>
      <div class="emp-stats-grid emp-stats-5">
        <div class="emp-stat"><div class="es-n g"><?=$totalA?></div><div class="es-l">أيام الحضور</div></div>
        <div class="emp-stat"><div class="es-n r"><?=$absent?></div><div class="es-l">أيام الغياب</div></div>
        <div class="emp-stat"><div class="es-n a"><?=$lateA?></div><div class="es-l">أيام التأخير</div></div>
        <div class="emp-stat"><div class="es-n r"><?=fmtMin($lateMin)?></div><div class="es-l">مجموع التأخير</div></div>
        <div class="emp-stat"><div class="es-n" style="color:var(--green)"><?=fmtMin($totalOT)?></div><div class="es-l">⭐ وقت إضافي</div></div>
      </div>
      <div class="sec-body">
        <div class="sub-title">📅 سجل الحضور والانصراف</div>
        <?php if(empty($aRows)): ?>
          <p style="color:var(--text3);text-align:center;padding:12px">لا توجد سجلات في هذه الفترة</p>
        <?php else: ?>
        <div class="tbl-wrap">
          <table style="width:100%;border-collapse:collapse">
            <thead><tr style="background:var(--surface2)">
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الدخول</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الخروج</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التأخير</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">وقت إضافي ⭐</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الحالة</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">موقع الدخول / الخروج</th>
            </tr></thead>
            <tbody>
            <?php foreach($aRows as $a): ?>
            <?php $ot=calcOvertime($a,$otByEmpDate); ?>
            <tr>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=$a['date']?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:700;color:var(--green)"><?=$a['check_in']?substr($a['check_in'],11,5):'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$a['check_out']?'var(--red)':'var(--amber)'?>"><?=$a['check_out']?substr($a['check_out'],11,5):'<span style="color:var(--amber)">لم يخرج</span>'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$a['late_minutes']>0?'var(--red)':'var(--green)'?>;font-weight:600"><?=$a['late_minutes']>0?$a['late_minutes'].' د':'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$ot>0?'var(--green)':'var(--text3)'?>;font-weight:<?=$ot>0?'700':'400'?>"><?=$ot>0?'⭐ '.fmtMin($ot):'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><span class="badge <?=$a['status']==='late'?'b-amber':'b-green'?>"><?=$a['status']==='late'?'متأخر':'في الوقت'?></span></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;line-height:1.6">
                <?php if($a['check_in_lat']&&$a['check_in_lng']): ?>
                  <a href="https://maps.google.com/?q=<?=$a['check_in_lat']?>,<?=$a['check_in_lng']?>" target="_blank" style="color:var(--green);text-decoration:none">📍 دخول</a><br>
                  <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_in_address']??($a['check_in_lat'].','.$a['check_in_lng']))?></span>
                <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
                <?php if($a['check_out_lat']&&$a['check_out_lng']&&$a['check_out_lat']!='0.00000000'&&$a['check_out_lng']!='0.00000000'): ?>
                  <br><a href="https://maps.google.com/?q=<?=$a['check_out_lat']?>,<?=$a['check_out_lng']?>" target="_blank" style="color:var(--red);text-decoration:none;margin-top:4px;display:inline-block">📍 خروج</a><br>
                  <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_out_address']&&$a['check_out_address']!='0,0'?$a['check_out_address']:$a['check_out_lat'].','.$a['check_out_lng'])?></span>
                <?php endif; ?>
                <?php if(!$a['check_out'] && isAdmin()): ?><br><button class="early-btn" onclick="earlyLeave(<?=$eid?>,this)">مغادرة</button><?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if(!empty($notes)): ?>
        <div style="margin-top:14px">
          <div class="sub-title">📝 ملاحظات الموظف (<?=count($notes)?>)</div>
          <?php foreach($notes as $n): ?>
          <div class="note-box">
            <div class="note-text"><?=htmlspecialchars($n['note'])?></div>
            <div class="note-meta">🕐 <?=date('Y-m-d H:i',strtotime($n['created_at']))?><?php if($n['lat']&&$n['lng']): ?> | <a href="https://maps.google.com/?q=<?=$n['lat']?>,<?=$n['lng']?>" target="_blank" style="color:var(--amber)">📍</a><?php endif; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($empList)): ?><div class="card" style="text-align:center;color:var(--text3);padding:24px">لا يوجد موظفون في هذه الفترة</div><?php endif; ?>
  </div>

  <!-- تقارير السائقين -->
  <div id="sec-drivers" class="<?=$report_tab!=='drivers'?'section-hidden':''?>">
    <?php
    $driverList=[];
    foreach($employees as $e){
      if($e['role']!=='driver') continue;
      if($emp_filter && $e['id']!=$emp_filter) continue;
      $driverList[]=$e;
    }
    foreach($driverList as $emp):
      $eid=$emp['id'];
      $aRows=$attendanceByEmp[$eid]??[];
      $dData=$tripsByDriver[$eid]??null;
      $notes=$notesByEmp[$eid]??[];
      $totalA=count($aRows);
      $lateA=count(array_filter($aRows,fn($a)=>$a['status']==='late'));
      $lateMin=array_sum(array_column(array_filter($aRows,fn($a)=>$a['status']==='late'),'late_minutes'));
      $daysInRange=(strtotime($to)-strtotime($from))/86400+1;
      $absent=max(0,$daysInRange-$totalA);
      $kmIn=$dData?round($dData['km_inside'],1):0;
      $kmOut=$dData?round($dData['km_outside'],1):0;
    ?>
    <div class="emp-report">
      <div class="emp-hdr">
        <div>
          <div class="emp-hdr-name">🚗 <?=htmlspecialchars($emp['name'])?></div>
          <?php if($dData): ?><div class="emp-hdr-sub"><?=htmlspecialchars($dData['veh'])?> — <?=htmlspecialchars($dData['plate'])?></div><?php endif; ?>
        </div>
        <span class="badge b-gold">سائق</span>
      </div>
      <?php $totalOT=array_sum(array_map(fn($a)=>calcOvertime($a,$otByEmpDate),$aRows)); ?>
      <div class="emp-stats-grid emp-stats-5">
        <div class="emp-stat"><div class="es-n g"><?=$totalA?></div><div class="es-l">الحضور</div></div>
        <div class="emp-stat"><div class="es-n r"><?=$absent?></div><div class="es-l">الغياب</div></div>
        <div class="emp-stat"><div class="es-n a"><?=fmtMin($lateMin)?></div><div class="es-l">التأخير</div></div>
        <div class="emp-stat"><div class="es-n" style="color:var(--green)"><?=fmtMin($totalOT)?></div><div class="es-l">⭐ وقت إضافي</div></div>
        <div class="emp-stat"><div class="es-n" style="color:var(--blue)"><?=$kmIn+$kmOut?> كم</div><div class="es-l">إجمالي كم</div></div>
      </div>
      <div class="sec-body">
        <div class="km-grid">
          <div class="km-card km-in"><div style="font-size:20px;font-weight:800;color:var(--blue)"><?=$kmIn?> كم</div><div style="font-size:11px;color:var(--blue);margin-top:3px">داخل الدوام</div></div>
          <div class="km-card km-out"><div style="font-size:20px;font-weight:800;color:var(--red)"><?=$kmOut?> كم</div><div style="font-size:11px;color:var(--red);margin-top:3px">خارج الدوام ⚠</div></div>
        </div>
        <div class="sub-title">📅 سجل الحضور</div>
        <?php if(empty($aRows)): ?>
          <p style="color:var(--text3);text-align:center;padding:12px">لا توجد سجلات</p>
        <?php else: ?>
        <div class="tbl-wrap">
          <table style="width:100%;border-collapse:collapse">
            <thead><tr style="background:var(--surface2)">
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الدخول</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الخروج</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التأخير</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">وقت إضافي ⭐</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الحالة</th>
              <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">موقع الدخول / الخروج</th>
            </tr></thead>
            <tbody>
            <?php foreach($aRows as $a): ?>
            <?php $ot=calcOvertime($a,$otByEmpDate); ?>
            <tr>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=$a['date']?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:700;color:var(--green)"><?=$a['check_in']?substr($a['check_in'],11,5):'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=$a['check_out']?substr($a['check_out'],11,5):'<span style="color:var(--amber)">لم يخرج</span>'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$a['late_minutes']>0?'var(--red)':'var(--text3)'?>"><?=$a['late_minutes']>0?$a['late_minutes'].' د':'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$ot>0?'var(--green)':'var(--text3)'?>;font-weight:<?=$ot>0?'700':'400'?>"><?=$ot>0?'⭐ '.fmtMin($ot):'—'?></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><span class="badge <?=$a['status']==='late'?'b-amber':'b-green'?>"><?=$a['status']==='late'?'متأخر':'في الوقت'?></span></td>
              <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;line-height:1.6">
                <?php if($a['check_in_lat']&&$a['check_in_lng']): ?>
                  <a href="https://maps.google.com/?q=<?=$a['check_in_lat']?>,<?=$a['check_in_lng']?>" target="_blank" style="color:var(--green);text-decoration:none">📍 دخول</a><br>
                  <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_in_address']??($a['check_in_lat'].','.$a['check_in_lng']))?></span>
                <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
                <?php if($a['check_out_lat']&&$a['check_out_lng']&&$a['check_out_lat']!='0.00000000'&&$a['check_out_lng']!='0.00000000'): ?>
                  <br><a href="https://maps.google.com/?q=<?=$a['check_out_lat']?>,<?=$a['check_out_lng']?>" target="_blank" style="color:var(--red);text-decoration:none;margin-top:4px;display:inline-block">📍 خروج</a><br>
                  <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_out_address']&&$a['check_out_address']!='0,0'?$a['check_out_address']:$a['check_out_lat'].','.$a['check_out_lng'])?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if($dData&&!empty($dData['trips'])): ?>
        <div style="margin-top:14px">
          <div class="sub-title">🗺 المشاوير (<?=count($dData['trips'])?>)</div>
          <div class="tbl-wrap">
            <table style="width:100%;border-collapse:collapse">
              <thead><tr style="background:var(--surface2)">
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">بداية المشوار</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">نهاية المشوار</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">كم داخل الدوام 🔵</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">كم خارج الدوام 🔴</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">إجمالي كم</th>
              </tr></thead>
              <tbody>
              <?php foreach($dData['trips'] as $t): ?>
              <?php $tIn=round($t['km_inside']??0,2); $tOut=round($t['km_outside']??0,2); $tTotal=round($t['distance_km'],2); ?>
              <tr>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=date('Y-m-d',strtotime($t['start_time']))?></td>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:700;color:var(--green)"><?=substr($t['start_time'],11,5)?></td>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:var(--red)"><?=$t['end_time']?substr($t['end_time'],11,5):'<span style="color:var(--green)">🟢 نشط</span>'?></td>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:700;color:var(--blue)"><?=$tIn?> كم</td>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:700;color:<?=$tOut>0?'var(--red)':'var(--text3)'?>"><?=$tOut>0?'⚠ '.$tOut.' كم':'—'?></td>
                <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:var(--text2)"><?=$tTotal?> كم</td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($driverList)): ?><div class="card" style="text-align:center;color:var(--text3);padding:24px">لا يوجد سائقون</div><?php endif; ?>
  </div>

  <!-- سجل الدوام الكلي -->
  <div id="sec-attendance" class="<?=$report_tab!=='attendance'?'section-hidden':''?>">
    <div class="card" style="padding:0;overflow:hidden">
      <div class="card-title" style="padding:14px 16px 0">📋 سجل الدوام الكامل</div>
      <?php if(empty($allAttendance)): ?>
        <p style="color:var(--text3);text-align:center;padding:24px">لا توجد بيانات</p>
      <?php else: ?>
      <div class="tbl-wrap">
        <table style="width:100%;border-collapse:collapse">
          <thead><tr style="background:var(--surface2)">
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الموظف</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الدخول</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الخروج</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التأخير</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الحالة</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">موقع الدخول / الخروج</th>
          </tr></thead>
          <tbody>
          <?php foreach($allAttendance as $a): ?>
          <tr>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=$a['date']?></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-weight:600"><?=htmlspecialchars($a['emp_name'])?><br><span style="font-size:10px;color:var(--text3)"><?=$a['role']==='driver'?'سائق':'موظف'?></span></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:var(--green);font-weight:600"><?=$a['check_in']?substr($a['check_in'],11,5):'—'?></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><?=$a['check_out']?substr($a['check_out'],11,5):'<span style="color:var(--amber)">لم يخرج</span>'?></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border);color:<?=$a['late_minutes']>0?'var(--red)':'var(--text3)'?>"><?=$a['late_minutes']>0?$a['late_minutes'].' د':'—'?></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border)"><span class="badge <?=$a['status']==='late'?'b-amber':'b-green'?>"><?=$a['status']==='late'?'متأخر':'في الوقت'?></span></td>
            <td style="padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;line-height:1.6">
              <?php if($a['check_in_lat']&&$a['check_in_lng']): ?>
                <a href="https://maps.google.com/?q=<?=$a['check_in_lat']?>,<?=$a['check_in_lng']?>" target="_blank" style="color:var(--green);text-decoration:none">📍 دخول</a><br>
                <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_in_address']??($a['check_in_lat'].','.$a['check_in_lng']))?></span>
              <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
              <?php if($a['check_out_lat']&&$a['check_out_lng']): ?>
                <br><a href="https://maps.google.com/?q=<?=$a['check_out_lat']?>,<?=$a['check_out_lng']?>" target="_blank" style="color:var(--red);text-decoration:none;margin-top:4px;display:inline-block">📍 خروج</a><br>
                <span style="color:var(--text3);font-size:11px"><?=htmlspecialchars($a['check_out_address']??($a['check_out_lat'].','.$a['check_out_lng']))?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>


</div>
<script>
function swTab(t,btn){
  ['individual','drivers','attendance'].forEach(x=>document.getElementById('sec-'+x).classList.add('section-hidden'));
  document.getElementById('sec-'+t).classList.remove('section-hidden');
  document.querySelectorAll('.rtab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-input').value=t;
  const url=new URL(window.location);url.searchParams.set('tab',t);history.replaceState({},''  ,url);
}
function earlyLeave(empId,btn){
  if(!confirm('تسجيل مغادرة مبكرة؟'))return;
  btn.disabled=true;btn.textContent='⏳';
  fetch('api/force_checkout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({employee_id:empId})})
  .then(r=>r.json()).then(d=>{
    if(d.success){btn.textContent='✅';btn.style.background='var(--green-bg)';btn.style.color='var(--green)';}
    else{btn.textContent='❌';btn.disabled=false;}
  }).catch(()=>{btn.textContent='خطأ';btn.disabled=false;});
}
</script>
</body>
</html>
