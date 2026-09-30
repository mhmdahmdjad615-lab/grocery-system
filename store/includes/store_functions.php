<?php
// نقطة التحميل المشتركة لكل صفحات المتجر الإلكتروني العامة (لمحلات التجزئة)
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
// نعيد استخدام كل الدوال المحاسبية الجاهزة (money, e, تحويل الوحدات، إعدادات المتجر...)
// بدل تكرارها، حتى يبقى منطق الحسابات والمخزون في مكان واحد فقط
require_once __DIR__ . '/../../admin/includes/functions.php';

// التأكد من وجود كل الجداول (بما فيها جداول المتجر) دون تكرار منطق الترحيل
ensure_schema($pdo);

// ============================================================
// [ترحيل خاص بالمتجر فقط] إضافة "تصنيف فرعي" لجدول categories
// ------------------------------------------------------------
// [جدول مُعدَّل]: categories
// [العمود الجديد]: parent_id INT NULL — يشير إلى صف آخر بنفس الجدول (تصنيف أب)
//   NULL = تصنيف رئيسي، وأي قيمة = تصنيف فرعي تابع لذلك التصنيف الرئيسي.
// لا يؤثر هذا على أي صفحة أو تقرير حالي بلوحة التحكم (كل الاستعلامات القديمة
// تستخدم *SELECT أو أعمدة محددة أخرى ولا تتأثر بعمود إضافي). تمت إضافته هنا
// (ملفات المتجر) تحديداً وليس داخل admin/includes/functions.php حفاظاً على
// عدم لمس أي ملف بلوحة التحكم كما طُلب.
// ============================================================
function ensure_store_category_hierarchy($pdo) {
    $col = $pdo->query("SHOW COLUMNS FROM categories LIKE 'parent_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL AFTER name");
        $pdo->exec("ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL");
    }
}
ensure_store_category_hierarchy($pdo);

define('STORE_BASE_URL', '');

// ============================================================
// جلسة محل التجزئة (منفصلة تماماً عن جلسة مستخدمي لوحة تحكم الحسابات)
// ============================================================
function store_current_id() {
    return $_SESSION['store_customer_id'] ?? null;
}

function store_logged_in() {
    return !empty($_SESSION['store_customer_id']);
}

function store_require_login() {
    if (!store_logged_in()) {
        $_SESSION['store_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php');
        exit;
    }
}

function store_current_customer($pdo) {
    if (!store_logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $pdo->prepare("SELECT * FROM store_customers WHERE id = ?");
    $stmt->execute([store_current_id()]);
    $cached = $stmt->fetch();
    return $cached;
}

// ============================================================
// سلة الشراء (تُخزَّن بالجلسة: [product_id => ['qty'=>, 'unit_id'=>]])
// ============================================================
function cart_get() {
    if (!isset($_SESSION['store_cart']) || !is_array($_SESSION['store_cart'])) {
        $_SESSION['store_cart'] = [];
    }
    return $_SESSION['store_cart'];
}

function cart_add($product_id, $unit_id, $qty) {
    $cart = cart_get();
    $key = $product_id . '_' . $unit_id;
    if (isset($cart[$key])) {
        $cart[$key]['qty'] += $qty;
    } else {
        $cart[$key] = ['product_id' => $product_id, 'unit_id' => $unit_id, 'qty' => $qty];
    }
    $_SESSION['store_cart'] = $cart;
}

function cart_update_qty($key, $qty) {
    $cart = cart_get();
    if (isset($cart[$key])) {
        if ($qty <= 0) { unset($cart[$key]); }
        else { $cart[$key]['qty'] = $qty; }
    }
    $_SESSION['store_cart'] = $cart;
}

function cart_remove($key) {
    $cart = cart_get();
    unset($cart[$key]);
    $_SESSION['store_cart'] = $cart;
}

function cart_clear() {
    $_SESSION['store_cart'] = [];
}

function cart_count() {
    $c = 0;
    foreach (cart_get() as $it) { $c += 1; }
    return $c;
}

// يعيد تفاصيل كل سطر بالسلة جاهزة للعرض (اسم المنتج، الوحدة، السعر، الإجمالي) بناءً على بيانات حية من القاعدة
function cart_details($pdo) {
    $cart = cart_get();
    $details = [];
    $total = 0;
    foreach ($cart as $key => $it) {
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND show_in_store = 1");
        $pStmt->execute([$it['product_id']]);
        $product = $pStmt->fetch();
        if (!$product) continue;

        $unit = $it['unit_id'] ? get_unit_by_id($pdo, $it['product_id'], $it['unit_id']) : get_base_unit($pdo, $it['product_id']);
        $factor = (float)$unit['factor'];
        $basePrice = store_display_price($pdo, $product);
        $unitPrice = get_unit_price($basePrice, $unit);
        $lineTotal = $unitPrice * $it['qty'];
        $total += $lineTotal;

        $details[$key] = [
            'key' => $key,
            'product' => $product,
            'unit' => $unit,
            'qty' => $it['qty'],
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }
    return ['items' => $details, 'total' => $total];
}

// رابط فتح واتساب مع نص جاهز
function store_whatsapp_link($pdo, $text) {
    $number = preg_replace('/[^0-9]/', '', get_store_setting($pdo, 'whatsapp_number', ''));
    if (!$number) return null;
    return 'https://wa.me/' . $number . '?text=' . urlencode($text);
}

function store_telegram_link($pdo) {
    $username = trim(get_store_setting($pdo, 'telegram_username', ''), '@ ');
    if (!$username) return null;
    return 'https://t.me/' . $username;
}

// السعر الفعلي المستخدم للعرض والطلب: سعر المتجر المحدد يدوياً إن وُجد،
// وإلا آخر سعر بيع فعلي مسجَّل بمخزن البيع كاحتياطي (بدل إظهار "اتصل للسعر" بلا داعٍ)
function store_display_price($pdo, $product) {
    if (!empty($product['store_price']) && (float)$product['store_price'] > 0) {
        return (float)$product['store_price'];
    }
    $fallback = get_latest_selling_price($pdo, $product['id']);
    return $fallback !== null ? $fallback : 0.0;
}

// ============================================================
// شجرة التصنيفات (رئيسي/فرعي) لاستخدامها في القوائم المنسدلة المتتابعة
// ============================================================
function get_store_category_tree($pdo) {
    return $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY name")->fetchAll();
}
