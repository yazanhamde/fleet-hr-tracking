<?php
// ============================================================
// theme_hr_patch.php — إضافة badge الإشعارات + روابط HR
// أضف require_once 'includes/theme_hr_patch.php'; بعد theme.php
// في كل صفحة الـ HR
// ============================================================

// لا نستدعيها مرتين
if (!function_exists('getHRNavLinks')) :

require_once __DIR__ . '/hr_helpers.php';

/**
 * يُعيد CSS + JS لإضافة badge الإشعارات على أيقونة الجرس في الـ topbar
 * أضفه داخل <head> أو قبل </body>
 */
function getNotifBadgeScript(): string {
    if (!isLoggedIn()) return '';
    return '
<style>
.notif-bell{position:relative;display:inline-flex}
.notif-count{position:absolute;top:-5px;right:-5px;background:var(--red);color:#fff;border-radius:10px;font-size:9px;font-weight:800;padding:1px 5px;min-width:16px;text-align:center;display:none}
.notif-count.show{display:block}
</style>
<script>
(function(){
  function pollNotif(){
    fetch("' . SITE_URL . '/api/notif_api.php?action=count")
      .then(r=>r.json()).then(d=>{
        const nb = document.getElementById("notif-badge");
        const mb = document.getElementById("msg-badge");
        if(nb){ nb.textContent=d.notif_unread; nb.classList.toggle("show",d.notif_unread>0); }
        if(mb){ mb.textContent=d.msg_unread;   mb.classList.toggle("show",d.msg_unread>0); }
      }).catch(()=>{});
  }
  document.addEventListener("DOMContentLoaded",()=>{ pollNotif(); setInterval(pollNotif,30000); });
})();
</script>';
}

/**
 * روابط HR للـ topbar (أضفها داخل .top-right أو .nav-drawer)
 */
function getHRNavLinks(string $current=''): string {
    if (!isLoggedIn()) return '';
    $links = [];

    // كل الموظفين
    $links[] = ['leaves.php',       '🏖️ الإجازات',    'leaves'];
    $links[] = ['shifts.php',        '📅 الورديات',    'shifts'];
    $links[] = ['performance.php',   '⭐ تقييم الأداء','performance'];
    $links[] = ['messages.php',      '✉️ الرسائل',     'messages'];
    $links[] = ['employee_report.php','📄 تقريري',     'report'];
    $links[] = ['notifications.php', '🔔 الإشعارات',   'notifications'];

    $html = '';
    foreach ($links as [$href,$label,$key]) {
        $active = $key === $current ? 'style="color:var(--blue)"' : '';
        $html  .= "<a href='{$href}' {$active}>{$label}</a>";
    }
    return $html;
}

/**
 * بديل عن getTopbar الموجودة — يضيف الروابط والـ badge
 * استخدامه: getHRTopbar('الإجازات','leaves')
 */
function getHRTopbar(string $subtitle='', string $current=''): string {
    if (!function_exists('isLoggedIn')) return '';
    $db    = getDB();
    $empId = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0;
    $notifCount = $empId ? unreadNotifCount($db,$empId) : 0;
    $msgCount   = $empId ? unreadMsgCount($db,$empId)   : 0;

    return '
<div class="topbar">
  <div class="brand">
    <div class="brand-icon">ف</div>
    <div>
      <div class="brand-name">مؤسسة فلسطين</div>
      ' . ($subtitle ? "<div class='brand-sub'>".htmlspecialchars($subtitle)."</div>" : '') . '
    </div>
  </div>
  <div class="top-right">
    <div class="top-links">
      ' . getHRNavLinks($current) . '
    </div>
    <a href="notifications.php" class="notif-bell" title="الإشعارات">
      🔔<span class="notif-count ' . ($notifCount>0?'show':'') . '" id="notif-badge">' . $notifCount . '</span>
    </a>
    <a href="messages.php" class="notif-bell" title="الرسائل">
      ✉️<span class="notif-count ' . ($msgCount>0?'show':'') . '" id="msg-badge">' . $msgCount . '</span>
    </a>
    <a href="api/logout.php" class="logout-btn">خروج</a>
    <button class="nav-toggle" onclick="document.getElementById(\'hr-nav\').classList.toggle(\'open\');document.getElementById(\'hr-ov\').classList.toggle(\'open\')">☰</button>
  </div>
</div>
<div class="nav-overlay" id="hr-ov" onclick="this.classList.remove(\'open\');document.getElementById(\'hr-nav\').classList.remove(\'open\')"></div>
<div class="nav-drawer" id="hr-nav">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <span style="font-weight:700;font-size:14px">القائمة</span>
    <button onclick="document.getElementById(\'hr-nav\').classList.remove(\'open\');document.getElementById(\'hr-ov\').classList.remove(\'open\')" style="background:none;border:none;color:var(--text3);font-size:20px;cursor:pointer">✕</button>
  </div>
  ' . getHRNavLinks($current) . '
  <a href="api/logout.php" style="color:var(--red)">خروج</a>
</div>
' . getNotifBadgeScript();
}

endif; // end if !function_exists
