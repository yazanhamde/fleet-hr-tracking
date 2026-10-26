<?php
require_once 'includes/auth.php';
require_once 'includes/theme.php';
requireAdmin();

$db   = getDB();
$tab  = $_GET['tab'] ?? 'sales';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// ---- سجل المبيعات ----
$salesStmt = $db->prepare("
    SELECT ps.*, p.name as part_name, p.part_code, e.name as emp_name
    FROM parts_sales ps
    JOIN parts p ON p.id = ps.part_id
    JOIN employees e ON e.id = ps.employee_id
    WHERE DATE(ps.sold_at) BETWEEN ? AND ?
    ORDER BY ps.sold_at DESC
");
$salesStmt->execute([$from, $to]);
$sales = $salesStmt->fetchAll();

$totalSoldQty   = array_sum(array_column($sales, 'qty'));
$totalSoldItems = count($sales);

$partSales = [];
foreach($sales as $s) {
    $partSales[$s['part_name']] = ($partSales[$s['part_name']] ?? 0) + $s['qty'];
}
arsort($partSales);
$topPart = array_key_first($partSales) ?? '—';

// ---- سجل حركة المخزون ----
$logStmt = $db->prepare("
    SELECT il.*, p.name as part_name, p.part_code, e.name as emp_name
    FROM inventory_log il
    JOIN parts p ON p.id = il.part_id
    LEFT JOIN employees e ON e.id = il.employee_id
    WHERE DATE(il.created_at) BETWEEN ? AND ?
    ORDER BY il.created_at DESC
");
$logStmt->execute([$from, $to]);
$logs = $logStmt->fetchAll();

// ---- إحصاء القطع — بدون GROUP BY مشكلة ----
$statsStmt = $db->query("
    SELECT p.id, p.part_code, p.name, p.qty, p.section_id,
           s.label as section_label
    FROM parts p
    LEFT JOIN sections s ON s.id = p.section_id
    ORDER BY p.name ASC
");
$partsAll = $statsStmt->fetchAll();

// حساب المبيعات لكل قطعة بـ PHP بدل GROUP BY
$soldByPart = [];
$allSalesStmt = $db->query("SELECT part_id, SUM(qty) as total FROM parts_sales GROUP BY part_id");
foreach($allSalesStmt->fetchAll() as $row) {
    $soldByPart[$row['part_id']] = $row['total'];
}
foreach($partsAll as &$p) {
    $p['total_sold'] = $soldByPart[$p['id']] ?? 0;
}
unset($p);
usort($partsAll, fn($a,$b) => $b['total_sold'] - $a['total_sold']);

function actionLabel($a) {
    return match($a) {
        'sell'   => ['label'=>'بيع',    'class'=>'b-red'],
        'add'    => ['label'=>'إضافة',  'class'=>'b-green'],
        'edit'   => ['label'=>'تعديل',  'class'=>'b-amber'],
        'delete' => ['label'=>'حذف',    'class'=>'b-red'],
        default  => ['label'=>$a,       'class'=>'b-blue'],
    };
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><?= getThemeHead('تقارير المستودع') ?>
<style>
.filter-wrap{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.filter-wrap .field{flex:1;min-width:120px}
.rtabs{display:flex;background:var(--surface2);border-radius:10px;overflow:hidden;margin-bottom:14px}
.rtab{flex:1;padding:10px 6px;font-size:13px;font-weight:700;text-align:center;cursor:pointer;color:var(--text3);background:none;border:none;font-family:inherit;border-bottom:2px solid transparent;transition:.15s}
.rtab.active{color:var(--blue);border-bottom-color:var(--blue);background:var(--surface)}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tbl-wrap table{min-width:520px;width:100%;border-collapse:collapse}
.diff-plus{color:var(--green);font-weight:700}
.diff-minus{color:var(--red);font-weight:700}
@media print{.topbar,.filter-card,.rtabs{display:none!important}}
</style>
</head>
<body>
<?= getTopbar('تقارير المستودع', true, 'inventory.php') ?>
<div class="page-wrap" style="max-width:900px">

  <div class="card filter-card">
    <form method="GET" class="filter-wrap">
      <input type="hidden" name="tab" id="tab-input" value="<?=htmlspecialchars($tab)?>">
      <div class="field"><label>من تاريخ</label><input type="date" name="from" value="<?=$from?>"></div>
      <div class="field"><label>إلى تاريخ</label><input type="date" name="to" value="<?=$to?>"></div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-blue" style="width:auto;padding:10px 18px;margin:0">🔍 عرض</button>
        <button type="button" class="btn btn-ghost" style="width:auto;padding:10px 14px;margin:0" onclick="window.print()">🖨</button>
      </div>
    </form>
  </div>

  <div class="grid3" style="margin-bottom:14px">
    <div class="sbox"><div class="slabel">إجمالي عمليات البيع</div><div class="sval gd"><?=$totalSoldItems?></div></div>
    <div class="sbox"><div class="slabel">مجموع القطع المباعة</div><div class="sval r"><?=$totalSoldQty?></div></div>
    <div class="sbox"><div class="slabel">الأكثر مبيعاً</div><div class="sval g" style="font-size:14px;margin-top:4px"><?=htmlspecialchars($topPart)?></div></div>
  </div>

  <div class="rtabs">
    <button class="rtab <?=$tab==='sales'?'active':''?>" onclick="swTab('sales',this)">🛒 سجل المبيعات</button>
    <button class="rtab <?=$tab==='log'?'active':''?>"   onclick="swTab('log',this)">📋 حركة المخزون</button>
    <button class="rtab <?=$tab==='stats'?'active':''?>" onclick="swTab('stats',this)">📊 إحصاء القطع</button>
  </div>

  <!-- سجل المبيعات -->
  <div id="sec-sales" <?=$tab!=='sales'?'style="display:none"':''?>>
    <div class="card" style="padding:0;overflow:hidden">
      <div class="card-title" style="padding:14px 16px 0">🛒 سجل المبيعات (<?=count($sales)?>)</div>
      <?php if(empty($sales)): ?>
        <p style="text-align:center;color:var(--text3);padding:24px">لا توجد مبيعات في هذه الفترة</p>
      <?php else: ?>
      <div class="tbl-wrap"><table>
        <thead><tr style="background:var(--surface2)">
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ والوقت</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">القطعة</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الكود</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الموظف</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الكمية</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">ملاحظة</th>
        </tr></thead>
        <tbody>
        <?php foreach($sales as $s): ?>
        <tr>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text3)"><?=date('Y-m-d H:i', strtotime($s['sold_at']))?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-weight:700"><?=htmlspecialchars($s['part_name'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-family:monospace;color:var(--blue);font-size:12px"><?=htmlspecialchars($s['part_code'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)"><?=htmlspecialchars($s['emp_name'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)"><span class="badge b-red"><?=$s['qty']?></span></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text3)"><?=htmlspecialchars($s['note']??'—')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- حركة المخزون -->
  <div id="sec-log" <?=$tab!=='log'?'style="display:none"':''?>>
    <div class="card" style="padding:0;overflow:hidden">
      <div class="card-title" style="padding:14px 16px 0">📋 حركة المخزون (<?=count($logs)?>)</div>
      <?php if(empty($logs)): ?>
        <p style="text-align:center;color:var(--text3);padding:24px">لا توجد حركات في هذه الفترة</p>
      <?php else: ?>
      <div class="tbl-wrap"><table>
        <thead><tr style="background:var(--surface2)">
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">التاريخ</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">القطعة</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">العملية</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">قبل</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">بعد</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الفرق</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">بواسطة</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">ملاحظة</th>
        </tr></thead>
        <tbody>
        <?php foreach($logs as $l):
          $act  = actionLabel($l['action']);
          $diff = $l['qty_after'] - $l['qty_before'];
        ?>
        <tr>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text3)"><?=date('Y-m-d H:i', strtotime($l['created_at']))?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-weight:700"><?=htmlspecialchars($l['part_name'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)"><span class="badge <?=$act['class']?>"><?=$act['label']?></span></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text2)"><?=$l['qty_before']?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text2)"><?=$l['qty_after']?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)">
            <span class="<?=$diff>0?'diff-plus':'diff-minus'?>"><?=$diff>0?'+'.$diff:$diff?></span>
          </td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px"><?=htmlspecialchars($l['emp_name']??'—')?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text3)"><?=htmlspecialchars($l['note']??'—')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- إحصاء القطع -->
  <div id="sec-stats" <?=$tab!=='stats'?'style="display:none"':''?>>
    <div class="card" style="padding:0;overflow:hidden">
      <div class="card-title" style="padding:14px 16px 0">📊 إحصاء جميع القطع</div>
      <?php if(empty($partsAll)): ?>
        <p style="text-align:center;color:var(--text3);padding:24px">لا توجد قطع</p>
      <?php else: ?>
      <div class="tbl-wrap"><table>
        <thead><tr style="background:var(--surface2)">
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الكود</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الاسم</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الرف</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">المتبقي</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">إجمالي المبيعات</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:1px solid var(--border)">الحالة</th>
        </tr></thead>
        <tbody>
        <?php foreach($partsAll as $st): ?>
        <tr>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-family:monospace;color:var(--blue);font-size:12px"><?=htmlspecialchars($st['part_code'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-weight:700"><?=htmlspecialchars($st['name'])?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)"><span class="badge b-blue"><?=htmlspecialchars($st['section_label']??'—')?></span></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)"><span class="badge <?=$st['qty']<=0?'b-red':($st['qty']<=5?'b-amber':'b-green')?>"><?=$st['qty']?></span></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border);font-weight:700;color:var(--text2)"><?=$st['total_sold']?></td>
          <td style="padding:10px 12px;border-bottom:1px solid var(--border)">
            <?php if($st['qty']<=0): ?>
              <span class="badge b-red">نفدت</span>
            <?php elseif($st['qty']<=5): ?>
              <span class="badge b-amber">منخفضة</span>
            <?php else: ?>
              <span class="badge b-green">وفيرة</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

</div>
<script>
function swTab(t, btn) {
  ['sales','log','stats'].forEach(x => {
    document.getElementById('sec-'+x).style.display = x===t ? '' : 'none';
  });
  document.querySelectorAll('.rtab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-input').value = t;
  const url = new URL(window.location);
  url.searchParams.set('tab', t);
  history.replaceState({}, '', url);
}
</script>
</body>
</html>
