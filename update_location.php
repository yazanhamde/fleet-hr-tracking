<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['lat']) || empty($data['lng'])) {
    echo json_encode(['error' => 'بيانات ناقصة']); exit;
}

$stmt = $db->prepare("INSERT INTO employee_locations (employee_id, lat, lng, accuracy) VALUES (?,?,?,?)");
$stmt->execute([$_SESSION['employee_id'], $data['lat'], $data['lng'], $data['accuracy'] ?? null]);

echo json_encode(['success' => true]);
