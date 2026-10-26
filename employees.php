<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireAdmin();

$db  = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $check = $db->prepare("SELECT id FROM employees WHERE username=?");
        $check->execute([$_POST['username']]);
        if ($check->fetch()) {
            $msg = 'error:اسم المستخدم موجود مسبقاً';
        } else {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $canReports = isset($_POST['can_view_reports']) ? 1 : 0;
            $stmt = $db->prepare("INSERT INTO employees (name, username, password, role, phone, work_start, work_end, can_view_reports) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $_POST['name'], $_POST['username'], $hash,
                $_POST['role'], $_POST['phone'],
                $_POST['work_start'] ?: '10:00:00',
                $_POST['work_end']   ?: '21:00:00',
                $canReports
            ]);
            $msg = 'success:تمت إضافة الموظف بنجاح ✓';
        }
    } elseif ($_POST['action'] === 'delete') {
        if ($_POST['id'] != $_SESSION['employee_id']) {
            $id = $_POST['id'];
            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            $db->prepare("DELETE FROM attendance WHERE employee_id=?")->execute([$id]);
            $db->prepare("DELETE FROM employee_locations WHERE employee_id=?")->execute([$id]);
            $db->prepare("DELETE FROM employee_notes WHERE employee_id=?")->execute([$id]);
            $db->prepare("DELETE FROM vehicle_tracking WHERE employee_id=?")->execute([$id]);
            $db->prepare("DELETE FROM trips WHERE employee_id=?")->execute([$id]);
            $db->prepare("DELETE FROM alerts WHERE employee_id=?")->execute([$id]);
            $db->prepare("UPDATE vehicles SET assigned_to=NULL, status='idle' WHERE assigned_to=?")->execute([$id]);
            $db->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            $msg = 'success:تم حذف الموظف';
        } else {
            $msg = 'error:لا تقدر تحذف حسابك الحالي';
        }
    } elseif ($_POST['action'] === 'reset_pass') {
        $hash = password_hash($_POST['new_pass'], PASSWORD_BCRYPT);
        $db->prepare("UPDATE employees SET password=? WHERE id=?")->execute([$hash, $_POST['id']]);
        $msg = 'success:تم تغيير كلمة السر ✓';
    } elseif ($_POST['action'] === 'edit') {
        $canReports = isset($_POST['can_view_reports']) ? 1 : 0;
        $stmt = $db->prepare("UPDATE employees SET name=?, phone=?, role=?, work_start=?, work_end=?, can_view_reports=? WHERE id=?");
        $stmt->execute([$_POST['name'], $_POST['phone'], $_POST['role'], $_POST['work_start'], $_POST['work_end'], $canReports, $_POST['id']]);
        $msg = 'success:تم التحديث ✓';
    }
}

$employees = $db->query("SELECT * FROM employees ORDER BY role DESC, name")->fetchAll();
$today     = date('Y-m-d');
$attToday  = [];
$att = $db->prepare("SELECT * FROM attendance WHERE date=?");
$att->execute([$today]);
foreach ($att->fetchAll() as $a) $attToday[$a['employee_id']] = $a;

$total   = count($employees);
$present = count(array_filter($employees, fn($e) => isset($attToday[$e['id']])));
$absent  = $total - $present;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><?= getThemeHead('إدارة الموظفين') ?></head>
<body>
<?= getTopbar('إدارة الموظفين') ?>

<div class="page-wrap" style="max-width:860px">

  <?php if($msg): $parts=explode(':',$msg,2); ?>
    <div class="notice <?=$parts[0]==='success'?'n-green':'n-red'?>"><?=$parts[1]?></div>
  <?php endif; ?>

  <!-- إحصاء سريع -->
  <div class="grid3" style="margin-bottom:12px">
    <div class="sbox"><div class="slabel">إجمالي</div><div class="sval gd"><?=$total?></div></div>
    <div class="sbox"><div class="slabel">حاضر اليوم</div><div class="sval g"><?=$present?></div></div>
    <div class="sbox"><div class="slabel">غائب اليوم</div><div class="sval r"><?=$absent?></div></div>
  </div>

  <!-- إضافة موظف -->
  <div class="card">
    <div class="card-title">➕ إضافة موظف جديد</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="grid2">
        <div class="field"><label>الاسم الكامل *</label><input type="text" name="name" required placeholder="مثال: أحمد محمد"></div>
        <div class="field"><label>اسم المستخدم *</label><input type="text" name="username" required placeholder="admin@store.com"></div>
        <div class="field"><label>كلمة السر *</label><input type="password" name="password" required placeholder="••••••••"></div>
        <div class="field"><label>رقم الهاتف</label><input type="text" name="phone" placeholder="07xxxxxxxx"></div>
        <div class="field">
          <label>الصلاحية</label>
          <select name="role">
            <option value="employee">موظف</option>
            <option value="driver">سائق</option>
            <option value="admin">مدير</option>
          </select>
        </div>
        <div class="field" style="display:flex;align-items:center;gap:8px;padding-top:22px">
          <input type="checkbox" name="can_view_reports" id="a-reports" style="width:18px;height:18px">
          <label for="a-reports" style="margin:0;cursor:pointer">📊 يقدر يشوف صفحة التقارير</label>
        </div>
        <div class="field"><label>وقت بدء الدوام</label><input type="time" name="work_start" value="10:00"></div>
        <div class="field"><label>وقت انتهاء الدوام</label><input type="time" name="work_end" value="21:00"></div>
      </div>
      <button type="submit" class="btn btn-blue" style="width:auto;padding:12px 28px;margin-top:4px">➕ إضافة الموظف</button>
    </form>
  </div>

  <!-- قائمة الموظفين -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="card-title" style="padding:14px 16px 0">👥 الموظفون (<?=count($employees)?>)</div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead>
          <tr style="background:var(--surface2)">
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الاسم</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">المستخدم</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الهاتف</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الدوام</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">حضور اليوم</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الصلاحية</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($employees as $emp):
            $a = $attToday[$emp['id']] ?? null;
          ?>
          <tr>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-weight:700"><?=htmlspecialchars($emp['name'])?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-family:monospace;font-size:12px;color:var(--blue)"><?=htmlspecialchars($emp['username'])?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);color:var(--text2)"><?=htmlspecialchars($emp['phone']??'—')?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text2)"><?=substr($emp['work_start'],0,5)?> — <?=substr($emp['work_end'],0,5)?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)">
              <?php if(!$a): ?>
                <span class="badge b-red">غائب</span>
              <?php elseif($a['check_out']): ?>
                <span class="badge b-green">خرج <?=substr($a['check_out'],11,5)?></span>
              <?php else: ?>
                <span class="badge <?=$a['status']==='late'?'b-amber':'b-green'?>">
                  دخل <?=substr($a['check_in'],11,5)?><?=$a['late_minutes']>0?' (+'.($a['late_minutes']).'د)':''?>
                </span>
              <?php endif; ?>
            </td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)">
              <span class="badge <?=$emp['role']==='admin'?'b-blue':($emp['role']==='driver'?'b-gold':'b-green')?>">
                <?=$emp['role']==='admin'?'مدير':($emp['role']==='driver'?'سائق':'موظف')?>
              </span>
              <?php if(!empty($emp['can_view_reports'])): ?>
                <span class="badge b-amber" style="margin-right:4px">📊 تقارير</span>
              <?php endif; ?>
            </td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)">
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <button onclick="openEdit(<?=htmlspecialchars(json_encode($emp))?> )"
                  style="padding:5px 10px;border-radius:6px;border:none;background:var(--surface2);color:var(--text2);cursor:pointer;font-size:12px;font-family:inherit;border:1px solid var(--border2)">تعديل</button>
                <button onclick="openPass(<?=$emp['id']?>,'<?=htmlspecialchars(addslashes($emp['name']))?>')"
                  style="padding:5px 10px;border-radius:6px;border:none;background:var(--amber-bg);color:var(--amber);cursor:pointer;font-size:12px;font-family:inherit">كلمة السر</button>
                <a href="leaves.php?emp_id=<?=$emp['id']?>"
                  style="padding:5px 10px;border-radius:6px;background:var(--green-bg);color:var(--green);font-size:12px;text-decoration:none;font-family:inherit;display:inline-block">🌴 إجازات</a>
                <a href="performance.php?emp_id=<?=$emp['id']?>"
                  style="padding:5px 10px;border-radius:6px;background:var(--blue-bg);color:var(--blue);font-size:12px;text-decoration:none;font-family:inherit;display:inline-block">⭐ تقييم</a>
                <a href="employee_report.php?emp_id=<?=$emp['id']?>"
                  style="padding:5px 10px;border-radius:6px;background:var(--surface2);color:var(--text2);font-size:12px;text-decoration:none;font-family:inherit;display:inline-block;border:1px solid var(--border)">📊 تقرير</a>
                <?php if($emp['id'] != $_SESSION['employee_id']): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف <?=htmlspecialchars($emp['name'])?>؟')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=$emp['id']?>">
                  <button type="submit" style="padding:5px 10px;border-radius:6px;border:none;background:var(--red-bg);color:var(--red);cursor:pointer;font-size:12px;font-family:inherit">حذف</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>


</div>

<!-- Modal تعديل -->
<div class="modal-bg" id="edit-modal">
  <div class="modal-box">
    <h3>✏️ تعديل الموظف</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="e-id">
      <div class="field"><label>الاسم الكامل *</label><input type="text" name="name" id="e-name" required></div>
      <div class="field"><label>رقم الهاتف</label><input type="text" name="phone" id="e-phone"></div>
      <div class="field">
        <label>الصلاحية</label>
        <select name="role" id="e-role">
          <option value="employee">موظف</option>
          <option value="driver">سائق</option>
          <option value="admin">مدير</option>
        </select>
      </div>
      <div class="field" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="can_view_reports" id="e-reports" style="width:18px;height:18px">
        <label for="e-reports" style="margin:0;cursor:pointer">📊 يقدر يشوف صفحة التقارير</label>
      </div>
      <div class="grid2">
        <div class="field"><label>بدء الدوام</label><input type="time" name="work_start" id="e-wstart"></div>
        <div class="field"><label>نهاية الدوام</label><input type="time" name="work_end" id="e-wend"></div>
      </div>
      <div class="mbtns">
        <button type="button" class="mbtn mc" onclick="closeModals()">إلغاء</button>
        <button type="submit" class="mbtn ms">حفظ ✓</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal كلمة السر -->
<div class="modal-bg" id="pass-modal">
  <div class="modal-box">
    <h3>🔑 تغيير كلمة السر</h3>
    <p style="font-size:13px;color:var(--text3);margin-bottom:14px" id="pass-name"></p>
    <form method="POST">
      <input type="hidden" name="action" value="reset_pass">
      <input type="hidden" name="id" id="p-id">
      <div class="field"><label>كلمة السر الجديدة</label><input type="password" name="new_pass" required placeholder="••••••••"></div>
      <div class="mbtns">
        <button type="button" class="mbtn mc" onclick="closeModals()">إلغاء</button>
        <button type="submit" class="mbtn ms">تغيير ✓</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(emp) {
  document.getElementById('e-id').value    = emp.id;
  document.getElementById('e-name').value  = emp.name;
  document.getElementById('e-phone').value = emp.phone || '';
  document.getElementById('e-role').value  = emp.role;
  document.getElementById('e-reports').checked = !!Number(emp.can_view_reports);
  document.getElementById('e-wstart').value = emp.work_start ? emp.work_start.substr(0,5) : '10:00';
  document.getElementById('e-wend').value   = emp.work_end   ? emp.work_end.substr(0,5)   : '21:00';
  document.getElementById('edit-modal').classList.add('open');
}
function openPass(id, name) {
  document.getElementById('p-id').value = id;
  document.getElementById('pass-name').textContent = 'الموظف: ' + name;
  document.getElementById('pass-modal').classList.add('open');
}
function closeModals() {
  document.querySelectorAll('.modal-bg').forEach(m => m.classList.remove('open'));
}
document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('click', e => { if(e.target === m) closeModals(); });
});
</script>
</body>
</html>
