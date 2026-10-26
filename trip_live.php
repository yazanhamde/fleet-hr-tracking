<?php
require_once '../includes/auth.php';
requireAdmin();
header('Content-Type: application/json');

$db = getDB();

$activeTrips = $db->query("
    SELECT 
        t.id, t.trip_uid, t.start_time, t.distance_km,
        t.km_inside, t.km_outside, t.outside_hours,
        t.start_lat, t.start_lng, t.purpose,
        e.id as emp_id, e.name as emp_name,
        v.id as veh_id, v.name as veh_name, v.plate_number,
        vt.lat, vt.lng, vt.speed, vt.recorded_at as last_update,
        TIMESTAMPDIFF(MINUTE, t.start_time, NOW()) as duration_min
    FROM trips t
    JOIN employees e ON e.id = t.employee_id
    JOIN vehicles v ON v.id = t.vehicle_id
    LEFT JOIN vehicle_tracking vt ON vt.id = (
        SELECT id FROM vehicle_tracking WHERE trip_id = t.trip_uid ORDER BY recorded_at DESC LIMIT 1
    )
    WHERE t.status = 'active'
    ORDER BY t.outside_hours DESC, t.km_outside DESC, t.start_time ASC
")->fetchAll();

$today = date('Y-m-d');
$completedToday = $db->query("
    SELECT 
        t.trip_uid, t.start_time, t.end_time, t.distance_km,
        t.km_inside, t.km_outside, t.outside_hours, t.purpose,
        e.name as emp_name,
        v.name as veh_name, v.plate_number,
        TIMESTAMPDIFF(MINUTE, t.start_time, t.end_time) as duration_min
    FROM trips t
    JOIN employees e ON e.id = t.employee_id
    JOIN vehicles v ON v.id = t.vehicle_id
    WHERE t.status = 'completed' AND DATE(t.start_time) = '$today'
    ORDER BY t.km_outside DESC, t.end_time DESC
")->fetchAll();

// إحصائيات دقيقة - تشمل كل مشاوير اليوم (نشطة + مكتملة)
$allToday = array_merge($activeTrips, $completedToday);
$stats = [
    'active_total'     => count($activeTrips),
    'active_outside'   => count(array_filter($activeTrips, fn($t) => $t['outside_hours'])),
    'active_inside'    => count(array_filter($activeTrips, fn($t) => !$t['outside_hours'])),
    'done_outside'     => count(array_filter($completedToday, fn($t) => $t['outside_hours'])),
    'done_inside'      => count(array_filter($completedToday, fn($t) => !$t['outside_hours'])),
    // إجمالي مشاوير اليوم (نشطة + مكتملة)
    'today_total'      => count($allToday),
    'today_outside'    => count(array_filter($allToday, fn($t) => $t['outside_hours'])),
    'today_inside'     => count(array_filter($allToday, fn($t) => !$t['outside_hours'])),
    // كيلو خارج الدوام الفعلية (من كل مشاوير اليوم)
    'km_outside_today' => round(array_sum(array_column($allToday, 'km_outside')), 2),
    // كيلو داخل الدوام الفعلية
    'km_inside_today'  => round(array_sum(array_column($allToday, 'km_inside')), 2),
    // إجمالي
    'km_total_today'   => round(array_sum(array_column($allToday, 'distance_km')), 2),
];

echo json_encode([
    'active'    => $activeTrips,
    'completed' => $completedToday,
    'stats'     => $stats,
    'ts'        => time(),
]);
