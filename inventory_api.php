<?php
// ===================================================
// inventory_api.php — إدارة المخزون (نفس بنية suppliers_api)
// ===================================================
require_once '../includes/auth.php';
require_once '../includes/upload_helper.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db      = getDB();
$isAdmin = isAdmin();
$action  = $_GET['action'] ?? 'list';
$empId   = $_SESSION['employee_id'] ?? null;

// ── تأكد من وجود الأعمدة ──────────────────────────
try {
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS image       VARCHAR(255)  DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS cost_price  DECIMAL(10,2) DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS sell_price  DECIMAL(10,2) DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS supplier_id INT(11)       DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS barcode     VARCHAR(100)  DEFAULT NULL");
    $db->exec("ALTER TABLE parts ADD COLUMN IF NOT EXISTS min_qty     INT(11)       NOT NULL DEFAULT 5");
} catch (Exception $e) {}

function logInventory($db, $partId, $empId, $action, $qtyBefore, $qtyAfter, $note = '') {
    try {
        $db->prepare("INSERT INTO inventory_log (part_id,employee_id,action,qty_before,qty_after,note) VALUES (?,?,?,?,?,?)")
           ->execute([$partId, $empId, $action, $qtyBefore, $qtyAfter, $note]);
    } catch (Exception $e) {}
}

try {

    // ── قائمة القطع ─────────────────────────────────
    if ($action === 'list') {
        $q      = trim($_GET['q'] ?? '');
        $sec    = trim($_GET['section'] ?? '');
        $stock  = $_GET['stock'] ?? '';
        $where  = []; $params = [];

        if ($q !== '') {
            $where[] = '(p.part_code LIKE ? OR p.name LIKE ? OR p.device LIKE ? OR p.barcode LIKE ?)';
            $params  = array_merge($params, ["%$q%","%$q%","%$q%","%$q%"]);
        }
        if ($sec !== '') { $where[] = 'p.section_id = ?'; $params[] = (int)$sec; }
        if ($stock === 'low') { $where[] = 'p.qty <= p.min_qty AND p.qty > 0'; }
        if ($stock === 'out') { $where[] = 'p.qty = 0'; }

        $wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT p.*,
                   s.label    AS section_label,
                   sup.name   AS supplier_name,
                   sup.phone  AS supplier_phone,
                   CASE WHEN p.cost_price > 0 AND p.sell_price > 0
                        THEN ROUND((p.sell_price - p.cost_price) / p.cost_price * 100, 1)
                        ELSE NULL END AS margin_pct
            FROM parts p
            LEFT JOIN sections  s   ON s.id   = p.section_id
            LEFT JOIN suppliers sup ON sup.id  = p.supplier_id
            $wSql
            ORDER BY p.name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // إحصائيات
        $total = count($rows);
        $ok    = 0; $low = 0; $out = 0;
        foreach ($rows as $r) {
            if ($r['qty'] == 0) $out++;
            elseif ($r['qty'] <= $r['min_qty']) $low++;
            else $ok++;
        }

        echo json_encode([
            'rows'  => $rows,
            'total' => $total,
            'ok'    => $ok,
            'low'   => $low,
            'out'   => $out,
        ]);

    // ── الأقسام / الأرفف ────────────────────────────
    } elseif ($action === 'sections') {
        $rows = $db->query("SELECT * FROM sections ORDER BY letter, number")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);

    // ── الموردين (للقائمة المنسدلة) ─────────────────
    } elseif ($action === 'suppliers') {
        $rows = $db->query("SELECT id, name, phone FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);

    // ── تفاصيل قطعة واحدة ───────────────────────────
    } elseif ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id مطلوب']); exit; }
        $stmt = $db->prepare("
            SELECT p.*, s.label AS section_label,
                   sup.name AS supplier_name,
                   CASE WHEN p.cost_price > 0 AND p.sell_price > 0
                        THEN ROUND((p.sell_price - p.cost_price) / p.cost_price * 100, 1)
                        ELSE NULL END AS margin_pct
            FROM parts p
            LEFT JOIN sections  s   ON s.id  = p.section_id
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));

    // ── بيع قطعة ────────────────────────────────────
    } elseif ($action === 'sell' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);
        $qty  = (int)($data['qty'] ?? 0);
        if ($qty <= 0) { echo json_encode(['error' => 'الكمية غير صحيحة']); exit; }

        $part = $db->prepare("SELECT qty, sell_price, cost_price, name FROM parts WHERE id=?");
        $part->execute([$id]);
        $part = $part->fetch(PDO::FETCH_ASSOC);
        if (!$part) { echo json_encode(['error' => 'القطعة غير موجودة']); exit; }
        if ($part['qty'] < $qty) { echo json_encode(['error' => 'الكمية المتاحة: ' . $part['qty']]); exit; }

        $qtyBefore = $part['qty'];
        $db->prepare("UPDATE parts SET qty = qty - ? WHERE id=?")->execute([$qty, $id]);
        try {
            $db->prepare("INSERT INTO parts_sales (part_id, employee_id, qty, sell_price, cost_price) VALUES (?,?,?,?,?)")
               ->execute([$id, $empId, $qty, $part['sell_price'], $part['cost_price']]);
        } catch (Exception $e) {}
        logInventory($db, $id, $empId, 'sell', $qtyBefore, $qtyBefore - $qty, "بيع $qty قطعة");
        echo json_encode(['success' => true]);

    // ── إضافة قطعة ──────────────────────────────────
    } elseif ($action === 'add' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $upload = safeUploadImage($_FILES['image'], 'parts');
            if (!$upload['ok']) { echo json_encode(['error' => $upload['error']]); exit; }
            $imagePath = $upload['path'];
        }
        $partCode = trim($_POST['part_code'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        if (!$partCode || !$name) { echo json_encode(['error' => 'الكود والاسم مطلوبان']); exit; }

        $stmt = $db->prepare("
            INSERT INTO parts (part_code, name, device, section_id, qty, min_qty,
                               cost_price, sell_price, supplier_id, barcode, note, image)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $partCode,
            $name,
            $_POST['device'] ?? '',
            !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null,
            (int)($_POST['qty'] ?? 0),
            (int)($_POST['min_qty'] ?? 5),
            $_POST['cost_price'] !== '' ? (float)$_POST['cost_price'] : null,
            $_POST['sell_price'] !== '' ? (float)$_POST['sell_price'] : null,
            !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
            $_POST['barcode'] ?? null,
            $_POST['note'] ?? '',
            $imagePath,
        ]);
        $newId = $db->lastInsertId();
        logInventory($db, $newId, $empId, 'add', 0, (int)($_POST['qty'] ?? 0), 'إضافة قطعة');
        echo json_encode(['success' => true, 'id' => $newId]);

    // ── تعديل قطعة ──────────────────────────────────
    } elseif ($action === 'edit' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id مطلوب']); exit; }

        $old = $db->prepare("SELECT qty, image FROM parts WHERE id=?");
        $old->execute([$id]);
        $old = $old->fetch(PDO::FETCH_ASSOC);
        if (!$old) { echo json_encode(['error' => 'القطعة غير موجودة']); exit; }

        $imagePath = $old['image'];
        if (!empty($_FILES['image']['name'])) {
            $upload = safeUploadImage($_FILES['image'], 'parts');
            if (!$upload['ok']) { echo json_encode(['error' => $upload['error']]); exit; }
            $imagePath = $upload['path'];
        }

        $newQty = (int)($_POST['qty'] ?? $old['qty']);
        $db->prepare("
            UPDATE parts SET part_code=?, name=?, device=?, section_id=?, qty=?, min_qty=?,
                             cost_price=?, sell_price=?, supplier_id=?, barcode=?, note=?, image=?
            WHERE id=?
        ")->execute([
            trim($_POST['part_code'] ?? ''),
            trim($_POST['name'] ?? ''),
            $_POST['device'] ?? '',
            !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null,
            $newQty,
            (int)($_POST['min_qty'] ?? 5),
            $_POST['cost_price'] !== '' ? (float)$_POST['cost_price'] : null,
            $_POST['sell_price'] !== '' ? (float)$_POST['sell_price'] : null,
            !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
            $_POST['barcode'] ?? null,
            $_POST['note'] ?? '',
            $imagePath,
            $id,
        ]);

        if ($newQty != $old['qty']) {
            logInventory($db, $id, $empId, 'edit', $old['qty'], $newQty, 'تعديل');
        }
        echo json_encode(['success' => true]);

    // ── حذف قطعة ────────────────────────────────────
    } elseif ($action === 'delete' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id مطلوب']); exit; }
        $db->prepare("DELETE FROM parts WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['error' => 'أمر غير معروف أو صلاحية غير كافية']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
