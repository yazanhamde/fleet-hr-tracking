<?php
// ============================================================
// api/notif_api.php — API الإشعارات (polling)
// يُستدعى كل 30 ثانية من الـ topbar لتحديث badge العداد
// ============================================================
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/hr_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$db    = getDB();
$empId = (int)$_SESSION['employee_id'];

$action = $_GET['action'] ?? 'count';

if ($action === 'count') {
    echo json_encode([
        'notif_unread' => unreadNotifCount($db, $empId),
        'msg_unread'   => unreadMsgCount($db, $empId),
    ]);

} elseif ($action === 'list') {
    $limit = min((int)($_GET['limit'] ?? 10), 30);
    $s = $db->prepare("SELECT id,type,title,body,is_read,created_at FROM notifications WHERE employee_id=? ORDER BY created_at DESC LIMIT ?");
    $s->execute([$empId, $limit]);
    echo json_encode(['items' => $s->fetchAll()]);

} elseif ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND employee_id=?")->execute([$id,$empId]);
    } else {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE employee_id=?")->execute([$empId]);
    }
    echo json_encode(['ok' => true]);

} elseif ($action === 'register_fcm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // حفظ FCM token للـ push notifications
    $token = trim($_POST['token'] ?? '');
    if ($token) {
        $db->prepare("UPDATE employees SET fcm_token=? WHERE id=?")->execute([$token,$empId]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'missing token']);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
}
