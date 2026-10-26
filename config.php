<?php
// ===================================================
// config.php — النسخة الآمنة (المرحلة الأولى)
// البيانات الحساسة تُقرأ من متغيرات البيئة فقط
// ===================================================

date_default_timezone_set('Asia/Amman');

// ── تحميل .env إن وُجد (للتطوير المحلي) ──────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
        putenv(trim($key) . '=' . trim($val));
    }
}

// ── دالة مساعدة لقراءة المتغيرات بأمان ──────────────
function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ── ثوابت التطبيق (من البيئة فقط، لا قيم مكتوبة) ───
define('DB_HOST',             env('DB_HOST',             'localhost'));
define('DB_USER',             env('DB_USER'));
define('DB_PASS',             env('DB_PASS'));
define('DB_NAME',             env('DB_NAME'));
define('SITE_URL',            env('SITE_URL',            'https://goldensketch.com/tracking'));
define('GMAPS_API_KEY',       env('GMAPS_API_KEY'));
define('WORK_START',          env('WORK_START',          '10:00'));
define('WORK_END',            env('WORK_END',            '21:00'));
define('LATE_GRACE_MINUTES',  (int) env('LATE_GRACE_MINUTES', '10'));

// ── اتصال قاعدة البيانات (Singleton) ─────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            // لا تكشف رسالة الخطأ الأصلية في الإنتاج
            die(json_encode(['error' => 'خطأ في الاتصال بقاعدة البيانات']));
        }
    }
    return $pdo;
}

// للتوافق مع الكود القديم
function getInvDB(): PDO { return getDB(); }

// ── إضافة أعمدة الأوفرتايم تلقائياً ─────────────────
function ensureOvertimeColumns(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = $db->query("SHOW COLUMNS FROM overtime_requests")->fetchAll(PDO::FETCH_COLUMN);
        $need = [
            'ot_check_in'          => "ALTER TABLE overtime_requests ADD COLUMN ot_check_in DATETIME NULL",
            'ot_check_out'         => "ALTER TABLE overtime_requests ADD COLUMN ot_check_out DATETIME NULL",
            'ot_check_out_lat'     => "ALTER TABLE overtime_requests ADD COLUMN ot_check_out_lat DECIMAL(10,8) NULL",
            'ot_check_out_lng'     => "ALTER TABLE overtime_requests ADD COLUMN ot_check_out_lng DECIMAL(11,8) NULL",
            'ot_check_out_address' => "ALTER TABLE overtime_requests ADD COLUMN ot_check_out_address VARCHAR(255) NULL",
        ];
        foreach ($need as $col => $sql) {
            if (!in_array($col, $cols)) {
                $db->exec($sql);
            }
        }
    } catch (Exception $e) {
        // تجاهل — سيُعاد المحاولة في الطلب التالي
    }
}
