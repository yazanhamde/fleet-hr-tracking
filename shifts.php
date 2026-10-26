<?php
// ============================================================
// shifts.php — إدارة الورديات المتعددة
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();
if (isDriver()) { header('Location: '.SITE_URL.'/driver.php'); exit; }

$db    = getDB();
$empId = $_SESSION['employee_id'];
$canManage = isAdmin() || isHR();

// ── معالجة POST (الأدمن / HR فقط) ─────────────────────────
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $targetEmp = (int)($_POST['employee_id']   ?? 0);
        $shiftId   = (int)($_POST['shift_id']      ?? 0);
        $shiftDate =       $_POST['shift_date']    ?? '';
        $note      = trim($_POST['note']           ?? '');

        if ($targetEmp < 1 || $shiftId < 1 || !$shiftDate) {
            $err = 'يرجى تعبئة جميع الحقول';
        } else {
            $db->prepare("INSERT INTO employee_shifts (employee_id,shift_id,shift_date,note,created_by)
                          VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE shift_id=VALUES(shift_id),note=VALUES(note),created_by=VALUES(created_by)")
               ->execute([$targetEmp,$shiftId,$shiftDate,$note,$empId]);

            $sh = $db->prepare("SELECT name,start_time,end_time FROM shifts WHERE id=?");
            $sh->execute([$shiftId]);
            $shData = $sh->fetch();
            notifyEmployee($db,$targetEmp,'shift_assigned',
                "تم تعيين وردية جديدة",
                "وردية {$shData['name']} — {$shiftDate} ({$shData['start_time']} → {$shData['end_time']})",
                0,'shift'
            );
            $msg = 'تم تعيين الوردية بنجاح ✅';
        }
    }

    if ($action === 'delete_shift') {
        $shId = (int)($_POST['shift_row_id'] ?? 0);
        $db->prepare("DELETE FROM employee_shifts WHERE id=? AND created_by=?")->execute([$shId,$empId]);
        $msg = 'تم حذف الوردية';
    }

    header('Location: shifts.php' . ($err ? '?err='.urlencode($err) : '?ok=1'));
    exit;
}
if (isset($_GET['ok']))  $msg = 'تمت العملية بنجاح ✅';
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── البيانات ────────────────────────────────────────────────
$shifts    = $db->query("SELECT * FROM shifts WHERE is_active=1 ORDER BY start_time")->fetchAll();
$employees = $db->query("SELECT id,name,job_title FROM employees WHERE role IN ('employee','driver','hr') ORDER BY name")->fetchAll();

// جدول الأسبوع الحالي (7 أيام من اليوم)
$weekDates = [];
for ($i=0; $i<7; $i++) {
    $weekDates[] = date('Y-m-d', strtotime("+$i days"));
}

// ورديات الأسبوع
$weekStart = $weekDates[0];
$weekEnd   = $weekDates[6];
$ws = $db->prepare("
  SELECT es.*,s.name as shift_name,s.start_time,s.end_time,s.color,e.name as emp_name
  FROM employee_shifts es
  JOIN shifts s ON s.id=es.shift_id
  JOIN employees e ON e.id=es.employee_id
  WHERE es.shift_date BETWEEN ? AND ?
  ORDER BY es.shift_date,e.name
");
$ws->execute([$weekStart,$weekEnd]);
$weekShifts = $ws->fetchAll();

// ورديات الموظف الحالي
$myShifts = $db->prepare("
  SELECT es.*,s.name as shift_name,s.start_time,s.end_time,s.color
  FROM employee_shifts es
  JOIN shifts s ON s.id=es.shift_id
  WHERE es.employee_id=? AND es.shift_date >= ?
  ORDER BY es.shift_date
  LIMIT 14
");
$myShifts->execute([$empId, date('Y-m-d')]);
$myShiftRows = $myShifts->fetchAll();

$dayNames = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الورديات') ?>
<style>
.week-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:14px}
.day-col{background:var(--surface2);border:1px solid var(--border);border-radius:8px;overflow:hidden;min-height:80px}
.day-hdr{background:var(--surface);padding:6px 4px;text-align:center;font-size:10px;font-weight:700;color:var(--text2);border-bottom:1px solid var(--border)}
.day-hdr.today{background:var(--blue);color:#fff}
.shift-chip{padding:4px 4px;margin:3px;border-radius:5px;font-size:9px;font-weight:700;text-align:center;cursor:default}
.my-shift-row{display:flex;align-items:center;gap:10px;padding:10px;background:var(--surface2);border:1px solid var(--border);border-radius:10px;margin-bottom:8px}
.shift-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
@media(max-width:480px){.week-grid{grid-template-columns:repeat(4,1fr)}}
</style>
</head>
<body>
<?= getTopbar('الورديات') ?>
<div class="page-wrap">

<?php if ($msg): ?><div class="notice n-green">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice n-red">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ورديات الموظف الحالي -->
<div class="card">
  <div class="card-title">📅 ورداياتي القادمة</div>
  <?php if (!$myShiftRows): ?>
    <div style="text-align:center;color:var(--text3);padding:16px">لم يتم تعيين ورديات لك بعد</div>
  <?php else: ?>
    <?php foreach ($myShiftRows as $sr):
      $d = strtotime($sr['shift_date']);
      $dayName = $dayNames[(int)date('w',$d)];
    ?>
    <div class="my-shift-row">
      <div class="shift-dot" style="background:<?= htmlspecialchars($sr['color']) ?>"></div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($sr['shift_name']) ?></div>
        <div style="font-size:11px;color:var(--text3)"><?= $sr['start_time'] ?> — <?= $sr['end_time'] ?></div>
        <?php if ($sr['note']): ?><div style="font-size:11px;color:var(--amber)"><?= htmlspecialchars($sr['note']) ?></div><?php endif; ?>
      </div>
      <div style="text-align:left">
        <div style="font-size:12px;font-weight:700"><?= $dayName ?></div>
        <div style="font-size:11px;color:var(--text3)"><?= $sr['shift_date'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- جدول الأسبوع (لكل المستخدمين) -->
<div class="card">
  <div class="card-title">📆 جدول الأسبوع الحالي</div>
  <div class="week-grid">
    <?php foreach ($weekDates as $d):
      $isToday = $d === date('Y-m-d');
      $dow = (int)date('w', strtotime($d));
      $dn  = $dayNames[$dow];
      $dayShifts = array_filter($weekShifts, fn($s) => $s['shift_date'] === $d);
    ?>
    <div class="day-col">
      <div class="day-hdr <?= $isToday ? 'today' : '' ?>"><?= $dn ?><br><?= date('d/m', strtotime($d)) ?></div>
      <?php foreach ($dayShifts as $sh): ?>
        <div class="shift-chip" style="background:<?= htmlspecialchars($sh['color']) ?>22;color:<?= htmlspecialchars($sh['color']) ?>;border:1px solid <?= htmlspecialchars($sh['color']) ?>44">
          <?= mb_substr(htmlspecialchars($sh['emp_name']),0,6) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- إدارة الورديات (أدمن/HR) -->
<?php if ($canManage): ?>
<div class="card">
  <div class="card-title">⚙️ تعيين وردية</div>
  <form method="post">
    <input type="hidden" name="action" value="assign">
    <div class="field">
      <label>الموظف</label>
      <select name="employee_id" required>
        <option value="">— اختر موظفاً —</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?><?= $e['job_title'] ? " ({$e['job_title']})" : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>الوردية</label>
      <select name="shift_id" required>
        <option value="">— اختر وردية —</option>
        <?php foreach ($shifts as $sh): ?>
        <option value="<?= $sh['id'] ?>"><?= htmlspecialchars($sh['name']) ?> (<?= $sh['start_time'] ?> — <?= $sh['end_time'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>التاريخ</label>
      <input type="date" name="shift_date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="field">
      <label>ملاحظة (اختياري)</label>
      <input type="text" name="note" placeholder="...">
    </div>
    <button type="submit" class="btn btn-blue">تعيين الوردية</button>
  </form>
</div>

<!-- قائمة كل ورديات الأسبوع مع حذف -->
<div class="card">
  <div class="card-title">🗂️ ورديات هذا الأسبوع (<?= $weekStart ?> – <?= $weekEnd ?>)</div>
  <?php if (!$weekShifts): ?>
    <div style="text-align:center;color:var(--text3);padding:16px">لا توجد ورديات مسجّلة هذا الأسبوع</div>
  <?php else: ?>
    <?php $lastDate = ''; foreach ($weekShifts as $sh): if ($sh['shift_date'] !== $lastDate): $lastDate=$sh['shift_date']; ?>
    <div style="font-size:11px;font-weight:700;color:var(--text3);margin:10px 0 4px"><?= $dayNames[(int)date('w',strtotime($sh['shift_date']))] ?> <?= $sh['shift_date'] ?></div>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:4px">
      <div class="shift-dot" style="background:<?= htmlspecialchars($sh['color']) ?>"></div>
      <div style="flex:1;font-size:13px;font-weight:700"><?= htmlspecialchars($sh['emp_name']) ?></div>
      <div style="font-size:11px;color:var(--text2)"><?= htmlspecialchars($sh['shift_name']) ?> <?= $sh['start_time'] ?>–<?= $sh['end_time'] ?></div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="delete_shift">
        <input type="hidden" name="shift_row_id" value="<?= $sh['id'] ?>">
        <button type="submit" style="background:var(--red-bg);color:var(--red);border:1px solid rgba(226,91,91,.3);border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;font-family:inherit">حذف</button>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>
</body>
</html>
