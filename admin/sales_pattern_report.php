<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-2 months'));
$to   = $_GET['to'] ?? date('Y-m-d');

// حسب يوم الأسبوع (يعتمد على created_at الفعلي وقت إنشاء الفاتورة)
$byDay = $pdo->prepare("
    SELECT DAYOFWEEK(created_at) dow, COUNT(*) invoices_count, SUM(total) total_sales
    FROM sale_invoices
    WHERE invoice_date BETWEEN ? AND ?
    GROUP BY dow
");
$byDay->execute([$from, $to]);
$byDay = $byDay->fetchAll();

$dayNames = [1=>'الأحد', 2=>'الإثنين', 3=>'الثلاثاء', 4=>'الأربعاء', 5=>'الخميس', 6=>'الجمعة', 7=>'السبت'];
$dayData = [];
foreach ($dayNames as $num => $name) {
    $dayData[$num] = ['name' => $name, 'count' => 0, 'total' => 0];
}
foreach ($byDay as $r) {
    $dayData[$r['dow']]['count'] = (int)$r['invoices_count'];
    $dayData[$r['dow']]['total'] = (float)$r['total_sales'];
}
$maxDayTotal = max(1, max(array_column($dayData, 'total')));
uasort($dayData, fn($a,$b) => $b['total'] <=> $a['total']);
$busiestDay = array_key_first($dayData);
$dayDataAsc = $dayData; uasort($dayDataAsc, fn($a,$b) => $a['total'] <=> $b['total']);
$slowestDay = array_key_first($dayDataAsc);

// حسب ساعة اليوم
$byHour = $pdo->prepare("
    SELECT HOUR(created_at) hr, COUNT(*) invoices_count, SUM(total) total_sales
    FROM sale_invoices
    WHERE invoice_date BETWEEN ? AND ?
    GROUP BY hr
");
$byHour->execute([$from, $to]);
$byHour = $byHour->fetchAll();
$hourData = [];
for ($h = 0; $h < 24; $h++) { $hourData[$h] = ['count' => 0, 'total' => 0]; }
foreach ($byHour as $r) {
    $hourData[$r['hr']]['count'] = (int)$r['invoices_count'];
    $hourData[$r['hr']]['total'] = (float)$r['total_sales'];
}
$maxHourTotal = max(1, max(array_column($hourData, 'total')));
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">أوقات وأيام البيع الأكثر والأقل نشاطاً</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-info no-print">
    <i class="fa-solid fa-circle-info"></i>
    يساعدك على توزيع عدد الموظفين حسب الحاجة الفعلية: زِد التغطية بالأيام/الساعات الأكثر ازدحاماً،
    وخفّضها بالأوقات الهادئة لتوفير تكلفة العمالة. يعتمد التوقيت على وقت تسجيل الفاتورة فعلياً بالنظام.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100">عرض</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div><div class="value" style="font-size:16px"><?= $dayData[$busiestDay]['name'] ?></div><div class="label">أكثر يوم مبيعاً - زِد به العمالة</div></div></div>
    </div>
    <div class="col-md-6">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div><div class="value" style="font-size:16px"><?= $dayDataAsc[$slowestDay]['name'] ?></div><div class="label">أقل يوم مبيعاً - يمكن تخفيف العمالة</div></div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">المبيعات حسب يوم الأسبوع</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>اليوم</th><th>عدد الفواتير</th><th>إجمالي المبيعات</th><th style="width:35%">مقارنة بصرية</th></tr></thead>
            <tbody>
            <?php foreach ($dayData as $d): ?>
                <tr>
                    <td><?= $d['name'] ?></td>
                    <td><?= $d['count'] ?></td>
                    <td><?= money($d['total']) ?></td>
                    <td><div class="bg-primary rounded" style="height:10px;width:<?= ($d['total']/$maxDayTotal*100) ?>%"></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">المبيعات حسب ساعة اليوم</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>الساعة</th><th>عدد الفواتير</th><th>إجمالي المبيعات</th><th style="width:35%">مقارنة بصرية</th></tr></thead>
            <tbody>
            <?php foreach ($hourData as $h => $d): if ($d['count'] == 0 && $d['total'] == 0) continue; ?>
                <tr>
                    <td><?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:00 - <?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:59</td>
                    <td><?= $d['count'] ?></td>
                    <td><?= money($d['total']) ?></td>
                    <td><div class="bg-success rounded" style="height:10px;width:<?= ($d['total']/$maxHourTotal*100) ?>%"></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted p-3 mb-0 no-print"><i class="fa-solid fa-circle-info"></i> إن لم تظهر بيانات هنا، فربما كل الفواتير سُجّلت بنفس التوقيت (مثلاً عبر استيراد بيانات قديمة)؛ الجدول يعتمد وقت إنشاء الفاتورة الفعلي بالنظام.</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
