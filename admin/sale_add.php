<?php
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)$_POST['customer_id'];
    $invoice_date = $_POST['invoice_date'];
    $notes = trim($_POST['notes'] ?? '');
    $paid = (float)($_POST['paid'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $unit_ids = $_POST['unit_id'] ?? [];

    if ($customer_id <= 0 || empty($product_ids)) {
        flash('يجب اختيار عميل وإضافة صنف واحد على الأقل', 'danger');
        redirect('sale_add.php');
    }

    try {
        $pdo->beginTransaction();

        $subtotal = 0;
        $items = [];
        foreach ($product_ids as $i => $pid) {
            $pid = (int)$pid;
            $enteredQty = (float)$quantities[$i];
            $enteredPrice = (float)$prices[$i];
            if ($pid <= 0 || $enteredQty <= 0) continue;

            // تحويل الكمية المُدخلة بوحدة البيع المختارة (كرتونة/طن...) إلى الوحدة
            // الأساسية للمنتج، لأن الخصم من المخزون يتم دائماً بالوحدة الأساسية
            $unit_id = isset($unit_ids[$i]) ? (int)$unit_ids[$i] : null;
            $unit = $unit_id ? get_unit_by_id($pdo, $pid, $unit_id) : get_base_unit($pdo, $pid);
            $factor = (float)$unit['factor'];
            $qty_base = convert_to_base_qty($enteredQty, $factor);
            $price_base = convert_price_to_base($enteredPrice, $factor);

            // سحب الكمية من دفعات مخزن البيع بنظام "الوارد أولاً يُصرف أولاً" (FIFO)
            // وحساب متوسط تكلفة الوحدة الموزون (بالوحدة الأساسية) لهذا البند
            $cost_price_base = consume_selling_stock_fifo($pdo, $pid, $qty_base);

            $lineTotal = $enteredQty * $enteredPrice; // يساوي qty_base * price_base رياضياً
            $subtotal += $lineTotal;
            $items[] = [
                'product_id' => $pid, 'qty' => $qty_base, 'price' => $price_base, 'cost' => $cost_price_base, 'total' => $lineTotal,
                'unit_name' => $unit['unit_name'], 'display_qty' => $enteredQty,
            ];
        }

        if (empty($items)) {
            $pdo->rollBack();
            flash('لم يتم إدخال أي أصناف صحيحة', 'danger');
            redirect('sale_add.php');
        }

        $discount = max(0, min($discount, $subtotal));
        $total = $subtotal - $discount;

        // فحص حد الائتمان: يمنع البيع الآجل إذا تجاوز رصيد العميل المتوقع حده الأقصى
        // المدير يستطيع تجاوز التنبيه عبر خانة التأكيد، أما الموظف فلا يمكنه تجاوزه إطلاقاً
        $remain = $total - $paid;
        $overrideCredit = is_admin() && isset($_POST['override_credit']);
        $creditIssue = check_credit_limit($pdo, $customer_id, $remain);
        if ($creditIssue && !$overrideCredit) {
            throw new Exception(
                'تحذير: هذه الفاتورة ستجعل رصيد "' . $creditIssue['customer_name'] . '" ' .
                money($creditIssue['projected_balance']) . '، وهو يتجاوز حد الائتمان المسموح (' .
                money($creditIssue['credit_limit']) . ') بمقدار ' . money($creditIssue['excess']) . '.' .
                (is_admin() ? ' فعّل خيار "تجاوز حد الائتمان" بالأسفل للمتابعة رغم ذلك.' : ' يرجى مراجعة المدير.')
            );
        }

        $invoice_number = generate_invoice_number($pdo, 'sale_invoices', 'SAL');

        $stmt = $pdo->prepare("INSERT INTO sale_invoices (invoice_number, customer_id, invoice_date, total, discount, paid, notes, user_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$invoice_number, $customer_id, $invoice_date, $total, $discount, $paid, $notes, current_user_id()]);
        $invoice_id = $pdo->lastInsertId();

        foreach ($items as $it) {
            $pdo->prepare("INSERT INTO sale_items (invoice_id, product_id, quantity, price, cost_price, total, unit_name, display_qty) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$invoice_id, $it['product_id'], $it['qty'], $it['price'], $it['cost'], $it['total'], $it['unit_name'], $it['display_qty']]);

            log_stock_movement($pdo, $it['product_id'], 'out', $it['qty'], 'sale', $invoice_id, 'فاتورة بيع ' . $invoice_number . ' - خصم من مخزن البيع (FIFO) - ' . $it['display_qty'] . ' ' . $it['unit_name']);
        }

        // تحديث رصيد العميل بالمبلغ غير المدفوع
        if ($remain != 0) {
            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$remain, $customer_id]);
        }

        // تسجيل حركة خزينة إذا تم التحصيل الفوري
        if ($paid > 0) {
            log_cash_movement($pdo, 'in', $paid, 'sale', $invoice_id, $invoice_date, 'تحصيل فاتورة بيع ' . $invoice_number);
        }

        $pdo->commit();
        flash('تم حفظ فاتورة البيع بنجاح رقم ' . $invoice_number);
        redirect('sale_view.php?id=' . $invoice_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء الحفظ: ' . $e->getMessage(), 'danger');
        redirect('sale_add.php');
    }
}

$customers = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();

// المنتجات المتاحة فعلياً بمخزن البيع فقط (برصيد أكبر من صفر)، مع إجمالي الكمية
// وسعر/تكلفة أقدم دفعة (لأنها ستُستهلك أولاً بنظام FIFO) كقيم مقترحة افتراضية
$products = $pdo->query("
    SELECT p.*,
        (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id = p.id AND location='selling') total_qty,
        (SELECT sale_price FROM stock_batches WHERE product_id = p.id AND location='selling' AND quantity > 0 ORDER BY created_at ASC, id ASC LIMIT 1) fifo_price,
        (SELECT purchase_price FROM stock_batches WHERE product_id = p.id AND location='selling' AND quantity > 0 ORDER BY created_at ASC, id ASC LIMIT 1) fifo_cost
    FROM products p
    HAVING total_qty > 0
    ORDER BY p.name
")->fetchAll();

// تجهيز وحدات كل منتج (كرتونة/طن/قطعة...) لإرسالها للجافاسكريبت
$unitsMap = [];
foreach ($products as $p) {
    $units = get_product_units($pdo, $p['id']);
    $baseName = $p['unit'];
    $unitsMap[$p['id']] = array_map(function ($u) use ($baseName) {
        return ['id' => $u['id'], 'name' => $u['unit_name'], 'factor' => (float)$u['factor'], 'is_base' => (bool)$u['is_base'], 'base_name' => $baseName];
    }, $units);
}

// تجهيز مستويات الأسعار المتعددة المقترحة لكل منتج (سعر الدفعة الحالية + أي أسعار مقترحة إضافية)
$allTiers = $pdo->query("SELECT * FROM product_prices ORDER BY id")->fetchAll();
$tiersByProduct = [];
foreach ($allTiers as $t) {
    $tiersByProduct[$t['product_id']][] = ['name' => $t['price_name'], 'price' => (float)$t['price']];
}
$priceTiersMap = [];
foreach ($products as $p) {
    $list = [['name' => 'سعر الدفعة الحالية (الأقدم)', 'price' => (float)$p['fifo_price']]];
    if (!empty($tiersByProduct[$p['id']])) {
        $list = array_merge($list, $tiersByProduct[$p['id']]);
    }
    $priceTiersMap[$p['id']] = $list;
}

$productsOptionsHtml = '';
foreach ($products as $p) {
    $productsOptionsHtml .= '<option value="' . $p['id'] . '" data-price="' . $p['fifo_price'] . '" data-cost="' . $p['fifo_cost'] . '">' . e($p['name']) . ' (متاح: ' . rtrim(rtrim(number_format($p['total_qty'],2),'0'),'.') . ' ' . e($p['unit']) . ')</option>';
}
?>
<h4 class="mb-4">فاتورة بيع جديدة</h4>

<form method="post">
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">العميل</label>
                    <select name="customer_id" id="customerSelect" class="form-select" required onchange="showCreditInfo()">
                        <option value="">-- اختر عميل --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                data-balance="<?= $c['balance'] ?>"
                                data-limit="<?= $c['credit_limit'] !== null ? $c['credit_limit'] : '' ?>">
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="creditInfo" class="text-muted"></small>
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
                <thead><tr><th style="width:26%">المنتج</th><th>الكمية</th><th>الوحدة</th><th>مستوى السعر</th><th>سعر البيع (لكل وحدة مختارة)</th><th>الإجمالي</th><th></th></tr></thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">إجمالي قبل الخصم</label>
                    <input type="text" id="subtotalDisplay" class="form-control" value="0.00" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">خصم (مبلغ)</label>
                    <input type="number" step="0.01" min="0" name="discount" id="discountInput" class="form-control" value="0" oninput="calcInvoiceTotal()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">الإجمالي بعد الخصم</label>
                    <input type="text" id="invoiceTotalDisplay" class="form-control fw-bold" value="0.00" readonly>
                    <input type="hidden" id="invoiceTotal" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label">المبلغ المحصل الآن</label>
                    <input type="number" step="0.01" name="paid" id="paidInput" class="form-control" value="0" oninput="updateRemain()">
                </div>
                <div class="col-md-2">
                    <label class="form-label">المتبقي (آجل)</label>
                    <input type="text" class="form-control" value="0.00" readonly id="remainDisplayBox">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-floppy-disk"></i> حفظ الفاتورة</button>
                </div>
                <?php if (is_admin()): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="override_credit" value="1" class="form-check-input" id="overrideCredit">
                        <label class="form-check-label text-danger" for="overrideCredit">تجاوز حد الائتمان والمتابعة رغم التحذير (صلاحية مدير)</label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
const PRODUCT_OPTIONS_HTML = <?= json_encode($productsOptionsHtml) ?>;
const PRODUCT_PRICE_TIERS = <?= json_encode($priceTiersMap, JSON_UNESCAPED_UNICODE) ?>;
const PRODUCT_UNITS = <?= json_encode($unitsMap, JSON_UNESCAPED_UNICODE) ?>;
const SALE_MODE = true;
document.addEventListener('DOMContentLoaded', function () {
    addInvoiceRow('itemsBody', PRODUCT_OPTIONS_HTML);
});

function showCreditInfo() {
    const select = document.getElementById('customerSelect');
    const info = document.getElementById('creditInfo');
    const opt = select.selectedOptions[0];
    if (!opt || !opt.value) { info.textContent = ''; return; }
    const balance = parseFloat(opt.dataset.balance) || 0;
    const limit = opt.dataset.limit;
    let text = 'الرصيد الحالي: ' + balance.toFixed(2) + ' <?= CURRENCY ?>';
    if (limit !== '') {
        text += ' | حد الائتمان: ' + parseFloat(limit).toFixed(2) + ' <?= CURRENCY ?>';
    } else {
        text += ' | بدون حد ائتمان';
    }
    info.textContent = text;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
