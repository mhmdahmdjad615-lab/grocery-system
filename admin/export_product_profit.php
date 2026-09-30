<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/includes/export.php';

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// نفس المعادلة الدقيقة المستخدمة بصفحة product_profit.php (صافي مبيعات - صافي تكلفة فعلية، بعد خصم المرتجعات)
$stmt = $pdo->prepare("
    SELECT p.name, c.name category_name,
           COALESCE(sales.qty,0) qty_sold,
           COALESCE(ret.qty,0) qty_returned,
           COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0) net_revenue,
           COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0) net_cost,
           (COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0)) - (COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0)) profit
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty, SUM(si.total) revenue, SUM(si.quantity*si.cost_price) cost
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale'
        GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT sri.product_id, SUM(sri.quantity) qty, SUM(sri.total) revenue,
               SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END) restocked_cost
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ?
        GROUP BY sri.product_id
    ) ret ON ret.product_id = p.id
    HAVING qty_sold > 0
    ORDER BY profit DESC
");
$stmt->execute([$from, $to, $from, $to]);
$data = $stmt->fetchAll();

$headers = ['الصنف', 'المنتج', 'الكمية المباعة', 'الكمية المرتجعة', 'صافي المبيعات', 'صافي التكلفة', 'الربح', 'نسبة الربح %'];
$rows = [];
foreach ($data as $r) {
    $margin = $r['net_cost'] > 0 ? round($r['profit'] / $r['net_cost'] * 100, 1) : '-';
    $rows[] = [
        $r['category_name'] ?? '-',
        $r['name'],
        $r['qty_sold'],
        $r['qty_returned'],
        $r['net_revenue'],
        $r['net_cost'],
        $r['profit'],
        $margin,
    ];
}

export_to_excel('ربح_المنتجات_' . $from . '_الى_' . $to . '.csv', $headers, $rows);
