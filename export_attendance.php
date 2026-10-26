<?php
// ===================================================
// api/export_attendance.php — تصدير كشف الحضور CSV
// المرحلة الأولى: Quick Win بدون مكتبات خارجية
// الوصول: المدير أو من لديه can_view_reports فقط
// ===================================================

require_once '../includes/auth.php';
requireReportsAccess();

$db = getDB();

// ── معاملات الفلترة (نفس reports.php) ─────────────
$dateFrom   = $_GET['date_from']   ?? date('Y-m-01');
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');
$employeeId = (int)($_GET['employee_id'] ?? 0);
$role       = $_GET['role']        ?? '';  // 'employee' | 'driver' | ''

// تحقق بسيط من الصيغة
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// ── بناء الاستعلام ──────────────────────────────────
$conditions = ['a.date BETWEEN ? AND ?'];
$params     = [$dateFrom, $dateTo];

if ($employeeId > 0) {
    $conditions[] = 'a.employee_id = ?';
    $params[]     = $employeeId;
}
if (in_array($role, ['employee', 'driver'])) {
    $conditions[] = 'e.role = ?';
    $params[]     = $role;
}

$where = implode(' AND ', $conditions);

$stmt = $db->prepare("
    SELECT
        a.date                                              AS `التاريخ`,
        e.name                                             AS `اسم الموظف`,
        CASE e.role
            WHEN 'driver'   THEN 'سائق'
            WHEN 'employee' THEN 'موظف'
            WHEN 'admin'    THEN 'مدير'
            ELSE e.role
        END                                                AS `الدور`,
        a.check_in                                         AS `وقت الدخول`,
        a.check_out                                        AS `وقت الخروج`,
        CASE
            WHEN a.check_in IS NULL THEN 'غائب'
            WHEN a.late_minutes > 0 THEN CONCAT('متأخر ', a.late_minutes, ' د')
            ELSE 'حاضر'
        END                                                AS `الحالة`,
        COALESCE(a.late_minutes, 0)                        AS `دقائق التأخير`,
        CASE
            WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL
            THEN ROUND(TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out) / 60, 2)
            ELSE ''
        END                                                AS `ساعات العمل`,
        COALESCE(a.check_in_address,  '')                  AS `عنوان الدخول`,
        COALESCE(a.check_out_address, '')                  AS `عنوان الخروج`
    FROM attendance a
    JOIN employees  e ON e.id = a.employee_id
    WHERE {$where}
    ORDER BY a.date DESC, e.name ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── اسم الملف ──────────────────────────────────────
$filename = 'كشف_الحضور_' . $dateFrom . '_إلى_' . $dateTo . '.csv';

// ── إرسال الـ Headers ──────────────────────────────
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// BOM لدعم Excel عربي
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── رأس الجدول ─────────────────────────────────────
if (!empty($rows)) {
    fputcsv($out, array_keys($rows[0]));
}

// ── البيانات ───────────────────────────────────────
foreach ($rows as $row) {
    fputcsv($out, $row);
}

// ── صف الإجماليات ──────────────────────────────────
if (!empty($rows)) {
    $totalDays    = count($rows);
    $presentDays  = count(array_filter($rows, fn($r) => $r['التاريخ'] && $r['وقت الدخول']));
    $lateDays     = count(array_filter($rows, fn($r) => (int)$r['دقائق التأخير'] > 0));
    $totalHours   = array_sum(array_column($rows, 'ساعات العمل'));

    fputcsv($out, []); // سطر فارغ
    fputcsv($out, [
        'المجموع',
        "إجمالي السجلات: {$totalDays}",
        "أيام حضور: {$presentDays}",
        '',
        '',
        "أيام تأخر: {$lateDays}",
        '',
        "إجمالي الساعات: {$totalHours}",
        '',
        '',
    ]);
}

fclose($out);
exit;
