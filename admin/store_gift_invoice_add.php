<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_customer_id = (int)($_POST['store_customer_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_ids = $_POST['unit_id'] ?? [];

    if ($store_customer_id <= 0 || empty($product_ids)) {
        flash('يجب اختيار تاجر وإضافة صنف واحد على الأقل', 'danger');
        redirect('store_gift_invoice_add.php');
    }

    $items = [];
    foreach ($product_ids as $i => $pid) {
        $pid = (int)$pid;
        $qty = (float)($quantities[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $items[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_id' => (int)($unit_ids[$i] ?? 0)];
    }

    if (empty($items)) {
        flash('لم يتم إدخال أي أصناف صحيحة', 'danger');
        redirect('store_gift_invoice_add.php');
    }

    try {
        $pdo->beginTransaction();
        $invoice_id = create_gift_store_order($pdo, $store_customer_id, $items, $notes);
        $pdo->commit();
        flash('تم إصدار فاتورة الهدية المجانية بنجاح، وتم تسجيلها بلوحة تحكم التاجر وبرنامج الحسابات');
        redirect('sale_view.php?id=' . $invoice_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
        redirect('store_gift_invoice_add.php');
    }
}

$merchants = $pdo->query("SELECT id, shop_name, owner_name, city FROM store_customers WHERE status = 'approved' ORDER BY shop_name")->fetchAll();

// المنتجات المتاحة فعلياً بمخزن البيع فقط (بنفس منطق sale_add.php)
$products = $pdo->query("
    SELECT p.*,
        (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id = p.id AND location='selling') total_qty
    FROM products p
    HAVING total_qty > 0
    ORDER BY p.name
")->fetchAll();

$unitsMap = [];
foreach ($products as $p) {
    $units = get_product_units($pdo, $p['id']);
    $baseName = $p['unit'];
    $unitsMap[$p['id']] = array_map(function ($u) use ($baseName) {
        return ['id' => $u['id'], 'name' => $u['unit_name'], 'factor' => (float)$u['factor'], 'is_base' => (bool)$u['is_base'], 'base_name' => $baseName];
    }, $units);
}

$gift_header_text = get_store_setting($pdo, 'gift_header_text', 'هدية مجانية مقدمة من');
$site_name = get_store_setting($pdo, 'site_name', '');

// سعر العرض التقديري لكل منتج (نفس منطق store_display_price بالمتجر: سعر المتجر المحدد، وإلا آخر سعر بيع فعلي)
$productsOptionsHtml = '';
foreach ($products as $p) {
    $displayPrice = (!empty($p['store_price']) && (float)$p['store_price'] > 0) ? (float)$p['store_price'] : (get_latest_selling_price($pdo, $p['id']) ?: 0);
    $productsOptionsHtml .= '<option value="' . $p['id'] . '" data-price="' . $displayPrice . '">' . e($p['name']) . ' (متاح: ' . rtrim(rtrim(number_format($p['total_qty'],2),'0'),'.') . ' ' . e($p['unit']) . ')</option>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fa-solid fa-gift text-warning"></i> إصدار فاتورة هدية مجانية لتاجر</h4>
    <a href="store_settings.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
</div>

<div class="alert alert-warning">
    <i class="fa-solid fa-circle-info"></i>
    ستُصدَر فاتورة مستقلة بخصم 100% (لا تُحسب كدَين على التاجر إطلاقاً)، ويظهر أعلاها
    "<?= e($gift_header_text) ?> <?= e($site_name) ?> لـ [اسم التاجر] بتاريخ [التاريخ]"، وتُختم بختم
    "<?= e(get_store_setting($pdo, 'gift_stamp_text', 'هدية مجانية')) ?>" عند الطباعة. تُسحب الكمية من
    مخزن البيع فعلياً وتُسجَّل بلوحة تحكم التاجر وبرنامج الحسابات ضمن إجمالي استفادته وهداياه.
</div>

<form method="post">
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">التاجر المستفيد</label>
                    <select name="store_customer_id" class="form-select" required>
                        <option value="">-- اختر تاجر --</option>
                        <?php foreach ($merchants as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['shop_name']) ?> - <?= e($m['owner_name']) ?> (<?= e($m['city'] ?: 'بدون مدينة') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ملاحظات (اختياري)</label>
                    <input type="text" name="notes" class="form-control" placeholder="مثال: هدية بمناسبة تجاوز حجم مشتريات شهري">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>أصناف الهدية</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addInvoiceRow('itemsBody', PRODUCT_OPTIONS_HTML)">
                <i class="fa-solid fa-plus"></i> إضافة صنف
            </button>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead><tr><th style="width:28%">المنتج</th><th>الكمية</th><th>الوحدة</th><th>السعر (تقديري - لن يُحصَّل)</th><th>الإجمالي</th><th></th></tr></thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </div>

    <button type="submit" class="btn btn-warning"><i class="fa-solid fa-gift"></i> إصدار فاتورة الهدية</button>
</form>

<script>
const PRODUCT_OPTIONS_HTML = <?= json_encode($productsOptionsHtml) ?>;
const PRODUCT_UNITS = <?= json_encode($unitsMap, JSON_UNESCAPED_UNICODE) ?>;
const SALE_MODE = false;
document.addEventListener('DOMContentLoaded', function () {
    addInvoiceRow('itemsBody', PRODUCT_OPTIONS_HTML);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
