<?php
// ===================================================
// suppliers_api.php — إدارة الموردين (المرحلة الرابعة)
// ===================================================
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db      = getDB();
$isAdmin = isAdmin();
$action  = $_GET['action'] ?? 'list';

// ── تأكد من وجود الأعمدة الإضافية فقط ──────────────
try {
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS cost_price  decimal(10,2) DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS sell_price  decimal(10,2) DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS supplier_id int(11)       DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS barcode     varchar(100)  DEFAULT NULL");
} catch (Exception $e) {}


try {
    // ── قائمة الموردين ──────────────────────────────
    if ($action === 'list') {
        $onlyActive = ($_GET['active'] ?? '1') === '1';
        $where = $onlyActive ? 'WHERE is_active=1' : '';
        $rows = $db->query("
            SELECT s.*,
                   COUNT(p.id) AS parts_count
            FROM suppliers s
            LEFT JOIN parts p ON p.supplier_id = s.id
            $where
            GROUP BY s.id, s.name, s.phone, s.email, s.address, s.note, s.is_active, s.created_at
            ORDER BY s.name ASC
        ")->fetchAll();
        echo json_encode($rows);

    // ── إضافة مورد ──────────────────────────────────
    } elseif ($action === 'add' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        if ($name === '') { echo json_encode(['error' => 'اسم المورد مطلوب']); exit; }
        $stmt = $db->prepare("INSERT INTO suppliers (name, phone, email, address, note) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $data['phone']??null, $data['email']??null, $data['address']??null, $data['note']??null]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);

    // ── تعديل مورد ──────────────────────────────────
    } elseif ($action === 'edit' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        if (!$id || $name === '') { echo json_encode(['error' => 'بيانات ناقصة']); exit; }
        $db->prepare("UPDATE suppliers SET name=?,phone=?,email=?,address=?,note=?,is_active=? WHERE id=?")
           ->execute([$name, $data['phone']??null, $data['email']??null, $data['address']??null, $data['note']??null, (int)($data['is_active']??1), $id]);
        echo json_encode(['success' => true]);

    // ── حذف مورد (تعطيل) ────────────────────────────
    } elseif ($action === 'delete' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);
        // إلغاء الربط بالقطع قبل الحذف
        $db->prepare("UPDATE parts SET supplier_id=NULL WHERE supplier_id=?")->execute([$id]);
        $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);

    // ── إحصائيات مورد واحد ──────────────────────────
    } elseif ($action === 'stats') {
        $id = (int)($_GET['id'] ?? 0);
        $supplier = $db->prepare("SELECT * FROM suppliers WHERE id=?");
        $supplier->execute([$id]);
        $s = $supplier->fetch();
        if (!$s) { echo json_encode(['error' => 'مورد غير موجود']); exit; }

        $parts = $db->prepare("
            SELECT p.*, sec.label AS section_label
            FROM parts p
            LEFT JOIN sections sec ON sec.id = p.section_id
            WHERE p.supplier_id = ?
            ORDER BY p.name ASC
        ");
        $parts->execute([$id]);
        $s['parts'] = $parts->fetchAll();

        // إجمالي قيمة المخزون لهذا المورد
        $val = $db->prepare("SELECT SUM(qty * COALESCE(cost_price,0)) as total_cost,
                                     SUM(qty * COALESCE(sell_price,0)) as total_sell
                             FROM parts WHERE supplier_id=?");
        $val->execute([$id]);
        $s['inventory_value'] = $val->fetch();

        echo json_encode($s);

    } else {
        echo json_encode(['error' => 'أمر غير معروف']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
