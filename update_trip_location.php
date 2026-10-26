<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['trip_uid']) || !isset($data['lat']) || !isset($data['lng'])) {
    echo json_encode(['error' => 'بيانات ناقصة']); exit;
}

$empId = $_SESSION['employee_id'];

$stmt = $db->prepare("SELECT * FROM trips WHERE trip_uid=? AND employee_id=? AND status='active'");
$stmt->execute([$data['trip_uid'], $empId]);
$trip = $stmt->fetch();
if (!$trip) { echo json_encode(['error' => 'مشوار غير موجود']); exit; }

$lat   = (float)$data['lat'];
$lng   = (float)$data['lng'];
$speed = (float)($data['speed'] ?? 0);
$now   = date('Y-m-d H:i:s');

// هل هذه النقطة داخل أم خارج وقت الدوام؟
$h = (int)date('H');
$outsideNow = ($h < 10 || $h >= 21) ? 1 : 0;

// حفظ نقطة التتبع مع علامة داخل/خارج
$stmt = $db->prepare("INSERT INTO vehicle_tracking (vehicle_id, employee_id, lat, lng, speed, trip_id, recorded_at) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$trip['vehicle_id'], $empId, $lat, $lng, $speed, $data['trip_uid'], $now]);

// تحديث موقع الموظف للخريطة الرئيسية
$db->prepare("INSERT INTO employee_locations (employee_id, lat, lng) VALUES (?,?,?)")
   ->execute([$empId, $lat, $lng]);

// ========= حساب الكيلومترات بدقة — كل قطعة بحسب وقتها =========
$distStmt = $db->prepare("SELECT lat, lng, recorded_at FROM vehicle_tracking WHERE trip_id=? ORDER BY recorded_at ASC");
$distStmt->execute([$data['trip_uid']]);
$points = $distStmt->fetchAll();

$totalKm    = 0;
$insideKm   = 0;
$outsideKm  = 0;

for ($i = 1; $i < count($points); $i++) {
    $seg = haversine(
        $points[$i-1]['lat'], $points[$i-1]['lng'],
        $points[$i]['lat'],   $points[$i]['lng']
    );
    $totalKm += $seg;

    // وقت النقطة الثانية يحدد نوع القطعة
    $segHour = (int)date('H', strtotime($points[$i]['recorded_at']));
    $isOutside = ($segHour < 10 || $segHour >= 21);
    if ($isOutside) $outsideKm += $seg;
    else            $insideKm  += $seg;
}

$totalKm   = round($totalKm, 3);
$insideKm  = round($insideKm, 3);
$outsideKm = round($outsideKm, 3);

// تحديث المشوار بالكيلومترات المفصلة
$db->prepare("UPDATE trips SET distance_km=?, km_inside=?, km_outside=? WHERE trip_uid=?")
   ->execute([$totalKm, $insideKm, $outsideKm, $data['trip_uid']]);

// ======= مراقبة مشددة داخل الدوام (كل 5 دقائق) =======
if (!$outsideNow) {
    $stmt = $db->prepare("SELECT id FROM alerts WHERE vehicle_id=? AND type='late_arrival' AND message LIKE '%تتبع دوام%' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$trip['vehicle_id']]);
    if (!$stmt->fetch()) {
        $empName = $_SESSION['employee_name'];
        $msg = "📍 تتبع دوام: {$empName} — داخل: {$insideKm} كم | الإجمالي: {$totalKm} كم";
        $db->prepare("INSERT INTO alerts (type, vehicle_id, employee_id, message) VALUES ('late_arrival',?,?,?)")
           ->execute([$trip['vehicle_id'], $empId, $msg]);
    }
}

// ======= مراقبة مشددة خارج الدوام (كل 5 دقائق) =======
if ($outsideNow) {
    $stmt = $db->prepare("SELECT id FROM alerts WHERE vehicle_id=? AND type='vehicle_outside_hours' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$trip['vehicle_id']]);
    if (!$stmt->fetch()) {
        $empName = $_SESSION['employee_name'];
        $msg = "🚨 خارج الدوام: {$empName} — خارج: {$outsideKm} كم | داخل: {$insideKm} كم | الإجمالي: {$totalKm} كم";
        $db->prepare("INSERT INTO alerts (type, vehicle_id, employee_id, message) VALUES ('vehicle_outside_hours',?,?,?)")
           ->execute([$trip['vehicle_id'], $empId, $msg]);
        // تحديث حالة السيارة إلى تنبيه
        $db->prepare("UPDATE vehicles SET status='alert' WHERE id=?")->execute([$trip['vehicle_id']]);
    }
}

echo json_encode([
    'success'     => true,
    'total_km'    => $totalKm,
    'distance_km' => $totalKm,   // alias يستخدمه الـ JS في driver.php
    'inside_km'   => $insideKm,
    'outside_km'  => $outsideKm,
    'points'      => count($points),
    'outside_now' => $outsideNow,
]);

function haversine($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}
