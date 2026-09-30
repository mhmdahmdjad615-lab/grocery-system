<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$admin = is_admin();
$stats = get_dashboard_stats($pdo);
$categories = get_nav_categories();

// شارات تنبيه صغيرة على المربعات (تُحسب فقط للمدير لأنها بيانات تشغيلية/مالية)
$tileBadges = [];
if ($admin) {
    $tileBadges['inventory'] = $stats['low_stock'] > 0 ? $stats['low_stock'] : null;
    $tileBadges['finance'] = null;
    $overdue = $pdo->query("SELECT COUNT(DISTINCT customer_id) c FROM sale_invoices WHERE (total - paid) > 0.001 AND invoice_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch()['c'];
    if ($overdue > 0) $tileBadges['finance'] = $overdue;

    $pendingStoreOrders = (int)$pdo->query("SELECT COUNT(*) c FROM store_orders WHERE status='pending'")->fetch()['c'];
    $pendingStoreCustomers = (int)$pdo->query("SELECT COUNT(*) c FROM store_customers WHERE status='pending'")->fetch()['c'];
    $tileBadges['store'] = ($pendingStoreOrders + $pendingStoreCustomers) > 0 ? ($pendingStoreOrders + $pendingStoreCustomers) : null;
}

// بيانات الرسوم البيانية (للمدير فقط - بيانات مالية)
$salesTrend = [];
$topProductsChart = [];
if ($admin) {
    $trendStmt = $pdo->prepare("
        SELECT invoice_date, SUM(total) total FROM sale_invoices
        WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY invoice_date
    ");
    $trendStmt->execute();
    $trendByDate = [];
    foreach ($trendStmt->fetchAll() as $r) { $trendByDate[$r['invoice_date']] = (float)$r['total']; }
    for ($d = 13; $d >= 0; $d--) {
        $date = date('Y-m-d', strtotime("-{$d} days"));
        $salesTrend[] = ['date' => date('d/m', strtotime($date)), 'total' => $trendByDate[$date] ?? 0];
    }

    $topProductsStmt = $pdo->query("
        SELECT p.name, SUM(si.total) revenue
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id JOIN products p ON p.id = si.product_id
        WHERE s.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id ORDER BY revenue DESC LIMIT 5
    ");
    $topProductsChart = $topProductsStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الرئيسية - نظام حسابات ومخازن</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="hub-wrapper">
    <header class="hub-topbar">
        <div class="brand"><i class="fa-solid fa-boxes-stacked"></i> محل الجملة</div>
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-success-subtle text-success" id="liveBadge"><i class="fa-solid fa-circle" style="font-size:8px"></i> تحديث حي</span>
            <span class="text-muted"><i class="fa-solid fa-user"></i> <?= e($_SESSION['full_name'] ?? '') ?></span>
            <a href="logout.php" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
        </div>
    </header>

    <div class="hub-content">
        <?php $f = get_flash(); if ($f): ?>
            <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show">
                <?= e($f['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="section-title"><i class="fa-solid fa-grip"></i> الأقسام</div>
        <div class="hub-tiles">
            <?php foreach ($categories as $key => $cat):
                $visible = get_visible_pages_in_category($cat);
                if (empty($visible)) continue;
                $badge = $tileBadges[$key] ?? null;
            ?>
            <a href="<?= e($cat['landing']) ?>" class="hub-tile">
                <?php if ($badge): ?><span class="hub-badge"><?= $badge ?></span><?php endif; ?>
                <div class="hub-icon" style="background:<?= e($cat['color']) ?>"><i class="fa-solid <?= e($cat['icon']) ?>"></i></div>
                <div class="hub-label"><?= e($cat['label']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="section-title">
            <i class="fa-solid fa-gauge"></i> نظرة سريعة
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="icon-box" style="background:#1e7e34"><i class="fa-solid fa-cash-register"></i></div>
                    <div><div class="value" id="stat_sales_today"><?= money($stats['sales_today']) ?></div><div class="label">مبيعات اليوم (<span id="stat_sales_today_count"><?= $stats['sales_today_count'] ?></span> فاتورة)</div></div>
                </div>
            </div>
            <?php if ($admin): ?>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-cart-arrow-down"></i></div>
                    <div><div class="value" id="stat_purchases_today"><?= money($stats['purchases_today']) ?></div><div class="label">مشتريات اليوم</div></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="icon-box" id="stat_profit_icon" style="background:<?= $stats['net_profit_month']>=0?'#198754':'#dc3545' ?>"><i class="fa-solid fa-chart-line"></i></div>
                    <div><div class="value" id="stat_net_profit"><?= money($stats['net_profit_month']) ?></div><div class="label">صافي الربح هذا الشهر <i class="fa-solid fa-circle-info text-muted" title="صافي المبيعات بعد المرتجعات ناقص تكلفة البضاعة المباعة فعلياً، زائد/ناقص الإيرادات/النفقات الأخرى (بدون احتساب قيمة المخزون أو المشتريات - راجع: قائمة الأرباح والخسائر)" style="font-size:11px;cursor:help"></i></div></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="icon-box" id="stat_low_stock_icon" style="background:<?= $stats['low_stock']>0?'#dc3545':'#6c757d' ?>"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><div class="value" id="stat_low_stock"><?= $stats['low_stock'] ?></div><div class="label">أصناف منخفضة المخزون</div></div>
                </div>
            </div>
        </div>

        <?php if ($admin): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa-solid fa-chart-line"></i> اتجاه المبيعات (آخر 14 يوماً)</div>
                    <div class="card-body">
                        <canvas id="salesTrendChart" height="220"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa-solid fa-ranking-star"></i> أكثر 5 منتجات مبيعاً (آخر 30 يوماً)</div>
                    <div class="card-body">
                        <canvas id="topProductsChart" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    <div><div class="value" id="stat_customer_debt"><?= money($stats['total_customer_debt']) ?></div><div class="label">مديونية العملاء لنا</div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-scale-unbalanced"></i></div>
                    <div><div class="value" id="stat_supplier_debt"><?= money($stats['total_supplier_debt']) ?></div><div class="label">مستحق للموردين (علينا)</div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-warehouse"></i></div>
                    <div><div class="value" id="stat_inventory_value"><?= money($stats['inventory_value']) ?></div><div class="label">قيمة المخزون الحالي (شراء)</div></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-title"><i class="fa-solid fa-file-invoice"></i> آخر فواتير المبيعات</div>
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>رقم الفاتورة</th><th>العميل</th><th>التاريخ</th><th>الإجمالي</th></tr></thead>
                    <tbody id="recent_sales_body">
                    <?php foreach ($stats['recent_sales'] as $s): ?>
                        <tr>
                            <td><a href="sale_view.php?id=<?= $s['id'] ?>"><?= e($s['invoice_number']) ?></a></td>
                            <td><?= e($s['customer_name']) ?></td>
                            <td><?= e($s['invoice_date']) ?></td>
                            <td><?= money($s['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stats['recent_sales'])): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد بيانات</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($admin): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script>
const DASHBOARD_IS_ADMIN = <?= $admin ? 'true' : 'false' ?>;

function fmtMoney(n) {
    return Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' <?= CURRENCY ?>';
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function renderRecentSales(rows) {
    const body = document.getElementById('recent_sales_body');
    if (!body) return;
    if (!rows || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">لا توجد بيانات</td></tr>';
        return;
    }
    body.innerHTML = rows.map(function (s) {
        return '<tr>' +
            '<td><a href="sale_view.php?id=' + s.id + '">' + s.invoice_number + '</a></td>' +
            '<td>' + s.customer_name + '</td>' +
            '<td>' + s.invoice_date + '</td>' +
            '<td>' + fmtMoney(s.total) + '</td>' +
        '</tr>';
    }).join('');
}

function pulseLiveBadge() {
    const badge = document.getElementById('liveBadge');
    if (!badge) return;
    badge.style.opacity = '0.4';
    setTimeout(function () { badge.style.opacity = '1'; }, 300);
}

function refreshDashboard() {
    fetch('ajax/dashboard_stats.php')
        .then(r => r.json())
        .then(data => {
            setText('stat_sales_today', fmtMoney(data.sales_today));
            setText('stat_sales_today_count', data.sales_today_count);
            setText('stat_low_stock', data.low_stock);
            const lowIcon = document.getElementById('stat_low_stock_icon');
            if (lowIcon) lowIcon.style.background = data.low_stock > 0 ? '#dc3545' : '#6c757d';

            renderRecentSales(data.recent_sales);

            if (DASHBOARD_IS_ADMIN) {
                setText('stat_purchases_today', fmtMoney(data.purchases_today));
                setText('stat_customer_debt', fmtMoney(data.total_customer_debt));
                setText('stat_supplier_debt', fmtMoney(data.total_supplier_debt));
                setText('stat_net_profit', fmtMoney(data.net_profit_month));
                setText('stat_inventory_value', fmtMoney(data.inventory_value));
                const profitIcon = document.getElementById('stat_profit_icon');
                if (profitIcon) profitIcon.style.background = data.net_profit_month >= 0 ? '#198754' : '#dc3545';
            }
            pulseLiveBadge();
        })
        .catch(() => {});
}
setInterval(refreshDashboard, 15000);

<?php if ($admin): ?>
// رسم اتجاه المبيعات
new Chart(document.getElementById('salesTrendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($salesTrend, 'date')) ?>,
        datasets: [{
            label: 'المبيعات',
            data: <?= json_encode(array_column($salesTrend, 'total')) ?>,
            borderColor: '#198754',
            backgroundColor: 'rgba(25,135,84,0.1)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// رسم أكثر المنتجات مبيعاً
new Chart(document.getElementById('topProductsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topProductsChart, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'قيمة المبيعات',
            data: <?= json_encode(array_map('floatval', array_column($topProductsChart, 'revenue'))) ?>,
            backgroundColor: ['#198754','#0d6efd','#fd7e14','#6f42c1','#dc3545'],
            borderRadius: 6,
        }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
<?php endif; ?>
</script>
</body>
</html>
