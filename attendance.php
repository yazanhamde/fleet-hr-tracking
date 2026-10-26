<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
$empId = $_SESSION['employee_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// دالة تحويل الإحداثيات لعنوان بالعربي
function getAddress($lat, $lng) {
    if (!$lat || !$lng) return null;
    $key = GMAPS_API_KEY;
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&language=ar&key={$key}";
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $json = json_decode($res, true);
    if ($json['status'] === 'OK' && !empty($json['results'])) {
        // خذ أقصر عنوان مفيد (المكون الثاني عادةً الحي أو الشارع)
        foreach ($json['results'] as $r) {
            $addr = $r['formatted_address'] ?? '';
            if ($addr) return $addr;
        }
    }
    return null;
}

if ($action === 'check_in') {
    $empInfo = $db->prepare("SELECT * FROM employees WHERE id=?");
    $empInfo->execute([$empId]);
    $empRow = $empInfo->fetch();
    $workStartStr = $empRow['work_start'] ?? '10:00:00';
    $workStart = strtotime(date('Y-m-d') . ' ' . $workStartStr);
    $grace = $workStart + (LATE_GRACE_MINUTES * 60);
    $nowTs = time();

    if ($nowTs < $workStart) {
        echo json_encode(['error' => 'لم يحن وقت الدوام بعد، دوامك يبدأ الساعة ' . date('h:i a', $workStart)]); exit;
    }

    $stmt = $db->prepare("SELECT id FROM attendance WHERE employee_id=? AND date=?");
    $stmt->execute([$empId, $today]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'سجلت دخولك اليوم مسبقاً']); exit;
    }

    $lateMin = $nowTs > $grace ? round(($nowTs - $workStart) / 60) : 0;
    $status = $lateMin > 0 ? 'late' : 'on_time';

    // جلب العنوان
    $address = getAddress($data['lat'] ?? null, $data['lng'] ?? null);

    $stmt = $db->prepare("INSERT INTO attendance (employee_id, check_in, check_in_lat, check_in_lng, check_in_address, date, status, late_minutes) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$empId, $now, $data['lat'], $data['lng'], $address, $today, $status, $lateMin]);

    $s2 = $db->prepare("INSERT INTO employee_locations (employee_id, lat, lng) VALUES (?,?,?)");
    $s2->execute([$empId, $data['lat'], $data['lng']]);

    echo json_encode(['success' => true, 'late_minutes' => $lateMin, 'address' => $address]);

} elseif ($action === 'check_out') {
    $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=? AND check_out IS NULL");
    $stmt->execute([$empId, $today]);
    $rec = $stmt->fetch();
    if (!$rec) {
        echo json_encode(['error' => 'لا يوجد دخول مسجل اليوم']); exit;
    }

    // جلب العنوان
    $address = getAddress($data['lat'] ?? null, $data['lng'] ?? null);

    $s2 = $db->prepare("UPDATE attendance SET check_out=?, check_out_lat=?, check_out_lng=?, check_out_address=? WHERE id=?");
    $s2->execute([$now, $data['lat'], $data['lng'], $address, $rec['id']]);

    echo json_encode(['success' => true, 'address' => $address]);

} elseif ($action === 'overtime_checkout') {
    // انصراف الوقت الإضافي — منفصل تماماً عن check_in/check_out العادي
    // يُستخدم بعد موافقة المدير على طلب الأوفر تايم، ويُحسب من لحظة الموافقة (ot_check_in) فقط
    ensureOvertimeColumns($db);

    $otStmt = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND status='approved' ORDER BY id DESC LIMIT 1");
    $otStmt->execute([$empId]);
    $otReq = $otStmt->fetch();
    if (!$otReq) {
        echo json_encode(['error' => 'لا يوجد طلب وقت إضافي موافق عليه']); exit;
    }
    if (!$otReq['ot_check_in']) {
        echo json_encode(['error' => 'لم يتم تسجيل بداية الوقت الإضافي بعد']); exit;
    }

    $address = getAddress($data['lat'] ?? null, $data['lng'] ?? null);

    $db->prepare("UPDATE overtime_requests SET status='completed', ot_check_out=?, ot_check_out_lat=?, ot_check_out_lng=?, ot_check_out_address=? WHERE id=?")
       ->execute([$now, $data['lat'] ?? 0, $data['lng'] ?? 0, $address, $otReq['id']]);

    $otMinutes = round((strtotime($now) - strtotime($otReq['ot_check_in'])) / 60);

    // إنهاء أي مشوار نشط (للسائقين) — لأنه أنهى عمله فعلياً الآن
    $tripStmt = $db->prepare("SELECT * FROM trips WHERE employee_id=? AND status='active' LIMIT 1");
    $tripStmt->execute([$empId]);
    $trip = $tripStmt->fetch();
    if ($trip) {
        $db->prepare("UPDATE trips SET status='completed', end_time=? WHERE trip_uid=?")
           ->execute([$now, $trip['trip_uid']]);
        $db->prepare("UPDATE vehicles SET status='idle' WHERE id=?")
           ->execute([$trip['vehicle_id']]);
    }

    echo json_encode(['success' => true, 'address' => $address, 'ot_minutes' => $otMinutes]);

} else {
    echo json_encode(['error' => 'أمر غير معروف']);
}
