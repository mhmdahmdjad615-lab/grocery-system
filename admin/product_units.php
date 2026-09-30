<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$product_id = (int)($_GET['product_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) { flash('المنتج غير موجود', 'danger'); redirect('products.php'); }

// إضافة مستوى تعبئة جديد يحتوي على عدد من وحدة أصغر موجودة بالفعل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_unit') {
    $unit_name = trim($_POST['unit_name'] ?? '');
    $print_name = trim($_POST['print_name'] ?? '') ?: $unit_name;
    $wraps_unit_id = (int)($_POST['wraps_unit_id'] ?? 0);
    $qty = (float)($_POST['qty_of_wrapped'] ?? 0);
    $weight = trim($_POST['net_weight'] ?? '');

    if ($unit_name === '' || $qty <= 0 || $wraps_unit_id <= 0) {
        flash('يرجى إدخال اسم الوحدة، اختيار الوحدة الأصغر، وعدد صحيح أكبر من صفر', 'danger');
    } else {
        try {
            add_product_unit_chain($pdo, $product_id, $unit_name, $wraps_unit_id, $qty, [
                'print_name'     => $print_name,
                'net_weight'     => $weight !== '' ? (float)$weight : null,
                'allow_purchase' => isset($_POST['allow_purchase']),
                'allow_sell'     => isset($_POST['allow_sell']),
                'allow_count'    => isset($_POST['allow_count']),
            ]);
            flash('تمت إضافة الوحدة بنجاح');
        } catch (Exception $e) {
            flash($e->getMessage(), 'danger');
        }
    }
    redirect('product_units.php?product_id=' . $product_id);
}

// تحديث إعدادات التسعير (تلقائي/يدوي) لوحدة معيّنة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pricing') {
    $unit_id = (int)$_POST['unit_id'];
    $price_mode = $_POST['price_mode'] === 'manual' ? 'manual' : 'auto';
    $manual_price = $price_mode === 'manual' ? (float)$_POST['manual_price'] : null;
    $pdo->prepare("UPDATE product_units SET price_mode=?, manual_price=? WHERE id=? AND product_id=?")
        ->execute([$price_mode, $manual_price, $unit_id, $product_id]);
    flash('تم تحديث إعداد التسعير لهذه الوحدة');
    redirect('product_units.php?product_id=' . $product_id);
}

if (isset($_GET['delete'])) {
    $unit_id = (int)$_GET['delete'];
    // منع حذف وحدة تعتمد عليها وحدة أكبر أخرى (wraps_unit_id يشير إليها)
    $dependent = $pdo->prepare("SELECT COUNT(*) c FROM product_units WHERE wraps_unit_id = ?");
    $dependent->execute([$unit_id]);
    if ($dependent->fetch()['c'] > 0) {
        flash('لا يمكن حذف هذه الوحدة لأن وحدات أخرى أكبر منها تعتمد عليها في سلسلة التحويل', 'danger');
        redirect('product_units.php?product_id=' . $product_id);
    }
    // منع حذف الوحدة إن كانت مستخدمة فعلياً بفاتورة سابقة
    $used = $pdo->prepare("
        SELECT
        (SELECT COUNT(*) FROM purchase_items WHERE unit_name = (SELECT unit_name FROM product_units WHERE id=?) AND product_id=?) +
        (SELECT COUNT(*) FROM sale_items WHERE unit_name = (SELECT unit_name FROM product_units WHERE id=?) AND product_id=?) c
    ");
    $used->execute([$unit_id, $product_id, $unit_id, $product_id]);
    if ($used->fetch()['c'] > 0) {
        flash('لا يمكن حذف هذه الوحدة لوجود فواتير سابقة استخدمتها', 'danger');
    } else {
        $pdo->prepare("DELETE FROM product_units WHERE id = ? AND product_id = ? AND is_base = 0")->execute([$unit_id, $product_id]);
        flash('تم حذف الوحدة');
    }
    redirect('product_units.php?product_id=' . $product_id);
}

$units = get_product_units($pdo, $product_id); // الأكبر أولاً
$baseUnit = get_base_unit($pdo, $product_id);

// سعر بيع "الوحدة الأساسية" التقريبي الحالي (من أحدث دفعة بمخزن البيع) لعرض التسعير التلقائي المقترح
$lastBasePriceRow = $pdo->prepare("SELECT sale_price FROM stock_batches WHERE product_id = ? AND location='selling' AND sale_price IS NOT NULL ORDER BY created_at DESC LIMIT 1");
$lastBasePriceRow->execute([$product_id]);
$lastBasePrice = (float)($lastBasePriceRow->fetch()['sale_price'] ?? 0);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">هيكل وحدات: <?= e($product['name']) ?></h4>
    <a href="products.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للمنتجات</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    الوحدة الأساسية <strong>(<?= e($baseUnit['unit_name']) ?>)</strong> ثابتة طوال عمر المنتج، وكل الحسابات
    الداخلية (المخزون، الجرد، التكلفة) تعتمد عليها فقط. أضف وحدات تعبئة أكبر بتحديد "تحتوي على كم من
    وحدة أصغر موجودة بالفعل" - سيحسب النظام معامل التحويل للوحدة الأساسية تلقائياً عبر السلسلة كاملة.
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header">إضافة مستوى تعبئة جديد</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add_unit">
                    <div class="mb-3">
                        <label class="form-label">اسم الوحدة</label>
                        <input type="text" name="unit_name" class="form-control" placeholder="مثال: كرتونة، طن، لفة" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الاسم بالفواتير والطباعة (اختياري)</label>
                        <input type="text" name="print_name" class="form-control" placeholder="افتراضياً = نفس اسم الوحدة">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">تحتوي على (عدد)</label>
                            <input type="number" step="0.0001" min="0.0001" name="qty_of_wrapped" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">من الوحدة</label>
                            <select name="wraps_unit_id" class="form-select" required>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= e($u['unit_name']) ?> <?= $u['is_base'] ? '(أساسية)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الوزن الصافي لوحدة واحدة (اختياري، كجم)</label>
                        <input type="number" step="0.0001" name="net_weight" class="form-control">
                    </div>
                    <div class="row mb-3">
                        <div class="col-4 form-check">
                            <input type="checkbox" name="allow_purchase" class="form-check-input" checked>
                            <label class="form-check-label">شراء</label>
                        </div>
                        <div class="col-4 form-check">
                            <input type="checkbox" name="allow_sell" class="form-check-input" checked>
                            <label class="form-check-label">بيع</label>
                        </div>
                        <div class="col-4 form-check">
                            <input type="checkbox" name="allow_count" class="form-check-input" checked>
                            <label class="form-check-label">جرد</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">إضافة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header">الوحدات الحالية لهذا المنتج</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead><tr>
                        <th>الوحدة</th><th>تحتوي على</th><th>= بالوحدة الأساسية</th>
                        <th>الأعلام</th><th>السعر التلقائي المقترح</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($units as $u): ?>
                        <tr>
                            <td>
                                <?= e($u['unit_name']) ?>
                                <?= $u['is_base'] ? '<span class="badge bg-secondary">أساسية</span>' : '<span class="badge bg-primary">تعبئة</span>' ?>
                                <?php if ($u['net_weight']): ?><br><small class="text-muted">وزن: <?= rtrim(rtrim(number_format($u['net_weight'],4),'0'),'.') ?> كجم</small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['is_base']): ?>-<?php else: ?>
                                    <?= rtrim(rtrim(number_format($u['qty_of_wrapped'],4),'0'),'.') ?>
                                    <?php
                                        $wrapped = array_values(array_filter($units, fn($x) => $x['id'] == $u['wraps_unit_id']));
                                        echo e($wrapped[0]['unit_name'] ?? '-');
                                    ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $u['is_base'] ? '1 (مرجعية)' : rtrim(rtrim(number_format($u['factor'],4),'0'),'.') . ' ' . e($baseUnit['unit_name']) ?></td>
                            <td>
                                <?= $u['allow_purchase'] ? '<span class="badge bg-success">شراء</span>' : '' ?>
                                <?= $u['allow_sell'] ? '<span class="badge bg-info text-dark">بيع</span>' : '' ?>
                                <?= $u['allow_count'] ? '<span class="badge bg-secondary">جرد</span>' : '' ?>
                            </td>
                            <td>
                                <?php if ($u['is_base']): ?>
                                    -
                                <?php else:
                                    $suggested = get_unit_price($lastBasePrice, $u);
                                ?>
                                    <form method="post" class="d-flex align-items-center gap-1">
                                        <input type="hidden" name="action" value="update_pricing">
                                        <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                                        <select name="price_mode" class="form-select form-select-sm" style="width:100px" onchange="this.form.querySelector('.manualPriceInput').style.display = this.value==='manual' ? 'block':'none'">
                                            <option value="auto" <?= $u['price_mode']==='auto'?'selected':'' ?>>تلقائي</option>
                                            <option value="manual" <?= $u['price_mode']==='manual'?'selected':'' ?>>يدوي</option>
                                        </select>
                                        <input type="number" step="0.01" name="manual_price" class="form-control form-control-sm manualPriceInput" style="width:90px; <?= $u['price_mode']==='manual'?'':'display:none' ?>" value="<?= $u['manual_price'] ?? round($suggested,2) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-check"></i></button>
                                    </form>
                                    <small class="text-muted">تلقائي الآن: <?= money($suggested) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$u['is_base']): ?>
                                <a href="product_units.php?product_id=<?= $product_id ?>&delete=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الوحدة؟')"><i class="fa-solid fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
