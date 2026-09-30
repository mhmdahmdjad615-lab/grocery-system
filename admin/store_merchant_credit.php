<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int)$_POST['id'];
    if ($_POST['action'] === 'set_override') {
        $pct = (float)($_POST['credit_percentage'] ?? -1);
        if ($pct < 0 || $pct > 100) {
            flash('النسبة يجب أن تكون بين 0 و100', 'danger');
        } else {
            set_merchant_credit_percentage($pdo, $id, $pct);
            flash('تم حفظ نسبة الائتمان الخاصة بهذا التاجر');
        }
    } elseif ($_POST['action'] === 'clear_override') {
        clear_merchant_credit_override($pdo, $id);
        flash('تم إلغاء النسبة الخاصة، وسيُستخدم الإعداد العام الآن');
    }
    redirect('store_merchant_credit.php');
}

$defaultPct = (float)get_store_setting($pdo, 'credit_limit_percentage', 20);

$merchants = $pdo->query("
    SELECT sc.*, c.balance
    FROM store_customers sc
    LEFT JOIN customers c ON c.id = sc.customer_id
    WHERE sc.status = 'approved'
    ORDER BY sc.shop_name
")->fetchAll();

$overrides = [];
foreach ($pdo->query("SELECT * FROM merchant_credit_overrides")->fetchAll() as $o) {
    $overrides[$o['store_customer_id']] = (float)$o['credit_percentage'];
}

$rows = [];
foreach ($merchants as $m) {
    $info = get_merchant_credit_info($pdo, $m['id']);
    $savings = get_merchant_savings_info($pdo, $m['id']);
    $alert = get_merchant_credit_alert($pdo, $m['id'], $info);
    $badge = get_merchant_badge($pdo, $savings['total_benefit']);
    $rows[] = ['merchant' => $m, 'info' => $info, 'savings' => $savings, 'alert' => $alert, 'badge' => $badge, 'has_override' => isset($overrides[$m['id']])];
}
// الأعلى سقف ائتمان أولاً
usort($rows, fn($a, $b) => $b['info']['credit_limit'] <=> $a['info']['credit_limit']);

$totalCreditLimit = array_sum(array_column(array_column($rows, 'info'), 'credit_limit'));
$totalDue = array_sum(array_column(array_column($rows, 'info'), 'due_from_merchant'));
$nearLimitCount = count(array_filter($rows, fn($r) => $r['alert']['is_near_limit'] || $r['alert']['is_over_limit']));
$activePeriod = get_credit_calculation_period($pdo);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fa-solid fa-credit-card"></i> رصيد ائتمان التجار</h4>
    <a href="store_settings.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للإعدادات</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    سقف الائتمان المتاح لكل تاجر = <strong>الإيراد الذي حققته المنصة من مشترياته</strong> (الفرق بين سعر شراء
    المنصة للمنتج من المورد وسعر بيعه للتاجر عبر كل فواتيره الحقيقية) × <strong>نسبة الائتمان</strong>
    (العامة من <a href="store_settings.php">الإعدادات</a>، أو نسبة خاصة تُحدَّد له هنا).
    <br>
    <i class="fa-solid fa-calendar-days"></i>
    الفترة المعتمدة حالياً لحساب الإيراد: <strong><?= e(get_credit_period_label($activePeriod)) ?></strong>
    — <a href="store_settings.php">تغيير الفترة من الإعدادات</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#0dcaf0"><i class="fa-solid fa-shop"></i></div>
        <div><div class="value"><?= count($rows) ?></div><div class="label">تاجر مُفعَّل</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-credit-card"></i></div>
        <div><div class="value"><?= money($totalCreditLimit) ?></div><div class="label">إجمالي سقوف الائتمان الممنوحة</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div><div class="value"><?= money($totalDue) ?></div><div class="label">إجمالي المطلوب سداده من التجار</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-percent"></i></div>
        <div><div class="value"><?= number_format($defaultPct,1) ?>%</div><div class="label">النسبة العامة الحالية</div></div></div>
    </div>
</div>

<?php if ($nearLimitCount > 0): ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    يوجد <strong><?= $nearLimitCount ?></strong> تاجر قريب من استنفاد سقف ائتمانه أو تجاوزه (مُميَّزون أدناه). قد يحتاجون تنبيهاً للسداد أو مراجعة سقفهم.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">تفاصيل كل تاجر</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>التاجر</th><th>الإيراد المحقق منه</th><th>النسبة</th><th>سقف الائتمان</th>
                <th>المطلوب سداده</th><th>مستحق له</th><th>المتاح للطلب الآن</th><th>تحديد نسبة خاصة</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $m = $r['merchant']; $info = $r['info']; $alert = $r['alert']; $badge = $r['badge']; ?>
                <tr class="<?= $alert['is_over_limit'] ? 'table-danger' : ($alert['is_near_limit'] ? 'table-warning' : '') ?>">
                    <td>
                        <a href="store_merchant_statement.php?id=<?= $m['id'] ?>"><?= e($m['shop_name']) ?></a>
                        <br><span class="badge" style="background:<?= $badge['color'] ?>;color:#fff"><i class="fa-solid <?= $badge['icon'] ?>"></i> <?= e($badge['label']) ?></span>
                        <?php if ($alert['is_over_limit']): ?><br><span class="badge bg-danger mt-1"><i class="fa-solid fa-circle-exclamation"></i> تجاوز الحد</span>
                        <?php elseif ($alert['is_near_limit']): ?><br><span class="badge bg-warning text-dark mt-1"><i class="fa-solid fa-triangle-exclamation"></i> قريب من الحد (<?= number_format($alert['used_percentage'],0) ?>%)</span><?php endif; ?>
                        <?php if ($m['customer_id']): ?><br><small><a href="customer_statement.php?id=<?= $m['customer_id'] ?>">كشف الحساب المحاسبي</a></small><?php endif; ?>
                    </td>
                    <td><?= money($info['revenue_generated']) ?></td>
                    <td>
                        <?= number_format($info['percentage'],1) ?>%
                        <?php if ($r['has_override']): ?><span class="badge bg-primary">خاصة</span><?php else: ?><span class="badge bg-secondary">عامة</span><?php endif; ?>
                    </td>
                    <td class="fw-bold"><?= money($info['credit_limit']) ?></td>
                    <td class="<?= $info['due_from_merchant']>0?'text-danger fw-bold':'' ?>"><?= money($info['due_from_merchant']) ?></td>
                    <td class="<?= $info['due_to_merchant']>0?'text-success fw-bold':'' ?>"><?= money($info['due_to_merchant']) ?></td>
                    <td class="text-success fw-bold"><?= money($info['available_credit']) ?></td>
                    <td style="min-width:220px">
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="action" value="set_override">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <input type="number" step="0.01" min="0" max="100" name="credit_percentage"
                                   class="form-control form-control-sm" style="width:80px"
                                   value="<?= $r['has_override'] ? number_format($overrides[$m['id']],2) : '' ?>" placeholder="%">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-check"></i></button>
                        </form>
                        <?php if ($r['has_override']): ?>
                        <form method="post" class="mt-1">
                            <input type="hidden" name="action" value="clear_override">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">إلغاء النسبة الخاصة</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا يوجد تجار مُفعَّلون بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
