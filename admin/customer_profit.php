<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// [مُصحَّح] لم تكن مرتجعات العميل تُخصم إطلاقاً من مبيعاته/ربحه بالنسخة السابقة، ما كان
// يُظهر مبيعات وربحاً أعلى من الحقيقة لأي عميل لديه مرتجعات. تم خصمها بدقة هنا.
$rows = $pdo->prepare("
    SELECT c.id, c.name, c.phone, c.balance,
           COUNT(DISTINCT s.id) invoices_count,
           COALESCE(SUM(si.quantity),0) qty_sold,
           COALESCE(SUM(si.total),0) - COALESCE(ret.revenue,0) net_revenue,
           COALESCE(SUM(si.quantity * si.cost_price),0) - COALESCE(ret.restocked_cost,0) net_cost,
           (COALESCE(SUM(si.total),0) - COALESCE(ret.revenue,0)) - (COALESCE(SUM(si.quantity * si.cost_price),0) - COALESCE(ret.restocked_cost,0)) profit
    FROM sale_items si
    JOIN sale_invoices s ON s.id = si.invoice_id
    JOIN customers c ON c.id = s.customer_id
    LEFT JOIN (
        SELECT sr.customer_id, SUM(sri.total) revenue,
               SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END) restocked_cost
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ?
        GROUP BY sr.customer_id
    ) ret ON ret.customer_id = c.id
    WHERE s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale'
    GROUP BY c.id
    ORDER BY profit DESC
");
$rows->execute([$from, $to, $from, $to]);
$rows = $rows->fetchAll();

$total_revenue = array_sum(array_column($rows, 'net_revenue'));
$total_cost = array_sum(array_column($rows, 'net_cost'));
$total_profit = array_sum(array_column($rows, 'profit'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">الربح من كل عميل</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <strong>المعادلة الدقيقة:</strong> (مبيعات العميل − مرتجعاته) − (تكلفتها الفعلية وقت البيع − تكلفة أي
    مرتجع أُعيد فعلياً للمخزون).
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
        <div><div class="value"><?= money($total_cost) ?></div><div class="label">صافي التكلفة</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:<?= $total_profit>=0?'#198754':'#dc3545' ?>"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="value"><?= money($total_profit) ?></div><div class="label">إجمالي الربح</div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">تفاصيل الربح حسب العميل (الأعلى ربحاً أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>العميل</th><th>عدد الفواتير</th><th>الكمية المباعة</th><th>صافي المبيعات</th>
                <th>صافي التكلفة</th><th>الربح</th><th>هامش الربح</th><th>الرصيد المستحق عليه</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $margin = $r['net_revenue'] > 0 ? ($r['profit'] / $r['net_revenue'] * 100) : 0; ?>
                <tr class="<?= $i===0 ? 'table-success' : '' ?>">
                    <td><a href="customer_statement.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a> <?php if ($i===0): ?><span class="badge bg-success">الأعلى ربحاً</span><?php endif; ?></td>
                    <td><?= $r['invoices_count'] ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?></td>
                    <td><?= money($r['net_revenue']) ?></td>
                    <td><?= money($r['net_cost']) ?></td>
                    <td class="<?= $r['profit']>=0?'text-success':'text-danger' ?> fw-bold"><?= money($r['profit']) ?></td>
                    <td><span class="badge <?= $margin>=0?'bg-success':'bg-danger' ?>"><?= number_format($margin,1) ?>%</span></td>
                    <td class="<?= $r['balance']>0?'text-danger fw-bold':'' ?>"><?= money($r['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد مبيعات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
