<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT sc.*, c.balance FROM store_customers sc LEFT JOIN customers c ON c.id = sc.customer_id WHERE sc.id = ?");
$stmt->execute([$id]);
$merchant = $stmt->fetch();
if (!$merchant) { flash('التاجر غير موجود', 'danger'); redirect('store_merchant_credit.php'); }

$creditInfo = get_merchant_credit_info($pdo, $id);
$savingsInfo = get_merchant_savings_info($pdo, $id);
$alert = get_merchant_credit_alert($pdo, $id, $creditInfo);
$badge = get_merchant_badge($pdo, $savingsInfo['total_benefit']);
$statement = get_merchant_credit_statement($pdo, $id);

$typeLabels = [
    'sale' => ['bg-white', ''],
    'gift' => ['bg-warning-subtle', '🎁 '],
    'gift_cancelled' => ['bg-light text-muted', '🚫 '],
    'payment' => ['', ''],
];
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">كشف حساب التاجر: <?= e($merchant['shop_name']) ?></h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="store_merchant_credit.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="badge mb-2" style="background:<?= $badge['color'] ?>;color:#fff"><i class="fa-solid <?= $badge['icon'] ?>"></i> <?= e($badge['label']) ?></span>
                <p class="mb-1"><strong>المسؤول:</strong> <?= e($merchant['owner_name']) ?></p>
                <p class="mb-1"><strong>الهاتف:</strong> <?= e($merchant['phone']) ?></p>
                <p class="mb-0"><strong>المدينة:</strong> <?= e($merchant['city'] ?: '-') ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card h-100"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-credit-card"></i></div>
        <div><div class="value"><?= money($creditInfo['credit_limit']) ?></div><div class="label">سقف الائتمان (<?= number_format($creditInfo['percentage'],1) ?>%)</div></div></div>
    </div>
    <div class="col-md-3">
        <div class="stat-card h-100"><div class="icon-box" style="background:<?= $creditInfo['due_from_merchant']>0?'#dc3545':'#6c757d' ?>"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div><div class="value"><?= money($creditInfo['due_from_merchant']) ?></div><div class="label">المطلوب سداده</div></div></div>
    </div>
    <div class="col-md-3">
        <div class="stat-card h-100"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-hand-holding-heart"></i></div>
        <div><div class="value"><?= money($savingsInfo['total_benefit']) ?></div><div class="label">إجمالي الاستفادة</div></div></div>
    </div>
</div>

<div class="alert alert-secondary py-2">
    <i class="fa-solid fa-calendar-days"></i>
    الإيراد المحقق (<?= money($creditInfo['revenue_generated']) ?>) المستخدَم لحساب سقف الائتمان أعلاه محسوب
    <strong><?= e(get_credit_period_label($creditInfo['period'])) ?></strong> —
    <a href="store_settings.php">تغيير الفترة من الإعدادات</a>
</div>

<?php if ($alert['is_over_limit'] || $alert['is_near_limit']): ?>
<div class="alert <?= $alert['is_over_limit'] ? 'alert-danger' : 'alert-warning' ?>">
    <i class="fa-solid fa-triangle-exclamation"></i>
    هذا التاجر <?= $alert['is_over_limit'] ? 'تجاوز' : 'قريب من' ?> سقف ائتمانه (مستخدَم <?= number_format($alert['used_percentage'],1) ?>% من السقف).
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">خصومات الفواتير</div>
                <div class="fs-5 fw-bold"><?= money($savingsInfo['total_discounts']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">عدد الهدايا المجانية</div>
                <div class="fs-5 fw-bold">🎁 <?= $savingsInfo['gift_count'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">قيمة الهدايا المجانية</div>
                <div class="fs-5 fw-bold"><?= money($savingsInfo['gift_value']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">كشف الحركة الكامل (فواتير بيع، هدايا، مدفوعات)</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>التاريخ</th><th>البيان</th><th>مدين (عليه)</th><th>دائن (له)</th><th>الرصيد التراكمي</th></tr></thead>
            <tbody>
            <?php foreach ($statement as $r): $meta = $typeLabels[$r['type']]; ?>
                <tr class="<?= $meta[0] ?>">
                    <td><?= e($r['date']) ?></td>
                    <td><?= $meta[1] ?><?= $r['link'] ? '<a href="'.$r['link'].'">'.e($r['desc']).'</a>' : e($r['desc']) ?></td>
                    <td><?= $r['debit'] > 0 ? money($r['debit']) : '-' ?></td>
                    <td><?= $r['credit'] > 0 ? money($r['credit']) : '-' ?></td>
                    <td class="fw-bold"><?= money($r['running_balance']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($statement)): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد حركات بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
