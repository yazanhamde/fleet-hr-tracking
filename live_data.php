<?php
require_once '../includes/auth.php';
requireAdmin();
header('Content-Type: application/json');

$db = getDB();
$today = date('Y-m-d');

// إذا طلب مسار موظف/سائق محدد (trail=empId)
if (isset($_GET['trail']) && is_numeric($_GET['trail'])) {
    $empId = (int)$_GET['trail'];
    // آخر 100 موقع لهذا الموظف في آخر 24 ساعة
    $stmt = $db->prepare("
        SELECT lat, lng, recorded_at
        FROM employee_locations
        WHERE employee_id=? AND recorded_at >= NOW() - INTERVAL 24 HOUR
        ORDER BY recorded_at ASC
        LIMIT 200
    ");
    $stmt->execute([$empId]);
    $trail = $stmt->fetchAll();
    // أضف مواقع المركبة إذا كان سائقاً
    $stmt2 = $db->prepare("
        SELECT lat, lng, recorded_at
        FROM vehicle_tracking
        WHERE employee_id=? AND recorded_at >= NOW() - INTERVAL 24 HOUR
        ORDER BY recorded_at ASC
        LIMIT 200
    ");
    $stmt2->execute([$empId]);
    $vTrail = $stmt2->fetchAll();
    // دمج المسارين وترتيبهم بالوقت وفلترة الإحداثيات الصفرية
    $allTrail = array_merge($trail, $vTrail);
    $allTrail = array_filter($allTrail, fn($p)=>$p['lat']!=0 && $p['lng']!=0);
    $allTrail = array_values($allTrail);
    usort($allTrail, fn($a,$b)=>strcmp($a['recorded_at'],$b['recorded_at']));
    echo json_encode(['trail' => $allTrail]);
    exit;
}

// موظفون (role=employee) مع آخر موقع
$employees = $db->query("
    SELECT e.id, e.name, e.role,
           a.check_in, a.check_out, a.status as att_status, a.late_minutes,
           a.check_in_address, a.check_out_address,
           l.lat, l.lng, l.recorded_at as last_seen
    FROM employees e
    LEFT JOIN attendance a ON a.employee_id=e.id AND a.date='$today'
    LEFT JOIN employee_locations l ON l.id=(
        SELECT id FROM employee_locations WHERE employee_id=e.id ORDER BY recorded_at DESC LIMIT 1
    )
    WHERE e.role='employee'
    ORDER BY e.name
")->fetchAll();

// سائقون مع آخر موقع وحالة الدوام
$drivers = $db->query("
    SELECT e.id, e.name, e.role,
           a.check_in, a.check_out, a.status as att_status, a.late_minutes,
           a.check_in_address, a.check_out_address,
           l.lat, l.lng, l.recorded_at as last_seen,
           v.id as vehicle_id, v.name as vehicle_name, v.plate_number,
           t.trip_uid, t.status as trip_status, t.start_time as trip_start,
           COALESCE(t.km_inside,0) as km_inside, COALESCE(t.km_outside,0) as km_outside, COALESCE(t.distance_km,0) as distance_km
    FROM employees e
    LEFT JOIN attendance a ON a.employee_id=e.id AND a.date='$today'
    LEFT JOIN employee_locations l ON l.id=(
        SELECT id FROM employee_locations WHERE employee_id=e.id ORDER BY recorded_at DESC LIMIT 1
    )
    LEFT JOIN vehicles v ON v.assigned_to=e.id
    LEFT JOIN trips t ON t.employee_id=e.id AND t.status='active'
    WHERE e.role='driver'
    ORDER BY e.name
")->fetchAll();

// سيارات مع آخر موقع
$vehicles = $db->query("
    SELECT v.id, v.name, v.plate_number, v.status,
           e.name as driver_name,
           t.trip_uid, t.start_time, t.distance_km, t.outside_hours,
           vt.lat, vt.lng, vt.recorded_at as last_seen
    FROM vehicles v
    LEFT JOIN employees e ON e.id=v.assigned_to
    LEFT JOIN trips t ON t.vehicle_id=v.id AND t.status='active'
    LEFT JOIN vehicle_tracking vt ON vt.id=(
        SELECT id FROM vehicle_tracking WHERE vehicle_id=v.id ORDER BY recorded_at DESC LIMIT 1
    )
    ORDER BY v.id
")->fetchAll();

// تنبيهات غير مقروءة
$alerts = $db->query("
    SELECT a.*, e.name as emp_name, v.name as veh_name
    FROM alerts a
    LEFT JOIN employees e ON e.id=a.employee_id
    LEFT JOIN vehicles v ON v.id=a.vehicle_id
    WHERE a.is_read=0
    ORDER BY a.created_at DESC
    LIMIT 20
")->fetchAll();

// إحصائيات
$stats = [
    'present'  => 0,
    'late'     => 0,
    'absent'   => 0,
    'drivers_present' => 0,
    'active_vehicles' => 0,
    'alerts'   => count($alerts),
];

foreach ($employees as $emp) {
    if ($emp['check_in'] && !$emp['check_out']) {
        $stats['present']++;
        if ($emp['att_status'] === 'late') $stats['late']++;
    } elseif (!$emp['check_in']) {
        $stats['absent']++;
    }
}
foreach ($drivers as $drv) {
    if ($drv['check_in'] && !$drv['check_out']) {
        $stats['drivers_present']++;
        if ($drv['att_status'] === 'late') $stats['late']++;
    } elseif (!$drv['check_in']) {
        $stats['absent']++;
    }
}
foreach ($vehicles as $v) {
    if ($v['status'] === 'active') $stats['active_vehicles']++;
}

// طلبات الوقت الإضافي المعلقة
$overtimeRequests = $db->query("
    SELECT r.id, r.employee_id, r.requested_at, r.status,
           e.name, e.role
    FROM overtime_requests r
    JOIN employees e ON e.id = r.employee_id
    WHERE r.request_date='$today' AND r.status='pending'
    ORDER BY r.requested_at ASC
")->fetchAll();

echo json_encode([
    'employees' => $employees,
    'drivers'   => $drivers,
    'vehicles'  => $vehicles,
    'alerts'    => $alerts,
    'overtime_requests' => $overtimeRequests,
    'stats'     => $stats,
    'ts'        => time(),
]);
