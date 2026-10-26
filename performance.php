<?php
// ============================================================
// performance.php — تقييم الأداء الشهري
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();
if (isDriver()) { header('Location: '.SITE_URL.'/driver.php'); exit; }

$db    = getDB();
$empId = $_SESSION['employee_id'];
$canManage = isAdmin() || isHR();

$msg = $err = '';
$curYear  = (int)date('Y');
$curMonth = (int)date('n');

// ── معالجة POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $targetId = (int)($_POST['employee_id']       ?? 0);
    $ry       = (int)($_POST['review_year']        ?? $curYear);
    $rm       = (int)($_POST['review_month']       ?? $curMonth);
    $sa       = (int)($_POST['score_attendance']   ?? 0);
    $sp       = (int)($_POST['score_performance']  ?? 0);
    $sb       = (int)($_POST['score_behavior']     ?? 0);
    $sc       = (int)($_POST['score_commitment']   ?? 0);
    $notes    = trim($_POST['notes']               ?? '');

    if ($targetId < 1 || $sa<1||$sp<1||$sb<1||$sc<1) {
        $err = 'يرجى تعبئة جميع درجات التقييم';
    } else {
        $total = round(($sa+$sp+$sb+$sc)/4, 2);
        $db->prepare("
          INSERT INTO performance_reviews
            (employee_id,reviewer_id,review_year,review_month,score_attendance,score_performance,score_behavior,score_commitment,score_total,notes)
          VALUES (?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            reviewer_id=VALUES(reviewer_id),
            score_attendance=VALUES(score_attendance),
            score_performance=VALUES(score_performance),
            score_behavior=VALUES(score_behavior),
            score_commitment=VALUES(score_commitment),
            score_total=VALUES(score_total),
            notes=VALUES(notes)
        ")->execute([$targetId,$empId,$ry,$rm,$sa,$sp,$sb,$sc,$total,$notes]);

        $monthAr = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        notifyEmployee($db,$targetId,'performance',
            "تقييم الأداء لشهر {$monthAr[$rm]} {$ry}",
            "حصلت على تقييم إجمالي: {$total}/5" . ($notes ? " — {$notes}" : ''),
            0,'performance'
        );
        $msg = "تم حفظ التقييم بنجاح (إجمالي: {$total}/5) ✅";
    }
    header('Location: performance.php' . ($err ? '?err='.urlencode($err) : '?ok=1'));
    exit;
}
if (isset($_GET['ok']))  $msg = 'تم الحفظ ✅';
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── بيانات الصفحة ────────────────────────────────────────────
$employees = $db->query("SELECT id,name,job_title FROM employees WHERE role IN ('employee','driver','hr') ORDER BY name")->fetchAll();

// تقييمات الموظف الحالي
$myReviews = $db->prepare("
  SELECT pr.*,e.name as reviewer_name
  FROM performance_reviews pr
  JOIN employees e ON e.id=pr.reviewer_id
  WHERE pr.employee_id=?
  ORDER BY pr.review_year DESC,pr.review_month DESC
  LIMIT 12
");
$myReviews->execute([$empId]);
$myReviewRows = $myReviews->fetchAll();

// للأدمن: آخر تقييمات الكل
$allReviews = [];
if ($canManage) {
    $ar = $db->prepare("
      SELECT pr.*,e.name as emp_name,e.job_title
      FROM performance_reviews pr
      JOIN employees e ON e.id=pr.employee_id
      WHERE pr.review_year=? AND pr.review_month=?
      ORDER BY pr.score_total DESC
    ");
    $ar->execute([$curYear,$curMonth]);
    $allReviews = $ar->fetchAll();
}

$monthAr = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

function starRating(float $score): string {
    $full  = (int)$score;
    $half  = ($score - $full) >= 0.5 ? 1 : 0;
    $empty = 5 - $full - $half;
    return str_repeat('★',$full) . str_repeat('½',$half) . str_repeat('☆',$empty);
}

function scoreColor(float $s): string {
    if ($s>=4.5) return '#3ecf8e';
    if ($s>=3.5) return '#4f8ef7';
    if ($s>=2.5) return '#f5a623';
    return '#e25b5b';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('تقييم الأداء') ?>
<style>
.score-ring{width:70px;height:70px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:20px;font-weight:800;margin:0 auto;border:3px solid currentColor}
.criteria-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.criteria-row:last-child{border-bottom:none}
.criteria-name{flex:1;font-size:13px;font-weight:600}
.stars-input{display:flex;gap:4px;flex-direction:row-reverse;justify-content:flex-end}
.stars-input input{display:none}
.stars-input label{font-size:22px;color:var(--border2);cursor:pointer;transition:color .1s}
.stars-input input:checked ~ label,
.stars-input label:hover,.stars-input label:hover ~ label{color:var(--gold)}
.rev-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:13px;margin-bottom:8px}
.rev-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.rev-scores{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px}
.rev-score-item{display:flex;justify-content:space-between;padding:4px 0;color:var(--text2)}
.rev-score-item strong{color:var(--gold)}
</style>
</head>
<body>
<?= getTopbar('تقييم الأداء') ?>
<div class="page-wrap">

<?php if ($msg): ?><div class="notice n-green">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice n-red">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- تقييماتي -->
<div class="card">
  <div class="card-title">⭐ تقييماتي</div>
  <?php if (!$myReviewRows): ?>
    <div style="text-align:center;color:var(--text3);padding:20px">لا توجد تقييمات بعد</div>
  <?php else: ?>
    <?php foreach ($myReviewRows as $r): ?>
    <div class="rev-card">
      <div class="rev-header">
        <div>
          <div style="font-weight:700;font-size:14px"><?= $monthAr[$r['review_month']] ?> <?= $r['review_year'] ?></div>
          <div style="font-size:11px;color:var(--text3)">بواسطة: <?= htmlspecialchars($r['reviewer_name']) ?></div>
        </div>
        <div style="text-align:center">
          <div style="font-size:22px;font-weight:800;color:<?= scoreColor((float)$r['score_total']) ?>"><?= $r['score_total'] ?></div>
          <div style="font-size:10px;color:var(--text3)">من 5</div>
        </div>
      </div>
      <div class="rev-scores">
        <div class="rev-score-item">الحضور <strong><?= $r['score_attendance'] ?>/5</strong></div>
        <div class="rev-score-item">الأداء <strong><?= $r['score_performance'] ?>/5</strong></div>
        <div class="rev-score-item">السلوك <strong><?= $r['score_behavior'] ?>/5</strong></div>
        <div class="rev-score-item">الالتزام <strong><?= $r['score_commitment'] ?>/5</strong></div>
      </div>
      <?php if ($r['notes']): ?>
        <div style="margin-top:8px;font-size:12px;color:var(--text2);padding:8px;background:var(--surface);border-radius:6px"><?= htmlspecialchars($r['notes']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- نموذج التقييم (أدمن/HR) -->
<?php if ($canManage): ?>
<div class="card">
  <div class="card-title">📝 إجراء تقييم جديد</div>
  <form method="post">
    <div class="field">
      <label>الموظف</label>
      <select name="employee_id" required>
        <option value="">— اختر موظفاً —</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?><?= $e['job_title'] ? " ({$e['job_title']})" : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grid2" style="gap:10px;margin-bottom:12px">
      <div class="field" style="margin:0">
        <label>السنة</label>
        <select name="review_year">
          <?php for ($y=$curYear; $y>=$curYear-2; $y--): ?>
          <option value="<?= $y ?>" <?= $y===$curYear?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field" style="margin:0">
        <label>الشهر</label>
        <select name="review_month">
          <?php for ($m=1; $m<=12; $m++): ?>
          <option value="<?= $m ?>" <?= $m===$curMonth?'selected':'' ?>><?= $monthAr[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>

    <?php
    $criteria = [
        ['score_attendance',  'الحضور والانضباط'],
        ['score_performance', 'الأداء والإنتاجية'],
        ['score_behavior',    'السلوك والتعاون'],
        ['score_commitment',  'الالتزام بالمهام'],
    ];
    foreach ($criteria as [$name,$label]): ?>
    <div class="criteria-row">
      <div class="criteria-name"><?= $label ?></div>
      <div class="stars-input">
        <?php for ($i=5; $i>=1; $i--): ?>
        <input type="radio" name="<?= $name ?>" id="<?= $name ?>_<?= $i ?>" value="<?= $i ?>" required>
        <label for="<?= $name ?>_<?= $i ?>">★</label>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="field" style="margin-top:12px">
      <label>ملاحظات (اختياري)</label>
      <textarea name="notes" placeholder="تعليق على أداء الموظف..."></textarea>
    </div>
    <button type="submit" class="btn btn-blue">حفظ التقييم</button>
  </form>
</div>

<!-- تقييمات الشهر الحالي للجميع -->
<?php if ($allReviews): ?>
<div class="card">
  <div class="card-title">📊 تقييمات <?= $monthAr[$curMonth] ?> <?= $curYear ?></div>
  <?php foreach ($allReviews as $r): ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:6px">
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($r['emp_name']) ?></div>
      <?php if ($r['job_title']): ?><div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($r['job_title']) ?></div><?php endif; ?>
    </div>
    <div style="font-size:20px;font-weight:800;color:<?= scoreColor((float)$r['score_total']) ?>"><?= $r['score_total'] ?>/5</div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
