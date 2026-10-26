<?php
/**
 * fleet_api.php — إضافات المرحلة الثالثة
 * أضف هذه الـ actions إلى fleet_api.php الموجود قبل سطر:
 *   http_response_code(400);
 *   echo json_encode(['ok'=>false,'error'=>'Unknown action']);
 */

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

    // إشعار للسائق
    $db->prepare("
        INSERT INTO notifications (employee_id, type, message, related_id)
        VALUES (?, 'task_assigned', ?, ?)
    ")->execute([$driverId, "📋 مهمة جديدة: $title", $db->lastInsertId()]);

    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
    exit;
}
