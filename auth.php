<?php
// ===================================================
// auth.php — النسخة الآمنة (المرحلة الأولى)
// إضافات: Rate Limiting، Session Regeneration، CSRF
// ===================================================

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 315360000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ════════════════════════════════════════════════════
// دوال الصلاحيات (كما كانت — لا تغيير)
// ════════════════════════════════════════════════════

function isLoggedIn(): bool  { return isset($_SESSION['employee_id']); }
function isAdmin(): bool     { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function isDriver(): bool    { return isset($_SESSION['role']) && $_SESSION['role'] === 'driver'; }
function isEmployee(): bool  { return isset($_SESSION['role']) && $_SESSION['role'] === 'employee'; }

function canViewReports(): bool {
    return isAdmin() || (isset($_SESSION['can_view_reports']) && $_SESSION['can_view_reports'] == 1);
}

function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php'); exit; }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . (isDriver() ? '/driver.php' : '/employee.php'));
        exit;
    }
}

function requireReportsAccess(): void {
    requireLogin();
    if (!canViewReports()) {
        header('Location: ' . SITE_URL . (isDriver() ? '/driver.php' : '/employee.php'));
        exit;
    }
}

// ════════════════════════════════════════════════════
// Rate Limiting — تخزين في Session (بدون Redis/DB)
// قفل بعد MAX_ATTEMPTS محاولة فاشلة
// ════════════════════════════════════════════════════

define('MAX_ATTEMPTS',   5);
define('LOCKOUT_SECONDS', 900);  // 15 دقيقة
define('CAPTCHA_AFTER',   3);    // أظهر تنبيه بعد 3 محاولات

function _rl_key(string $username): string {
    // مفتاح مدمج: IP + username (لمنع حجب مستخدمين آخرين)
    return 'rl_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . strtolower(trim($username)));
}

function getRateLimitData(string $username): array {
    $key = _rl_key($username);
    return $_SESSION[$key] ?? ['attempts' => 0, 'locked_until' => 0];
}

function isRateLimited(string $username): bool {
    $data = getRateLimitData($username);
    if ($data['locked_until'] > time()) return true;
    if ($data['locked_until'] > 0 && $data['locked_until'] <= time()) {
        // انتهى القفل — صفّر
        unset($_SESSION[_rl_key($username)]);
    }
    return false;
}

function getRateLimitRemainingSeconds(string $username): int {
    $data = getRateLimitData($username);
    return max(0, $data['locked_until'] - time());
}

function getFailedAttempts(string $username): int {
    return getRateLimitData($username)['attempts'] ?? 0;
}

function recordFailedAttempt(string $username): void {
    $key  = _rl_key($username);
    $data = getRateLimitData($username);
    $data['attempts']++;
    if ($data['attempts'] >= MAX_ATTEMPTS) {
        $data['locked_until'] = time() + LOCKOUT_SECONDS;
    }
    $_SESSION[$key] = $data;
}

function clearRateLimit(string $username): void {
    unset($_SESSION[_rl_key($username)]);
}

// ════════════════════════════════════════════════════
// CSRF Token
// ════════════════════════════════════════════════════

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ════════════════════════════════════════════════════
// دالة تسجيل الدخول الآمنة
// ════════════════════════════════════════════════════

/**
 * @return string|false  دور المستخدم عند النجاح، أو false عند الفشل
 *                       أو 'locked' إذا الحساب مقفل
 */
function login(string $username, string $password): string|false {

    // 1) تحقق من Rate Limit
    if (isRateLimited($username)) {
        return 'locked';
    }

    // 2) اجلب المستخدم من قاعدة البيانات
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM employees WHERE username = ?");
    $stmt->execute([trim($username)]);
    $emp  = $stmt->fetch();

    // 3) تحقق من كلمة السر
    if ($emp && password_verify($password, $emp['password'])) {

        // ✅ نجاح — صفّر محاولات الفشل
        clearRateLimit($username);

        // 🔒 Session Regeneration (يمنع Session Fixation)
        session_regenerate_id(true);

        // 🔒 تجديد CSRF Token بعد الدخول
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // حفظ بيانات الجلسة
        $_SESSION['employee_id']      = $emp['id'];
        $_SESSION['employee_name']    = $emp['name'];
        $_SESSION['role']             = $emp['role'];
        $_SESSION['can_view_reports'] = $emp['can_view_reports'] ?? 0;
        $_SESSION['login_time']       = time();
        $_SESSION['user_ip']          = $_SERVER['REMOTE_ADDR'] ?? '';

        return $emp['role'];
    }

    // ❌ فشل — سجّل المحاولة
    recordFailedAttempt($username);
    return false;
}

function logout(): void {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// ════════════════════════════════════════════════════
// حماية إضافية: تحقق من ثبات IP أثناء الجلسة
// (اختياري — عطّله إن كان المستخدمون على شبكات متغيرة)
// ════════════════════════════════════════════════════
function checkSessionIntegrity(): void {
    if (!isLoggedIn()) return;
    // إذا تغيّر الـ IP كثيراً — طرد الجلسة
    // نقارن أول 3 أوكتتات فقط (يتعامل مع NAT الديناميكي)
    if (isset($_SESSION['user_ip'])) {
        $storedPrefix  = implode('.', array_slice(explode('.', $_SESSION['user_ip']),  0, 3));
        $currentPrefix = implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 3));
        if ($storedPrefix !== $currentPrefix) {
            session_destroy();
            header('Location: ' . SITE_URL . '/login.php?reason=session_expired');
            exit;
        }
    }
}
