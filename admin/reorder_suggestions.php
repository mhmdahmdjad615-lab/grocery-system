<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$analysis_days = (int)($_GET['analysis_days'] ?? 90);
$coverage_days = (int)($_GET['coverage_days'] ?? 14);
$from = date('Y-m-d', strtotime("-{$analysis_days} days"));
$to = date('Y-m-d');

$rows = $pdo->prepare("
    SELECT p.id, p.name, c.name category_name, p.unit, p.min_quantity,
           COALESCE(sales.qty,0) qty_sold,
           COALESCE(stock.qty,0) current_stock
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    JOIN (SELECT DISTINCT product_id FROM stock_batches WHERE location='selling') sw ON sw.product_id = p.id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ?
        GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) qty FROM stock_batches WHERE location='selling' GROUP BY product_id
    ) stock ON stock.product_id = p.id
");
$rows->execute([$from, $to]);
$rows = $rows->fetchAll();

foreach ($rows as &$r) {
    $daily_avg = $r['qty_sold'] / $analysis_days;
    $target_stock = $daily_avg * $coverage_days;
    $suggested_qty = round(max(0, $target_stock - $r['current_stock']), 2);
    $r['daily_avg'] = $daily_avg;
    $r['target_stock'] = $target_stock;
    $r['suggested_qty'] = $suggested_qty;
}
unset($r);

// الأولوية: من له اقتراح طلب فعلي (>0)، مرتبة تنازلياً حسب الكمية المقترحة
$suggested = array_values(array_filter($rows, fn($r) => $r['suggested_qty'] > 0));
usort($suggested, fn($a, $b) => $b['suggested_qty'] <=> $a['suggested_qty']);
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">اقتراح كميات إعادة الطلب</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary no-print"><i class="fa-solid fa-print"></i> طباعة قائمة الطلب</button>
</div>

<div class="alert alert-info no-print">
    <i class="fa-solid fa-circle-info"></i>
    الكمية المقترحة = (متوسط الطلب اليومي × عدد أيام التغطية المطلوبة) − الرصيد الحالي بمخزن البيع.
    عدّل "فترة تحليل المبيعات" و"عدد أيام التغطية المستهدفة" حسب عادات محلك.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <label class="form-label">فترة تحليل متوسط المبيعات (بالأيام)</label>
                <input type="number" name="analysis_days" class="form-control" value="<?= $analysis_days ?>" min="7">
            </div>
            <div class="col-md-4">
                <label class="form-label">عدد أيام التغطية المستهدفة بعد الطلب</label>
                <input type="number" name="coverage_days" class="form-control" value="<?= $coverage_days ?>" min="1">
            </div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100">إعادة الحساب</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">قائمة إعادة الطلب المقترحة (<?= count($suggested) ?> صنف) - بناءً على مبيعات آخر <?= $analysis_days ?> يوم</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>متوسط الطلب اليومي</th>
                <th>الرصيد الحالي</th><th>المستهدف (<?= $coverage_days ?> يوم تغطية)</th>
                <th>الكمية المقترح طلبها</th>
            </tr></thead>
            <tbody>
            <?php foreach ($suggested as $r): ?>
                <tr class="<?= $r['current_stock'] <= $r['min_quantity'] ? 'table-danger' : '' ?>">
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= number_format($r['daily_avg'],2) ?> <?= e($r['unit']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['current_stock'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= number_format($r['target_stock'],2) ?> <?= e($r['unit']) ?></td>
                    <td class="fw-bold text-primary"><?= number_format($r['suggested_qty'],2) ?> <?= e($r['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($suggested)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد اقتراحات طلب حالياً - المخزون كافٍ لكل المنتجات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
