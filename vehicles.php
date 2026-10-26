<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireAdmin();

$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $stmt = $db->prepare("INSERT INTO vehicles (name, plate_number, model, assigned_to) VALUES (?,?,?,?)");
        $stmt->execute([$_POST['name'],$_POST['plate_number'],$_POST['model'],$_POST['assigned_to']?:null]);
        $msg = 'success:تمت إضافة السيارة بنجاح ✓';
    } elseif ($_POST['action'] === 'delete') {
        $db->prepare("DELETE FROM vehicles WHERE id=?")->execute([$_POST['id']]);
        $msg = 'success:تم حذف السيارة';
    } elseif ($_POST['action'] === 'edit') {
        $stmt = $db->prepare("UPDATE vehicles SET name=?, plate_number=?, model=?, assigned_to=? WHERE id=?");
        $stmt->execute([$_POST['name'],$_POST['plate_number'],$_POST['model'],$_POST['assigned_to']?:null,$_POST['id']]);
        $msg = 'success:تم تحديث السيارة ✓';
    } elseif ($_POST['action'] === 'alert') {
        $db->prepare("UPDATE vehicles SET status='alert' WHERE id=?")->execute([$_POST['id']]);
        $msg = 'success:تم تفعيل التنبيه';
    }
}

$vehicles = $db->query("SELECT v.*, e.name as driver_name FROM vehicles v LEFT JOIN employees e ON e.id=v.assigned_to ORDER BY v.id")->fetchAll();
$drivers  = $db->query("SELECT id, name FROM employees WHERE role='driver' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><?= getThemeHead('إدارة السيارات') ?></head>
<body>
<?= getTopbar('إدارة السيارات') ?>
<div class="page-wrap" style="max-width:800px">

  <?php if($msg): $parts=explode(':', $msg, 2); ?>
    <div class="notice <?=$parts[0]==='success'?'n-green':'n-red'?>" style="margin-bottom:4px"><?=$parts[1]?></div>
  <?php endif; ?>

  <!-- إضافة سيارة -->
  <div class="card">
    <div class="card-title">➕ إضافة سيارة جديدة</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="grid2">
        <div class="field"><label>اسم السيارة *</label><input type="text" name="name" placeholder="مثال: سيارة 1" required></div>
        <div class="field"><label>رقم اللوحة</label><input type="text" name="plate_number" placeholder="مثال: ABC-34-12"></div>
        <div class="field"><label>الموديل</label><input type="text" name="model" placeholder="مثال: تويوتا كامري 2022"></div>
        <div class="field"><label>السائق المخصص (اختياري)</label>
          <select name="assigned_to">
            <option value="">— بدون سائق ثابت —</option>
            <?php foreach($drivers as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-green" style="width:auto;padding:12px 24px">➕ إضافة السيارة</button>
    </form>
  </div>

  <!-- قائمة السيارات -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="card-title" style="padding:14px 16px 0">🚗 السيارات الحالية (<?=count($vehicles)?>)</div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr style="background:var(--surface2)">
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الاسم</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">اللوحة</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الموديل</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">السائق</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الحالة</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">إجراءات</th>
        </tr></thead>
        <tbody>
          <?php foreach($vehicles as $v): ?>
          <tr>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-weight:600"><?=htmlspecialchars($v['name'])?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-family:monospace;color:var(--blue)"><?=htmlspecialchars($v['plate_number']??'')?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border);color:var(--text2)"><?=htmlspecialchars($v['model']??'')?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)"><?=$v['driver_name']?htmlspecialchars($v['driver_name']):'<span style="color:var(--text3)">—</span>'?></td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)">
              <span class="badge <?=$v['status']==='active'?'b-green':($v['status']==='alert'?'b-red':'b-gold')?>"><?=$v['status']==='active'?'نشطة':($v['status']==='alert'?'تنبيه':'متوقفة')?></span>
            </td>
            <td style="padding:11px 12px;border-bottom:1px solid var(--border)">
              <div style="display:flex;gap:6px">
                <button onclick="openEdit(<?=$v['id']?>,'<?=htmlspecialchars(addslashes($v['name']))?>', '<?=htmlspecialchars(addslashes($v['plate_number']??''))?>', '<?=htmlspecialchars(addslashes($v['model']??''))?>', '<?=$v['assigned_to']??''?>')" style="padding:5px 10px;border-radius:6px;border:none;background:var(--amber-bg);color:var(--amber);cursor:pointer;font-size:12px">تعديل</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف السيارة؟')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=$v['id']?>">
                  <button type="submit" style="padding:5px 10px;border-radius:6px;border:none;background:var(--red-bg);color:var(--red);cursor:pointer;font-size:12px">حذف</button>
                </form>
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
    <h3>✏️ تعديل السيارة</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="e-id">
      <div class="field"><label>الاسم *</label><input type="text" name="name" id="e-name" required></div>
      <div class="field"><label>رقم اللوحة</label><input type="text" name="plate_number" id="e-plate"></div>
      <div class="field"><label>الموديل</label><input type="text" name="model" id="e-model"></div>
      <div class="field"><label>السائق</label>
        <select name="assigned_to" id="e-driver">
          <option value="">— بدون سائق —</option>
          <?php foreach($drivers as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mbtns">
        <button type="button" class="mbtn mc" onclick="document.getElementById('edit-modal').classList.remove('open')">إلغاء</button>
        <button type="submit" class="mbtn ms">حفظ ✓</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id,name,plate,model,driver){
  document.getElementById('e-id').value=id;
  document.getElementById('e-name').value=name;
  document.getElementById('e-plate').value=plate;
  document.getElementById('e-model').value=model;
  document.getElementById('e-driver').value=driver||'';
  document.getElementById('edit-modal').classList.add('open');
}
document.getElementById('edit-modal').addEventListener('click',e=>{if(e.target===document.getElementById('edit-modal'))document.getElementById('edit-modal').classList.remove('open');});
</script>
</body>
</html>
