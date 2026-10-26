<?php
require_once '../includes/auth.php';
requireLogin();

// ساعات الدوام من config.php
$WORK_START_H = (int)explode(':', WORK_START)[0]; // 10
$WORK_END_H   = (int)explode(':', WORK_END)[0];   // 21
header('Content-Type: application/json');

$db = getDB();
$empId = $_SESSION['employee_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$now = date('Y-m-d H:i:s');

if ($action === 'start') {
    $stmt = $db->prepare("SELECT id FROM trips WHERE employee_id=? AND status='active'");
    $stmt->execute([$empId]);
    if ($stmt->fetch()) { echo json_encode(['error' => 'عندك مشوار نشط — أنهيه أولاً']); exit; }

    $stmt = $db->prepare("SELECT id FROM trips WHERE vehicle_id=? AND status='active'");
    $stmt->execute([$data['vehicle_id']]);
    if ($stmt->fetch()) { echo json_encode(['error' => 'هالسيارة بمشوار مع موظف آخر']); exit; }

    $tripUid = uniqid('trip_', true);
    $h = (int)date('H');
    $startedOutside = ($h < $WORK_START_H || $h >= $WORK_END_H) ? 1 : 0;

    $stmt = $db->prepare("INSERT INTO trips (trip_uid, vehicle_id, employee_id, start_time, start_lat, start_lng, purpose, outside_hours, km_inside, km_outside) VALUES (?,?,?,?,?,?,?,?,0,0)");
    $stmt->execute([$tripUid, $data['vehicle_id'], $empId, $now, $data['lat'], $data['lng'], $data['purpose'] ?? '', 0]);

    $db->prepare("UPDATE vehicles SET status='active', assigned_to=? WHERE id=?")->execute([$empId, $data['vehicle_id']]);

    echo json_encode(['success' => true, 'trip_uid' => $tripUid, 'started_outside' => $startedOutside]);

} elseif ($action === 'end') {
    $stmt = $db->prepare("SELECT * FROM trips WHERE trip_uid=? AND employee_id=? AND status='active'");
    $stmt->execute([$data['trip_uid'], $empId]);
    $trip = $stmt->fetch();
    if (!$trip) { echo json_encode(['error' => 'مشوار غير موجود']); exit; }

    // حساب الكيلومترات المفصلة داخل/خارج الدوام
    list($total, $inside, $outside) = calculateTripDistanceDetailed($db, $data['trip_uid']);

    $stmt = $db->prepare("UPDATE trips SET status='completed', end_time=?, end_lat=?, end_lng=?, distance_km=?, km_inside=?, km_outside=? WHERE trip_uid=?");
    $stmt->execute([$now, $data['lat'] ?? 0, $data['lng'] ?? 0, $total, $inside, $outside, $data['trip_uid']]);

    $db->prepare("UPDATE vehicles SET status='idle' WHERE id=?")->execute([$trip['vehicle_id']]);

    echo json_encode([
        'success'    => true,
        'total_km'   => $total,
        'inside_km'  => $inside,
        'outside_km' => $outside,
    ]);

} elseif ($action === 'today_stats') {
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_trips,
            COALESCE(SUM(distance_km),0) as total_km,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as done_trips
        FROM trips 
        WHERE employee_id=? AND DATE(start_time)=?
    ");
    $stmt->execute([$empId, $today]);
    $stats = $stmt->fetch();
    echo json_encode([
        'total_trips' => (int)$stats['total_trips'],
        'total_km'    => round($stats['total_km'], 2),
        'done_trips'  => (int)$stats['done_trips'],
    ]);

} else {
    echo json_encode(['error' => 'أمر غير معروف']);
}

function calculateTripDistanceDetailed($db, $tripUid) {
    global $WORK_START_H, $WORK_END_H;
    $stmt = $db->prepare("SELECT lat, lng, recorded_at FROM vehicle_tracking WHERE trip_id=? ORDER BY recorded_at ASC");
    $stmt->execute([$tripUid]);
    $points = $stmt->fetchAll();

    $total = 0; $inside = 0; $outside = 0;

    if (count($points) < 3) {
        // Fallback: احسب المسافة المباشرة بين نقطة البداية والنهاية من جدول trips
        $t2 = $db->prepare("SELECT start_lat, start_lng, end_lat, end_lng, start_time, end_time, outside_hours FROM trips WHERE trip_uid=?");
        $t2->execute([$tripUid]);
        $trip = $t2->fetch();
        if ($trip && $trip['end_lat'] && $trip['end_lng']) {
            $total = haversine($trip['start_lat'], $trip['start_lng'], $trip['end_lat'], $trip['end_lng']);
            // حدد داخل أم خارج بحسب وقت بداية المشوار
            $startHour = (int)date('H', strtotime($trip['start_time']));
            if ($startHour < $WORK_START_H || $startHour >= $WORK_END_H) $outside = $total;
            else $inside = $total;
        }
        return [round($total,3), round($inside,3), round($outside,3)];
    }

    for ($i = 1; $i < count($points); $i++) {
        $seg = haversine($points[$i-1]['lat'], $points[$i-1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
        $total += $seg;
        $segHour = (int)date('H', strtotime($points[$i]['recorded_at']));
        if ($segHour < $WORK_START_H || $segHour >= $WORK_END_H) $outside += $seg;
        else $inside += $seg;
    }
    return [round($total,3), round($inside,3), round($outside,3)];
}

function haversine($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2-$lat1); $dLon = deg2rad($lon2-$lon1);
    $a = sin($dLat/2)*sin($dLat/2)+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    return $R*2*atan2(sqrt($a),sqrt(1-$a));
}
