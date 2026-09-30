<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-6 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// أداء عام لكل مورد: عدد الفواتير، إجمالي المشتريات، متوسط قيمة الفاتورة، الرصيد المستحق له
$suppliers = $pdo->prepare("
    SELECT s.id, s.name, s.phone, s.balance,
           COUNT(pi.id) invoices_count,
           COALESCE(SUM(pi.total),0) total_purchases,
           COALESCE(AVG(pi.total),0) avg_invoice,
           COALESCE(SUM(pii.quantity),0) total_qty_supplied
    FROM suppliers s
    LEFT JOIN purchase_invoices pi ON pi.supplier_id = s.id AND pi.invoice_date BETWEEN ? AND ?
    LEFT JOIN purchase_items pii ON pii.invoice_id = pi.id
    GROUP BY s.id
    ORDER BY total_purchases DESC
");
$suppliers->execute([$from, $to]);
$suppliers = $suppliers->fetchAll();

// أفضل مورد بالفترة = الأعلى تعاملاً (حجم مشتريات) ضمن من لديهم فواتير
$bestBySpend = null;
foreach ($suppliers as $s) {
    if ($s['invoices_count'] > 0) { $bestBySpend = $s; break; }
}

// مقارنة أسعار المنتجات المشتركة بين الموردين: لكل منتج، متوسط سعر الشراء من كل مورد
$priceCompare = $pdo->prepare("
    SELECT pr.id product_id, pr.name product_name, s.id supplier_id, s.name supplier_name,
           AVG(pii.price) avg_price, SUM(pii.quantity) total_qty
    FROM purchase_items pii
    JOIN purchase_invoices pi ON pi.id = pii.invoice_id
    JOIN suppliers s ON s.id = pi.supplier_id
    JOIN products pr ON pr.id = pii.product_id
    WHERE pi.invoice_date BETWEEN ? AND ?
    GROUP BY pr.id, s.id
    ORDER BY pr.name, avg_price ASC
");
$priceCompare->execute([$from, $to]);
$priceRows = $priceCompare->fetchAll();

// تجميع حسب المنتج لإظهار أرخص مورد لكل منتج
$byProduct = [];
foreach ($priceRows as $r) {
    $byProduct[$r['product_id']]['name'] = $r['product_name'];
    $byProduct[$r['product_id']]['suppliers'][] = $r;
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">تحليل الموردين - من هو أفضل مورد؟</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
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

<?php if ($bestBySpend): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-trophy"></i>
    أكثر مورد تعاملاً معه بالفترة المحددة هو <strong><?= e($bestBySpend['name']) ?></strong>
    بإجمالي مشتريات <strong><?= money($bestBySpend['total_purchases']) ?></strong>
    عبر <?= $bestBySpend['invoices_count'] ?> فاتورة.
    راجع جدول "مقارنة الأسعار" أدناه لمعرفة أرخص مورد لكل منتج على حدة.
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">أداء الموردين (مرتبة حسب إجمالي المشتريات)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>المورد</th><th>عدد الفواتير</th><th>إجمالي المشتريات</th>
                <th>متوسط قيمة الفاتورة</th><th>الكمية الموردة</th><th>الرصيد المستحق له الآن</th>
            </tr></thead>
            <tbody>
            <?php foreach ($suppliers as $i => $s): ?>
                <tr class="<?= $i===0 && $s['invoices_count']>0 ? 'table-success' : '' ?>">
                    <td>
                        <a href="supplier_statement.php?id=<?= $s['id'] ?>"><?= e($s['name']) ?></a>
                        <?php if ($i===0 && $s['invoices_count']>0): ?><span class="badge bg-success">الأعلى تعاملاً</span><?php endif; ?>
                    </td>
                    <td><?= $s['invoices_count'] ?></td>
                    <td><?= money($s['total_purchases']) ?></td>
                    <td><?= money($s['avg_invoice']) ?></td>
                    <td><?= rtrim(rtrim(number_format($s['total_qty_supplied'],2),'0'),'.') ?></td>
                    <td class="<?= $s['balance']>0?'text-danger fw-bold':'' ?>"><?= money($s['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($suppliers)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا يوجد موردين</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">مقارنة أسعار الشراء بين الموردين لكل منتج (الأرخص أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>المنتج</th><th>المورد</th><th>متوسط سعر الشراء</th><th>الكمية المشتراة منه</th></tr></thead>
            <tbody>
            <?php foreach ($byProduct as $pid => $data): ?>
                <?php foreach ($data['suppliers'] as $idx => $s): ?>
                    <tr class="<?= $idx===0 ? 'table-success' : '' ?>">
                        <td><?= $idx===0 ? e($data['name']) : '' ?></td>
                        <td>
                            <?= e($s['supplier_name']) ?>
                            <?php if ($idx===0 && count($data['suppliers'])>1): ?><span class="badge bg-success">الأرخص</span><?php endif; ?>
                        </td>
                        <td><?= money($s['avg_price']) ?></td>
                        <td><?= rtrim(rtrim(number_format($s['total_qty'],2),'0'),'.') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (empty($byProduct)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد بيانات مشتريات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
