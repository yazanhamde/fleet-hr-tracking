<?php
// ===================================================
// finance_api.php — الداشبورد المالي (المرحلة الرابعة)
// ===================================================
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = $_GET['action'] ?? 'summary';

try {
    // ── ملخص مالي عام ───────────────────────────────
    if ($action === 'summary') {
        // إجمالي قيمة المخزون (تكلفة شراء)
        $inv = $db->query("
            SELECT
              COUNT(*)                                           AS total_parts,
              SUM(qty)                                           AS total_units,
              SUM(qty * COALESCE(cost_price, 0))                AS inventory_cost,
              SUM(qty * COALESCE(sell_price, 0))                AS inventory_sell,
              SUM(qty * (COALESCE(sell_price,0) - COALESCE(cost_price,0))) AS potential_profit
            FROM parts
        ")->fetch();

        // مبيعات هذا الشهر
        $salesMonth = $db->query("
            SELECT
              COUNT(*)                                                       AS orders,
              SUM(ps.qty)                                                    AS units_sold,
              SUM(ps.qty * COALESCE(ps.sell_price, p.sell_price, 0))        AS revenue,
              SUM(ps.qty * COALESCE(ps.cost_price, p.cost_price, 0))        AS cost,
              SUM(ps.qty * (COALESCE(ps.sell_price, p.sell_price, 0)
                           - COALESCE(ps.cost_price, p.cost_price, 0)))     AS profit
            FROM parts_sales ps
            JOIN parts p ON p.id = ps.part_id
            WHERE ps.sold_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
        ")->fetch();

        // مبيعات الشهر الماضي (للمقارنة)
        $salesLastMonth = $db->query("
            SELECT
              SUM(ps.qty * COALESCE(ps.sell_price, p.sell_price, 0))    AS revenue,
              SUM(ps.qty * (COALESCE(ps.sell_price, p.sell_price, 0)
                           - COALESCE(ps.cost_price, p.cost_price, 0))) AS profit
            FROM parts_sales ps
            JOIN parts p ON p.id = ps.part_id
            WHERE ps.sold_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH,'%Y-%m-01')
              AND ps.sold_at <  DATE_FORMAT(NOW(),'%Y-%m-01')
        ")->fetch();

        // قطع نفدت أو قاربت النفاد
        $lowStock = $db->query("
            SELECT COUNT(*) FROM parts WHERE qty <= min_qty AND qty > 0
        ")->fetchColumn();
        $outOfStock = $db->query("
            SELECT COUNT(*) FROM parts WHERE qty = 0
        ")->fetchColumn();

        echo json_encode([
            'inventory'      => $inv,
            'sales_month'    => $salesMonth,
            'sales_last_month' => $salesLastMonth,
            'low_stock'      => (int)$lowStock,
            'out_of_stock'   => (int)$outOfStock,
        ]);

    // ── أعلى القطع مبيعاً (هذا الشهر) ─────────────
    } elseif ($action === 'top_parts') {
        $limit = (int)($_GET['limit'] ?? 10);
        $days  = (int)($_GET['days'] ?? 30);
        $rows = $db->prepare("
            SELECT p.id, p.name, p.part_code,
                   SUM(ps.qty) AS units_sold,
                   SUM(ps.qty * COALESCE(ps.sell_price, p.sell_price, 0)) AS revenue,
                   SUM(ps.qty * (COALESCE(ps.sell_price, p.sell_price, 0)
                                - COALESCE(ps.cost_price, p.cost_price, 0))) AS profit
            FROM parts_sales ps
            JOIN parts p ON p.id = ps.part_id
            WHERE ps.sold_at >= NOW() - INTERVAL ? DAY
            GROUP BY p.id
            ORDER BY units_sold DESC
            LIMIT ?
        ");
        $rows->execute([$days, $limit]);
        echo json_encode($rows->fetchAll());

    // ── مبيعات يومية (آخر N يوم) ────────────────────
    } elseif ($action === 'daily_sales') {
        $days = min(90, (int)($_GET['days'] ?? 30));
        $rows = $db->prepare("
            SELECT
              DATE(ps.sold_at) AS day,
              SUM(ps.qty * COALESCE(ps.sell_price, p.sell_price, 0))    AS revenue,
              SUM(ps.qty * (COALESCE(ps.sell_price, p.sell_price, 0)
                           - COALESCE(ps.cost_price, p.cost_price, 0))) AS profit,
              COUNT(*)                                                    AS orders
            FROM parts_sales ps
            JOIN parts p ON p.id = ps.part_id
            WHERE ps.sold_at >= NOW() - INTERVAL ? DAY
            GROUP BY DATE(ps.sold_at)
            ORDER BY day ASC
        ");
        $rows->execute([$days]);
        echo json_encode($rows->fetchAll());

    // ── قطع نفاد المخزون ─────────────────────────────
    } elseif ($action === 'low_stock') {
        $rows = $db->query("
            SELECT p.*, s.label AS section_label,
                   sup.name AS supplier_name, sup.phone AS supplier_phone,
                   CASE
                     WHEN p.qty = 0            THEN 'out'
                     WHEN p.qty <= p.min_qty   THEN 'low'
                     ELSE 'ok'
                   END AS stock_status
            FROM parts p
            LEFT JOIN sections s   ON s.id   = p.section_id
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            WHERE p.qty <= p.min_qty
            ORDER BY p.qty ASC, p.name ASC
        ")->fetchAll();
        echo json_encode($rows);

    // ── هامش ربح لكل قطعة ────────────────────────────
    } elseif ($action === 'margins') {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $filter = $_GET['filter'] ?? 'all'; // all | no_price | low_margin

        $where = '';
        if ($filter === 'no_price')    $where = "WHERE cost_price IS NULL OR sell_price IS NULL";
        if ($filter === 'low_margin')  $where = "WHERE cost_price > 0 AND sell_price > 0
                                                   AND ((sell_price - cost_price) / cost_price * 100) < 20";

        $total = $db->query("SELECT COUNT(*) FROM parts $where")->fetchColumn();
        $rows  = $db->prepare("
            SELECT p.*, s.label AS section_label,
                   sup.name AS supplier_name,
                   CASE WHEN p.cost_price > 0 AND p.sell_price > 0
                        THEN ROUND((p.sell_price - p.cost_price) / p.cost_price * 100, 1)
                        ELSE NULL END AS margin_pct,
                   (p.sell_price - p.cost_price) AS margin_abs
            FROM parts p
            LEFT JOIN sections s    ON s.id   = p.section_id
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            $where
            ORDER BY margin_pct ASC
            LIMIT $limit OFFSET $offset
        ");
        $rows->execute();
        echo json_encode(['rows' => $rows->fetchAll(), 'total' => (int)$total, 'pages' => ceil($total / $limit)]);

    // ── مبيعات حسب مورد ─────────────────────────────
    } elseif ($action === 'by_supplier') {
        $rows = $db->query("
            SELECT sup.id, sup.name AS supplier_name,
                   COUNT(DISTINCT p.id)                                           AS parts_count,
                   SUM(ps.qty * COALESCE(ps.sell_price, p.sell_price, 0))        AS revenue,
                   SUM(ps.qty * COALESCE(ps.cost_price, p.cost_price, 0))        AS cost,
                   SUM(ps.qty * (COALESCE(ps.sell_price, p.sell_price, 0)
                                - COALESCE(ps.cost_price, p.cost_price, 0)))     AS profit
            FROM suppliers sup
            JOIN parts p         ON p.supplier_id   = sup.id
            JOIN parts_sales ps  ON ps.part_id      = p.id
            WHERE ps.sold_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
            GROUP BY sup.id
            ORDER BY revenue DESC
        ")->fetchAll();
        echo json_encode($rows);

    } else {
        echo json_encode(['error' => 'أمر غير معروف']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
