<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
ensureOvertimeColumns($db);
$empId = $_SESSION['employee_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$today = date('Y-m-d');
$currentHour = (int)date('H');
$lookupDate = $today;

// ========== موظف/سائق: إرسال طلب وقت إضافي ==========
if ($action === 'request') {
    // نافذة الأوفر تايم: 9 مساء → 10 صبح (بدون أي شرط على الحضور)
    if ($currentHour >= 10 && $currentHour < 21) {
        echo json_encode(['error' => 'لا يمكن طلب الوقت الإضافي قبل الساعة 9 مساءً']); exit;
    }

    // تحقق من عدم وجود طلب سابق
    $check = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND request_date=?");
    $check->execute([$empId, $lookupDate]);
    $existing = $check->fetch();
    if ($existing) {
        echo json_encode(['success' => true, 'already_requested' => true, 'status' => $existing['status']]); exit;
    }

    // إنشاء الطلب
    $db->prepare("INSERT INTO overtime_requests (employee_id, request_date) VALUES (?,?)")
       ->execute([$empId, $lookupDate]);
    $reqId = $db->lastInsertId();

    // جلب اسم الموظف
    $empStmt = $db->prepare("SELECT name, role FROM employees WHERE id=?");
    $empStmt->execute([$empId]);
    $emp = $empStmt->fetch();
    $role = $emp['role'] === 'driver' ? 'سائق' : 'موظف';

    // إرسال تنبيه للمدير
    $db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('overtime_request',?,?)")
       ->execute([$empId, "⏰ {$emp['name']} ($role) يطلب الاستمرار في العمل بعد الساعة 9 مساءً — طلب #$reqId"]);

    echo json_encode(['success' => true, 'request_id' => $reqId]);
}

// ========== موظف/سائق: التحقق من حالة الطلب ==========
elseif ($action === 'check_status') {
    $stmt = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND request_date=?");
    $stmt->execute([$empId, $lookupDate]);
    $req = $stmt->fetch();
    if (!$req) {
        echo json_encode(['status' => 'none']); exit;
    }
    $otMinutes = 0;
    if ($req['ot_check_in'] && $req['ot_check_out']) {
        $otMinutes = round((strtotime($req['ot_check_out']) - strtotime($req['ot_check_in'])) / 60);
    }
    echo json_encode(['status' => $req['status'], 'ot_check_in' => $req['ot_check_in'], 'ot_minutes' => $otMinutes]);
}

// ========== أدمن: الموافقة أو الرفض ==========
elseif ($action === 'respond') {
    if (!isAdmin()) { echo json_encode(['error' => 'غير مصرح']); exit; }

    $reqId    = (int)($data['request_id'] ?? 0);
    $response = $data['response'] ?? ''; // 'approved' or 'rejected'

    if (!$reqId || !in_array($response, ['approved', 'rejected'])) {
        echo json_encode(['error' => 'بيانات غير صحيحة']); exit;
    }

    $stmt = $db->prepare("SELECT r.*, e.name, e.role FROM overtime_requests r JOIN employees e ON e.id=r.employee_id WHERE r.id=?");
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();
    if (!$req) { echo json_encode(['error' => 'الطلب غير موجود']); exit; }

    $db->prepare("UPDATE overtime_requests SET status=?, responded_at=NOW() WHERE id=?")
       ->execute([$response, $reqId]);

    // تنبيه للموظف (كتنبيه عادي)
    $label = $response === 'approved' ? '✅ وافق المدير' : '❌ رفض المدير';
    $role  = $req['role'] === 'driver' ? 'سائق' : 'موظف';
    $db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('overtime_request',?,?)")
       ->execute([$req['employee_id'], "$label على طلب الوقت الإضافي لـ {$req['name']} ($role)"]);

    if ($response === 'approved') {
        // ساعة الإضافي الفعلية تبدأ من لحظة موافقة المدير (منفصلة تماماً عن check_in/check_out)
        $db->prepare("UPDATE overtime_requests SET ot_check_in=NOW() WHERE id=?")->execute([$reqId]);
    }

    if ($response === 'rejected') {
        // رفض = لا يوجد وقت إضافي إطلاقاً، ولا يُحسب أي شيء كإضافي
        // فقط ننهي الدوام العادي تلقائياً (منفصل عن منطق الإضافي) إن كان لا يزال مسجلاً داخل الدوام
        $nowStr = date('Y-m-d H:i:s');
        $attStmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=? AND check_out IS NULL");
        $attStmt->execute([$req['employee_id'], $req['request_date']]);
        $attRec = $attStmt->fetch();
        if ($attRec) {
            $db->prepare("UPDATE attendance SET check_out=? WHERE id=?")
               ->execute([$nowStr, $attRec['id']]);
        } else {
            // إذا لا يوجد سجل حضور أصلاً، أنشئ سجل بخروج فقط (تسجيل الواقعة)
            $checkInStmt = $db->prepare("SELECT id FROM attendance WHERE employee_id=? AND date=?");
            $checkInStmt->execute([$req['employee_id'], $req['request_date']]);
            if (!$checkInStmt->fetch()) {
                $db->prepare("INSERT INTO attendance (employee_id, date, check_out) VALUES (?,?,?)")
                   ->execute([$req['employee_id'], $req['request_date'], $nowStr]);
            }
        }

        // إذا سائق وعنده مشوار نشط — أنهيه تلقائياً
        if ($req['role'] === 'driver') {
            $tripStmt = $db->prepare("SELECT * FROM trips WHERE employee_id=? AND status='active' LIMIT 1");
            $tripStmt->execute([$req['employee_id']]);
            $trip = $tripStmt->fetch();
            if ($trip) {
                $db->prepare("UPDATE trips SET status='completed', end_time=? WHERE trip_uid=?")
                   ->execute([$nowStr, $trip['trip_uid']]);
                $db->prepare("UPDATE vehicles SET status='idle' WHERE id=?")
                   ->execute([$trip['vehicle_id']]);
            }
        }
    }
    // إذا وافق: ot_check_in تم تعيينه أعلاه. الموظف سيسجل انصرافه الإضافي يدوياً عبر زر منفصل عند الانتهاء
    // وسيُحسب وقته الإضافي من لحظة الموافقة فقط (ot_check_in) إلى لحظة الانصراف (ot_check_out) — وليس من نهاية الدوام

    echo json_encode(['success' => true, 'response' => $response]);
}

// ========== أدمن: قائمة الطلبات المعلقة ==========
elseif ($action === 'list_pending') {
    if (!isAdmin()) { echo json_encode(['error' => 'غير مصرح']); exit; }

    $stmt = $db->prepare("
        SELECT r.id, r.employee_id, r.request_date, r.requested_at, r.status,
               e.name, e.role
        FROM overtime_requests r
        JOIN employees e ON e.id = r.employee_id
        WHERE r.request_date=? AND r.status='pending'
        ORDER BY r.requested_at ASC
    ");
    $stmt->execute([$today]);
    echo json_encode(['requests' => $stmt->fetchAll()]);
}

else {
    echo json_encode(['error' => 'أمر غير معروف']);
}
