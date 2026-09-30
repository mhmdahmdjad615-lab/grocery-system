<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

$rows = $pdo->prepare("
    SELECT p.id, p.name, c.name category_name, p.unit,
           COALESCE(sales.qty,0) qty_sold,
           COALESCE(purch.qty,0) qty_purchased
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ?
        GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT pi.product_id, SUM(pi.quantity) qty
        FROM purchase_items pi JOIN purchase_invoices pv ON pv.id = pi.invoice_id
        WHERE pv.invoice_date BETWEEN ? AND ?
        GROUP BY pi.product_id
    ) purch ON purch.product_id = p.id
    HAVING qty_sold > 0 OR qty_purchased > 0
    ORDER BY qty_sold DESC
");
$rows->execute([$from, $to, $from, $to]);
$rows = $rows->fetchAll();

$total_sold = array_sum(array_column($rows, 'qty_sold'));
$total_purchased = array_sum(array_column($rows, 'qty_purchased'));
$overall_ratio = $total_purchased > 0 ? ($total_sold / $total_purchased * 100) : null;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">كمية المبيعات مقابل المشتريات لكل منتج</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    نسبة البيع إلى الشراء = (الكمية المباعة ÷ الكمية المشتراة) × 100 خلال نفس الفترة.
    نسبة أعلى من 100% تعني أنك تبيع من مخزون سابق أكثر مما تشتري بهذه الفترة، وأقل من 100%
    تعني تراكم مخزون لم يُباع بعد.
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
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-cart-shopping"></i></div>
        <div><div class="value"><?= rtrim(rtrim(number_format($total_sold,2),'0'),'.') ?></div><div class="label">إجمالي الكمية المباعة</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-dolly"></i></div>
        <div><div class="value"><?= rtrim(rtrim(number_format($total_purchased,2),'0'),'.') ?></div><div class="label">إجمالي الكمية المشتراة</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-percent"></i></div>
        <div><div class="value"><?= $overall_ratio !== null ? number_format($overall_ratio,1).'%' : '-' ?></div><div class="label">نسبة البيع إلى الشراء إجمالاً</div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">التفاصيل حسب المنتج</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>الصنف</th><th>المنتج</th><th>الكمية المباعة</th><th>الكمية المشتراة</th><th>نسبة البيع إلى الشراء</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $ratio = $r['qty_purchased'] > 0 ? ($r['qty_sold'] / $r['qty_purchased'] * 100) : null; ?>
                <tr>
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_purchased'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td>
                        <?php if ($ratio !== null): ?>
                            <span class="badge <?= $ratio>=100?'bg-success':($ratio>=50?'bg-warning text-dark':'bg-danger') ?>"><?= number_format($ratio,1) ?>%</span>
                        <?php else: ?><span class="badge bg-secondary">لا توجد مشتريات بالفترة</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد بيانات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
