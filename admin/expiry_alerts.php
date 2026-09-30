<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$days_ahead = (int)($_GET['days_ahead'] ?? 30);

$rows = $pdo->prepare("
    SELECT sb.*, p.name product_name, p.unit, c.name category_name,
           DATEDIFF(sb.expiry_date, CURDATE()) days_left
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE sb.expiry_date IS NOT NULL AND sb.quantity > 0
      AND sb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ORDER BY sb.expiry_date ASC
");
$rows->execute([$days_ahead]);
$rows = $rows->fetchAll();

$expired = array_values(array_filter($rows, fn($r) => $r['days_left'] < 0));
$soon = array_values(array_filter($rows, fn($r) => $r['days_left'] >= 0));
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">تنبيه الصلاحية</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-info no-print">
    <i class="fa-solid fa-circle-info"></i>
    يعرض كل دفعة (بالمخزن المؤقت أو مخزن البيع) لها تاريخ صلاحية مُدخل وتقترب من الانتهاء أو
    انتهت بالفعل. حدّد تاريخ الصلاحية عند تسجيل فاتورة الشراء ليعمل هذا التقرير.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <label class="form-label">التنبيه قبل الانتهاء بعدد أيام</label>
                <input type="number" name="days_ahead" class="form-control" value="<?= $days_ahead ?>" min="1">
            </div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100">تحديث</button></div>
        </form>
    </div>
</div>

<?php if (!empty($expired)): ?>
<div class="card mb-4">
    <div class="card-header bg-danger text-white">منتهية الصلاحية بالفعل (<?= count($expired) ?>)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>الصنف</th><th>المنتج</th><th>الموقع</th><th>الكمية</th><th>تاريخ الانتهاء</th><th>منذ كم يوم</th></tr></thead>
            <tbody>
            <?php foreach ($expired as $r): ?>
                <tr class="table-danger">
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= $r['location']==='selling' ? 'مخزن البيع' : 'المخزن المؤقت' ?></td>
                    <td><?= rtrim(rtrim(number_format($r['quantity'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= e($r['expiry_date']) ?></td>
                    <td class="fw-bold">منذ <?= abs($r['days_left']) ?> يوم</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-warning-subtle">تقترب من الانتهاء خلال <?= $days_ahead ?> يوم (<?= count($soon) ?>)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>الصنف</th><th>المنتج</th><th>الموقع</th><th>الكمية</th><th>تاريخ الانتهاء</th><th>الأيام المتبقية</th></tr></thead>
            <tbody>
            <?php foreach ($soon as $r): ?>
                <tr class="<?= $r['days_left'] <= 7 ? 'table-warning' : '' ?>">
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= $r['location']==='selling' ? 'مخزن البيع' : 'المخزن المؤقت' ?></td>
                    <td><?= rtrim(rtrim(number_format($r['quantity'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= e($r['expiry_date']) ?></td>
                    <td class="fw-bold"><?= $r['days_left'] ?> يوم</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($soon)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد دفعات تقترب من الانتهاء</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
