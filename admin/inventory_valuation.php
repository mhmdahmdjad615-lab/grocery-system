<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$location = $_GET['location'] ?? 'selling';

$sql = "
    SELECT sb.*, p.name product_name, p.unit, c.name category_name
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE sb.location = ? AND sb.quantity > 0
    ORDER BY p.name ASC, sb.created_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$location]);
$rows = $stmt->fetchAll();

$total_purchase_value = array_sum(array_map(fn($r) => $r['quantity'] * $r['purchase_price'], $rows));
$total_sale_value = array_sum(array_map(fn($r) => $r['quantity'] * (float)($r['sale_price'] ?? 0), $rows));
$total_qty = array_sum(array_column($rows, 'quantity'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">جرد المخزون</h4>
    <a href="export_inventory.php?location=<?= e($location) ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    الجرد يتم لكل دفعة على حدة بسعر الشراء وسعر البيع الخاصين بها، لضمان حساب دقيق للأرباح
    حتى مع اختلاف الأسعار بين دفعات نفس المنتج.
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-6">
                <label class="form-label">موقع الجرد</label>
                <select name="location" class="form-select" onchange="this.form.submit()">
                    <option value="selling" <?= $location==='selling'?'selected':'' ?>>مخزن البيع (الكميات المسعّرة الجاهزة للبيع)</option>
                    <option value="temp" <?= $location==='temp'?'selected':'' ?>>المخزن المؤقت (بضاعة بانتظار تحديد سعر البيع)</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#6c757d"><i class="fa-solid fa-cubes"></i></div>
        <div><div class="value"><?= rtrim(rtrim(number_format($total_qty,2),'0'),'.') ?></div><div class="label">إجمالي الكمية (كل الوحدات)</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($total_purchase_value) ?></div><div class="label">القيمة الإجمالية بسعر الشراء</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-tags"></i></div>
        <div><div class="value"><?= $location==='selling' ? money($total_sale_value) : '-' ?></div><div class="label">القيمة الإجمالية بسعر البيع</div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">تفاصيل الجرد لكل دفعة <button class="btn btn-sm btn-outline-secondary float-start no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة</button></div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>الصنف</th><th>المنتج</th><th>الكمية</th><th>سعر الشراء</th>
                <th>القيمة بسعر الشراء</th>
                <?php if ($location==='selling'): ?><th>سعر البيع</th><th>القيمة بسعر البيع</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['category_name'] ?? '-') ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['quantity'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
                    <td><?= money($r['purchase_price']) ?></td>
                    <td><?= money($r['quantity'] * $r['purchase_price']) ?></td>
                    <?php if ($location==='selling'): ?>
                    <td><?= $r['sale_price'] !== null ? money($r['sale_price']) : '-' ?></td>
                    <td><?= money($r['quantity'] * (float)($r['sale_price'] ?? 0)) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد كميات لجردها بهذا الموقع</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
