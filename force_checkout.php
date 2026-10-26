<?php
require_once '../includes/auth.php';
requireAdmin();
header('Content-Type: application/json');

$db = getDB();
$data = json_decode(file_get_contents('php://input'), true);
$empId = (int)($data['employee_id'] ?? 0);

if (!$empId) {
    echo json_encode(['error' => 'معرّف الموظف مطلوب']); exit;
}

$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

// تحقق من وجود سجل دوام نشط (موظف أو سائق)
$stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=? AND check_out IS NULL");
$stmt->execute([$empId, $today]);
$rec = $stmt->fetch();

if (!$rec) {
    echo json_encode(['error' => 'لا يوجد سجل دوام نشط لهذا الموظف']); exit;
}

$db->prepare("UPDATE attendance SET check_out=?, check_out_lat=0, check_out_lng=0 WHERE id=?")
   ->execute([$now, $rec['id']]);

// إذا كان عنده وقت إضافي موافق عليه وما زال جارياً — أنهِه أيضاً حتى لا تبقى الحالة معلّقة
ensureOvertimeColumns($db);
$otStmt = $db->prepare("SELECT * FROM overtime_requests WHERE employee_id=? AND status='approved' AND ot_check_in IS NOT NULL AND ot_check_out IS NULL ORDER BY id DESC LIMIT 1");
$otStmt->execute([$empId]);
$otRec = $otStmt->fetch();
if ($otRec) {
    $db->prepare("UPDATE overtime_requests SET status='completed', ot_check_out=? WHERE id=?")
       ->execute([$now, $otRec['id']]);
}

// إذا كان السائق في مشوار نشط — أنهيه أيضاً
$empInfo = $db->prepare("SELECT name, role FROM employees WHERE id=?");
$empInfo->execute([$empId]);
$emp = $empInfo->fetch();

$tripEnded = false;
if ($emp && $emp['role'] === 'driver') {
    $tripStmt = $db->prepare("SELECT * FROM trips WHERE employee_id=? AND status='active' LIMIT 1");
    $tripStmt->execute([$empId]);
    $trip = $tripStmt->fetch();
    if ($trip) {
        // احسب المسافة
        $distStmt = $db->prepare("SELECT lat, lng FROM vehicle_tracking WHERE trip_id=? ORDER BY recorded_at ASC");
        $distStmt->execute([$trip['trip_uid']]);
        $points = $distStmt->fetchAll();
        $dist = 0;
        for ($i = 1; $i < count($points); $i++) {
            $dist += haversine($points[$i-1]['lat'], $points[$i-1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
        }
        $db->prepare("UPDATE trips SET status='completed', end_time=?, end_lat=0, end_lng=0, distance_km=? WHERE trip_uid=?")
           ->execute([$now, round($dist,2), $trip['trip_uid']]);
        $db->prepare("UPDATE vehicles SET status='idle' WHERE id=?")->execute([$trip['vehicle_id']]);
        $tripEnded = true;
    }
}

$adminName = $_SESSION['employee_name'];
$role = $emp['role'] === 'driver' ? 'سائق' : 'موظف';

$db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('late_arrival',?,?)")
   ->execute([$empId, "👤 المدير $adminName أنهى دوام {$emp['name']} ($role) يدوياً الساعة ".date('H:i')]);

echo json_encode(['success' => true, 'time' => $now, 'trip_ended' => $tripEnded]);

function haversine($lat1,$lon1,$lat2,$lon2){
    $R=6371;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)*sin($dLat/2)+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    return $R*2*atan2(sqrt($a),sqrt(1-$a));
}
