<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
$empId = $_SESSION['employee_id'];
$data = json_decode(file_get_contents('php://input'), true);
$note = trim($data['note'] ?? '');
$lat  = $data['lat'] ?? null;
$lng  = $data['lng'] ?? null;

if (!$note) {
    echo json_encode(['error' => 'الملاحظة فارغة']); exit;
}

// تحقق إن جدول notes موجود، وإلا أنشئه
$db->exec("CREATE TABLE IF NOT EXISTS employee_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    note TEXT NOT NULL,
    lat DECIMAL(10,8),
    lng DECIMAL(11,8),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
)");

$stmt = $db->prepare("INSERT INTO employee_notes (employee_id, note, lat, lng) VALUES (?,?,?,?)");
$stmt->execute([$empId, $note, $lat ?: null, $lng ?: null]);

// أضف تنبيه للمدير
$empName = $_SESSION['employee_name'];
$db->prepare("INSERT INTO alerts (type, employee_id, message) VALUES ('late_arrival',?,?)")
   ->execute([$empId, "📝 ملاحظة من $empName: ".mb_substr($note,0,80)]);

echo json_encode(['success' => true]);
