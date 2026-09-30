<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

// [مُصحَّح بالكامل] رقم ربح واحد دقيق وموحّد مع كل صفحات التقارير الأخرى (لا يوجد بعد
// الآن أي تعارض بين هذه الصفحة وصفحة الأرباح والخسائر) — يعتمد المعادلة المحاسبية
// الصحيحة: صافي المبيعات (بعد المرتجعات) - صافي تكلفة البضاعة المباعة فعلياً + الإيرادات
// الأخرى - النفقات الأخرى. المشتريات وقيمة المخزون لا تدخلان في حساب الربح إطلاقاً.
$report = get_accurate_profit_report($pdo, $from, $to);

$grossSalesStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(paid),0) p FROM sale_invoices WHERE invoice_date BETWEEN ? AND ?");
$grossSalesStmt->execute([$from, $to]);
$salesTotal = $grossSalesStmt->fetch();

// أكثر المنتجات مبيعاً (بالكمية الصافية بعد خصم أي مرتجع لنفس المنتج بالفترة)
$topProducts = $pdo->prepare("
    SELECT pr.name,
           SUM(si.quantity) - COALESCE(ret.qty,0) qty,
           SUM(si.total) - COALESCE(ret.revenue,0) total
    FROM sale_items si
    JOIN sale_invoices s ON s.id = si.invoice_id
    JOIN products pr ON pr.id = si.product_id
    LEFT JOIN (
        SELECT sri.product_id, SUM(sri.quantity) qty, SUM(sri.total) revenue
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ? GROUP BY sri.product_id
    ) ret ON ret.product_id = si.product_id
    WHERE s.invoice_date BETWEEN ? AND ?
    GROUP BY si.product_id ORDER BY qty DESC LIMIT 10
");
$topProducts->execute([$from, $to, $from, $to]);
$topProducts = $topProducts->fetchAll();

// المنتجات منخفضة المخزون (إجمالي كمية دفعاتها بمخزن البيع أقل من أو يساوي الحد الأدنى)
$lowStock = $pdo->query("
    SELECT p.name, p.min_quantity, COALESCE(SUM(sb.quantity),0) quantity
    FROM products p
    JOIN stock_batches sb ON sb.product_id = p.id AND sb.location = 'selling'
    GROUP BY p.id
    HAVING quantity <= p.min_quantity
    ORDER BY quantity ASC
")->fetchAll();

// حركة الخزينة خلال الفترة
$cashMovements = $pdo->prepare("SELECT * FROM cash_movements WHERE movement_date BETWEEN ? AND ? ORDER BY movement_date DESC, id DESC");
$cashMovements->execute([$from, $to]);
$cashMovements = $cashMovements->fetchAll();
$cash_in_period = array_sum(array_map(fn($m)=>$m['type']=='in'?$m['amount']:0, $cashMovements));
$cash_out_period = array_sum(array_map(fn($m)=>$m['type']=='out'?$m['amount']:0, $cashMovements));

// أعلى العملاء مديونية / أعلى الموردين استحقاق
$topDebtCustomers = $pdo->query("SELECT * FROM customers WHERE balance > 0 ORDER BY balance DESC LIMIT 10")->fetchAll();
$topDebtSuppliers = $pdo->query("SELECT * FROM suppliers WHERE balance > 0 ORDER BY balance DESC LIMIT 10")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fa-solid fa-chart-column"></i> التقارير - نظرة عامة</h4>
</div>
<p class="text-muted mb-4">لبقية التقارير التفصيلية استخدم القائمة الجانبية على اليمين.</p>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    كل الأرقام هنا صافية (بعد خصم المرتجعات) ومبنية على التكلفة الفعلية وقت البيع، ومطابقة
    تماماً لصفحة <a href="profit_loss.php">الأرباح والخسائر</a> التفصيلية.
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100">عرض التقرير</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#1e7e34"><i class="fa-solid fa-cash-register"></i></div>
        <div><div class="value"><?= money($report['net_sales_revenue']) ?></div><div class="label">صافي المبيعات (بعد المرتجعات)</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-cart-arrow-down"></i></div>
        <div><div class="value"><?= money($report['net_purchases']) ?></div><div class="label">صافي المشتريات (بعد المرتجعات)</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:<?= $report['net_profit']>=0?'#198754':'#dc3545' ?>"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="value"><?= money($report['net_profit']) ?></div><div class="label">صافي الربح</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($cash_in_period - $cash_out_period) ?></div><div class="label">صافي حركة الخزينة بالفترة</div></div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">أكثر 10 منتجات مبيعاً خلال الفترة (صافي بعد المرتجعات)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>المنتج</th><th>الكمية المباعة</th><th>الإجمالي</th></tr></thead>
                    <tbody>
                    <?php foreach ($topProducts as $tp): ?>
                        <tr><td><?= e($tp['name']) ?></td><td><?= rtrim(rtrim(number_format($tp['qty'],2),'0'),'.') ?></td><td><?= money($tp['total']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($topProducts)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد بيانات</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-danger">المنتجات منخفضة المخزون (تحتاج إعادة طلب)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>المنتج</th><th>الكمية الحالية</th><th>الحد الأدنى</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStock as $ls): ?>
                        <tr class="table-danger"><td><?= e($ls['name']) ?></td><td><?= rtrim(rtrim(number_format($ls['quantity'],2),'0'),'.') ?></td><td><?= rtrim(rtrim(number_format($ls['min_quantity'],2),'0'),'.') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($lowStock)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا يوجد نقص بالمخزون</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">أعلى العملاء مديونية</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>العميل</th><th>الرصيد المستحق</th></tr></thead>
                    <tbody>
                    <?php foreach ($topDebtCustomers as $c): ?>
                        <tr><td><a href="customer_statement.php?id=<?= $c['id'] ?>"><?= e($c['name']) ?></a></td><td class="text-danger fw-bold"><?= money($c['balance']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($topDebtCustomers)): ?><tr><td colspan="2" class="text-center text-muted py-3">لا توجد ديون</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">أعلى الموردين استحقاقاً</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>المورد</th><th>المستحق له</th></tr></thead>
                    <tbody>
                    <?php foreach ($topDebtSuppliers as $s): ?>
                        <tr><td><a href="supplier_statement.php?id=<?= $s['id'] ?>"><?= e($s['name']) ?></a></td><td class="text-danger fw-bold"><?= money($s['balance']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($topDebtSuppliers)): ?><tr><td colspan="2" class="text-center text-muted py-3">لا توجد مستحقات</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">حركة الخزينة خلال الفترة</div>
            <div class="card-body p-0" style="max-height:350px; overflow-y:auto;">
                <table class="table mb-0">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>المصدر</th><th>المبلغ</th></tr></thead>
                    <tbody>
                    <?php foreach ($cashMovements as $m): ?>
                        <tr>
                            <td><?= e($m['movement_date']) ?></td>
                            <td><?= $m['type']=='in' ? '<span class="badge bg-success">دخول</span>' : '<span class="badge bg-danger">خروج</span>' ?></td>
                            <td><?= e($m['source']) ?></td>
                            <td><?= money($m['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cashMovements)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد حركات</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
