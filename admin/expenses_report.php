<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to'] ?? date('Y-m-d');

// إجمالي كل نوع بحسب التصنيف
$byCategory = $pdo->prepare("SELECT type, category, SUM(amount) total, COUNT(*) cnt FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY type, category ORDER BY type, total DESC");
$byCategory->execute([$from, $to]);
$byCategory = $byCategory->fetchAll();

$expenseCats = array_values(array_filter($byCategory, fn($r) => $r['type'] === 'expense'));
$incomeCats = array_values(array_filter($byCategory, fn($r) => $r['type'] === 'income'));

$total_expenses = array_sum(array_column($expenseCats, 'total'));
$total_income = array_sum(array_column($incomeCats, 'total'));

// الاتجاه الشهري
$monthly = $pdo->prepare("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') ym,
           SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) expenses,
           SUM(CASE WHEN type='income' THEN amount ELSE 0 END) income
    FROM expenses WHERE expense_date BETWEEN ? AND ?
    GROUP BY ym ORDER BY ym
");
$monthly->execute([$from, $to]);
$monthly = $monthly->fetchAll();
$max_monthly = 1;
foreach ($monthly as $m) { $max_monthly = max($max_monthly, $m['expenses'], $m['income']); }
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">تقرير النفقات والإيرادات</h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="expenses.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع لسجل العمليات</a>
    </div>
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
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div><div class="value"><?= money($total_expenses) ?></div><div class="label">إجمالي النفقات الأخرى</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div><div class="value"><?= money($total_income) ?></div><div class="label">إجمالي الإيرادات الأخرى</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:<?= ($total_income-$total_expenses)>=0?'#0d6efd':'#6c757d' ?>"><i class="fa-solid fa-scale-balanced"></i></div>
        <div><div class="value"><?= money($total_income - $total_expenses) ?></div><div class="label">الصافي (إيرادات - نفقات أخرى)</div></div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-danger">تقرير النفقات حسب التصنيف</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>التصنيف</th><th>عدد العمليات</th><th>الإجمالي</th><th>النسبة</th></tr></thead>
                    <tbody>
                    <?php foreach ($expenseCats as $r): $pct = $total_expenses>0 ? ($r['total']/$total_expenses*100) : 0; ?>
                        <tr>
                            <td><?= e($r['category']) ?></td>
                            <td><?= $r['cnt'] ?></td>
                            <td><?= money($r['total']) ?></td>
                            <td style="min-width:140px">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="flex-grow-1 bg-light rounded" style="height:8px;">
                                        <div class="bg-danger rounded" style="height:8px;width:<?= min(100,$pct) ?>%"></div>
                                    </div>
                                    <small><?= number_format($pct,1) ?>%</small>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($expenseCats)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد نفقات بهذه الفترة</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-success">تقرير الإيرادات الإضافية حسب التصنيف</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>التصنيف</th><th>عدد العمليات</th><th>الإجمالي</th><th>النسبة</th></tr></thead>
                    <tbody>
                    <?php foreach ($incomeCats as $r): $pct = $total_income>0 ? ($r['total']/$total_income*100) : 0; ?>
                        <tr>
                            <td><?= e($r['category']) ?></td>
                            <td><?= $r['cnt'] ?></td>
                            <td><?= money($r['total']) ?></td>
                            <td style="min-width:140px">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="flex-grow-1 bg-light rounded" style="height:8px;">
                                        <div class="bg-success rounded" style="height:8px;width:<?= min(100,$pct) ?>%"></div>
                                    </div>
                                    <small><?= number_format($pct,1) ?>%</small>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($incomeCats)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد إيرادات إضافية بهذه الفترة</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">الاتجاه الشهري (نفقات مقابل إيرادات إضافية)</div>
    <div class="card-body">
        <?php if (empty($monthly)): ?>
            <p class="text-center text-muted py-3 mb-0">لا توجد بيانات بهذه الفترة</p>
        <?php else: ?>
        <table class="table mb-0">
            <thead><tr><th>الشهر</th><th>النفقات</th><th>الإيرادات</th><th style="width:35%">مقارنة بصرية</th></tr></thead>
            <tbody>
            <?php foreach ($monthly as $m): ?>
                <tr>
                    <td><?= e($m['ym']) ?></td>
                    <td class="text-danger"><?= money($m['expenses']) ?></td>
                    <td class="text-success"><?= money($m['income']) ?></td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <div class="bg-danger rounded" style="height:8px;width:<?= ($m['expenses']/$max_monthly*100) ?>%"></div>
                            <div class="bg-success rounded" style="height:8px;width:<?= ($m['income']/$max_monthly*100) ?>%"></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
