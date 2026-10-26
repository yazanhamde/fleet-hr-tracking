<?php
require_once '../includes/auth.php';
requireAdmin();
header('Content-Type: application/json');
$db = getDB();
$data = json_decode(file_get_contents('php://input'), true);
$db->prepare("UPDATE alerts SET is_read=1 WHERE id=?")->execute([$data['id']]);
echo json_encode(['success' => true]);
