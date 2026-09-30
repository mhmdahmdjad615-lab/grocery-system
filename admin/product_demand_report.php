<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-3 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

$days = (strtotime($to) - strtotime($from)) / 86400 + 1;
if ($days < 1) $days = 1;

$rows = $pdo->prepare("
    SELECT p.id, p.name, c.name category_name, p.unit,
           SUM(si.quantity) qty_sold,
           COUNT(DISTINCT s.invoice_date) days_with_sales
    FROM sale_items si
    JOIN sale_invoices s ON s.id = si.invoice_id
    JOIN products p ON p.id = si.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE s.invoice_date BETWEEN ? AND ?
    GROUP BY p.id
    ORDER BY qty_sold DESC
");
$rows->execute([$from, $to]);
$rows = $rows->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">متوسط الطلب اليومي / الأسبوعي / الشهري لكل منتج</h4>
    <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    المتوسط اليومي = إجمالي الكمية المباعة خلال الفترة ÷ عدد أيام الفترة (<?= (int)$days ?> يوم).
    الأسبوعي = اليومي × 7، والشهري = اليومي × 30. يساعدك هذا في تقدير الكمية المناسبة لطلبها من
    المورد في المرة القادمة.
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
    <div class="card-header">التفاصيل حسب المنتج (الفترة: <?= (int)$days ?> يوم)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>إجمالي الكمية المباعة</th>
                <th>متوسط يومي</th><th>متوسط أسبوعي</th><th>متوسط شهري</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $daily = $r['qty_sold'] / $days;
                $weekly = $daily * 7;
                $monthly = $daily * 30;
            ?>
                <tr>
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['qty_sold'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><strong><?= number_format($daily,2) ?></strong> <?= e($r['unit']) ?></td>
                    <td><?= number_format($weekly,2) ?> <?= e($r['unit']) ?></td>
                    <td><?= number_format($monthly,2) ?> <?= e($r['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد مبيعات بهذه الفترة</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
