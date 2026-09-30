<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$platform = get_platform_merchant_stats($pdo);
$monthlyTrend = get_platform_monthly_benefit_trend($pdo, 6);
$topMerchants = get_top_merchants_by_benefit($pdo, 10);

$merchants = $pdo->query("SELECT id, shop_name, owner_name, city FROM store_customers WHERE status = 'approved' ORDER BY shop_name")->fetchAll();
$rows = [];
foreach ($merchants as $m) {
    $savings = get_merchant_savings_info($pdo, $m['id']);
    $credit = get_merchant_credit_info($pdo, $m['id']);
    $badge = get_merchant_badge($pdo, $savings['total_benefit']);
    $rows[] = ['merchant' => $m, 'savings' => $savings, 'credit' => $credit, 'badge' => $badge];
}
usort($rows, fn($a, $b) => $b['savings']['total_benefit'] <=> $a['savings']['total_benefit']);

$maxCityCount = 1;
foreach ($platform['by_city'] as $c) { $maxCityCount = max($maxCityCount, (int)$c['cnt']); }
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0"><i class="fa-solid fa-chart-line"></i> تقرير استفادة التجار وإحصائيات المحافظات</h4>
    <div>
        <a href="export_merchant_benefits.php" class="btn btn-outline-success"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="store_settings.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#0dcaf0"><i class="fa-solid fa-shop"></i></div>
        <div><div class="value"><?= $platform['total_merchants'] ?></div><div class="label">إجمالي عدد التجار</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($platform['total_discounts']) ?></div><div class="label">إجمالي خصومات الفواتير</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#f59e0b"><i class="fa-solid fa-gift"></i></div>
        <div><div class="value"><?= money($platform['total_gift_value']) ?></div><div class="label">إجمالي قيمة الهدايا المجانية</div></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-hand-holding-heart"></i></div>
        <div><div class="value"><?= money($platform['total_benefit']) ?></div><div class="label">إجمالي استفادة كل التجار</div></div></div>
    </div>
</div>

<div class="row g-3 mb-4 no-print">
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa-solid fa-chart-line"></i> الاتجاه الشهري لإجمالي الاستفادة (آخر 6 أشهر)</div>
            <div class="card-body"><canvas id="monthlyTrendChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa-solid fa-ranking-star"></i> أعلى 10 تجار استفادة</div>
            <div class="card-body"><canvas id="topMerchantsChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">توزيع التجار حسب المحافظة (المدينة)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>المحافظة</th><th>عدد التجار</th><th style="width:40%">مقارنة بصرية</th></tr></thead>
            <tbody>
            <?php foreach ($platform['by_city'] as $c): ?>
                <tr>
                    <td><?= e($c['city']) ?></td>
                    <td class="fw-bold"><?= $c['cnt'] ?></td>
                    <td><div class="bg-info rounded" style="height:10px;width:<?= ($c['cnt']/$maxCityCount*100) ?>%"></div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($platform['by_city'])): ?><tr><td colspan="3" class="text-center text-muted py-3">لا يوجد تجار مُفعَّلون بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">استفادة كل تاجر بالتفصيل (الأعلى استفادة أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>التاجر</th><th>الشارة</th><th>المدينة</th><th>خصومات الفواتير</th>
                <th>عدد الهدايا</th><th>قيمة الهدايا</th><th>إجمالي الاستفادة</th>
                <th>سقف الائتمان الحالي</th><th>المطلوب سداده</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $m = $r['merchant']; $s = $r['savings']; $c = $r['credit']; $badge = $r['badge']; ?>
                <tr class="<?= $i===0 && $s['total_benefit']>0 ? 'table-success' : '' ?>">
                    <td><a href="store_merchant_statement.php?id=<?= $m['id'] ?>"><?= e($m['shop_name']) ?></a> <?php if ($i===0 && $s['total_benefit']>0): ?><span class="badge bg-success">الأعلى استفادة</span><?php endif; ?></td>
                    <td><span class="badge" style="background:<?= $badge['color'] ?>;color:#fff"><i class="fa-solid <?= $badge['icon'] ?>"></i> <?= e($badge['label']) ?></span></td>
                    <td><?= e($m['city'] ?: '-') ?></td>
                    <td><?= money($s['total_discounts']) ?></td>
                    <td><?= $s['gift_count'] ?></td>
                    <td><?= money($s['gift_value']) ?></td>
                    <td class="fw-bold text-success"><?= money($s['total_benefit']) ?></td>
                    <td><?= money($c['credit_limit']) ?></td>
                    <td class="<?= $c['due_from_merchant']>0?'text-danger fw-bold':'' ?>"><?= money($c['due_from_merchant']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="9" class="text-center text-muted py-3">لا توجد بيانات بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('monthlyTrendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthlyTrend, 'ym')) ?>,
        datasets: [
            {
                label: 'خصومات الفواتير',
                data: <?= json_encode(array_map('floatval', array_column($monthlyTrend, 'discounts'))) ?>,
                borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', fill: true, tension: .35,
            },
            {
                label: 'قيمة الهدايا',
                data: <?= json_encode(array_map('floatval', array_column($monthlyTrend, 'gifts'))) ?>,
                borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.1)', fill: true, tension: .35,
            },
        ]
    },
    options: { scales: { y: { beginAtZero: true } } }
});

new Chart(document.getElementById('topMerchantsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topMerchants, 'shop_name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'إجمالي الاستفادة',
            data: <?= json_encode(array_map('floatval', array_column($topMerchants, 'total_benefit'))) ?>,
            backgroundColor: '#6f42c1', borderRadius: 6,
        }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
