<?php
// ===================================================
// login.php — النسخة الآمنة (المرحلة الأولى)
// إضافات: Rate Limiting UI، CSRF Token، تنبيه المحاولات
// ===================================================

require_once 'includes/auth.php';
require_once 'includes/theme.php';

if (isLoggedIn()) {
    if (isAdmin())       header('Location: admin.php');
    elseif (isDriver())  header('Location: driver.php');
    else                 header('Location: employee.php');
    exit;
}

$error        = '';
$lockSeconds  = 0;
$attempts     = 0;
$showWarning  = false;
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── تحقق CSRF ────────────────────────────────────
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'طلب غير صالح، أعد المحاولة.';
    } else {
        $username_val = trim($_POST['username'] ?? '');
        $password     = $_POST['password'] ?? '';

        $result = login($username_val, $password);

        if ($result === 'locked') {
            $lockSeconds = getRateLimitRemainingSeconds($username_val);
            $mins = ceil($lockSeconds / 60);
            $error = "🔒 تم تجاوز عدد المحاولات المسموحة. الحساب مقفل لمدة {$mins} دقيقة.";
        } elseif ($result === 'admin') {
            header('Location: admin.php');    exit;
        } elseif ($result === 'driver') {
            header('Location: driver.php');   exit;
        } elseif ($result === 'employee') {
            header('Location: employee.php'); exit;
        } else {
            $attempts    = getFailedAttempts($username_val);
            $remaining   = MAX_ATTEMPTS - $attempts;
            $showWarning = ($attempts >= CAPTCHA_AFTER);
            if ($remaining > 0) {
                $error = "اسم المستخدم أو كلمة السر غلط. ({$remaining} محاولة متبقية قبل القفل)";
            } else {
                $lockSeconds = getRateLimitRemainingSeconds($username_val);
                $mins = ceil($lockSeconds / 60);
                $error = "🔒 تم تجاوز عدد المحاولات. الحساب مقفل لمدة {$mins} دقيقة.";
            }
        }
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<?= getThemeHead('تسجيل الدخول') ?>
<style>
body{
  display:flex;align-items:center;justify-content:center;
  min-height:100dvh;padding:16px;
  background:radial-gradient(ellipse at 50% 0%,#0d2a4a 0%,#060f1e 60%,#030810 100%);
}
.login-card{
  background:linear-gradient(160deg,#0e1f38 0%,#08152a 100%);
  border:1px solid rgba(60,120,220,.25);
  border-radius:22px;padding:36px 28px;
  width:100%;max-width:390px;
  box-shadow:0 0 0 1px rgba(60,120,220,.08),0 30px 80px rgba(0,0,0,.6);
  position:relative;overflow:hidden;
}
.card-glow{
  position:absolute;top:0;left:50%;transform:translateX(-50%);
  width:220px;height:3px;
  background:linear-gradient(90deg,transparent,#e8a44a,transparent);
  border-radius:0 0 4px 4px;
}
.logo{text-align:center;margin-bottom:30px}
.logo-icon{
  width:68px;height:68px;
  background:linear-gradient(145deg,#1a4a8a,#1a56db);
  border-radius:18px;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 16px;
  border:1px solid rgba(80,140,255,.3);
  box-shadow:0 4px 20px rgba(26,86,219,.45);
}
.logo-icon svg{width:34px;height:34px;fill:none;stroke:#a8c8f8;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.logo h1{font-size:22px;font-weight:800;color:#e8f0ff;margin-bottom:6px;letter-spacing:.3px}
.logo p{font-size:13px;color:#5a7aaa}
.field{margin-bottom:16px}
.field label{display:block;font-size:12px;color:#6a8acc;margin-bottom:7px;font-weight:600;letter-spacing:.3px}
.field input{
  width:100%;padding:13px 16px;
  background:rgba(255,255,255,.05);
  border:1.5px solid rgba(60,120,220,.2);
  border-radius:10px;
  font-size:15px;font-family:inherit;
  color:#d0e0ff;outline:none;
  transition:border .2s,background .2s;
  direction:ltr;text-align:right;
}
.field input:focus{border-color:rgba(80,140,255,.55);background:rgba(255,255,255,.08)}
.field input::placeholder{color:#3a5a8a;direction:rtl}
.btn-login{
  width:100%;padding:14px;margin-top:6px;
  background:linear-gradient(135deg,#1a56db,#1040b0);
  border:1px solid rgba(80,140,255,.3);
  border-radius:10px;
  font-size:16px;font-family:inherit;font-weight:700;
  color:#c8d8ff;cursor:pointer;
  transition:opacity .2s,transform .1s;
  letter-spacing:.5px;
}
.btn-login:hover{opacity:.9}
.btn-login:active{transform:scale(.98)}
.btn-login:disabled{opacity:.4;cursor:not-allowed}
.err{background:rgba(220,60,60,.12);border:1px solid rgba(220,60,60,.25);border-radius:10px;padding:12px 14px;color:#f08080;font-size:13px;margin-bottom:16px;text-align:center}
.warn{background:rgba(220,160,30,.1);border:1px solid rgba(220,160,30,.3);border-radius:10px;padding:12px 14px;color:#f0c060;font-size:13px;margin-bottom:16px;text-align:center}
.lock-timer{font-size:24px;font-weight:800;color:#f08080;display:block;margin-top:6px}
.footer-text{text-align:center;margin-top:20px;font-size:12px;color:#2a4a7a}
</style>
</head>
<body>
<div class="login-card">
  <div class="card-glow"></div>
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
    </div>
    <h1>مؤسسة فلسطين</h1>
    <p>تسجيل الدخول لنظام الإدارة</p>
  </div>

  <?php if ($error): ?>
    <div class="err">
      ⚠️ <?= htmlspecialchars($error) ?>
      <?php if ($lockSeconds > 0): ?>
        <span class="lock-timer" id="countdown"><?= gmdate('i:s', $lockSeconds) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($showWarning && !$lockSeconds): ?>
    <div class="warn">
      ⚠️ تنبيه: محاولات فاشلة متعددة. سيُقفل الحساب تلقائياً بعد <?= MAX_ATTEMPTS - $attempts ?> محاولة إضافية.
    </div>
  <?php endif; ?>

  <form method="POST" id="loginForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <div class="field">
      <label>اسم المستخدم</label>
      <input type="text" name="username"
             value="<?= htmlspecialchars($username_val) ?>"
             placeholder="أدخل اسم المستخدم"
             required autocomplete="username" inputmode="text"
             <?= $lockSeconds > 0 ? 'disabled' : '' ?>>
    </div>
    <div class="field">
      <label>كلمة السر</label>
      <input type="password" name="password" placeholder="••••••••"
             required autocomplete="current-password"
             <?= $lockSeconds > 0 ? 'disabled' : '' ?>>
    </div>
    <button type="submit" class="btn-login" id="submitBtn"
            <?= $lockSeconds > 0 ? 'disabled' : '' ?>>
      <?= $lockSeconds > 0 ? '🔒 الحساب مقفل مؤقتاً' : 'دخول' ?>
    </button>
  </form>

  <div class="footer-text" id="install-hint"></div>
  <button class="btn-login" id="install-btn" style="display:none;margin-top:10px;font-size:13px">📲 تثبيت التطبيق</button>
</div>

<script>
// ── عداد تنازلي للقفل ────────────────────────────────
const secs = <?= (int)$lockSeconds ?>;
if (secs > 0) {
  const el  = document.getElementById('countdown');
  const btn = document.getElementById('submitBtn');
  let rem   = secs;
  const tick = () => {
    rem--;
    if (rem <= 0) {
      // إعادة تحميل الصفحة بعد انتهاء القفل
      window.location.reload();
    } else {
      const m = String(Math.floor(rem/60)).padStart(2,'0');
      const s = String(rem%60).padStart(2,'0');
      if (el) el.textContent = m+':'+s;
    }
  };
  setInterval(tick, 1000);
}

// ── Service Worker & PWA ────────────────────────────
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/tracking/sw.js').catch(()=>{});
let dp;
window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault(); dp = e;
  document.getElementById('install-hint').textContent = 'يمكنك تثبيت التطبيق على جهازك!';
  document.getElementById('install-btn').style.display = 'flex';
});
document.getElementById('install-btn').addEventListener('click', async () => {
  if (!dp) return; dp.prompt();
  const { outcome } = await dp.userChoice;
  if (outcome === 'accepted') {
    document.getElementById('install-btn').style.display = 'none';
    document.getElementById('install-hint').textContent  = '✅ تم التثبيت!';
  }
  dp = null;
});
</script>
</body>
</html>
