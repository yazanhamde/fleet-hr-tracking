<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db   = getDB();
$data = json_decode(file_get_contents('php://input'), true);
$empId    = (int)($data['employee_id'] ?? $_SESSION['employee_id']);
$vehicleId= isset($data['vehicle_id']) ? (int)$data['vehicle_id'] : null;

// منع تكرار التنبيه خلال 10 دقائق
$stmt = $db->prepare("SELECT id FROM alerts WHERE employee_id=? AND type='absent' AND message LIKE '%أطفأ الموقع%' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$stmt->execute([$empId]);
if($stmt->fetch()){ echo json_encode(['ok'=>true,'skipped'=>true]); exit; }

// جلب اسم الموظف
$eStmt = $db->prepare("SELECT name FROM employees WHERE id=?");
$eStmt->execute([$empId]);
$emp = $eStmt->fetch();
$name = $emp['name'] ?? 'سائق';

$msg = "📵 السائق {$name} أطفأ الموقع (GPS)!";
if($vehicleId){
  $vStmt = $db->prepare("SELECT name, plate_number FROM vehicles WHERE id=?");
  $vStmt->execute([$vehicleId]);
  $v = $vStmt->fetch();
  if($v) $msg .= " — السيارة: {$v['name']} ({$v['plate_number']})";
}

$db->prepare("INSERT INTO alerts (type, vehicle_id, employee_id, message) VALUES ('absent',?,?,?)")
   ->execute([$vehicleId, $empId, $msg]);

echo json_encode(['ok'=>true]);
