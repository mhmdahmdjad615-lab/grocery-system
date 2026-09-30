<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// إرجاع كمية من دفعة إلى المخزن المؤقت لتعديل سعر البيع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_to_temp') {
    $batch_id = (int)$_POST['batch_id'];
    $qty = (float)$_POST['qty'];
    try {
        $pdo->beginTransaction();
        return_to_temp($pdo, $batch_id, $qty);
        $pdo->commit();
        flash('تم إرجاع الكمية إلى المخزن المؤقت. عدّل سعر البيع من هناك ثم رحّلها مجدداً');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
    }
    redirect('selling_warehouse.php');
}

// إزالة منتج بالكامل من مخزن البيع (فقط إن كان رصيده صفر)
if (isset($_GET['remove_product'])) {
    $pid = (int)$_GET['remove_product'];
    try {
        remove_product_from_selling($pdo, $pid);
        flash('تمت إزالة المنتج من مخزن البيع، ويمكن إضافته مجدداً من صفحة المنتجات');
    } catch (Exception $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('selling_warehouse.php');
}

$search = trim($_GET['search'] ?? '');
$sql = "
    SELECT sb.*, p.name product_name, p.unit, p.id product_id, p.min_quantity, c.name category_name
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE sb.location = 'selling'
";
$params = [];
if ($search !== '') { $sql .= " AND p.name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY p.name ASC, sb.created_at ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

// حساب إجمالي الكمية لكل منتج لمعرفة حالة المخزون المنخفض
$totalsByProduct = [];
foreach ($batches as $b) {
    $totalsByProduct[$b['product_id']] = ($totalsByProduct[$b['product_id']] ?? 0) + (float)$b['quantity'];
}

$total_cost_value = array_sum(array_map(fn($b) => $b['quantity'] * $b['purchase_price'], $batches));
$total_sale_value = array_sum(array_map(fn($b) => $b['quantity'] * (float)$b['sale_price'], $batches));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">مخزن البيع (الكميات الفعلية المتاحة للعملاء)</h4>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    كل صف هنا يمثل دفعة بسعر شراء وسعر بيع محددين. قد يظهر نفس المنتج بأكثر من صف إذا
    كانت هناك كميات دخلت بأسعار مختلفة. عند البيع، يُخصم أولاً من أقدم دفعة (الوارد أولاً يُصرف أولاً).
    نسبة الربح المعروضة تُحسب تلقائياً: <code>(سعر البيع - سعر الشراء) ÷ سعر الشراء × 100</code>.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div><div class="value"><?= money($total_cost_value) ?></div><div class="label">قيمة المخزون بسعر الشراء</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-tags"></i></div>
        <div><div class="value"><?= money($total_sale_value) ?></div><div class="label">قيمة المخزون بسعر البيع</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="value"><?= money($total_sale_value - $total_cost_value) ?></div><div class="label">الربح المتوقع من المخزون الحالي</div></div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-9"><input type="text" name="search" class="form-control" placeholder="بحث باسم المنتج..." value="<?= e($search) ?>"></div>
            <div class="col-md-3"><button class="btn btn-outline-secondary w-100">بحث</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>الكمية المتاحة</th>
                <th>سعر الشراء</th><th>سعر البيع</th><th>نسبة الربح</th><th>إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $b):
                $isLow = $totalsByProduct[$b['product_id']] <= $b['min_quantity'];
                $profitPct = ($b['sale_price'] && $b['purchase_price'] > 0) ? (($b['sale_price'] - $b['purchase_price']) / $b['purchase_price'] * 100) : null;
                $productUnits = get_product_units($pdo, $b['product_id']);
            ?>
                <tr class="<?= $isLow ? 'table-danger' : '' ?>">
                    <td><?= e($b['category_name'] ?? '-') ?></td>
                    <td>
                        <?= e($b['product_name']) ?>
                        <?php if ($b['is_placeholder']): ?><span class="badge bg-secondary">صف تنبيه - لا يوجد رصيد</span><?php endif; ?>
                    </td>
                    <td>
                        <strong><?= rtrim(rtrim(number_format($b['quantity'],2),'0'),'.') ?></strong> <?= e($b['unit']) ?>
                        <?php if (count($productUnits) > 1): ?>
                        <br><small class="text-muted"><?= format_qty_composite($pdo, $b['product_id'], $b['quantity']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= money($b['purchase_price']) ?></td>
                    <td>
                        <?= $b['sale_price'] !== null ? money($b['sale_price']) : '-' ?>
                        <?php if ($b['sale_price'] !== null && count($productUnits) > 1): ?>
                        <br><a href="#" class="small" data-bs-toggle="collapse" data-bs-target="#priceBreakdown<?= $b['id'] ?>">عرض أسعار كل وحدة</a>
                        <div class="collapse mt-1" id="priceBreakdown<?= $b['id'] ?>">
                            <table class="table table-sm table-borderless mb-0">
                            <?php foreach ($productUnits as $u): if ($u['is_base']) continue; ?>
                                <tr><td class="p-0 small"><?= e($u['unit_name']) ?></td><td class="p-0 small text-end"><?= money(get_unit_price($b['sale_price'], $u)) ?></td></tr>
                            <?php endforeach; ?>
                            </table>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($profitPct !== null): ?>
                            <span class="badge <?= $profitPct >= 0 ? 'bg-success' : 'bg-danger' ?>"><?= number_format($profitPct, 1) ?>%</span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($b['quantity'] > 0): ?>
                        <button class="btn btn-sm btn-outline-warning" title="إرجاع للمخزن المؤقت لتعديل السعر"
                            data-bs-toggle="modal" data-bs-target="#returnModal"
                            onclick="document.getElementById('return_batch_id').value='<?= $b['id'] ?>';
                                     document.getElementById('return_product_name').innerText='<?= e($b['product_name']) ?>';
                                     document.getElementById('return_qty').setAttribute('max','<?= $b['quantity'] ?>');
                                     document.getElementById('return_qty').value='<?= $b['quantity'] ?>';">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($totalsByProduct[$b['product_id']] == 0): ?>
                        <a href="selling_warehouse.php?remove_product=<?= $b['product_id'] ?>" class="btn btn-sm btn-outline-danger" title="إزالة المنتج نهائياً من مخزن البيع" onclick="return confirm('إزالة المنتج نهائياً من مخزن البيع؟')"><i class="fa-solid fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($batches)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد منتجات بمخزن البيع بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال إرجاع كمية للمخزن المؤقت -->
<div class="modal fade" id="returnModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="return_to_temp">
        <input type="hidden" name="batch_id" id="return_batch_id">
        <div class="modal-header">
          <h5 class="modal-title">إرجاع للمخزن المؤقت: <span id="return_product_name"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <label class="form-label">الكمية المطلوب إرجاعها</label>
            <input type="number" step="0.01" min="0.01" name="qty" id="return_qty" class="form-control" required>
            <small class="text-muted">بعد الإرجاع، عدّل سعر البيع من صفحة المخزن المؤقت ثم رحّلها مجدداً.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-warning">إرجاع</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
