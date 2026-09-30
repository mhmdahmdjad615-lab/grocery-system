<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// أعمار ديون العملاء: لكل عميل له رصيد، نجمع فواتيره غير المسددة بالكامل ونصنفها
// حسب عدد الأيام منذ تاريخ كل فاتورة إلى شرائح عمرية
function build_aging($pdo, $type) {
    if ($type === 'customer') {
        $rows = $pdo->query("
            SELECT c.id party_id, c.name party_name, s.invoice_date, (s.total - s.paid) remaining
            FROM sale_invoices s JOIN customers c ON c.id = s.customer_id
            WHERE (s.total - s.paid) > 0.001
        ")->fetchAll();
    } else {
        $rows = $pdo->query("
            SELECT sup.id party_id, sup.name party_name, p.invoice_date, (p.total - p.paid) remaining
            FROM purchase_invoices p JOIN suppliers sup ON sup.id = p.supplier_id
            WHERE (p.total - p.paid) > 0.001
        ")->fetchAll();
    }

    $buckets = [];
    foreach ($rows as $r) {
        $days = (int)((strtotime(date('Y-m-d')) - strtotime($r['invoice_date'])) / 86400);
        if ($days <= 30) $bucket = '0-30';
        elseif ($days <= 60) $bucket = '31-60';
        elseif ($days <= 90) $bucket = '61-90';
        else $bucket = '90+';

        if (!isset($buckets[$r['party_id']])) {
            $buckets[$r['party_id']] = ['name' => $r['party_name'], '0-30'=>0, '31-60'=>0, '61-90'=>0, '90+'=>0, 'total'=>0];
        }
        $buckets[$r['party_id']][$bucket] += $r['remaining'];
        $buckets[$r['party_id']]['total'] += $r['remaining'];
    }
    uasort($buckets, fn($a, $b) => $b['total'] <=> $a['total']);
    return $buckets;
}

$customerAging = build_aging($pdo, 'customer');
$supplierAging = build_aging($pdo, 'supplier');

$sumBucket = function ($buckets, $key) {
    return array_sum(array_column($buckets, $key));
};
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">تقرير أعمار الديون</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    يوضح منذ متى المبلغ مستحق (بالفواتير غير المسددة بالكامل)، ليساعدك على تحديد أولويات
    التحصيل من العملاء (الأقدم أولى بالمتابعة) وأولويات السداد للموردين قبل تأخر أكثر.
</div>

<div class="card mb-4">
    <div class="card-header text-danger">أعمار مديونية العملاء (مستحق لنا)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>العميل</th><th>0-30 يوم</th><th>31-60 يوم</th><th>61-90 يوم</th>
                <th class="table-danger">أكثر من 90 يوم</th><th>الإجمالي</th>
            </tr></thead>
            <tbody>
            <?php foreach ($customerAging as $pid => $b): ?>
                <tr>
                    <td><a href="customer_statement.php?id=<?= $pid ?>"><?= e($b['name']) ?></a></td>
                    <td><?= $b['0-30']>0 ? money($b['0-30']) : '-' ?></td>
                    <td><?= $b['31-60']>0 ? money($b['31-60']) : '-' ?></td>
                    <td><?= $b['61-90']>0 ? money($b['61-90']) : '-' ?></td>
                    <td class="<?= $b['90+']>0?'text-danger fw-bold':'' ?>"><?= $b['90+']>0 ? money($b['90+']) : '-' ?></td>
                    <td class="fw-bold"><?= money($b['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customerAging)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد ديون مستحقة على العملاء</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($customerAging)): ?>
            <tfoot><tr class="fw-bold table-light">
                <td>الإجمالي</td>
                <td><?= money($sumBucket($customerAging,'0-30')) ?></td>
                <td><?= money($sumBucket($customerAging,'31-60')) ?></td>
                <td><?= money($sumBucket($customerAging,'61-90')) ?></td>
                <td class="text-danger"><?= money($sumBucket($customerAging,'90+')) ?></td>
                <td><?= money($sumBucket($customerAging,'total')) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header text-primary">أعمار مستحقات الموردين (علينا)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>المورد</th><th>0-30 يوم</th><th>31-60 يوم</th><th>61-90 يوم</th>
                <th class="table-danger">أكثر من 90 يوم</th><th>الإجمالي</th>
            </tr></thead>
            <tbody>
            <?php foreach ($supplierAging as $pid => $b): ?>
                <tr>
                    <td><a href="supplier_statement.php?id=<?= $pid ?>"><?= e($b['name']) ?></a></td>
                    <td><?= $b['0-30']>0 ? money($b['0-30']) : '-' ?></td>
                    <td><?= $b['31-60']>0 ? money($b['31-60']) : '-' ?></td>
                    <td><?= $b['61-90']>0 ? money($b['61-90']) : '-' ?></td>
                    <td class="<?= $b['90+']>0?'text-danger fw-bold':'' ?>"><?= $b['90+']>0 ? money($b['90+']) : '-' ?></td>
                    <td class="fw-bold"><?= money($b['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($supplierAging)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد مستحقات للموردين</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($supplierAging)): ?>
            <tfoot><tr class="fw-bold table-light">
                <td>الإجمالي</td>
                <td><?= money($sumBucket($supplierAging,'0-30')) ?></td>
                <td><?= money($sumBucket($supplierAging,'31-60')) ?></td>
                <td><?= money($sumBucket($supplierAging,'61-90')) ?></td>
                <td class="text-danger"><?= money($sumBucket($supplierAging,'90+')) ?></td>
                <td><?= money($sumBucket($supplierAging,'total')) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
