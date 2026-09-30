<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int)$_POST['supplier_id'];
    $invoice_date = $_POST['invoice_date'];
    $notes = trim($_POST['notes'] ?? '');
    $paid = (float)($_POST['paid'] ?? 0);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $unit_ids = $_POST['unit_id'] ?? [];
    $expiry_dates = $_POST['expiry_date'] ?? [];

    if ($supplier_id <= 0 || empty($product_ids)) {
        flash('يجب اختيار مورد وإضافة صنف واحد على الأقل', 'danger');
        redirect('purchase_add.php');
    }

    try {
        $pdo->beginTransaction();

        $total = 0;
        $items = [];
        foreach ($product_ids as $i => $pid) {
            $pid = (int)$pid;
            $enteredQty = (float)$quantities[$i];
            $enteredPrice = (float)$prices[$i];
            if ($pid <= 0 || $enteredQty <= 0) continue;

            // تحويل الكمية والسعر المُدخلين بوحدة الشراء المختارة (كرتونة/طن...) إلى
            // الوحدة الأساسية للمنتج، لأن كل الكميات تُخزَّن داخلياً بالوحدة الأساسية فقط
            $unit_id = isset($unit_ids[$i]) ? (int)$unit_ids[$i] : null;
            $unit = $unit_id ? get_unit_by_id($pdo, $pid, $unit_id) : get_base_unit($pdo, $pid);
            $factor = (float)$unit['factor'];

            $qty_base = convert_to_base_qty($enteredQty, $factor);
            $price_base = convert_price_to_base($enteredPrice, $factor);
            $lineTotal = $enteredQty * $enteredPrice; // يساوي qty_base * price_base رياضياً

            $total += $lineTotal;
            $items[] = [
                'product_id' => $pid, 'qty' => $qty_base, 'price' => $price_base, 'total' => $lineTotal,
                'expiry_date' => $expiry_dates[$i] ?? null,
                'unit_name' => $unit['unit_name'], 'display_qty' => $enteredQty,
            ];
        }

        if (empty($items)) {
            $pdo->rollBack();
            flash('لم يتم إدخال أي أصناف صحيحة', 'danger');
            redirect('purchase_add.php');
        }

        $invoice_number = generate_invoice_number($pdo, 'purchase_invoices', 'PUR');

        $stmt = $pdo->prepare("INSERT INTO purchase_invoices (invoice_number, supplier_id, invoice_date, total, paid, notes, user_id) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$invoice_number, $supplier_id, $invoice_date, $total, $paid, $notes, current_user_id()]);
        $invoice_id = $pdo->lastInsertId();

        foreach ($items as $it) {
            $pdo->prepare("INSERT INTO purchase_items (invoice_id, product_id, quantity, price, total, unit_name, display_qty) VALUES (?,?,?,?,?,?,?)")
                ->execute([$invoice_id, $it['product_id'], $it['qty'], $it['price'], $it['total'], $it['unit_name'], $it['display_qty']]);

            // إنشاء دفعة جديدة بالمخزن المؤقت (بالوحدة الأساسية دائماً، بدون سعر بيع بعد)
            create_temp_batch($pdo, $it['product_id'], $it['qty'], $it['price'], $invoice_id, $invoice_date, $it['expiry_date']);
            log_stock_movement($pdo, $it['product_id'], 'in', $it['qty'], 'purchase', $invoice_id, 'فاتورة شراء ' . $invoice_number . ' - دخول للمخزن المؤقت (' . $it['display_qty'] . ' ' . $it['unit_name'] . ')');
        }

        // تحديث رصيد المورد بالمبلغ غير المدفوع
        $remain = $total - $paid;
        if ($remain != 0) {
            $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id = ?")->execute([$remain, $supplier_id]);
        }

        // تسجيل حركة خزينة إذا تم الدفع الفوري
        if ($paid > 0) {
            log_cash_movement($pdo, 'out', $paid, 'purchase', $invoice_id, $invoice_date, 'دفعة على فاتورة شراء ' . $invoice_number);
        }

        $pdo->commit();
        flash('تم حفظ فاتورة الشراء بنجاح رقم ' . $invoice_number);
        redirect('purchase_view.php?id=' . $invoice_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء الحفظ: ' . $e->getMessage(), 'danger');
        redirect('purchase_add.php');
    }
}

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$products = $pdo->query("
    SELECT p.*,
    (SELECT price FROM purchase_items pi JOIN purchase_invoices piv ON piv.id = pi.invoice_id WHERE pi.product_id = p.id ORDER BY piv.invoice_date DESC, pi.id DESC LIMIT 1) last_price,
    (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id = p.id AND location='temp') temp_qty
    FROM products p ORDER BY p.name
")->fetchAll();

// تجهيز وحدات كل منتج (الأساسية + وحدات التعبئة الأكبر) لإرسالها للجافاسكريبت
$unitsMap = [];
foreach ($products as $p) {
    $units = get_product_units($pdo, $p['id']);
    $baseName = $p['unit'];
    $unitsMap[$p['id']] = array_map(function ($u) use ($baseName) {
        return ['id' => $u['id'], 'name' => $u['unit_name'], 'factor' => (float)$u['factor'], 'is_base' => (bool)$u['is_base'], 'base_name' => $baseName];
    }, $units);
}

$productsOptionsHtml = '';
foreach ($products as $p) {
    $productsOptionsHtml .= '<option value="' . $p['id'] . '" data-price="' . ($p['last_price'] ?? 0) . '">' . e($p['name']) . ' (بالمخزن المؤقت حالياً: ' . rtrim(rtrim(number_format($p['temp_qty'],2),'0'),'.') . ' ' . e($p['unit']) . ')</option>';
}
?>
<h4 class="mb-4">فاتورة شراء جديدة</h4>

<form method="post">
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">المورد</label>
                    <select name="supplier_id" class="form-select" required>
                        <option value="">-- اختر مورد --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">تاريخ الفاتورة</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ملاحظات</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>أصناف الفاتورة</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addInvoiceRow('itemsBody', PRODUCT_OPTIONS_HTML)">
                <i class="fa-solid fa-plus"></i> إضافة صنف
            </button>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead><tr><th style="width:28%">المنتج</th><th>الكمية</th><th>الوحدة</th><th>سعر الشراء (لكل وحدة مختارة)</th><th>الإجمالي</th><th>تاريخ الصلاحية (اختياري)</th><th></th></tr></thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">إجمالي الفاتورة</label>
                    <input type="text" id="invoiceTotalDisplay" class="form-control fw-bold" value="0.00" readonly>
                    <input type="hidden" id="invoiceTotal" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">المبلغ المدفوع الآن</label>
                    <input type="number" step="0.01" name="paid" id="paidInput" class="form-control" value="0" oninput="updateRemain()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">المتبقي (آجل على المورد)</label>
                    <input type="text" class="form-control" value="0.00" readonly id="remainDisplayBox">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-floppy-disk"></i> حفظ الفاتورة</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const PRODUCT_OPTIONS_HTML = <?= json_encode($productsOptionsHtml) ?>;
const PRODUCT_UNITS = <?= json_encode($unitsMap, JSON_UNESCAPED_UNICODE) ?>;
const PURCHASE_MODE = true;
// إضافة أول صف تلقائياً عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function () {
    addInvoiceRow('itemsBody', PRODUCT_OPTIONS_HTML);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
