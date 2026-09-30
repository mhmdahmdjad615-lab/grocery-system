<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/includes/export.php';

$merchants = $pdo->query("SELECT id, shop_name, owner_name, phone, city FROM store_customers WHERE status = 'approved' ORDER BY shop_name")->fetchAll();

$headers = ['التاجر', 'المسؤول', 'الهاتف', 'المدينة', 'الشارة', 'خصومات الفواتير', 'عدد الهدايا', 'قيمة الهدايا', 'إجمالي الاستفادة', 'سقف الائتمان', 'نسبة الائتمان %', 'المطلوب سداده', 'مستحق له'];
$rows = [];
foreach ($merchants as $m) {
    $savings = get_merchant_savings_info($pdo, $m['id']);
    $credit = get_merchant_credit_info($pdo, $m['id']);
    $badge = get_merchant_badge($pdo, $savings['total_benefit']);
    $rows[] = [
        $m['shop_name'],
        $m['owner_name'],
        $m['phone'],
        $m['city'] ?: '-',
        $badge['label'],
        round($savings['total_discounts'], 2),
        $savings['gift_count'],
        round($savings['gift_value'], 2),
        round($savings['total_benefit'], 2),
        round($credit['credit_limit'], 2),
        round($credit['percentage'], 2),
        round($credit['due_from_merchant'], 2),
        round($credit['due_to_merchant'], 2),
    ];
}
// الأعلى استفادة أولاً
usort($rows, fn($a, $b) => $b[8] <=> $a[8]);

export_to_excel('تقرير_استفادة_التجار_' . date('Y-m-d') . '.csv', $headers, $rows);
