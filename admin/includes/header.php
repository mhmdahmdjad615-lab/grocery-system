<?php
require_once __DIR__ . '/auth.php';
require_login();
$current_page = basename($_SERVER['PHP_SELF']);

// تنبيه المنتجات منخفضة المخزون (إجمالي كمية دفعاتها بمخزن البيع أقل من أو يساوي الحد الأدنى)
$low_stock_count = $pdo->query("
    SELECT COUNT(*) c FROM (
        SELECT p.id, p.min_quantity, COALESCE(SUM(sb.quantity),0) total_qty
        FROM products p
        JOIN stock_batches sb ON sb.product_id = p.id AND sb.location = 'selling'
        GROUP BY p.id
        HAVING total_qty <= p.min_quantity
    ) t
")->fetch()['c'];

// تنبيه البضاعة الراكدة (لها رصيد بمخزن البيع ولم تُبَع منذ 30 يوماً على الأقل، أو لم تُبَع إطلاقاً)
$dead_stock_count = is_admin() ? $pdo->query("
    SELECT COUNT(*) c FROM (
        SELECT p.id
        FROM products p
        JOIN (SELECT DISTINCT product_id FROM stock_batches WHERE location='selling' AND quantity > 0) sw ON sw.product_id = p.id
        LEFT JOIN (
            SELECT si.product_id, MAX(s.invoice_date) last_date
            FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
            GROUP BY si.product_id
        ) ls ON ls.product_id = p.id
        WHERE ls.last_date IS NULL OR ls.last_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ) t
")->fetch()['c'] : 0;

// تنبيه ديون متأخرة أكثر من 90 يوماً (للمدير فقط)
$overdue_debt_count = is_admin() ? $pdo->query("
    SELECT COUNT(DISTINCT customer_id) c FROM sale_invoices
    WHERE (total - paid) > 0.001 AND invoice_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
")->fetch()['c'] : 0;

// تنبيه طلبات المتجر الإلكتروني الجديدة بانتظار الاعتماد (للمدير فقط)
$pending_store_orders_count = is_admin() ? (int)$pdo->query("SELECT COUNT(*) c FROM store_orders WHERE status='pending'")->fetch()['c'] : 0;

// تنبيه طلبات واتساب/تليجرام الجديدة لم تتم متابعتها بعد (للمدير فقط)
$new_manual_requests_count = is_admin() ? (int)$pdo->query("SELECT COUNT(*) c FROM manual_order_requests WHERE status='new'")->fetch()['c'] : 0;

// تنبيه حسابات محلات تجزئة جديدة بانتظار الموافقة (للمدير فقط)
$pending_store_customers_count = is_admin() ? (int)$pdo->query("SELECT COUNT(*) c FROM store_customers WHERE status='pending'")->fetch()['c'] : 0;

// تنبيه دفعات قريبة من الصلاحية أو منتهية (للمدير فقط)
$expiry_alert_count = is_admin() ? $pdo->query("
    SELECT COUNT(*) c FROM stock_batches
    WHERE expiry_date IS NOT NULL AND quantity > 0 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch()['c'] : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نظام حسابات ومخازن - محل مواد غذائية وبقالة جملة</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
    <!-- الشريط الجانبي السياقي: يعرض فقط صفحات القسم الحالي -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span>محل الجملة</span>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-home-link"><i class="fa-solid fa-house"></i> الرئيسية</a>
            <div class="sidebar-divider"></div>
            <?php
            $currentCategoryKey = get_category_for_page($current_page);
            $categories = get_nav_categories();
            if ($currentCategoryKey && isset($categories[$currentCategoryKey])):
                $cat = $categories[$currentCategoryKey];
                $visiblePages = get_visible_pages_in_category($cat);
            ?>
            <div class="sidebar-category-label" style="color:<?= e($cat['color']) ?>">
                <i class="fa-solid <?= e($cat['icon']) ?>"></i> <?= e($cat['label']) ?>
            </div>
            <?php foreach ($visiblePages as $page): [$file, $label, $icon] = $page; ?>
            <a href="<?= e($file) ?>" class="<?= $current_page==$file?'active':'' ?>"><i class="fa-solid <?= e($icon) ?>"></i> <?= e($label) ?></a>
            <?php endforeach; ?>
            <?php endif; ?>
            <div class="sidebar-divider"></div>
            <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج</a>
        </nav>
    </aside>

    <div class="main-content">
        <header class="topbar">
            <button class="btn-toggle-sidebar d-lg-none" id="toggleSidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="ms-auto d-flex align-items-center gap-3">
                <?php if ($low_stock_count > 0): ?>
                <a href="selling_warehouse.php" class="text-decoration-none text-danger">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= $low_stock_count ?> صنف منخفض المخزون
                </a>
                <?php endif; ?>
                <?php if ($dead_stock_count > 0): ?>
                <a href="product_velocity.php?velocity=dead&from=<?= date('Y-m-d', strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>" class="text-decoration-none text-warning">
                    <i class="fa-solid fa-box-archive"></i> <?= $dead_stock_count ?> صنف راكد
                </a>
                <?php endif; ?>
                <?php if ($overdue_debt_count > 0): ?>
                <a href="debt_aging.php" class="text-decoration-none text-danger">
                    <i class="fa-solid fa-hourglass-half"></i> <?= $overdue_debt_count ?> عميل متأخر السداد (90+ يوم)
                </a>
                <?php endif; ?>
                <?php if ($expiry_alert_count > 0): ?>
                <a href="expiry_alerts.php" class="text-decoration-none text-danger">
                    <i class="fa-solid fa-calendar-xmark"></i> <?= $expiry_alert_count ?> دفعة قريبة/منتهية الصلاحية
                </a>
                <?php endif; ?>
                <?php if ($pending_store_orders_count > 0): ?>
                <a href="store_orders.php?status=pending" class="text-decoration-none text-success">
                    <i class="fa-solid fa-bag-shopping"></i> <?= $pending_store_orders_count ?> طلب متجر جديد
                </a>
                <?php endif; ?>
                <?php if ($new_manual_requests_count > 0): ?>
                <a href="manual_requests.php?status=new" class="text-decoration-none text-primary">
                    <i class="fa-solid fa-comments"></i> <?= $new_manual_requests_count ?> طلب واتساب/تليجرام جديد
                </a>
                <?php endif; ?>
                <?php if ($pending_store_customers_count > 0): ?>
                <a href="store_customers.php?status=pending" class="text-decoration-none text-warning">
                    <i class="fa-solid fa-shop"></i> <?= $pending_store_customers_count ?> حساب محل بانتظار الموافقة
                </a>
                <?php endif; ?>
                <span class="text-muted"><i class="fa-solid fa-user"></i> <?= e($_SESSION['full_name'] ?? '') ?></span>
            </div>
        </header>
        <main class="page-content">
            <?php $f = get_flash(); if ($f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show">
                    <?= e($f['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
