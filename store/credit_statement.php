<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();
$customer = store_current_customer($pdo);

$creditInfo = get_merchant_credit_info($pdo, $customer['id']);
$savingsInfo = get_merchant_savings_info($pdo, $customer['id']);
$creditAlert = get_merchant_credit_alert($pdo, $customer['id'], $creditInfo);
$merchantBadge = get_merchant_badge($pdo, $savingsInfo['total_benefit']);
$statement = get_merchant_credit_statement($pdo, $customer['id']);

$typeMeta = [
    'sale' => ['', ''],
    'gift' => ['background:var(--p-50)', '🎁 '],
    'gift_cancelled' => ['background:var(--g-100);color:var(--g-500)', '🚫 '],
    'payment' => ['', ''],
];

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h1 class="h1 mb-0"><i class="fa-solid fa-file-invoice-dollar text-gold"></i> كشف حساب الائتمان</h1>
        <a href="dashboard.php" class="btn btn-outline btn-sm">رجوع للوحة التحكم</a>
    </div>

    <?php if ($creditAlert['is_over_limit'] || $creditAlert['is_near_limit']): ?>
    <div class="gcard mb-4" style="padding:16px 20px;background:<?= $creditAlert['is_over_limit'] ? '#fef2f2' : 'var(--p-50)' ?>;border-color:<?= $creditAlert['is_over_limit'] ? '#fca5a5' : 'var(--p-100)' ?>">
        <i class="fa-solid fa-triangle-exclamation" style="color:<?= $creditAlert['is_over_limit'] ? 'var(--c-danger)' : 'var(--p-600)' ?>"></i>
        <strong><?= $creditAlert['is_over_limit'] ? 'لقد تجاوزت سقف الائتمان المتاح لك' : 'أنت تستخدم ' . number_format($creditAlert['used_percentage'],0) . '% من سقف ائتمانك' ?></strong>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">سقف الائتمان</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--p-700)"><?= money($creditInfo['credit_limit']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">المتاح الآن</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--c-success)"><?= money($creditInfo['available_credit']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">المطلوب سداده</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:<?= $creditInfo['due_from_merchant']>0?'var(--c-danger)':'var(--g-500)' ?>"><?= money($creditInfo['due_from_merchant']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">شارتك الحالية</div>
                <div class="fw-bold" style="font-size:15px;color:<?= $merchantBadge['color'] ?>"><i class="fa-solid <?= $merchantBadge['icon'] ?>"></i> <?= e($merchantBadge['label']) ?></div>
            </div>
        </div>
    </div>

    <div class="gcard mb-3" style="padding:12px 16px;background:var(--g-50)">
        <span class="text-xs text-muted-store"><i class="fa-solid fa-calendar-days"></i> الإيراد المحقق (<?= money($creditInfo['revenue_generated']) ?>) المستخدَم لحساب سقف الائتمان محسوب <strong><?= e(get_credit_period_label($creditInfo['period'])) ?></strong></span>
    </div>

    <div class="gcard">
        <div class="gcard-header"><i class="fa-solid fa-list"></i> كل الحركات (فواتير بيع، هدايا مجانية، مدفوعات)</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>التاريخ</th><th>البيان</th><th>عليه</th><th>له</th><th>الرصيد التراكمي</th></tr></thead>
                <tbody>
                <?php foreach ($statement as $r): $meta = $typeMeta[$r['type']]; ?>
                    <tr style="<?= $meta[0] ?>">
                        <td class="text-sm"><?= e($r['date']) ?></td>
                        <td class="text-sm"><?= $meta[1] . e($r['desc']) ?></td>
                        <td class="tabular-num"><?= $r['debit'] > 0 ? money($r['debit']) : '-' ?></td>
                        <td class="tabular-num text-success"><?= $r['credit'] > 0 ? money($r['credit']) : '-' ?></td>
                        <td class="tabular-num fw-bold"><?= money($r['running_balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($statement)): ?><tr><td colspan="5" class="text-center text-muted-store py-4">لا توجد حركات بعد</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
