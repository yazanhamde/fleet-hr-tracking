<?php
// ============================================================
// messages.php — المراسلة الداخلية
// ============================================================
require_once 'includes/auth.php';
require_once 'includes/theme.php';
require_once 'includes/hr_helpers.php';
requireLogin();

$db    = getDB();
$empId = $_SESSION['employee_id'];

$msg = $err = '';

// ── معالجة POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $subject    = trim($_POST['subject']      ?? '');
        $body       = trim($_POST['body']         ?? '');
        if ($receiverId < 1 || !$body) {
            $err = 'يرجى اختيار المستقبل وكتابة نص الرسالة';
        } elseif ($receiverId === $empId) {
            $err = 'لا يمكنك إرسال رسالة لنفسك';
        } else {
            $db->prepare("INSERT INTO internal_messages (sender_id,receiver_id,subject,body) VALUES (?,?,?,?)")
               ->execute([$empId,$receiverId,$subject,$body]);
            $msgId = $db->lastInsertId();
            notifyEmployee($db,$receiverId,'message',
                'رسالة جديدة من ' . getEmpName($db,$empId),
                $subject ?: mb_substr($body,0,60),
                $msgId,'message'
            );
            $msg = 'تم إرسال الرسالة ✅';
        }
    }

    if ($action === 'mark_read') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        if ($msgId) {
            $db->prepare("UPDATE internal_messages SET is_read=1,read_at=NOW() WHERE id=? AND receiver_id=?")->execute([$msgId,$empId]);
        }
    }

    if ($action === 'delete') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        $box   = $_POST['box'] ?? 'inbox';
        if ($msgId) {
            if ($box === 'inbox')  $db->prepare("UPDATE internal_messages SET deleted_by_receiver=1 WHERE id=? AND receiver_id=?")->execute([$msgId,$empId]);
            if ($box === 'sent')   $db->prepare("UPDATE internal_messages SET deleted_by_sender=1   WHERE id=? AND sender_id=?")->execute([$msgId,$empId]);
        }
    }

    header('Location: messages.php' . ($err ? '?err='.urlencode($err) : ($msg ? '?ok=1' : '')));
    exit;
}
if (isset($_GET['ok']))  $msg = 'تمت العملية ✅';
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── بيانات ──────────────────────────────────────────────────
$view  = $_GET['view'] ?? 'inbox';
$openId= (int)($_GET['open'] ?? 0);

// صندوق الوارد
$inbox = $db->prepare("
  SELECT im.*,e.name as sender_name
  FROM internal_messages im
  JOIN employees e ON e.id=im.sender_id
  WHERE im.receiver_id=? AND im.deleted_by_receiver=0
  ORDER BY im.created_at DESC LIMIT 50
");
$inbox->execute([$empId]);
$inboxRows = $inbox->fetchAll();

// الصادر
$sent = $db->prepare("
  SELECT im.*,e.name as receiver_name
  FROM internal_messages im
  JOIN employees e ON e.id=im.receiver_id
  WHERE im.sender_id=? AND im.deleted_by_sender=0
  ORDER BY im.created_at DESC LIMIT 50
");
$sent->execute([$empId]);
$sentRows = $sent->fetchAll();

// كل الموظفين للإرسال
$allEmps = $db->query("SELECT id,name,job_title FROM employees WHERE id != {$empId} ORDER BY name")->fetchAll();

// فتح رسالة محددة
$openMsg = null;
if ($openId) {
    $om = $db->prepare("SELECT im.*,s.name as sender_name,r.name as receiver_name FROM internal_messages im JOIN employees s ON s.id=im.sender_id JOIN employees r ON r.id=im.receiver_id WHERE im.id=? AND (im.sender_id=? OR im.receiver_id=?)");
    $om->execute([$openId,$empId,$empId]);
    $openMsg = $om->fetch();
    if ($openMsg && $openMsg['receiver_id']==$empId && !$openMsg['is_read']) {
        $db->prepare("UPDATE internal_messages SET is_read=1,read_at=NOW() WHERE id=?")->execute([$openId]);
        $openMsg['is_read'] = 1;
    }
}

$unreadCount = unreadMsgCount($db, $empId);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('الرسائل') ?>
<style>
.msg-list{padding:0}
.msg-row{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;align-items:flex-start}
.msg-row:hover{background:var(--surface2)}
.msg-row.unread{background:rgba(79,142,247,.05);border-right:3px solid var(--blue)}
.msg-row.selected{background:var(--surface2);border-right:3px solid var(--green)}
.msg-avatar{width:34px;height:34px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;color:var(--blue)}
.msg-meta{flex:1;min-width:0}
.msg-from{font-size:13px;font-weight:700}
.msg-subj{font-size:12px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-time{font-size:10px;color:var(--text3);margin-top:2px}
.msg-body-view{padding:16px;background:var(--surface2);border-radius:10px;margin-top:10px;font-size:14px;line-height:1.7;white-space:pre-wrap;word-break:break-word}
.tabs{display:flex;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:12px}
.tab-btn{flex:1;padding:10px 4px;font-size:13px;font-weight:700;text-align:center;cursor:pointer;background:none;border:none;color:var(--text3);font-family:inherit;transition:.15s;text-decoration:none;display:block}
.tab-btn.active{background:var(--blue);color:#fff}
</style>
</head>
<body>
<?= getTopbar('الرسائل') ?>
<div class="page-wrap">

<?php if ($msg): ?><div class="notice n-green">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice n-red">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="tabs">
  <a href="?view=inbox"   class="tab-btn <?= $view==='inbox'   ? 'active' : '' ?>">📥 الوارد <?= $unreadCount ? "<span class='badge b-blue' style='font-size:10px;padding:1px 5px'>{$unreadCount}</span>" : '' ?></a>
  <a href="?view=sent"    class="tab-btn <?= $view==='sent'    ? 'active' : '' ?>">📤 الصادر</a>
  <a href="?view=compose" class="tab-btn <?= $view==='compose' ? 'active' : '' ?>">✏️ رسالة جديدة</a>
</div>

<?php if ($view === 'compose'): ?>
<!-- رسالة جديدة -->
<div class="card">
  <div class="card-title">✏️ إنشاء رسالة</div>
  <form method="post">
    <input type="hidden" name="action" value="send">
    <div class="field">
      <label>إلى</label>
      <select name="receiver_id" required>
        <option value="">— اختر الموظف —</option>
        <?php foreach ($allEmps as $e): ?>
        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?><?= $e['job_title'] ? " ({$e['job_title']})" : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>الموضوع (اختياري)</label>
      <input type="text" name="subject" placeholder="موضوع الرسالة...">
    </div>
    <div class="field">
      <label>نص الرسالة</label>
      <textarea name="body" placeholder="اكتب رسالتك..." required style="height:130px"></textarea>
    </div>
    <button type="submit" class="btn btn-blue">إرسال ←</button>
  </form>
</div>

<?php elseif ($view === 'inbox'): ?>
<!-- صندوق الوارد -->
<?php if ($openMsg && $openMsg['receiver_id']==$empId): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <div>
      <div style="font-size:15px;font-weight:700"><?= htmlspecialchars($openMsg['subject'] ?: '(بدون موضوع)') ?></div>
      <div style="font-size:12px;color:var(--text3)">من: <?= htmlspecialchars($openMsg['sender_name']) ?> — <?= date('d/m/Y H:i', strtotime($openMsg['created_at'])) ?></div>
    </div>
    <a href="?view=inbox" class="badge b-amber">← رجوع</a>
  </div>
  <div class="msg-body-view"><?= htmlspecialchars($openMsg['body']) ?></div>
  <div style="display:flex;gap:8px;margin-top:12px">
    <a href="?view=compose&reply_to=<?= $openMsg['sender_id'] ?>" class="btn btn-blue" style="margin:0;padding:10px">↩ رد</a>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="msg_id" value="<?= $openMsg['id'] ?>">
      <input type="hidden" name="box" value="inbox">
      <button type="submit" class="btn btn-red" style="margin:0;padding:10px">🗑 حذف</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden">
  <?php if (!$inboxRows): ?>
    <div style="text-align:center;color:var(--text3);padding:28px">صندوق الوارد فارغ ✉️</div>
  <?php else: ?>
    <?php foreach ($inboxRows as $r): ?>
    <div class="msg-row <?= !$r['is_read'] ? 'unread' : '' ?> <?= $r['id']==$openId ? 'selected' : '' ?>" onclick="location.href='?view=inbox&open=<?= $r['id'] ?>'">
      <div class="msg-avatar"><?= mb_substr($r['sender_name'],0,1) ?></div>
      <div class="msg-meta">
        <div class="msg-from"><?= htmlspecialchars($r['sender_name']) ?> <?= !$r['is_read'] ? '<span class="badge b-blue" style="font-size:9px;padding:1px 5px">جديد</span>' : '' ?></div>
        <div class="msg-subj"><?= htmlspecialchars($r['subject'] ?: mb_substr($r['body'],0,50)) ?></div>
        <div class="msg-time"><?= date('d/m H:i', strtotime($r['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- الصادر -->
<?php if ($openMsg && $openMsg['sender_id']==$empId): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <div>
      <div style="font-size:15px;font-weight:700"><?= htmlspecialchars($openMsg['subject'] ?: '(بدون موضوع)') ?></div>
      <div style="font-size:12px;color:var(--text3)">إلى: <?= htmlspecialchars($openMsg['receiver_name']) ?> — <?= date('d/m/Y H:i', strtotime($openMsg['created_at'])) ?></div>
    </div>
    <a href="?view=sent" class="badge b-amber">← رجوع</a>
  </div>
  <div class="msg-body-view"><?= htmlspecialchars($openMsg['body']) ?></div>
  <div style="margin-top:10px;font-size:12px;color:<?= $openMsg['is_read'] ? 'var(--green)' : 'var(--text3)' ?>">
    <?= $openMsg['is_read'] ? '✅ قرأها المستقبل ' . date('d/m H:i', strtotime($openMsg['read_at'])) : '⏳ لم يقرأها بعد' ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden">
  <?php if (!$sentRows): ?>
    <div style="text-align:center;color:var(--text3);padding:28px">لا توجد رسائل مرسلة</div>
  <?php else: ?>
    <?php foreach ($sentRows as $r): ?>
    <div class="msg-row <?= $r['id']==$openId ? 'selected' : '' ?>" onclick="location.href='?view=sent&open=<?= $r['id'] ?>'">
      <div class="msg-avatar"><?= mb_substr($r['receiver_name'],0,1) ?></div>
      <div class="msg-meta">
        <div class="msg-from">إلى: <?= htmlspecialchars($r['receiver_name']) ?> <?= $r['is_read'] ? '<span style="font-size:10px;color:var(--green)">✓</span>' : '' ?></div>
        <div class="msg-subj"><?= htmlspecialchars($r['subject'] ?: mb_substr($r['body'],0,50)) ?></div>
        <div class="msg-time"><?= date('d/m H:i', strtotime($r['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- page-wrap -->
</body>
</html>
