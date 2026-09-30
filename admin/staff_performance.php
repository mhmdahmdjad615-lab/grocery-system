<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

$rows = $pdo->prepare("
    SELECT u.id, u.full_name, u.role,
           COUNT(s.id) invoices_count,
           COALESCE(SUM(s.total),0) - COALESCE(retSum.total_returns,0) total_sales,
           COALESCE(SUM(s.paid),0) total_collected,
           COALESCE(AVG(s.total),0) avg_invoice
    FROM users u
    LEFT JOIN sale_invoices s ON s.user_id = u.id AND s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale'
    LEFT JOIN (
        SELECT s2.user_id, SUM(sr.total) total_returns
        FROM sale_returns sr JOIN sale_invoices s2 ON s2.id = sr.sale_invoice_id
        WHERE sr.return_date BETWEEN ? AND ?
        GROUP BY s2.user_id
    ) retSum ON retSum.user_id = u.id
    GROUP BY u.id
    HAVING invoices_count > 0
    ORDER BY total_sales DESC
");
$rows->execute([$from, $to, $from, $to]);
$rows = $rows->fetchAll();

// [مُصحَّح] الربح الناتج عن كل موظف الآن يخصم مرتجعات مبيعاته أيضاً (لم تكن تُخصم سابقاً)،
// ويُنسب المرتجع لنفس الموظف صاحب الفاتورة الأصلية (وليس من قام باستلام المرتجع بالضرورة)
$profitByUser = $pdo->prepare("
    SELECT s.user_id,
           SUM(si.total - (si.quantity * si.cost_price)) gross_profit,
           COALESCE(ret.total_return_profit_impact, 0) return_impact
    FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id AND s.invoice_type = 'sale'
    LEFT JOIN (
        SELECT s2.user_id, SUM(sri.total - (CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END)) total_return_profit_impact
        FROM sale_return_items sri
        JOIN sale_returns sr ON sr.id = sri.return_id
        JOIN sale_invoices s2 ON s2.id = sr.sale_invoice_id
        WHERE sr.return_date BETWEEN ? AND ?
        GROUP BY s2.user_id
    ) ret ON ret.user_id = s.user_id
    WHERE s.invoice_date BETWEEN ? AND ?
    GROUP BY s.user_id
");
$profitByUser->execute([$from, $to, $from, $to]);
$profitMap = [];
foreach ($profitByUser->fetchAll() as $p) {
    // صافي الربح = مجمل الربح من الفواتير − أثر المرتجعات (المبلغ المرتجع ناقص ما أُعيد فعلياً من تكلفته للمخزون)
    $profitMap[$p['user_id']] = (float)$p['gross_profit'] - (float)$p['return_impact'];
}

$total_sales = array_sum(array_column($rows, 'total_sales'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">أداء المبيعات لكل موظف</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    "الربح الناتج" لكل موظف يخصم الآن أي مرتجعات على فواتيره بنفس الفترة، بخلاف النسخة السابقة
    التي كانت تتجاهل المرتجعات تماماً.
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

<div class="card">
    <div class="card-header">التفاصيل (الأعلى مبيعاً أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الموظف</th><th>الدور</th><th>عدد الفواتير</th><th>إجمالي المبيعات</th>
                <th>المحصَّل نقداً</th><th>متوسط قيمة الفاتورة</th><th>الربح الناتج (صافي)</th><th>حصة من إجمالي المبيعات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $share = $total_sales>0 ? ($r['total_sales']/$total_sales*100) : 0; ?>
                <tr class="<?= $i===0?'table-success':'' ?>">
                    <td><?= e($r['full_name']) ?> <?php if($i===0):?><span class="badge bg-success">الأعلى مبيعاً</span><?php endif; ?></td>
                    <td><span class="badge <?= $r['role']==='admin'?'bg-success':'bg-primary' ?>"><?= $r['role']==='admin'?'مدير':'موظف' ?></span></td>
                    <td><?= $r['invoices_count'] ?></td>
                    <td class="fw-bold"><?= money($r['total_sales']) ?></td>
                    <td><?= money($r['total_collected']) ?></td>
                    <td><?= money($r['avg_invoice']) ?></td>
                    <td class="<?= ($profitMap[$r['id']] ?? 0)>=0?'text-success':'text-danger' ?>"><?= money($profitMap[$r['id']] ?? 0) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="flex-grow-1 bg-light rounded" style="height:8px;">
                                <div class="bg-primary rounded" style="height:8px;width:<?= min(100,$share) ?>%"></div>
                            </div>
                            <small><?= number_format($share,1) ?>%</small>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد مبيعات مسجلة بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
