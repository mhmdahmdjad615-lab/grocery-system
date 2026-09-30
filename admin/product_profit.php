<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// [مُصحَّح بالكامل] الربح الفعلي لكل منتج خلال الفترة =
// (مبيعاته - مرتجعاته) - (تكلفته الفعلية وقت البيع - تكلفة أي مرتجع أُعيد فعلياً للمخزون)
// لم تكن المرتجعات تُخصم إطلاقاً بالنسخة السابقة، ولم تكن "تكلفة المشتريات" تمثل التكلفة
// الحقيقية لما بيع فعلاً (فقد تشتري كمية ولا تبيعها بنفس الفترة، أو تبيع من مخزون قديم).
$rows = $pdo->prepare("
    SELECT p.id, p.name, c.name category_name, p.unit,
           COALESCE(sales.qty,0) qty_sold,
           COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0) net_revenue,
           COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0) net_cost,
           COALESCE(ret.qty,0) qty_returned,
           COALESCE(purch.qty,0) qty_purchased,
           (COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0)) - (COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0)) profit
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty, SUM(si.total) revenue, SUM(si.quantity*si.cost_price) cost
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale'
        GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT sri.product_id, SUM(sri.quantity) qty, SUM(sri.total) revenue,
               SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END) restocked_cost
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ?
        GROUP BY sri.product_id
    ) ret ON ret.product_id = p.id
    LEFT JOIN (
        SELECT pi.product_id, SUM(pi.quantity) qty
        FROM purchase_items pi JOIN purchase_invoices pv ON pv.id = pi.invoice_id
        WHERE pv.invoice_date BETWEEN ? AND ?
        GROUP BY pi.product_id
    ) purch ON purch.product_id = p.id
    HAVING qty_sold > 0 OR qty_purchased > 0
    ORDER BY profit DESC
");
$rows->execute([$from, $to, $from, $to, $from, $to]);
$rows = $rows->fetchAll();

$total_revenue = array_sum(array_column($rows, 'net_revenue'));
$total_cost = array_sum(array_column($rows, 'net_cost'));
$total_profit = array_sum(array_column($rows, 'profit'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">الربح من كل منتج</h4>
    <div>
        <a href="export_product_profit.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a>
        <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
    </div>
</div>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <strong>المعادلة الدقيقة:</strong> (مبيعات المنتج − مرتجعاته) − (تكلفته الفعلية وقت البيع − تكلفة أي مرتجع
    أُعيد للمخزون فعلياً). الأرقام هنا تعتمد على التكلفة الحقيقية المسجَّلة وقت كل عملية بيع (FIFO)، وليس على
    إجمالي المشتريات، لضمان دقة الربح حتى لو اختلف توقيت الشراء عن توقيت البيع.
    <br><small class="text-muted">(فواتير الهدايا المجانية مُستبعدة من هذا التقرير — راجع "تقرير استفادة التجار" بقسم المتجر الإلكتروني لقيمتها)</small>
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
        <div class="stat-card"><div class="icon-box" style="background:#1e7e34"><i class="fa-solid fa-cash-register"></i></div>
        <div><div class="value"><?= money($total_revenue) ?></div><div class="label">صافي المبيعات (بعد المرتجعات)</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-coins"></i></div>
        <div><div class="value"><?= money($total_cost) ?></div><div class="label">صافي تكلفة البضاعة المباعة</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:<?= $total_profit>=0?'#198754':'#dc3545' ?>"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="value"><?= money($total_profit) ?></div><div class="label">إجمالي الربح</div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">تفاصيل الربح حسب المنتج (الأعلى ربحاً أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>الكمية المباعة</th><th>الكمية المرتجعة</th>
                <th>صافي المبيعات</th><th>صافي التكلفة</th><th>الربح</th><th>نسبة الربح</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $margin = $r['net_cost'] > 0 ? ($r['profit'] / $r['net_cost'] * 100) : null;
            ?>
                <tr class="<?= $i===0 ? 'table-success' : '' ?>">
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['name']) ?> <?php if ($i===0): ?><span class="badge bg-success">الأعلى ربحاً</span><?php endif; ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?></td>
                    <td><?= $r['qty_returned']>0 ? rtrim(rtrim(number_format($r['qty_returned'],2),'0'),'.') : '-' ?></td>
                    <td><?= money($r['net_revenue']) ?></td>
                    <td><?= money($r['net_cost']) ?></td>
                    <td class="<?= $r['profit']>=0?'text-success':'text-danger' ?> fw-bold"><?= money($r['profit']) ?></td>
                    <td>
                        <?php if ($margin !== null): ?>
                            <span class="badge <?= $margin>=0?'bg-success':'bg-danger' ?>"><?= number_format($margin,1) ?>%</span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد بيانات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
