<?php
// ============================================================
// employee_report.php — تقرير PDF للموظف
// يعمل كـ HTML يُطبع / يُحوَّل لـ PDF من المتصفح
// الأدمن/HR: يختار الموظف والشهر
// الموظف: يرى تقريره الخاص فقط
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();
if (isDriver()) { header('Location: '.SITE_URL.'/driver.php'); exit; }

$db        = getDB();
$myEmpId   = $_SESSION['employee_id'];
$canManage = isAdmin() || isHR();

$targetId  = $canManage ? (int)($_GET['emp'] ?? $myEmpId) : $myEmpId;
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));
$print     = isset($_GET['print']);

// ── جلب بيانات الموظف ────────────────────────────────────
$empStmt = $db->prepare("SELECT * FROM employees WHERE id=?");
$empStmt->execute([$targetId]);
$emp = $empStmt->fetch();
if (!$emp) { http_response_code(404); die('موظف غير موجود'); }

// التحقق من الصلاحية
if (!$canManage && $targetId !== $myEmpId) {
    header('Location: '.SITE_URL.'/employee.php'); exit;
}

// ── بيانات التقرير ─────────────────────────────────────────
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// سجلات الحضور
$attStmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? ORDER BY date");
$attStmt->execute([$targetId,$monthStart,$monthEnd]);
$attRows = $attStmt->fetchAll();

// إحصاءات الحضور
$presentDays  = count($attRows);
$lateDays     = 0;
$totalLateMin = 0;
$totalHours   = 0;
foreach ($attRows as $a) {
    if ($a['status']==='late') { $lateDays++; $totalLateMin += $a['late_minutes']; }
    if ($a['check_in'] && $a['check_out']) {
        $totalHours += round((strtotime($a['check_out'])-strtotime($a['check_in']))/3600, 1);
    }
}

// أيام الغياب (أيام عمل في الشهر - أيام الحضور)
$workingDays = calcBusinessDays($monthStart, $monthEnd, $db);
$absentDays  = max(0, $workingDays - $presentDays);

// الوقت الإضافي
$otStmt = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND request_date BETWEEN ? AND ? ORDER BY request_date");
$otStmt->execute([$targetId,$monthStart,$monthEnd]);
$otRows = $otStmt->fetchAll();
$totalOtMin = 0;
foreach ($otRows as $ot) {
    if ($ot['ot_check_in'] && $ot['ot_check_out']) {
        $totalOtMin += round((strtotime($ot['ot_check_out'])-strtotime($ot['ot_check_in']))/60);
    }
}

// الإجازات
$leavesStmt = $db->prepare("SELECT lr.*,lt.name as type_name FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? AND lr.start_date BETWEEN ? AND ? AND lr.status='approved' ORDER BY lr.start_date");
$leavesStmt->execute([$targetId,$monthStart,$monthEnd]);
$leaveRows = $leavesStmt->fetchAll();
$totalLeaveDays = array_sum(array_column($leaveRows,'days_count'));

// تقييم الشهر
$perfStmt = $db->prepare("SELECT pr.*,e.name as reviewer_name FROM performance_reviews pr JOIN employees e ON e.id=pr.reviewer_id WHERE pr.employee_id=? AND pr.review_year=? AND pr.review_month=?");
$perfStmt->execute([$targetId,$year,$month]);
$perf = $perfStmt->fetch();

// ورديات الشهر
$shiftsStmt = $db->prepare("SELECT es.*,s.name as shift_name,s.start_time,s.end_time,s.color FROM employee_shifts es JOIN shifts s ON s.id=es.shift_id WHERE es.employee_id=? AND es.shift_date BETWEEN ? AND ? ORDER BY es.shift_date");
$shiftsStmt->execute([$targetId,$monthStart,$monthEnd]);
$shiftRows = $shiftsStmt->fetchAll();

$monthAr = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$statusAr = ['on_time'=>'في الوقت','late'=>'متأخر','early_leave'=>'خرج مبكراً'];

$dayNames = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];

// قائمة للأدمن
$allEmps = $canManage ? $db->query("SELECT id,name FROM employees WHERE role IN ('employee','driver','hr') ORDER BY name")->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('تقرير الموظف') ?>
<style>
@media print {
  .no-print{display:none!important}
  body{background:#fff!important;color:#111!important}
  .report-card{border:1px solid #ddd!important;box-shadow:none!important;break-inside:avoid}
  .topbar{display:none!important}
}
.report-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.stat-box{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px;text-align:center}
.stat-num{font-size:22px;font-weight:800}
.stat-lbl{font-size:10px;color:var(--text3);margin-top:2px}
.att-table{width:100%;border-collapse:collapse;font-size:12px}
.att-table th{background:var(--surface2);padding:7px 8px;text-align:right;border:1px solid var(--border);font-size:11px;color:var(--text2)}
.att-table td{padding:7px 8px;border:1px solid var(--border)}
.att-table tr:nth-child(even){background:rgba(255,255,255,.02)}
.perf-bar{height:8px;background:var(--border);border-radius:4px;overflow:hidden;flex:1}
.perf-fill{height:100%;border-radius:4px}
.score-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border)}
.score-row:last-child{border-bottom:none}
.report-header{text-align:center;padding:16px;background:linear-gradient(135deg,#1a56db,#0f1117);border-radius:12px;margin-bottom:12px;color:#fff}
</style>
</head>
<body>
<?php if (!$print): echo getTopbar('تقرير الموظف'); endif; ?>
<div class="page-wrap <?= $print ? 'no-print-wrap' : '' ?>">

<?php if (!$print): ?>
<!-- فلاتر -->
<div class="report-card no-print">
  <div class="card-title">🔍 اختيار التقرير</div>
  <form method="get">
    <?php if ($canManage): ?>
    <div class="field">
      <label>الموظف</label>
      <select name="emp">
        <?php foreach ($allEmps as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $e['id']==$targetId?'selected':'' ?>><?= htmlspecialchars($e['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
    <input type="hidden" name="emp" value="<?= $myEmpId ?>">
    <?php endif; ?>
    <div class="grid2" style="gap:10px">
      <div class="field">
        <label>الشهر</label>
        <select name="month">
          <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= $monthAr[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label>السنة</label>
        <select name="year">
          <?php for ($y=date('Y'); $y>=date('Y')-2; $y--): ?>
          <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-blue">عرض التقرير</button>
    <a href="?emp=<?= $targetId ?>&year=<?= $year ?>&month=<?= $month ?>&print=1" target="_blank" class="btn btn-ghost" style="margin-top:6px">🖨️ طباعة / PDF</a>
  </form>
</div>
<?php endif; ?>

<!-- رأس التقرير -->
<div class="report-header">
  <div style="font-size:28px;margin-bottom:6px">👤</div>
  <div style="font-size:18px;font-weight:800"><?= htmlspecialchars($emp['name']) ?></div>
  <?php if ($emp['job_title']): ?><div style="font-size:13px;opacity:.8"><?= htmlspecialchars($emp['job_title']) ?></div><?php endif; ?>
  <div style="font-size:14px;margin-top:8px;opacity:.9">تقرير <?= $monthAr[$month] ?> <?= $year ?></div>
  <div style="font-size:11px;opacity:.6;margin-top:3px">تاريخ الإصدار: <?= date('d/m/Y H:i') ?></div>
</div>

<!-- ملخص إحصائي -->
<div class="stat-grid">
  <div class="stat-box">
    <div class="stat-num" style="color:var(--green)"><?= $presentDays ?></div>
    <div class="stat-lbl">أيام الحضور</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--red)"><?= $absentDays ?></div>
    <div class="stat-lbl">أيام الغياب</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--amber)"><?= $lateDays ?></div>
    <div class="stat-lbl">أيام التأخر</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--blue)"><?= round($totalHours,1) ?></div>
    <div class="stat-lbl">ساعات العمل</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--gold)"><?= $totalOtMin>0 ? floor($totalOtMin/60).'h'.($totalOtMin%60?($totalOtMin%60).'m':'') : '—' ?></div>
    <div class="stat-lbl">وقت إضافي</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--amber)"><?= $totalLeaveDays ?></div>
    <div class="stat-lbl">أيام الإجازة</div>
  </div>
</div>

<!-- تقييم الأداء -->
<?php if ($perf): ?>
<div class="report-card">
  <div class="card-title">⭐ تقييم الأداء</div>
  <?php
  $criteria = [
    ['الحضور والانضباط', $perf['score_attendance']],
    ['الأداء والإنتاجية',$perf['score_performance']],
    ['السلوك والتعاون', $perf['score_behavior']],
    ['الالتزام بالمهام', $perf['score_commitment']],
  ];
  foreach ($criteria as [$name,$score]): $pct = $score*20; ?>
  <div class="score-row">
    <span style="font-size:12px;width:120px;flex-shrink:0"><?= $name ?></span>
    <div class="perf-bar"><div class="perf-fill" style="width:<?= $pct ?>%;background:var(--blue)"></div></div>
    <span style="font-size:13px;font-weight:700;width:30px;text-align:center"><?= $score ?>/5</span>
  </div>
  <?php endforeach; ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:13px;color:var(--text2)">التقييم الإجمالي</span>
    <span style="font-size:22px;font-weight:800;color:<?= $perf['score_total']>=4?'var(--green)':($perf['score_total']>=3?'var(--amber)':'var(--red)') ?>"><?= $perf['score_total'] ?>/5</span>
  </div>
  <?php if ($perf['notes']): ?>
  <div style="margin-top:10px;padding:10px;background:var(--surface2);border-radius:8px;font-size:13px;color:var(--text2)"><?= htmlspecialchars($perf['notes']) ?></div>
  <?php endif; ?>
  <div style="margin-top:6px;font-size:11px;color:var(--text3)">بواسطة: <?= htmlspecialchars($perf['reviewer_name']) ?></div>
</div>
<?php endif; ?>

<!-- جدول الحضور -->
<div class="report-card">
  <div class="card-title">📋 سجل الحضور (<?= count($attRows) ?> يوم)</div>
  <?php if (!$attRows): ?>
    <div style="text-align:center;color:var(--text3);padding:16px">لا توجد سجلات حضور هذا الشهر</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="att-table">
    <tr>
      <th>التاريخ</th><th>اليوم</th><th>الدخول</th><th>الخروج</th><th>الحالة</th><th>تأخر</th>
    </tr>
    <?php foreach ($attRows as $a):
      $dow = (int)date('w',strtotime($a['date']));
      $cin  = $a['check_in']  ? date('H:i',strtotime($a['check_in']))  : '—';
      $cout = $a['check_out'] ? date('H:i',strtotime($a['check_out'])) : '—';
      $stColor = $a['status']==='late' ? 'var(--amber)' : ($a['status']==='early_leave'?'var(--red)':'var(--green)');
    ?>
    <tr>
      <td><?= $a['date'] ?></td>
      <td style="color:var(--text3)"><?= $dayNames[$dow] ?></td>
      <td style="font-variant-numeric:tabular-nums"><?= $cin ?></td>
      <td style="font-variant-numeric:tabular-nums"><?= $cout ?></td>
      <td style="color:<?= $stColor ?>;font-weight:700"><?= $statusAr[$a['status']] ?? $a['status'] ?></td>
      <td style="color:var(--amber)"><?= $a['late_minutes'] > 0 ? $a['late_minutes'].' د' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- الإجازات -->
<?php if ($leaveRows): ?>
<div class="report-card">
  <div class="card-title">🏖️ الإجازات المعتمدة (<?= $totalLeaveDays ?> يوم)</div>
  <?php foreach ($leaveRows as $lr): ?>
  <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
    <span><?= htmlspecialchars($lr['type_name']) ?></span>
    <span style="color:var(--text3)"><?= $lr['start_date'] ?> — <?= $lr['end_date'] ?> (<?= $lr['days_count'] ?> د)</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- الورديات -->
<?php if ($shiftRows): ?>
<div class="report-card">
  <div class="card-title">📅 الورديات (<?= count($shiftRows) ?>)</div>
  <?php foreach ($shiftRows as $sr):
    $dow = (int)date('w',strtotime($sr['shift_date']));
  ?>
  <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px">
    <span><?= $dayNames[$dow] ?> <?= $sr['shift_date'] ?></span>
    <span style="color:<?= htmlspecialchars($sr['color']) ?>;font-weight:700"><?= htmlspecialchars($sr['shift_name']) ?> (<?= $sr['start_time'] ?>–<?= $sr['end_time'] ?>)</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ملاحظة التأخر التراكمي -->
<?php if ($totalLateMin > 0): ?>
<div class="report-card" style="border-color:var(--amber)">
  <div class="card-title" style="color:var(--amber)">⏱️ ملخص التأخر</div>
  <div style="display:flex;gap:16px">
    <div><span style="font-size:11px;color:var(--text3)">عدد مرات التأخر</span><br><span style="font-size:20px;font-weight:800;color:var(--amber)"><?= $lateDays ?></span></div>
    <div><span style="font-size:11px;color:var(--text3)">إجمالي دقائق التأخر</span><br><span style="font-size:20px;font-weight:800;color:var(--amber)"><?= $totalLateMin ?></span></div>
    <div><span style="font-size:11px;color:var(--text3)">بالساعات</span><br><span style="font-size:20px;font-weight:800;color:var(--amber)"><?= round($totalLateMin/60,1) ?>h</span></div>
  </div>
</div>
<?php endif; ?>

<?php if ($print): ?>
<div style="text-align:center;margin-top:20px;font-size:11px;color:var(--text3)">
  تم إصدار هذا التقرير بتاريخ <?= date('d/m/Y H:i') ?> — مؤسسة فلسطين
</div>
<script>window.onload=()=>window.print()</script>
<?php endif; ?>

</div><!-- page-wrap -->

<?php if (!$print): ?>
<style>.field select,.field input{display:block;width:100%;padding:10px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;color:var(--text);outline:none;transition:border .2s}.field label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;font-weight:600}.field{margin-bottom:12px}</style>
<?php endif; ?>
</body>
</html>
