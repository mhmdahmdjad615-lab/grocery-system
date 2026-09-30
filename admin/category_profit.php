<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// [مُصحَّح بالكامل]
// 1) خطأ سابق حقيقي: الاستعلام القديم كان يعرض بيانات منتج واحد فقط من كل صنف بدل
//    مجموع كل منتجاته (غياب SUM() الصحيح مع GROUP BY) — تم تصحيحه هنا بالكامل.
// 2) المعادلة الآن دقيقة محاسبياً: (صافي مبيعات الصنف بعد المرتجعات) − (صافي تكلفة
//    البضاعة المباعة فعلياً)، بدل الاعتماد على "المشتريات" أو "قيمة المخزون".
$rows = $pdo->prepare("
    SELECT COALESCE(c.name, 'بدون صنف') category_name,
           COALESCE(SUM(sales.qty),0) qty_sold,
           COALESCE(SUM(sales.revenue),0) - COALESCE(SUM(ret.revenue),0) net_revenue,
           COALESCE(SUM(sales.cost),0) - COALESCE(SUM(ret.restocked_cost),0) net_cost,
           (COALESCE(SUM(sales.revenue),0) - COALESCE(SUM(ret.revenue),0)) - (COALESCE(SUM(sales.cost),0) - COALESCE(SUM(ret.restocked_cost),0)) profit
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) qty, SUM(si.total) revenue, SUM(si.quantity*si.cost_price) cost
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale' GROUP BY si.product_id
    ) sales ON sales.product_id = p.id
    LEFT JOIN (
        SELECT sri.product_id, SUM(sri.total) revenue,
               SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END) restocked_cost
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ? GROUP BY sri.product_id
    ) ret ON ret.product_id = p.id
    GROUP BY c.id
    HAVING qty_sold > 0 OR net_cost > 0
    ORDER BY profit DESC
");
$rows->execute([$from, $to, $from, $to]);
$rows = $rows->fetchAll();

$total_profit = array_sum(array_column($rows, 'profit'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">الربح حسب الصنف (الفئة)</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    يساعدك على معرفة أي فئة من منتجاتك (مثلاً: مواد غذائية، منظفات، مشروبات) تحقق أكبر ربح فعلي
    لتوجيه التوسع والتسويق والمساحة بالمحل نحوها.
    <br><strong>المعادلة الدقيقة:</strong> (صافي مبيعات كل منتجات الصنف بعد المرتجعات) − (صافي تكلفتها الفعلية
    وقت البيع)، مجمّعة بشكل صحيح لكل منتجات الصنف.
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
    <div class="card-header">تفاصيل الربح حسب الصنف</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>الصنف</th><th>الكمية المباعة</th><th>صافي المبيعات</th><th>صافي التكلفة</th><th>الربح</th><th>نسبة من إجمالي الربح</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $share = $total_profit != 0 ? ($r['profit']/$total_profit*100) : 0; ?>
                <tr>
                    <td><?= e($r['category_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?></td>
                    <td><?= money($r['net_revenue']) ?></td>
                    <td><?= money($r['net_cost']) ?></td>
                    <td class="<?= $r['profit']>=0?'text-success':'text-danger' ?> fw-bold"><?= money($r['profit']) ?></td>
                    <td style="min-width:140px">
                        <div class="d-flex align-items-center gap-2">
                            <div class="flex-grow-1 bg-light rounded" style="height:8px;">
                                <div class="<?= $share>=0?'bg-success':'bg-danger' ?> rounded" style="height:8px;width:<?= min(100,abs($share)) ?>%"></div>
                            </div>
                            <small><?= number_format($share,1) ?>%</small>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد بيانات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
