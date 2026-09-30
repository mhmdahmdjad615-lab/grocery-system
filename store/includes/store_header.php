<?php
require_once __DIR__ . '/store_functions.php';
$storeCustomer = store_logged_in() ? store_current_customer($pdo) : null;
$siteName = get_store_setting($pdo, 'site_name', 'منصة البيع بالجملة');
$cartCount = cart_count();
$currentPage = basename($_SERVER['PHP_SELF']);

// أصناف لها منتجات ظاهرة بالمتجر (لصف التنقل أسفل الهيدر)
$navCategories = $pdo->query("
    SELECT c.id, c.name FROM categories c
    WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.show_in_store = 1)
    ORDER BY c.name LIMIT 10
")->fetchAll();

$minOrderForBar = (float)get_store_setting($pdo, 'min_order_amount', 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/store.css">
<!-- [جديد] أنماط شريط إحصائيات التجار المتحرك + شارات/ختم الهدية المجانية -->
<link rel="stylesheet" href="assets/css/store-extras.css">
</head>
<body>

<div class="announce-bar">
    <i class="fa-solid fa-truck-fast"></i>
    شحن مجاني للطلبات فوق <strong><?= money(max($minOrderForBar, 5000)) ?></strong> — توصيل خلال 24 ساعة لطلبات القاهرة الكبرى
</div>

<header class="gstore-header">
    <div class="container">
        <a href="index.php" class="gstore-brand">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span><?= e($siteName) ?></span>
        </a>

        <form class="gstore-search" method="get" action="index.php">
            <input type="text" name="search" placeholder="ابحث عن منتج، صنف، أو ماركة..." value="<?= e($_GET['search'] ?? '') ?>">
            <span class="kbd">⌘K</span>
            <button type="submit" style="display:none"></button>
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
        </form>

        <div class="gstore-actions">
            <a href="manual_order.php" class="gstore-icon-btn" title="اطلب عبر واتساب/تليجرام">
                <i class="fa-brands fa-whatsapp"></i>
            </a>
            <a href="<?= $storeCustomer ? 'dashboard.php' : 'login.php' ?>" class="gstore-icon-btn" title="لوحة التحكم">
                <i class="fa-solid fa-gauge"></i>
            </a>
            <a href="cart.php" class="gstore-icon-btn" title="السلة">
                <i class="fa-solid fa-cart-shopping"></i>
                <?php if ($cartCount > 0): ?><span class="gstore-badge"><?= $cartCount ?></span><?php endif; ?>
            </a>
            <?php if ($storeCustomer): ?>
            <div class="dropdown">
                <a href="#" class="gstore-account" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-shop"></i> <?= e($storeCustomer['shop_name']) ?>
                    <i class="fa-solid fa-chevron-down text-xs"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="dashboard.php"><i class="fa-solid fa-gauge"></i> لوحة التحكم</a></li>
                    <li><a class="dropdown-item" href="quick_order.php"><i class="fa-solid fa-bolt"></i> طلب سريع</a></li>
                    <li><a class="dropdown-item" href="account.php"><i class="fa-solid fa-id-card"></i> بيانات الحساب</a></li>
                    <li><a class="dropdown-item" href="credit_statement.php"><i class="fa-solid fa-file-invoice-dollar"></i> كشف حساب الائتمان</a></li>
                    <li><a class="dropdown-item" href="my_orders.php"><i class="fa-solid fa-box"></i> طلباتي</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج</a></li>
                </ul>
            </div>
            <?php else: ?>
            <a href="login.php" class="gstore-icon-btn d-none d-sm-flex" title="تسجيل الدخول"><i class="fa-solid fa-right-to-bracket"></i></a>
            <a href="register.php" class="btn-store-cta">سجّل محلك</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($navCategories)): ?>
    <nav class="gstore-navrow">
        <div class="container">
            <a href="index.php" class="<?= $currentPage==='index.php' && empty($_GET['category_id']) ?'active':'' ?>">كل المنتجات</a>
            <?php foreach ($navCategories as $c): ?>
                <a href="index.php?category_id=<?= $c['id'] ?>" class="<?= (int)($_GET['category_id'] ?? 0)===(int)$c['id']?'active':'' ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
            <?php if ($storeCustomer): ?>
                <a href="quick_order.php" class="<?= $currentPage==='quick_order.php'?'active':'' ?>"><i class="fa-solid fa-bolt"></i> طلب سريع</a>
            <?php endif; ?>
            <a href="manual_order.php"><i class="fa-brands fa-whatsapp"></i> اطلب عبر واتساب</a>
        </div>
    </nav>
    <?php endif; ?>
</header>

<?php
$flashMsg = get_flash();
if ($flashMsg): ?>
<div class="container mt-3">
    <div class="alert alert-<?= e($flashMsg['type']) ?> alert-dismissible fade show" style="border-radius:12px;border:none;">
        <?= e($flashMsg['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>
<main class="store-main">
