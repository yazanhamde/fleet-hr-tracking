<?php
// =======================================
// Cron Job — يشتغل كل دقيقة
// في cPanel: * * * * * php /home/goldensketch/public_html/tracking/cron.php
// =======================================

define('CRON_RUN', true);
require_once __DIR__ . '/includes/config.php';

$db = getDB();
$today = date('Y-m-d');
$now   = date('H:i');

// 1) تحقق من موظفين متأخرين بعد 30 دقيقة من بدء الدوام
$workStart = WORK_START;
list($wh, $wm) = explode(':', $workStart);
$lateThreshold = sprintf('%02d:%02d', $wh, $wm + 30);

if ($now >= $lateThreshold && $now < sprintf('%02d:%02d', $wh, $wm + 45)) {
    // ✅ إصلاح: يشمل السائقين (driver) والموظفين (employee) — كان يفوّت السائقين
    // ✅ إصلاح: يتجاهل الغياب في أيام العطل الرسمية
    $isHoliday = $db->prepare("SELECT id FROM holidays WHERE date = ? AND (is_recurring = 0 OR MONTH(date) = MONTH(?) AND DAY(date) = DAY(?))");
    $isHoliday->execute([$today, $today, $today]);
    if (!$isHoliday->fetch()) {
        $stmt = $db->prepare("
            SELECT e.id, e.name, e.role FROM employees e
            WHERE e.role IN ('employee','driver')
            AND e.id NOT IN (SELECT employee_id FROM attendance WHERE date=?)
        ");
        $stmt->execute([$today]);
        $absent = $stmt->fetchAll();
        foreach ($absent as $emp) {
            // تجنب تكرار التنبيه
            $check = $db->prepare("SELECT id FROM alerts WHERE employee_id=? AND type='absent' AND DATE(created_at)=?");
            $check->execute([$emp['id'], $today]);
            if (!$check->fetch()) {
                $roleLabel = $emp['role'] === 'driver' ? 'السائق' : 'الموظف';
                $db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('absent',?,?)")
                   ->execute([$emp['id'], "{$roleLabel} " . $emp['name'] . " لم يسجل حضوره حتى الآن"]);
            }
        }
    }
}

// 2) تحقق من سيارات تتحرك خارج الدوام
$hour = (int)date('H');
$workStartH = (int)explode(':', WORK_START)[0];
$workEndH   = (int)explode(':', WORK_END)[0];

if ($hour < $workStartH || $hour >= $workEndH) {
    $stmt = $db->query("SELECT * FROM trips WHERE status='active' AND outside_hours=0");
    $activeOutside = $stmt->fetchAll();
    foreach ($activeOutside as $trip) {
        // تحديث رايته
        $db->prepare("UPDATE trips SET outside_hours=1 WHERE id=?")->execute([$trip['id']]);
        // تنبيه
        $check = $db->prepare("SELECT id FROM alerts WHERE vehicle_id=? AND type='vehicle_outside_hours' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $check->execute([$trip['vehicle_id']]);
        if (!$check->fetch()) {
            $db->prepare("INSERT INTO alerts (type, vehicle_id, employee_id, message) VALUES ('vehicle_outside_hours',?,?,?)")
               ->execute([$trip['vehicle_id'], $trip['employee_id'], "🚨 سيارة رقم " . $trip['vehicle_id'] . " تتحرك خارج أوقات الدوام!"]);
            // تحديث حالة السيارة
            $db->prepare("UPDATE vehicles SET status='alert' WHERE id=?")->execute([$trip['vehicle_id']]);
        }
    }
}

// 3) تسجيل خروج تلقائي عند انتهاء الدوام
$workEnd = WORK_END;

// يشتغل في أي وقت بعد نهاية الدوام (وليس فقط أول 5 دقائق) — لأن الاستعلام
// نفسه لا يكرر التسجيل (check_out IS NULL)، فلا داعي لحصر النافذة بزمن ضيق
// كان هذا سبب عدم عمل تسجيل الانصراف التلقائي أحياناً (لو تأخر الكرون لحظة عن tick الدقيقة المحددة)
$workEndTs = strtotime(date('Y-m-d') . ' ' . $workEnd);
$nowTs2    = time();
if ($nowTs2 >= $workEndTs) {
    // موظفين وسائقين لم يسجلوا خروج بعد
    $stmt = $db->prepare("
        SELECT a.id AS att_id, a.employee_id, e.name, e.role
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        WHERE a.date = ? AND a.check_out IS NULL
    ");
    $stmt->execute([$today]);
    $still_in = $stmt->fetchAll();

    $nowStr = date('Y-m-d H:i:s');

    foreach ($still_in as $rec) {
        // ✅ إصلاح: سجّل آخر موقع معروف عند الانصراف التلقائي بدلاً من null
        $lastLoc = $db->prepare("
            SELECT lat, lng FROM employee_locations
            WHERE employee_id = ?
            ORDER BY recorded_at DESC LIMIT 1
        ");
        $lastLoc->execute([$rec['employee_id']]);
        $loc = $lastLoc->fetch();
        $checkOutLat = $loc['lat']  ?? null;
        $checkOutLng = $loc['lng']  ?? null;
        // العنوان التقريبي: إحداثيات فقط (Google Maps Reverse Geocoding يحتاج طلب HTTP — اختياري)
        $checkOutAddress = $loc ? "آخر موقع مسجّل ({$loc['lat']}, {$loc['lng']})" : null;

        $db->prepare("UPDATE attendance SET check_out=?, check_out_lat=?, check_out_lng=?, check_out_address=? WHERE id=?")
           ->execute([$nowStr, $checkOutLat, $checkOutLng, $checkOutAddress, $rec['att_id']]);

        // تحقق إذا عنده طلب أوفر تايم موافق عليه اليوم — إذا نعم لا تنهِ مشواره (لا يزال يعمل بالإضافي)
        $otCheck = $db->prepare("SELECT id FROM overtime_requests WHERE employee_id=? AND request_date=? AND status='approved'");
        $otCheck->execute([$rec['employee_id'], $today]);
        $hasApprovedOT = (bool)$otCheck->fetch();

        // لو سائق وعنده مشوار نشط ولا يوجد له وقت إضافي موافق عليه — أنهِ المشوار
        if ($rec['role'] === 'driver' && !$hasApprovedOT) {
            $tripStmt = $db->prepare("SELECT * FROM trips WHERE employee_id=? AND status='active' LIMIT 1");
            $tripStmt->execute([$rec['employee_id']]);
            $trip = $tripStmt->fetch();
            if ($trip) {
                $db->prepare("UPDATE trips SET status='completed', end_time=? WHERE trip_uid=?")
                   ->execute([$nowStr, $trip['trip_uid']]);
                $db->prepare("UPDATE vehicles SET status='idle' WHERE id=?")
                   ->execute([$trip['vehicle_id']]);
            }
        }

        // تجنب تكرار تنبيه "انصراف تلقائي" لنفس الموظف لنفس اليوم
        $alertCheck = $db->prepare("SELECT id FROM alerts WHERE employee_id=? AND type='absent' AND message LIKE '%انصراف%' AND DATE(created_at)=?");
        $alertCheck->execute([$rec['employee_id'], $today]);
        if (!$alertCheck->fetch()) {
            $role = $rec['role'] === 'driver' ? 'سائق' : 'موظف';
            $extra = $hasApprovedOT ? ' (مستمر بالوقت الإضافي الموافق عليه)' : '';
            $db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('absent',?,?)")
               ->execute([$rec['employee_id'], "🕙 تم تسجيل انصراف {$rec['name']} ($role) تلقائياً عند نهاية الدوام$extra"]);
        }
    }
}

// (أكثر من 30 يوم) — يشتغل مرة يومياً الساعة 00:00
if ($now === '00:00') {
    $db->exec("DELETE FROM employee_locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
}

// ── مهام الأسطول (المرحلة الثالثة) ──────────────────────
require_once __DIR__ . '/cron_fleet.php';
runFleetCron($db);

echo date('Y-m-d H:i:s') . " — Cron OK\n";
