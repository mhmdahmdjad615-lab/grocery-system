<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$horizon_days = (int)($_GET['horizon_days'] ?? 14);

$avgCustomerCycle = 30;
$avgSupplierCycle = 30;

// الفواتير المستحقة من العملاء (لم تُسدَّد بالكامل)
$customerDebts = $pdo->query("
    SELECT c.id, c.name, s.invoice_date, (s.total - s.paid) remaining
    FROM sale_invoices s JOIN customers c ON c.id = s.customer_id
    WHERE (s.total - s.paid) > 0.001
")->fetchAll();

// الفواتير المستحقة للموردين (لم تُسدَّد بالكامل)
$supplierDebts = $pdo->query("
    SELECT s.id, s.name, p.invoice_date, (p.total - p.paid) remaining
    FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id
    WHERE (p.total - p.paid) > 0.001
")->fetchAll();

// توزيع كل مبلغ مستحق على الأيام القادمة بناءً على عمر الفاتورة (كلما كانت أقدم، كلما
// افترضنا أنها ستُحصَّل/تُسدَّد أقرب، لأنها تجاوزت بالفعل الدورة الطبيعية)
function distribute_forecast($debts, $horizonDays, $avgCycle) {
    $buckets = [];
    for ($d = 0; $d < $horizonDays; $d++) { $buckets[$d] = 0; }

    foreach ($debts as $row) {
        $ageDays = (int)((strtotime(date('Y-m-d')) - strtotime($row['invoice_date'])) / 86400);
        $daysUntilExpected = max(0, $avgCycle - $ageDays);
        if ($daysUntilExpected >= $horizonDays) continue;
        $buckets[$daysUntilExpected] += (float)$row['remaining'];
    }
    return $buckets;
}

$inflowBuckets = distribute_forecast($customerDebts, $horizon_days, $avgCustomerCycle);
$outflowBuckets = distribute_forecast($supplierDebts, $horizon_days, $avgSupplierCycle);

$totalCustomerDebt = array_sum(array_column($customerDebts, 'remaining'));
$totalSupplierDebt = array_sum(array_column($supplierDebts, 'remaining'));
$expectedInflowInHorizon = array_sum($inflowBuckets);
$expectedOutflowInHorizon = array_sum($outflowBuckets);

$cash_balance = $pdo->query("SELECT COALESCE(SUM(CASE WHEN type='in' THEN amount ELSE -amount END),0) t FROM cash_movements")->fetch()['t'];

$rows = [];
$runningBalance = $cash_balance;
for ($d = 0; $d < $horizon_days; $d++) {
    $date = date('Y-m-d', strtotime("+{$d} days"));
    $in = $inflowBuckets[$d];
    $out = $outflowBuckets[$d];
    $runningBalance += $in - $out;
    $rows[] = ['date' => $date, 'in' => $in, 'out' => $out, 'net' => $in - $out, 'balance' => $runningBalance];
}
$lastRow = end($rows);
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">توقع التدفق النقدي القادم</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-warning no-print">
    <i class="fa-solid fa-circle-info"></i>
    هذا توقع تقديري وليس مضموناً: يفترض أن العميل يسدد خلال 30 يوماً من الفاتورة في المتوسط (وكلما
    تجاوزت الفاتورة هذه المدة، افترضنا تحصيلها في القريب العاجل)، وكذلك المورد. الهدف مساعدتك على
    توقع نقص السيولة قبل حدوثه، وليس رقماً نهائياً دقيقاً.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <label class="form-label">مدى التوقع (بالأيام)</label>
                <input type="number" name="horizon_days" class="form-control" value="<?= $horizon_days ?>" min="7" max="60">
            </div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100">تحديث</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($cash_balance) ?></div><div class="label">رصيد الخزينة الحالي</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-arrow-down"></i></div>
        <div><div class="value"><?= money($expectedInflowInHorizon) ?></div><div class="label">متوقع تحصيله خلال <?= $horizon_days ?> يوم</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-arrow-up"></i></div>
        <div><div class="value"><?= money($expectedOutflowInHorizon) ?></div><div class="label">متوقع دفعه للموردين خلال <?= $horizon_days ?> يوم</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:<?= $lastRow['balance']>=0?'#198754':'#dc3545' ?>"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="value"><?= money($lastRow['balance']) ?></div><div class="label">الرصيد المتوقع بعد <?= $horizon_days ?> يوم</div></div></div>
    </div>
</div>

<?php $negativeDay = null; foreach ($rows as $r) { if ($r['balance'] < 0) { $negativeDay = $r; break; } } ?>
<?php if ($negativeDay): ?>
<div class="alert alert-danger">
    <i class="fa-solid fa-triangle-exclamation"></i>
    تحذير: الرصيد المتوقع سيصبح <strong>سالباً</strong> بتاريخ <strong><?= e($negativeDay['date']) ?></strong>
    ما لم تُحصِّل ديوناً إضافية أو تؤجل بعض المدفوعات للموردين.
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">الجدول اليومي التراكمي المتوقع</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>التاريخ</th><th>متوقع تحصيله</th><th>متوقع دفعه</th><th>صافي اليوم</th><th>الرصيد التراكمي المتوقع</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['balance']<0?'table-danger':'' ?>">
                    <td><?= e($r['date']) ?></td>
                    <td class="text-success"><?= $r['in']>0 ? money($r['in']) : '-' ?></td>
                    <td class="text-danger"><?= $r['out']>0 ? money($r['out']) : '-' ?></td>
                    <td><?= money($r['net']) ?></td>
                    <td class="fw-bold"><?= money($r['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">إجمالي الديون المستحقة على العملاء (كل الآجال)</div>
            <div class="card-body"><h4 class="text-success mb-0"><?= money($totalCustomerDebt) ?></h4></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">إجمالي المستحق للموردين (كل الآجال)</div>
            <div class="card-body"><h4 class="text-danger mb-0"><?= money($totalSupplierDebt) ?></h4></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
