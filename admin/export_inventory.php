<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/includes/export.php';

$location = $_GET['location'] ?? 'selling';

$stmt = $pdo->prepare("
    SELECT c.name category_name, p.name product_name, sb.quantity, p.unit, sb.purchase_price, sb.sale_price
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE sb.location = ? AND sb.quantity > 0
    ORDER BY p.name ASC, sb.created_at ASC
");
$stmt->execute([$location]);
$data = $stmt->fetchAll();

$headers = ['الصنف', 'المنتج', 'الكمية', 'الوحدة', 'سعر الشراء', 'قيمة الشراء', 'سعر البيع', 'قيمة البيع'];
$rows = [];
foreach ($data as $r) {
    $rows[] = [
        $r['category_name'] ?? '-',
        $r['product_name'],
        $r['quantity'],
        $r['unit'],
        $r['purchase_price'],
        round($r['quantity'] * $r['purchase_price'], 2),
        $r['sale_price'] ?? '-',
        $r['sale_price'] !== null ? round($r['quantity'] * $r['sale_price'], 2) : '-',
    ];
}

export_to_excel('جرد_المخزون_' . $location . '_' . date('Y-m-d') . '.csv', $headers, $rows);
