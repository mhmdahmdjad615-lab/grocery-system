<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-3 months'));
$to   = $_GET['to'] ?? date('Y-m-d');
$days = max(1, (strtotime($to) - strtotime($from)) / 86400 + 1);

// كل المنتجات المضافة لمخزن البيع (حتى لو لم تُبَع إطلاقاً بالفترة، لرصد الراكد منها)
// مع كمية وقيمة مبيعاتها بالفترة، وكميتها الحالية بمخزن البيع، وتاريخ آخر عملية بيع لها
$rows = $pdo->prepare("
    SELECT p.id, p.name, c.name category_name, p.unit,
           COALESCE(sales.qty,0) qty_sold,
           COALESCE(sales.revenue,0) revenue,
           COALESCE(stock.qty,0) current_stock,
           last_sale.last_date
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    JOIN (SELECT DISTINCT product_id FROM stock_batches WHERE location='selling') sw ON sw.product_id = p.id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty, SUM(si.total) revenue
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ?
        GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) qty FROM stock_batches WHERE location='selling' GROUP BY product_id
    ) stock ON stock.product_id = p.id
    LEFT JOIN (
        SELECT si.product_id, MAX(s.invoice_date) last_date
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        GROUP BY si.product_id
    ) last_sale ON last_sale.product_id = p.id
    ORDER BY qty_sold DESC
");
$rows->execute([$from, $to]);
$rows = $rows->fetchAll();

// تصنيف سرعة الدوران: تقسيم المنتجات المُباعة فعلياً إلى ثلاث شرائح متساوية تقريباً
// (الأعلى كمية = سريع، الأوسط = متوسط، الأقل = بطيء)، وأي منتج لم يُبَع إطلاقاً = راكد
$soldRows = array_values(array_filter($rows, fn($r) => $r['qty_sold'] > 0));
$soldCount = count($soldRows);
$fastCut = (int)ceil($soldCount / 3);
$mediumCut = (int)ceil($soldCount * 2 / 3);

$rank = 0;
foreach ($rows as &$r) {
    if ($r['qty_sold'] <= 0) {
        $r['velocity'] = 'dead';
    } else {
        $rank++;
        if ($rank <= $fastCut) $r['velocity'] = 'fast';
        elseif ($rank <= $mediumCut) $r['velocity'] = 'medium';
        else $r['velocity'] = 'slow';
    }
    if ($r['last_date']) {
        $r['days_since_last_sale'] = (int)((strtotime(date('Y-m-d')) - strtotime($r['last_date'])) / 86400);
    } else {
        $r['days_since_last_sale'] = null;
    }
}
unset($r);

$velocityLabels = [
    'fast'   => ['سريع الدوران', 'bg-success'],
    'medium' => ['متوسط الدوران', 'bg-primary'],
    'slow'   => ['بطيء الدوران', 'bg-warning text-dark'],
    'dead'   => ['راكد (لم يُبع)', 'bg-danger'],
];

$countByVelocity = ['fast'=>0,'medium'=>0,'slow'=>0,'dead'=>0];
foreach ($rows as $r) $countByVelocity[$r['velocity']]++;

$filter = $_GET['velocity'] ?? '';
$displayRows = $filter ? array_filter($rows, fn($r) => $r['velocity'] === $filter) : $rows;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">سرعة دوران المنتجات (الأسرع مقابل الأبطأ بيعاً)</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    التصنيف يعتمد على ترتيب المنتجات حسب الكمية المباعة خلال الفترة: أعلى ثلث = <strong>سريع الدوران</strong>،
    الثلث الأوسط = <strong>متوسط</strong>، الثلث الأدنى (وله مبيعات) = <strong>بطيء</strong>،
    وأي منتج بمخزن البيع لم يُبَع إطلاقاً بالفترة = <strong>راكد</strong> ويحتاج انتباهك (عرض/تخفيض سعر/التوقف عن شرائه).
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100">عرض</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($velocityLabels as $key => $meta): ?>
    <div class="col-md-3 col-6">
        <a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&velocity=<?= $key ?>" class="text-decoration-none">
            <div class="stat-card <?= $filter===$key?'border border-3 border-dark':'' ?>">
                <div class="icon-box <?= $meta[1] ?>"><i class="fa-solid fa-gauge-high"></i></div>
                <div><div class="value"><?= $countByVelocity[$key] ?></div><div class="label"><?= $meta[0] ?></div></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($filter): ?>
<div class="mb-3"><a href="?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-outline-secondary">إلغاء الفلتر وعرض الكل</a></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">التفاصيل (الفترة: <?= (int)$days ?> يوم) <button class="btn btn-sm btn-outline-secondary float-start no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة</button></div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>التصنيف</th><th>الصنف</th><th>المنتج</th><th>الكمية المباعة</th>
                <th>قيمة المبيعات</th><th>الرصيد الحالي</th><th>آخر عملية بيع</th>
            </tr></thead>
            <tbody>
            <?php foreach ($displayRows as $r): $meta = $velocityLabels[$r['velocity']]; ?>
                <tr>
                    <td><span class="badge <?= $meta[1] ?>"><?= $meta[0] ?></span></td>
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= money($r['revenue']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['current_stock'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td>
                        <?php if ($r['last_date']): ?>
                            <?= e($r['last_date']) ?>
                            <span class="text-muted">(منذ <?= $r['days_since_last_sale'] ?> يوم)</span>
                        <?php else: ?>
                            <span class="text-danger">لم يُبَع من قبل إطلاقاً</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($displayRows)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد بيانات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
