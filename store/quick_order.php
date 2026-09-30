<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $added = 0;

    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (float)($qtys[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $pStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND show_in_store = 1");
        $pStmt->execute([$pid]);
        if (!$pStmt->fetch()) continue;

        $baseUnit = get_base_unit($pdo, $pid);
        cart_add($pid, $baseUnit['id'], $qty);
        $added++;
    }

    if ($added > 0) {
        flash("تمت إضافة {$added} صنف للسلة بنجاح");
        redirect('cart.php');
    } else {
        flash('يرجى اختيار منتج واحد على الأقل وكمية صحيحة', 'danger');
        redirect('quick_order.php');
    }
}

// شجرة التصنيفات (رئيسي/فرعي) — تُستخدم لتغذية القوائم المتتابعة في الواجهة
$categoryTree = get_store_category_tree($pdo);
$categoriesJs = array_map(function ($c) {
    return ['id' => (int)$c['id'], 'name' => $c['name'], 'parent_id' => $c['parent_id'] !== null ? (int)$c['parent_id'] : null];
}, $categoryTree);

// كل المنتجات الظاهرة بالمتجر مع السعر والوحدة الأساسية ورصيد المخزون وتصنيفها
$stmt = $pdo->query("
    SELECT p.*,
           (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id=p.id AND location='selling') available_qty
    FROM products p WHERE p.show_in_store = 1 ORDER BY p.name
");
$allProducts = $stmt->fetchAll();
$productsJs = [];
$hasUncategorized = false;
foreach ($allProducts as $p) {
    $price = store_display_price($pdo, $p);
    $catId = $p['category_id'] !== null ? (int)$p['category_id'] : 0;
    if ($catId === 0) $hasUncategorized = true;
    $productsJs[] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'sku' => $p['barcode'] ?: ('PRD-' . str_pad($p['id'],4,'0',STR_PAD_LEFT)),
        'unit' => $p['unit'],
        'price' => (float)$price,
        'stock' => (float)$p['available_qty'],
        'category_id' => $catId,
    ];
}
// تصنيف افتراضي "بدون تصنيف" لضمان ظهور المنتجات غير المصنّفة أيضاً بالقوائم
if ($hasUncategorized) {
    $categoriesJs[] = ['id' => 0, 'name' => 'بدون تصنيف', 'parent_id' => null];
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <h1 class="h1 mb-1"><i class="fa-solid fa-bolt text-gold"></i> طلب سريع</h1>
    <p class="text-muted-store mb-4">اختر التصنيف الرئيسي ثم الفرعي (إن وُجد) ثم المنتج، أدخل الكمية، وأضف عدة أصناف للسلة دفعة واحدة.</p>

    <form method="post" id="qoForm">
        <div id="qoRowsWrap" class="d-flex flex-column gap-3"></div>

        <button type="button" class="btn btn-outline btn-sm mt-3" onclick="qoAddRow()"><i class="fa-solid fa-plus"></i> إضافة سطر آخر</button>

        <div class="gcard mt-3" style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;position:sticky;bottom:12px;box-shadow:var(--sh-xl)">
            <div>
                <span class="text-sm text-muted-store">الإجمالي التقديري: </span>
                <span class="tabular-num fw-bold" id="qoGrandTotal" style="font-size:20px;color:var(--p-700)">0.00 <?= CURRENCY ?></span>
            </div>
            <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-cart-plus"></i> أضف الكل للسلة</button>
        </div>
    </form>
</div>

<script>
const QO_CATEGORIES = <?= json_encode($categoriesJs, JSON_UNESCAPED_UNICODE) ?>;
const QO_PRODUCTS = <?= json_encode($productsJs, JSON_UNESCAPED_UNICODE) ?>;
const QO_MAIN_CATS = QO_CATEGORIES.filter(c => c.parent_id === null);
let qoRowCount = 0;

function qoAddRow() {
    const idx = qoRowCount++;
    const wrap = document.getElementById('qoRowsWrap');
    const row = document.createElement('div');
    row.className = 'gcard qo-row fade-in';
    row.style.padding = '16px';
    row.dataset.idx = idx;
    row.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-6 col-lg-3">
                <label class="gform-label">التصنيف الرئيسي</label>
                <select class="gform-control qo-main" onchange="qoOnMainChange(this)"></select>
            </div>
            <div class="col-6 col-lg-3 qo-sub-wrap" style="display:none">
                <label class="gform-label">التصنيف الفرعي</label>
                <select class="gform-control qo-sub" onchange="qoOnSubChange(this)"></select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="gform-label">المنتج</label>
                <select class="gform-control qo-product" onchange="qoOnProductChange(this)" disabled>
                    <option value="">اختر تصنيفاً أولاً</option>
                </select>
                <input type="hidden" name="product_id[]" class="qo-pid">
            </div>
            <div class="col-6 col-lg-2">
                <label class="gform-label">الكمية</label>
                <input type="number" name="qty[]" class="gform-control qo-qty" step="0.01" min="0.01" value="1" oninput="qoCalcRow(this)" disabled>
            </div>
            <div class="col-12 col-lg-1 d-flex justify-content-lg-center">
                <button type="button" class="btn btn-ghost" onclick="qoRemoveRow(this)" title="حذف السطر"><i class="fa-solid fa-trash text-danger"></i></button>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center text-xs mt-2 pt-2" style="border-top:1px dashed var(--g-200)">
            <span class="text-muted-store qo-stock"></span>
            <span>السعر: <b class="qo-price">—</b> &nbsp;|&nbsp; الإجمالي: <b class="qo-total tabular-num" style="color:var(--p-700)">0.00</b></span>
        </div>
    `;
    wrap.appendChild(row);

    const mainSelect = row.querySelector('.qo-main');
    mainSelect.innerHTML = '<option value="">اختر التصنيف الرئيسي</option>' +
        QO_MAIN_CATS.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
}

function qoOnMainChange(select) {
    const row = select.closest('.qo-row');
    const mainId = select.value === '' ? null : parseInt(select.value);
    const subWrap = row.querySelector('.qo-sub-wrap');
    const subSelect = row.querySelector('.qo-sub');
    const prodSelect = row.querySelector('.qo-product');

    const subCats = mainId === null ? [] : QO_CATEGORIES.filter(c => c.parent_id === mainId);

    if (subCats.length > 0) {
        subWrap.style.display = '';
        subSelect.innerHTML = '<option value="">اختر التصنيف الفرعي</option>' + subCats.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        qoFillProducts(prodSelect, null); // ننتظر اختيار الفرعي أولاً
    } else {
        subWrap.style.display = 'none';
        subSelect.innerHTML = '';
        qoFillProducts(prodSelect, mainId);
    }
    qoResetRowInfo(row);
}

function qoOnSubChange(select) {
    const row = select.closest('.qo-row');
    const subId = select.value === '' ? null : parseInt(select.value);
    const prodSelect = row.querySelector('.qo-product');
    qoFillProducts(prodSelect, subId);
    qoResetRowInfo(row);
}

function qoFillProducts(select, categoryId) {
    if (categoryId === null) {
        select.innerHTML = '<option value="">اختر تصنيفاً أولاً</option>';
        select.disabled = true;
        return;
    }
    const list = QO_PRODUCTS.filter(p => p.category_id === categoryId);
    select.disabled = false;
    select.innerHTML = list.length
        ? '<option value="">اختر المنتج (' + list.length + ')</option>' + list.map(p => `<option value="${p.id}">${p.name}</option>`).join('')
        : '<option value="">لا توجد منتجات بهذا التصنيف</option>';
}

function qoOnProductChange(select) {
    const row = select.closest('.qo-row');
    const pid = select.value;
    const qtyInput = row.querySelector('.qo-qty');
    row.querySelector('.qo-pid').value = pid;

    if (!pid) { qoResetRowInfo(row); return; }

    const product = QO_PRODUCTS.find(p => p.id == pid);
    qtyInput.disabled = false;
    row.querySelector('.qo-price').textContent = product.price > 0 ? product.price.toFixed(2) + ' <?= CURRENCY ?>' : 'اتصل للسعر';
    const stockEl = row.querySelector('.qo-stock');
    stockEl.textContent = product.stock > 0 ? ('متوفر: ' + product.stock + ' ' + product.unit) : 'غير متوفر بالمخزون';
    stockEl.style.color = product.stock > 0 ? 'var(--c-success)' : 'var(--c-danger)';

    qoCalcRow(qtyInput);
}

function qoResetRowInfo(row) {
    row.querySelector('.qo-pid').value = '';
    row.querySelector('.qo-price').textContent = '—';
    row.querySelector('.qo-stock').textContent = '';
    row.querySelector('.qo-total').textContent = '0.00';
    const qtyInput = row.querySelector('.qo-qty');
    qtyInput.disabled = true;
    qtyInput.value = 1;
    qoCalcGrandTotal();
}

function qoCalcRow(qtyInput) {
    const row = qtyInput.closest('.qo-row');
    const pid = row.querySelector('.qo-pid').value;
    const product = QO_PRODUCTS.find(p => p.id == pid);
    const qty = parseFloat(qtyInput.value) || 0;
    const total = product ? product.price * qty : 0;
    row.querySelector('.qo-total').textContent = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    qoCalcGrandTotal();
}

function qoRemoveRow(btn) {
    const wrap = document.getElementById('qoRowsWrap');
    if (wrap.children.length <= 1) { return; } // اترك سطراً واحداً على الأقل
    btn.closest('.qo-row').remove();
    qoCalcGrandTotal();
}

function qoCalcGrandTotal() {
    let sum = 0;
    document.querySelectorAll('.qo-total').forEach(td => sum += parseFloat(td.textContent.replace(/,/g, '')) || 0);
    document.getElementById('qoGrandTotal').textContent = sum.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' <?= CURRENCY ?>';
}

document.addEventListener('DOMContentLoaded', function () {
    qoAddRow(); qoAddRow(); qoAddRow();
});
</script>

<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
