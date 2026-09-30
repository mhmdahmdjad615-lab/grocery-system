<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$stats = get_dashboard_stats($pdo);

// الموظف لا يرى أي بيانات مالية حساسة، فقط ما يخص عمل البيع اليومي
if (!is_admin()) {
    $stats = [
        'sales_today' => $stats['sales_today'],
        'sales_today_count' => $stats['sales_today_count'],
        'low_stock' => $stats['low_stock'],
        'customers_count' => $stats['customers_count'],
        'recent_sales' => $stats['recent_sales'],
    ];
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE);
