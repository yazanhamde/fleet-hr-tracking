<?php
// ============================================================
// hr_helpers.php — دوال مشتركة للمرحلة الثانية
// ============================================================

function isHR(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'hr';
}

function getEmpName(PDO $db, int $id): string {
    $s = $db->prepare("SELECT name FROM employees WHERE id=?");
    $s->execute([$id]);
    return $s->fetchColumn() ?: "موظف #{$id}";
}

function getLeaveBalance(PDO $db, int $empId, int $typeId, int $year): ?array {
    $s = $db->prepare("SELECT total_days,used_days FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?");
    $s->execute([$empId,$typeId,$year]);
    $row = $s->fetch();
    if (!$row) {
        // إنشاء رصيد تلقائياً
        $def = $db->prepare("SELECT default_balance FROM leave_types WHERE id=?");
        $def->execute([$typeId]);
        $defBal = (float)($def->fetchColumn() ?? 0);
        $db->prepare("INSERT IGNORE INTO leave_balances (employee_id,leave_type_id,year,total_days,used_days) VALUES (?,?,?,?,0)")
           ->execute([$empId,$typeId,$year,$defBal]);
        return ['total_days'=>$defBal,'used_days'=>0];
    }
    return $row;
}

/**
 * حساب أيام العمل الفعلية (تستثني الجمعة والسبت)
 * يمكن تعديلها لتستثني العطل أيضاً
 */
function calcBusinessDays(string $start, string $end, PDO $db): int {
    if ($start > $end) return 0;
    $days  = 0;
    $cur   = strtotime($start);
    $endTs = strtotime($end);

    // جلب العطل الرسمية في الفترة
    $holidays = $db->prepare("SELECT date FROM holidays WHERE date BETWEEN ? AND ?");
    $holidays->execute([$start, $end]);
    $hDays = array_column($holidays->fetchAll(), 'date');

    while ($cur <= $endTs) {
        $dow = (int)date('w', $cur);  // 0=أحد 6=سبت
        $d   = date('Y-m-d', $cur);
        if ($dow !== 5 && $dow !== 6 && !in_array($d, $hDays)) {
            $days++;
        }
        $cur = strtotime('+1 day', $cur);
    }
    return $days;
}

/**
 * إرسال إشعار داخلي لموظف
 */
function notifyEmployee(PDO $db, int $empId, string $type, string $title, string $body='', int $refId=0, string $refType=''): void {
    $db->prepare("INSERT INTO notifications (employee_id,type,title,body,ref_id,ref_type) VALUES (?,?,?,?,?,?)")
       ->execute([$empId,$type,$title,$body,$refId ?: null,$refType ?: null]);
}

/**
 * إرسال إشعار لجميع الأدمن و HR
 */
function notifyAdmins(PDO $db, string $type, string $title, string $body='', int $refId=0, string $refType=''): void {
    $admins = $db->query("SELECT id FROM employees WHERE role IN ('admin','hr')")->fetchAll();
    foreach ($admins as $a) {
        notifyEmployee($db, (int)$a['id'], $type, $title, $body, $refId, $refType);
    }
}

/**
 * عدد الإشعارات غير المقروءة
 */
function unreadNotifCount(PDO $db, int $empId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE employee_id=? AND is_read=0");
    $s->execute([$empId]);
    return (int)$s->fetchColumn();
}

/**
 * عدد الرسائل غير المقروءة
 */
function unreadMsgCount(PDO $db, int $empId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM internal_messages WHERE receiver_id=? AND is_read=0 AND deleted_by_receiver=0");
    $s->execute([$empId]);
    return (int)$s->fetchColumn();
}
