<?php
// ============================================================
// notifications.php — مركز الإشعارات الداخلية
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();

$db    = getDB();
$empId = $_SESSION['employee_id'];

// ── معالجة POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $id = (int)($_POST['notif_id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND employee_id=?")->execute([$id,$empId]);
        } else {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE employee_id=?")->execute([$empId]);
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['notif_id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM notifications WHERE id=? AND employee_id=?")->execute([$id,$empId]);
    }
    header('Location: notifications.php'); exit;
}

// ── API JSON ─────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $unread = unreadNotifCount($db, $empId);
    $latest = $db->prepare("SELECT id,type,title,body,is_read,created_at FROM notifications WHERE employee_id=? ORDER BY created_at DESC LIMIT 5");
    $latest->execute([$empId]);
    echo json_encode(['unread'=>$unread,'items'=>$latest->fetchAll()]);
    exit;
}

// ── جلب الإشعارات ────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$sql = "SELECT * FROM notifications WHERE employee_id=?";
if ($filter === 'unread') $sql .= " AND is_read=0";
$sql .= " ORDER BY created_at DESC LIMIT 60";
$ns = $db->prepare($sql);
$ns->execute([$empId]);
$notifs = $ns->fetchAll();
$unreadCount = unreadNotifCount($db, $empId);

$icons = [
    'leave_approved'  => '✅',
    'leave_rejected'  => '❌',
    'leave_request'   => '📋',
    'shift_assigned'  => '📅',
    'message'         => '✉️',
    'performance'     => '⭐',
    'report'          => '📊',
];
$colors = [
    'leave_approved'  => 'b-green',
    'leave_rejected'  => 'b-red',
    'leave_request'   => 'b-amber',
    'shift_assigned'  => 'b-blue',
    'message'         => 'b-blue',
    'performance'     => 'b-gold',
    'report'          => 'b-amber',
];
$links = [
    'leave_approved'  => 'leaves.php',
    'leave_rejected'  => 'leaves.php',
    'leave_request'   => 'leaves.php?tab=admin',
    'shift_assigned'  => 'shifts.php',
    'message'         => 'messages.php',
    'performance'     => 'performance.php',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الإشعارات') ?>
<style>
.notif-item{display:flex;gap:12px;padding:13px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;text-decoration:none;color:inherit;align-items:flex-start}
.notif-item:hover{background:var(--surface2)}
.notif-item.unread{background:rgba(79,142,247,.06);border-right:3px solid var(--blue)}
.notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:var(--surface2)}
.notif-body{flex:1;min-width:0}
.notif-title{font-size:13px;font-weight:700;margin-bottom:2px}
.notif-text{font-size:12px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notif-time{font-size:10px;color:var(--text3);margin-top:3px}
.notif-del{background:none;border:none;color:var(--text3);font-size:16px;cursor:pointer;padding:0 4px;flex-shrink:0;align-self:center}
.filter-bar{display:flex;gap:6px;margin-bottom:12px}
.filter-btn{padding:7px 16px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;border:1px solid var(--border);background:var(--surface2);color:var(--text2);text-decoration:none;transition:.15s}
.filter-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
</style>
</head>
<body>
<?= getTopbar('الإشعارات') ?>
<div class="page-wrap">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
  <div style="font-size:15px;font-weight:700">
    🔔 الإشعارات
    <?php if ($unreadCount): ?>
      <span class="badge b-blue" style="font-size:11px;margin-right:6px"><?= $unreadCount ?> غير مقروء</span>
    <?php endif; ?>
  </div>
  <?php if ($unreadCount): ?>
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="mark_read">
    <button type="submit" style="background:none;border:none;color:var(--blue);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">قراءة الكل</button>
  </form>
  <?php endif; ?>
</div>

<div class="filter-bar">
  <a href="?filter=all"    class="filter-btn <?= $filter==='all'    ? 'active' : '' ?>">الكل</a>
  <a href="?filter=unread" class="filter-btn <?= $filter==='unread' ? 'active' : '' ?>">غير مقروء</a>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <?php if (!$notifs): ?>
    <div style="text-align:center;color:var(--text3);padding:32px">لا توجد إشعارات 🎉</div>
  <?php else: ?>
    <?php foreach ($notifs as $n):
      $icon  = $icons[$n['type']]  ?? '📌';
      $color = $colors[$n['type']] ?? '';
      $link  = $links[$n['type']]  ?? '#';
      $ts    = strtotime($n['created_at']);
      $diff  = time() - $ts;
      if ($diff < 60) $timeStr = 'الآن';
      elseif ($diff < 3600) $timeStr = floor($diff/60) . ' دقيقة';
      elseif ($diff < 86400) $timeStr = floor($diff/3600) . ' ساعة';
      else $timeStr = date('d/m/Y', $ts);
    ?>
    <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" onclick="markAndOpen(<?= $n['id'] ?>,'<?= htmlspecialchars($link) ?>')">
      <div class="notif-icon"><?= $icon ?></div>
      <div class="notif-body">
        <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
        <?php if ($n['body']): ?>
        <div class="notif-text"><?= htmlspecialchars($n['body']) ?></div>
        <?php endif; ?>
        <div class="notif-time"><?= $timeStr ?></div>
      </div>
      <form method="post" style="margin:0" onclick="event.stopPropagation()">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
        <button type="submit" class="notif-del">🗑</button>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</div><!-- page-wrap -->

<script>
function markAndOpen(id, link) {
  fetch('notifications.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: `action=mark_read&notif_id=${id}`
  }).then(() => { if(link && link!=='#') location.href=link; else location.reload(); });
}
</script>
</body>
</html>
