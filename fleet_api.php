<?php
/**
 * fleet_api.php — API endpoints للأسطول
 * يُستخدم من صفحة السائق (driver.php) وخدمة cron
 */
require_once '../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$empId  = $_SESSION['employee_id'] ?? null;

if (!$empId) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

// ── مهام السائق (GET) ─────────────────────────────────────
if ($action === 'my_tasks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT t.*, v.name as vehicle_name, v.plate_number
        FROM driver_tasks t
        LEFT JOIN vehicles v ON v.id = t.vehicle_id
        WHERE t.driver_id = ?
          AND t.status NOT IN ('completed','cancelled')
        ORDER BY FIELD(t.status,'in_progress','accepted','pending'),
                 t.due_date ASC, t.priority DESC, t.id DESC
        LIMIT 20
    ");
    $stmt->execute([$empId]);
    echo json_encode(['ok'=>true,'tasks'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── قبول / تحديث حالة مهمة (POST) ───────────────────────
if ($action === 'update_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = intval($_POST['task_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $note   = $_POST['driver_note'] ?? null;

    // التحقق أن المهمة تخص هذا السائق
    $chk = $db->prepare("SELECT id, driver_id FROM driver_tasks WHERE id=?");
    $chk->execute([$taskId]);
    $task = $chk->fetch();

    if (!$task || $task['driver_id'] != $empId) {
        echo json_encode(['ok'=>false,'error'=>'Not authorized']);
        exit;
    }

    $allowed = ['accepted','in_progress','completed','cancelled'];
    if (!in_array($status, $allowed)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid status']);
        exit;
    }

    $done = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $db->prepare("UPDATE driver_tasks SET status=?, completed_at=?, driver_note=?, updated_at=NOW() WHERE id=?")
       ->execute([$status, $done, $note, $taskId]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── فحص الجيوفنس (POST — تُستدعى من cron أو update_location) ──
if ($action === 'check_geofence' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $lat       = floatval($_POST['lat'] ?? 0);
    $lng       = floatval($_POST['lng'] ?? 0);

    $veh = $db->prepare("SELECT * FROM vehicles WHERE id=? AND geofence_active=1 AND geofence_lat IS NOT NULL");
    $veh->execute([$vehicleId]);
    $v = $veh->fetch();

    if (!$v) {
        echo json_encode(['ok'=>true,'in_fence'=>null]);
        exit;
    }

    // حساب المسافة بصيغة Haversine
    $R = 6371000; // Earth radius in meters
    $φ1 = deg2rad($lat);
    $φ2 = deg2rad($v['geofence_lat']);
    $dφ = deg2rad($v['geofence_lat'] - $lat);
    $dλ = deg2rad($v['geofence_lng'] - $lng);
    $a  = sin($dφ/2)**2 + cos($φ1)*cos($φ2)*sin($dλ/2)**2;
    $distance = 2 * $R * asin(sqrt($a));

    $inFence = $distance <= $v['geofence_radius_m'];

    // سجّل تنبيه الخروج إذا لم يكن داخل النطاق
    if (!$inFence) {
        // تحقق أنه لم يُسجَّل تنبيه في آخر 10 دقائق
        $recent = $db->prepare("
            SELECT id FROM geofence_alerts
            WHERE vehicle_id=? AND alert_type='exit'
              AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            LIMIT 1
        ");
        $recent->execute([$vehicleId]);
        if (!$recent->fetch()) {
            $db->prepare("
                INSERT INTO geofence_alerts (vehicle_id, driver_id, alert_type, lat, lng, distance_m)
                VALUES (?,?,?,?,?,?)
            ")->execute([$vehicleId, $empId, 'exit', $lat, $lng, intval($distance)]);

            // تنبيه في جدول alerts الرئيسي
            $vName = $v['name'];
            $db->prepare("
                INSERT INTO alerts (type, vehicle_id, employee_id, message)
                VALUES ('geofence_exit',?,?,?)
            ")->execute([$vehicleId, $empId, "🚨 السيارة {$vName} خارج نطاق الجيوفنس! (المسافة: ".intval($distance)." م)"]);
        }
    }

    echo json_encode([
        'ok'         => true,
        'in_fence'   => $inFence,
        'distance_m' => intval($distance),
        'radius_m'   => intval($v['geofence_radius_m'])
    ]);
    exit;
}

// ── تقرير مصروفات مركبة (GET) ────────────────────────────
if ($action === 'vehicle_report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();  // إداريون فقط
    $vehicleId = intval($_GET['vehicle_id'] ?? 0);
    $from      = $_GET['from'] ?? date('Y-m-01');
    $to        = $_GET['to']   ?? date('Y-m-d');

    $fuelTotal = $db->prepare("
        SELECT COALESCE(SUM(fuel_cost),0), COALESCE(SUM(fuel_liters),0), COUNT(*)
        FROM vehicle_fuel_log
        WHERE vehicle_id=? AND log_date BETWEEN ? AND ?
    ");
    $fuelTotal->execute([$vehicleId,$from,$to]);
    [$fuelCost,$fuelLiters,$fuelFills] = $fuelTotal->fetch(PDO::FETCH_NUM);

    $maintTotal = $db->prepare("
        SELECT COALESCE(SUM(cost),0), COUNT(*)
        FROM vehicle_maintenance
        WHERE vehicle_id=? AND performed_at BETWEEN ? AND ?
    ");
    $maintTotal->execute([$vehicleId,$from,$to]);
    [$maintCost,$maintCount] = $maintTotal->fetch(PDO::FETCH_NUM);

    $kmTotal = $db->prepare("
        SELECT COALESCE(SUM(distance_km),0), COUNT(*)
        FROM trips
        WHERE vehicle_id=? AND DATE(start_time) BETWEEN ? AND ?
    ");
    $kmTotal->execute([$vehicleId,$from,$to]);
    [$totalKm,$totalTrips] = $kmTotal->fetch(PDO::FETCH_NUM);

    echo json_encode([
        'ok'          => true,
        'fuel_cost'   => round($fuelCost,2),
        'fuel_liters' => round($fuelLiters,1),
        'fuel_fills'  => intval($fuelFills),
        'maint_cost'  => round($maintCost,2),
        'maint_count' => intval($maintCount),
        'total_km'    => round($totalKm,1),
        'total_trips' => intval($totalTrips),
        'total_cost'  => round($fuelCost+$maintCost,2)
    ]);
    exit;
}

// ── إضافة سجل وقود (POST) ────────────────────────────────────────
if ($action === 'add_fuel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $vehicleId   = intval($_POST['vehicle_id'] ?? 0);
    $logDate     = $_POST['log_date']     ?? date('Y-m-d');
    $liters      = floatval($_POST['fuel_liters'] ?? 0);
    $cost        = floatval($_POST['fuel_cost']   ?? 0);
    $station     = trim($_POST['station_name']    ?? '');
    $odometer    = intval($_POST['odometer_km']   ?? 0) ?: null;

    if (!$vehicleId || $cost <= 0) {
        echo json_encode(['ok'=>false,'error'=>'بيانات ناقصة']); exit;
    }

    $db->prepare("
        INSERT INTO vehicle_fuel_log
          (vehicle_id, driver_id, fuel_liters, fuel_cost, odometer_km, station_name, log_date)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$vehicleId, $empId, $liters ?: null, $cost, $odometer, $station ?: null, $logDate]);

    // تحديث عداد الكيلومترات في جدول المركبات إن أُعطي
    if ($odometer) {
        $db->prepare("UPDATE vehicles SET odometer_km=? WHERE id=? AND odometer_km < ?")
           ->execute([$odometer, $vehicleId, $odometer]);
    }

    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
    exit;
}

// ── إضافة صيانة (POST) ───────────────────────────────────────────
if ($action === 'add_maintenance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $vehicleId   = intval($_POST['vehicle_id']       ?? 0);
    $type        = $_POST['maintenance_type']         ?? 'other';
    $description = trim($_POST['description']         ?? '');
    $cost        = floatval($_POST['cost']            ?? 0);
    $odometer    = intval($_POST['odometer_km']       ?? 0) ?: null;
    $nextDate    = $_POST['next_service_date']        ?? null ?: null;
    $nextKm      = intval($_POST['next_service_km']   ?? 0) ?: null;
    $performedBy = trim($_POST['performed_by']        ?? '');

    if (!$vehicleId) { echo json_encode(['ok'=>false,'error'=>'مركبة غير محددة']); exit; }

    $allowed = ['oil_change','tire','brake','filter','battery','inspection','other'];
    if (!in_array($type, $allowed)) $type = 'other';

    $db->prepare("
        INSERT INTO vehicle_maintenance
          (vehicle_id, maintenance_type, description, cost, odometer_km,
           next_service_km, next_service_date, performed_by, performed_at, created_by)
        VALUES (?,?,?,?,?,?,?,?,CURDATE(),?)
    ")->execute([$vehicleId,$type,$description,$cost,$odometer,$nextKm,$nextDate,$performedBy,$empId]);

    // تحديث بيانات الصيانة القادمة في جدول المركبات
    if ($nextDate || $nextKm) {
        $sets=[]; $vals=[];
        if($nextDate){ $sets[]='next_service_date=?'; $vals[]=$nextDate; }
        if($nextKm)  { $sets[]='next_service_km=?';   $vals[]=$nextKm;   }
        if($odometer){ $sets[]='odometer_km=?';        $vals[]=$odometer; }
        $vals[] = $vehicleId;
        $db->prepare("UPDATE vehicles SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    }

    // إلغاء تنبيه الصيانة إن وجد
    $db->prepare("UPDATE alerts SET dismissed_at=NOW() WHERE type='maintenance_due' AND vehicle_id=? AND dismissed_at IS NULL")
       ->execute([$vehicleId]);

    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
    exit;
}

// ── ضبط الجيوفنس (POST) ─────────────────────────────────────────
if ($action === 'set_geofence' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $lat       = floatval($_POST['lat']      ?? 0);
    $lng       = floatval($_POST['lng']      ?? 0);
    $radius    = intval($_POST['radius']     ?? 1000);
    $active    = intval($_POST['active']     ?? 0);

    if (!$vehicleId) { echo json_encode(['ok'=>false,'error'=>'مركبة غير محددة']); exit; }

    $db->prepare("
        UPDATE vehicles
        SET geofence_lat=?, geofence_lng=?, geofence_radius_m=?, geofence_active=?
        WHERE id=?
    ")->execute([$lat ?: null, $lng ?: null, $radius, $active, $vehicleId]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── إضافة مهمة سائق (POST) ──────────────────────────────────────
if ($action === 'add_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $title      = trim($_POST['title']       ?? '');
    $driverId   = intval($_POST['driver_id'] ?? 0);
    $vehicleId  = intval($_POST['vehicle_id']?? 0) ?: null;
    $destination= trim($_POST['destination'] ?? '');
    $priority   = $_POST['priority']         ?? 'normal';
    $dueDate    = $_POST['due_date']         ?? null ?: null;
    $dueTime    = $_POST['due_time']         ?? null ?: null;
    $description= trim($_POST['description'] ?? '');

    if (!$title || !$driverId) {
        echo json_encode(['ok'=>false,'error'=>'العنوان والسائق مطلوبان']); exit;
    }
    $allowedP = ['low','normal','high','urgent'];
    if (!in_array($priority,$allowedP)) $priority='normal';

    $db->prepare("
        INSERT INTO driver_tasks
          (driver_id, vehicle_id, title, description, destination, priority,
           due_date, due_time, assigned_by)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([$driverId,$vehicleId,$title,$description,$destination,
                 $priority,$dueDate,$dueTime,$empId]);
// إشعار للسائق// إشعار للسائق
$newTaskId = $db->lastInsertId();
$db->prepare("
    INSERT INTO notifications (employee_id, type, title, body, ref_id, ref_type)
    VALUES (?, 'task_assigned', 'مهمة جديدة', ?, ?, 'driver_task')
")->execute([$driverId, "📋 مهمة جديدة: $title", $newTaskId]);

    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'Unknown action']);
