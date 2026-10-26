<?php
// ===================================================
// upload_helper.php — رفع الملفات بأمان (المرحلة الأولى)
// يُستخدم في: api/inventory_api.php وأي مكان فيه رفع
// ===================================================

/**
 * رفع صورة بأمان كامل:
 *  1. التحقق من وجود الملف وعدم وجود خطأ
 *  2. قائمة بيضاء للامتدادات
 *  3. التحقق من MIME type الحقيقي (ليس ما يدّعيه المتصفح)
 *  4. فحص مبني على magic bytes
 *  5. اسم ملف عشوائي آمن (لا يعتمد على اسم المستخدم)
 *  6. حجم أقصى 5MB
 *  7. الحفظ داخل uploads/ مع .htaccess يمنع التنفيذ
 *
 * @param  array       $file        مصفوفة $_FILES['image']
 * @param  string      $subDir      مجلد فرعي داخل uploads/ (مثل 'parts')
 * @return array{ok:bool, path:string, error:string}
 */
function safeUploadImage(array $file, string $subDir = 'parts'): array {

    // ── ثوابت ─────────────────────────────────────────
    $maxSize   = 5 * 1024 * 1024;  // 5 MB
    $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Magic bytes لكل نوع (أول bytes الملف)
    $magicBytes = [
        'jpg'  => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89PNG\r\n\x1a\n"],
        'gif'  => ["GIF87a", "GIF89a"],
        'webp' => ["RIFF"],   // + نتحقق لاحقاً من WEBP
    ];

    // ── فحص 1: هل الملف موجود وبدون خطأ؟ ────────────
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح به في الخادم',
            UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المحدد في النموذج',
            UPLOAD_ERR_PARTIAL    => 'لم يكتمل رفع الملف',
            UPLOAD_ERR_NO_FILE    => 'لم يُرفع أي ملف',
            UPLOAD_ERR_NO_TMP_DIR => 'خطأ في مجلد الملفات المؤقتة',
            UPLOAD_ERR_CANT_WRITE => 'فشل حفظ الملف',
        ];
        $msg = $codes[$file['error'] ?? -1] ?? 'خطأ غير معروف عند الرفع';
        return ['ok' => false, 'path' => '', 'error' => $msg];
    }

    // ── فحص 2: الحجم ──────────────────────────────────
    if ($file['size'] > $maxSize) {
        return ['ok' => false, 'path' => '', 'error' => 'حجم الملف يتجاوز 5MB'];
    }

    // ── فحص 3: الامتداد ───────────────────────────────
    $origName = $file['name'] ?? '';
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'path' => '', 'error' => "امتداد غير مسموح به: .{$ext}"];
    }

    // ── فحص 4: MIME type الحقيقي ──────────────────────
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    if (!in_array($realMime, $allowedMime, true)) {
        return ['ok' => false, 'path' => '', 'error' => "نوع الملف غير مسموح به: {$realMime}"];
    }

    // ── فحص 5: Magic Bytes ────────────────────────────
    $handle  = fopen($file['tmp_name'], 'rb');
    $header  = fread($handle, 12);
    fclose($handle);

    $signatures = $magicBytes[$ext] ?? [];
    $matched    = false;
    foreach ($signatures as $sig) {
        if (str_starts_with($header, $sig)) { $matched = true; break; }
    }
    // WebP خاص: RIFF....WEBP
    if ($ext === 'webp') {
        $matched = (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP');
    }
    if (!$matched) {
        return ['ok' => false, 'path' => '', 'error' => 'محتوى الملف لا يطابق امتداده (محاولة تزوير)'];
    }

    // ── تجهيز مجلد الرفع ──────────────────────────────
    // uploads/ يجب أن تكون خارج public_html في الإنتاج
    // هنا نحافظ على الهيكل الحالي مع إضافة .htaccess للحماية
    $uploadBase = dirname(__DIR__) . '/uploads/' . $subDir . '/';
    if (!is_dir($uploadBase)) {
        mkdir($uploadBase, 0750, true);
    }

    // أنشئ .htaccess يمنع تنفيذ أي سكريبت داخل المجلد
    $htaccess = dirname(__DIR__) . '/uploads/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "Options -Indexes -ExecCGI\n" .
            "AddHandler cgi-script .php .php3 .php4 .php5 .phtml .pl .py .jsp .asp .sh .cgi\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
            "php_flag engine off\n" .
            "<FilesMatch \"\\.php$\">\n  Order allow,deny\n  Deny from all\n</FilesMatch>\n"
        );
    }

    // ── اسم ملف آمن وعشوائي ───────────────────────────
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $uploadBase . $safeName;

    // ── الرفع الفعلي ───────────────────────────────────
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['ok' => false, 'path' => '', 'error' => 'فشل في حفظ الملف على الخادم'];
    }

    // المسار النسبي للحفظ في قاعدة البيانات
    $relativePath = 'uploads/' . $subDir . '/' . $safeName;
    return ['ok' => true, 'path' => $relativePath, 'error' => ''];
}

/**
 * حذف صورة مرفوعة بأمان (منع Path Traversal)
 */
function safeDeleteUpload(string $relativePath): bool {
    // تأكد أن المسار داخل مجلد uploads فقط
    $base    = realpath(dirname(__DIR__) . '/uploads/');
    $absPath = realpath(dirname(__DIR__) . '/' . $relativePath);

    if (!$absPath || !$base || !str_starts_with($absPath, $base)) {
        return false; // محاولة Path Traversal
    }
    if (file_exists($absPath)) {
        return unlink($absPath);
    }
    return true; // الملف غير موجود = لا مشكلة
}
