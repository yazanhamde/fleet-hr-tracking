<?php
// ============================================================
// leaves.php — نظام الإجازات (طلب + موافقة + رصيد)
// يعمل للموظف: يرى رصيده ويطلب إجازة
// يعمل للأدمن/HR: يرى كل الطلبات ويوافق/يرفض
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();
if (isDriver()) { header('Location: '.SITE_URL.'/driver.php'); exit; }

$db    = getDB();
$empId = $_SESSION['employee_id'];
$role  = $_SESSION['role'];
$today = date('Y-m-d');
$year  = date('Y');

// ── معالجة الطلبات POST ────────────────────────────────────
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // موظف: تقديم طلب إجازة
    if ($action === 'submit_leave') {
        $typeId    = (int)($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date']   ?? '';
        $reason    = trim($_POST['reason'] ?? '');

        $daysCount = calcBusinessDays($startDate, $endDate, $db);

        if ($typeId < 1 || !$startDate || !$endDate || $startDate > $endDate) {
            $err = 'يرجى تعبئة جميع الحقول بشكل صحيح';
        } elseif ($daysCount < 1) {
            $err = 'الفترة المختارة لا تحتوي على أيام عمل';
        } else {
            // فحص رصيد (للإجازات المدفوعة غير صفرية)
            $bal = getLeaveBalance($db, $empId, $typeId, $year);
            if ($bal !== null && $bal['total_days'] > 0) {
                $remaining = $bal['total_days'] - $bal['used_days'];
                if ($daysCount > $remaining) {
                    $err = "رصيدك المتاح ({$remaining} يوم) لا يكفي لهذا الطلب ({$daysCount} يوم)";
                }
            }
            if (!$err) {
                $db->prepare("INSERT INTO leave_requests (employee_id,leave_type_id,start_date,end_date,days_count,reason) VALUES (?,?,?,?,?,?)")
                   ->execute([$empId,$typeId,$startDate,$endDate,$daysCount,$reason]);
                $reqId = $db->lastInsertId();
                // إشعار للأدمن
                notifyAdmins($db, 'leave_request', "طلب إجازة جديد من " . getEmpName($db,$empId), "طلب #{$reqId} — {$daysCount} يوم", $reqId, 'leave_request');
                $msg = "✅ تم تقديم طلب الإجازة بنجاح — سيتم إعلامك عند المراجعة";
            }
        }
    }

    // أدمن/HR: الموافقة أو الرفض
    if (($action === 'approve' || $action === 'reject') && (isAdmin() || isHR())) {
        $reqId    = (int)($_POST['req_id'] ?? 0);
        $adminNote= trim($_POST['admin_note'] ?? '');

        $req = $db->prepare("SELECT * FROM leave_requests WHERE id=?");
        $req->execute([$reqId]);
        $leaveReq = $req->fetch();

        if ($leaveReq && $leaveReq['status'] === 'pending') {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $db->prepare("UPDATE leave_requests SET status=?,admin_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?")
               ->execute([$newStatus,$adminNote,$empId,$reqId]);

            // تحديث الرصيد عند الموافقة
            if ($newStatus === 'approved') {
                $db->prepare("UPDATE leave_balances SET used_days=used_days+? WHERE employee_id=? AND leave_type_id=? AND year=?")
                   ->execute([$leaveReq['days_count'],$leaveReq['employee_id'],$leaveReq['leave_type_id'],$year]);
            }

            // إشعار للموظف
            $statusAr  = $newStatus === 'approved' ? 'موافق عليها ✅' : 'مرفوضة ❌';
            notifyEmployee($db, $leaveReq['employee_id'],
                $newStatus === 'approved' ? 'leave_approved' : 'leave_rejected',
                "تم البت في طلب إجازتك",
                "طلبك #{$reqId} — {$statusAr}" . ($adminNote ? " | {$adminNote}" : ''),
                $reqId, 'leave_request'
            );
            $msg = "تم " . ($newStatus==='approved'?'قبول':'رفض') . " الطلب";
        }
    }

    header('Location: leaves.php' . ($msg ? '?ok=1' : ($err ? '?err='.urlencode($err) : '')));
    exit;
}

if (isset($_GET['ok']))  $msg = 'تمت العملية بنجاح ✅';
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── بيانات الصفحة ─────────────────────────────────────────
$leaveTypes = $db->query("SELECT * FROM leave_types ORDER BY id")->fetchAll();

// رصيد الموظف الحالي
$balances = [];
foreach ($leaveTypes as $lt) {
    $balances[$lt['id']] = getLeaveBalance($db, $empId, $lt['id'], $year) ?? ['total_days'=>0,'used_days'=>0];
}

// طلبات الموظف
$myReqs = $db->prepare("SELECT lr.*,lt.name as type_name,lt.color FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? ORDER BY lr.created_at DESC LIMIT 30");
$myReqs->execute([$empId]);
$myRequests = $myReqs->fetchAll();

// للأدمن/HR: كل الطلبات المعلّقة
$pendingAll = [];
if (isAdmin() || isHR()) {
    $ps = $db->query("SELECT lr.*,lt.name as type_name,lt.color,e.name as emp_name,e.job_title FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id JOIN employees e ON e.id=lr.employee_id WHERE lr.status='pending' ORDER BY lr.created_at ASC");
    $pendingAll = $ps->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الإجازات') ?>
<style>
.tabs{display:flex;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:14px}
.tab-btn{flex:1;padding:10px 4px;font-size:13px;font-weight:700;text-align:center;cursor:pointer;background:none;border:none;color:var(--text3);font-family:inherit;transition:.15s}
.tab-btn.active{background:var(--blue);color:#fff}
.tab-pane{display:none}.tab-pane.active{display:block}
.bal-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.bal-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center}
.bal-num{font-size:22px;font-weight:800;margin-bottom:2px}
.bal-used{font-size:11px;color:var(--text3)}
.bal-name{font-size:12px;font-weight:700;color:var(--text2);margin-bottom:6px}
.req-row{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:8px}
.req-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.req-type{font-size:13px;font-weight:700}
.req-dates{font-size:12px;color:var(--text2);margin-bottom:4px}
.req-note{font-size:11px;color:var(--text3);margin-top:4px}
.prog-bar{height:5px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:6px}
.prog-fill{height:100%;border-radius:3px;transition:.3s}
.emp-name{font-size:12px;color:var(--text3);margin-bottom:4px}
.action-row{display:flex;gap:8px;margin-top:8px}
.action-row .btn{margin:0;font-size:12px;padding:9px}
</style>
</head>
<body>
<?= getTopbar(isAdmin()||isHR() ? 'إدارة الإجازات' : 'إجازاتي') ?>
<div class="page-wrap">

<?php if ($msg): ?>
  <div class="notice n-green">✅ <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="notice n-red">⚠️ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- تبويب -->
<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('balance',this)">رصيدي</button>
  <button class="tab-btn" onclick="switchTab('request',this)">طلب جديد</button>
  <button class="tab-btn" onclick="switchTab('history',this)">طلباتي</button>
  <?php if(isAdmin()||isHR()): ?>
  <button class="tab-btn" onclick="switchTab('admin',this)" id="admin-tab-btn">
    إدارة <?php if($pendingAll): ?><span class="badge b-amber" style="font-size:10px;padding:1px 6px"><?= count($pendingAll) ?></span><?php endif; ?>
  </button>
  <?php endif; ?>
</div>

<!-- رصيد الإجازات -->
<div class="tab-pane active" id="tab-balance">
  <div class="card">
    <div class="card-title">📊 رصيد إجازاتك — <?= $year ?></div>
    <div class="bal-grid">
      <?php foreach ($leaveTypes as $lt):
        $b = $balances[$lt['id']];
        $used  = (float)$b['used_days'];
        $total = (float)$b['total_days'];
        $rem   = max(0, $total - $used);
        $pct   = $total > 0 ? min(100, round($used/$total*100)) : 0;
      ?>
      <div class="bal-card">
        <div class="bal-name"><?= htmlspecialchars($lt['name']) ?></div>
        <div class="bal-num" style="color:<?= htmlspecialchars($lt['color']) ?>"><?= $rem ?></div>
        <div class="bal-used">متبقي من <?= $total ?> يوم</div>
        <?php if($total > 0): ?>
        <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= htmlspecialchars($lt['color']) ?>"></div></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- طلب إجازة جديد -->
<div class="tab-pane" id="tab-request">
  <div class="card">
    <div class="card-title">📝 طلب إجازة جديد</div>
    <form method="post">
      <input type="hidden" name="action" value="submit_leave">
      <div class="field">
        <label>نوع الإجازة</label>
        <select name="leave_type_id" required>
          <option value="">— اختر —</option>
          <?php foreach ($leaveTypes as $lt): ?>
          <option value="<?= $lt['id'] ?>"><?= htmlspecialchars($lt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>من تاريخ</label>
        <input type="date" name="start_date" min="<?= $today ?>" required>
      </div>
      <div class="field">
        <label>إلى تاريخ</label>
        <input type="date" name="end_date" min="<?= $today ?>" required>
      </div>
      <div class="field">
        <label>السبب (اختياري)</label>
        <textarea name="reason" placeholder="..."></textarea>
      </div>
      <button type="submit" class="btn btn-blue">إرسال الطلب</button>
    </form>
  </div>
</div>

<!-- سجل طلباتي -->
<div class="tab-pane" id="tab-history">
  <?php if (!$myRequests): ?>
    <div class="card" style="text-align:center;color:var(--text3);padding:28px">لا توجد طلبات بعد</div>
  <?php else: ?>
    <?php foreach ($myRequests as $r):
      $sc = ['pending'=>'b-amber','approved'=>'b-green','rejected'=>'b-red'][$r['status']];
      $sl = ['pending'=>'قيد المراجعة','approved'=>'موافق عليها','rejected'=>'مرفوضة'][$r['status']];
    ?>
    <div class="req-row">
      <div class="req-header">
        <span class="req-type" style="color:<?= htmlspecialchars($r['color']) ?>"><?= htmlspecialchars($r['type_name']) ?></span>
        <span class="badge <?= $sc ?>"><?= $sl ?></span>
      </div>
      <div class="req-dates">📅 <?= $r['start_date'] ?> — <?= $r['end_date'] ?> (<?= $r['days_count'] ?> يوم)</div>
      <?php if ($r['reason']): ?>
        <div class="req-note">السبب: <?= htmlspecialchars($r['reason']) ?></div>
      <?php endif; ?>
      <?php if ($r['admin_note']): ?>
        <div class="req-note" style="color:var(--amber)">ملاحظة الإدارة: <?= htmlspecialchars($r['admin_note']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- إدارة الطلبات (أدمن / HR) -->
<?php if (isAdmin()||isHR()): ?>
<div class="tab-pane" id="tab-admin">
  <div class="card">
    <div class="card-title">⏳ الطلبات المعلّقة (<?= count($pendingAll) ?>)</div>
    <?php if (!$pendingAll): ?>
      <div style="text-align:center;color:var(--text3);padding:20px">لا توجد طلبات معلّقة 🎉</div>
    <?php else: ?>
      <?php foreach ($pendingAll as $r): ?>
      <div class="req-row">
        <div class="emp-name">👤 <?= htmlspecialchars($r['emp_name']) ?><?= $r['job_title'] ? ' — '.$r['job_title'] : '' ?></div>
        <div class="req-header">
          <span class="req-type" style="color:<?= htmlspecialchars($r['color']) ?>"><?= htmlspecialchars($r['type_name']) ?></span>
          <span class="badge b-amber">قيد المراجعة</span>
        </div>
        <div class="req-dates">📅 <?= $r['start_date'] ?> — <?= $r['end_date'] ?> (<?= $r['days_count'] ?> يوم)</div>
        <?php if ($r['reason']): ?><div class="req-note"><?= htmlspecialchars($r['reason']) ?></div><?php endif; ?>
        <form method="post" class="action-row">
          <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
          <input name="admin_note" placeholder="ملاحظة (اختياري)" style="flex:2;padding:8px 10px;background:var(--surface);border:1px solid var(--border);border-radius:8px;font-size:12px;font-family:inherit;color:var(--text)">
          <button type="submit" name="action" value="approve" class="btn btn-green" style="flex:1;margin:0">✅</button>
          <button type="submit" name="action" value="reject"  class="btn btn-red"   style="flex:1;margin:0">❌</button>
        </form>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- جدول كل الطلبات للشهر الحالي -->
  <div class="card" style="margin-top:8px">
    <div class="card-title">📋 إجازات الشهر الحالي</div>
    <?php
    $allMonth = $db->prepare("
      SELECT lr.*,lt.name as type_name,lt.color,e.name as emp_name
      FROM leave_requests lr
      JOIN leave_types lt ON lt.id=lr.leave_type_id
      JOIN employees e ON e.id=lr.employee_id
      WHERE lr.start_date LIKE ? OR lr.end_date LIKE ?
      ORDER BY lr.start_date ASC
    ");
    $allMonth->execute([date('Y-m').'%', date('Y-m').'%']);
    $monthLeaves = $allMonth->fetchAll();
    ?>
    <?php if (!$monthLeaves): ?>
      <div style="text-align:center;color:var(--text3);padding:16px">لا توجد إجازات هذا الشهر</div>
    <?php else: ?>
      <?php foreach ($monthLeaves as $r):
        $sc = ['pending'=>'b-amber','approved'=>'b-green','rejected'=>'b-red'][$r['status']];
        $sl = ['pending'=>'معلّقة','approved'=>'موافق','rejected'=>'مرفوضة'][$r['status']];
      ?>
      <div class="req-row" style="margin-bottom:6px;padding:10px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div>
            <span style="font-weight:700;font-size:13px"><?= htmlspecialchars($r['emp_name']) ?></span>
            <span style="color:var(--text3);font-size:11px"> — <?= htmlspecialchars($r['type_name']) ?> (<?= $r['days_count'] ?>د)</span>
          </div>
          <span class="badge <?= $sc ?>" style="font-size:10px"><?= $sl ?></span>
        </div>
        <div style="font-size:11px;color:var(--text3);margin-top:3px"><?= $r['start_date'] ?> → <?= $r['end_date'] ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div><!-- page-wrap -->

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  btn.classList.add('active');
}
// فتح تبويب الإدارة تلقائياً لو قادم من URL
<?php if(isset($_GET['tab'])): ?>
const tabTarget = '<?= htmlspecialchars($_GET['tab']) ?>';
const tabEl = document.getElementById('tab-'+tabTarget);
if (tabEl) {
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  tabEl.classList.add('active');
  const btn = document.getElementById(tabTarget+'-tab-btn') || document.querySelector('[onclick*="'+tabTarget+'"]');
  if(btn) btn.classList.add('active');
}
<?php endif; ?>
</script>
</body>
</html>
