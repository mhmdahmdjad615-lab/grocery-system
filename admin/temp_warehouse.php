<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// نقل دفعة واحدة (كل الكمية أو جزء منها) إلى مخزن البيع بسعر بيع محدد، بأي وحدة يختارها المستخدم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_single') {
    $batch_id = (int)$_POST['batch_id'];
    $product_id = (int)$_POST['product_id'];
    $unit_id = (int)$_POST['unit_id'];
    $enteredQty = (float)$_POST['qty'];
    $enteredPrice = (float)$_POST['sale_price'];

    if ($enteredQty <= 0 || $enteredPrice <= 0) {
        flash('يجب إدخال كمية وسعر بيع صحيحين', 'danger');
        redirect('temp_warehouse.php');
    }
    try {
        $pdo->beginTransaction();
        $unit = get_unit_by_id($pdo, $product_id, $unit_id);
        $factor = (float)$unit['factor'];
        $qty_base = convert_to_base_qty($enteredQty, $factor);
        $price_base = convert_price_to_base($enteredPrice, $factor);
        move_to_selling($pdo, $batch_id, $qty_base, $price_base);
        $pdo->commit();
        flash('تم ترحيل الكمية إلى مخزن البيع بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
    }
    redirect('temp_warehouse.php');
}

// نقل نسبة مئوية من كل الدفعات دفعة واحدة، بتحديد هامش ربح موحّد فوق سعر شراء كل دفعة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_bulk') {
    $percent = (float)$_POST['bulk_percent'];
    $margin = (float)$_POST['bulk_margin'];

    if ($percent <= 0 || $percent > 100) {
        flash('النسبة المئوية يجب أن تكون بين 1 و100', 'danger');
        redirect('temp_warehouse.php');
    }

    try {
        $pdo->beginTransaction();
        $batches = $pdo->query("SELECT * FROM stock_batches WHERE location = 'temp'")->fetchAll();
        $count = 0;
        foreach ($batches as $b) {
            $qty = round($b['quantity'] * $percent / 100, 2);
            if ($qty <= 0) continue;
            $sale_price = round($b['purchase_price'] * (1 + $margin / 100), 2);
            move_to_selling($pdo, $b['id'], $qty, $sale_price);
            $count++;
        }
        $pdo->commit();
        flash("تم ترحيل {$percent}% من كل الدفعات ({$count} دفعة) بهامش ربح {$margin}% بنجاح (بأسعار الوحدة الأساسية)");
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء الترحيل الجماعي: ' . $e->getMessage(), 'danger');
    }
    redirect('temp_warehouse.php');
}

$batches = $pdo->query("
    SELECT sb.*, p.name product_name, p.unit, p.id product_id, c.name category_name,
           pi.invoice_number,
           COALESCE((SELECT new_price FROM product_price_history WHERE product_id = sb.product_id ORDER BY id DESC LIMIT 1), 0) suggested_price
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN purchase_invoices pi ON pi.id = sb.purchase_invoice_id
    WHERE sb.location = 'temp'
    ORDER BY sb.purchase_date ASC, sb.id ASC
")->fetchAll();

$total_temp_value = array_sum(array_map(fn($b) => $b['quantity'] * $b['purchase_price'], $batches));

// تجهيز وحدات كل منتج ظاهر بالمخزن المؤقت لإرسالها للجافاسكريبت (فقط الوحدات المسموح بيعها)
$unitsMap = [];
foreach ($batches as $b) {
    if (isset($unitsMap[$b['product_id']])) continue;
    $units = get_product_units($pdo, $b['product_id']);
    $unitsMap[$b['product_id']] = array_map(function ($u) {
        return ['id' => $u['id'], 'name' => $u['unit_name'], 'factor' => (float)$u['factor'], 'is_base' => (bool)$u['is_base']];
    }, array_values(array_filter($units, fn($u) => $u['allow_sell'] || $u['is_base'])));
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">المخزن المؤقت (بضاعة مستلمة بانتظار تحديد سعر البيع)</h4>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    هنا تظهر كل الكميات التي تم شراؤها (من فواتير الشراء) ولم يتم بعد تحديد سعر بيع لها.
    اختر الكمية والوحدة المطلوب الترحيل بها وسعر البيع لكل وحدة، لتنتقل تلقائياً إلى
    <a href="selling_warehouse.php">مخزن البيع</a>. الكمية المتبقية بدون ترحيل تبقى هنا بسعر الشراء فقط.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#fd7e14"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div><div class="value"><?= count($batches) ?></div><div class="label">دفعات بانتظار تحديد السعر</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($total_temp_value) ?></div><div class="label">قيمة المخزن المؤقت (سعر الشراء)</div></div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-warning-subtle">ترحيل جزء/كل الدفعات دفعة واحدة (بهامش ربح موحّد، بالوحدة الأساسية)</div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="move_bulk">
            <div class="col-md-3">
                <label class="form-label">النسبة المئوية من كل دفعة</label>
                <div class="input-group">
                    <input type="number" name="bulk_percent" class="form-control" value="100" min="1" max="100" required>
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">هامش الربح فوق سعر الشراء</label>
                <div class="input-group">
                    <input type="number" name="bulk_margin" class="form-control" value="20" step="0.01" required>
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="col-md-4">
                <small class="text-muted">مثال: هامش 20% على دفعة سعر شرائها 10 → سعر البيع سيكون 12</small>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-warning w-100" onclick="return confirm('سيتم ترحيل هذه النسبة من كل دفعات المخزن المؤقت الحالية، هل أنت متأكد؟')">تنفيذ للجميع</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">الدفعات الحالية بالمخزن المؤقت (الأقدم أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>تاريخ الشراء</th><th>رقم الفاتورة</th>
                <th>الكمية المتبقية</th><th>سعر الشراء (أساسية)</th><th>ترحيل إلى مخزن البيع</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><?= e($b['category_name'] ?? '-') ?></td>
                    <td><?= e($b['product_name']) ?> <a href="product_prices.php?product_id=<?= $b['product_id'] ?>" class="text-muted" title="أسعار مقترحة وسجل الأسعار"><i class="fa-solid fa-tags"></i></a></td>
                    <td><?= e($b['purchase_date']) ?></td>
                    <td><?= $b['invoice_number'] ? '<a href="purchase_view.php?id='.$b['purchase_invoice_id'].'">'.e($b['invoice_number']).'</a>' : '-' ?></td>
                    <td>
                        <strong><?= rtrim(rtrim(number_format($b['quantity'],2),'0'),'.') ?></strong> <?= e($b['unit']) ?>
                        <br><small class="text-muted"><?= format_qty_composite($pdo, $b['product_id'], $b['quantity']) ?></small>
                    </td>
                    <td><?= money($b['purchase_price']) ?></td>
                    <td style="min-width:420px">
                        <form method="post" class="d-flex gap-1 align-items-center flex-wrap move-form" data-product-id="<?= $b['product_id'] ?>" data-base-price="<?= $b['purchase_price'] ?>">
                            <input type="hidden" name="action" value="move_single">
                            <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= $b['product_id'] ?>">
                            <input type="number" name="qty" class="form-control form-control-sm qty-field" style="width:80px" step="0.01" min="0.01" required>
                            <select name="unit_id" class="form-select form-select-sm unit-field" style="width:100px" onchange="onMoveUnitChange(this)"></select>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="setPercentUnit(this, 25)">25%</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPercentUnit(this, 50)">50%</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPercentUnit(this, 100)">الكل</button>
                            </div>
                            <input type="number" name="sale_price" class="form-control form-control-sm price-field" style="width:100px" step="0.01" min="0.01" placeholder="سعر البيع/وحدة" value="<?= $b['suggested_price'] > 0 ? $b['suggested_price'] : '' ?>" required>
                            <input type="hidden" name="max_base_qty" value="<?= $b['quantity'] ?>">
                            <button type="submit" class="btn btn-sm btn-success">ترحيل</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($batches)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد دفعات بالمخزن المؤقت حالياً</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const TEMP_PRODUCT_UNITS = <?= json_encode($unitsMap, JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.move-form').forEach(function (form) {
    const productId = form.dataset.productId;
    const units = TEMP_PRODUCT_UNITS[productId] || [];
    const unitSelect = form.querySelector('.unit-field');
    units.forEach(function (u) {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.dataset.factor = u.factor;
        opt.textContent = u.name;
        if (u.is_base) opt.selected = true;
        unitSelect.appendChild(opt);
    });
    // تعبئة الكمية الافتراضية بالوحدة الأساسية = كل الكمية المتاحة
    const maxBaseQty = parseFloat(form.querySelector('input[name="max_base_qty"]').value);
    form.querySelector('.qty-field').value = maxBaseQty.toFixed(2);
    form.querySelector('.qty-field').setAttribute('data-max-base', maxBaseQty);
});

function onMoveUnitChange(select) {
    const form = select.closest('form');
    const factor = parseFloat(select.selectedOptions[0]?.dataset.factor) || 1;
    const maxBase = parseFloat(form.querySelector('.qty-field').dataset.maxBase);
    form.querySelector('.qty-field').value = (maxBase / factor).toFixed(4);
    form.querySelector('.qty-field').setAttribute('max', (maxBase / factor).toFixed(4));

    const basePrice = parseFloat(form.dataset.basePrice) || 0;
    // اقتراح سعر بيع تلقائي بهامش افتراضي 20% فوق سعر الشراء، معدَّلاً بمعامل الوحدة
    const suggested = basePrice * factor * 1.2;
    form.querySelector('.price-field').value = suggested.toFixed(2);
}

function setPercentUnit(btn, pct) {
    const form = btn.closest('form');
    const factor = parseFloat(form.querySelector('.unit-field').selectedOptions[0]?.dataset.factor) || 1;
    const maxBase = parseFloat(form.querySelector('.qty-field').dataset.maxBase);
    form.querySelector('.qty-field').value = ((maxBase / factor) * pct / 100).toFixed(4);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
