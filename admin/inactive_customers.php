<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// لكل عميل اشترى من قبل: تاريخ أول وآخر عملية شراء، متوسط الفاصل الزمني المعتاد بين
// مشترياته، وعدد الأيام منذ آخر عملية - لمعرفة من تجاوز عادته المعتادة بكثير
$rows = $pdo->query("
    SELECT c.id, c.name, c.phone, c.balance,
           COUNT(s.id) invoices_count,
           MIN(s.invoice_date) first_date,
           MAX(s.invoice_date) last_date,
           SUM(s.total) total_spent
    FROM customers c JOIN sale_invoices s ON s.customer_id = c.id
    GROUP BY c.id
    HAVING invoices_count >= 1
")->fetchAll();

foreach ($rows as &$r) {
    $span_days = (strtotime($r['last_date']) - strtotime($r['first_date'])) / 86400;
    $r['avg_gap_days'] = $r['invoices_count'] > 1 ? round($span_days / ($r['invoices_count'] - 1), 1) : null;
    $r['days_since_last'] = (int)((strtotime(date('Y-m-d')) - strtotime($r['last_date'])) / 86400);

    // معرّض للفقد: تجاوزت المدة منذ آخر شراء ضعف فاصله المعتاد (وله 3 فواتير على الأقل ليكون النمط موثوقاً)
    // أو ببساطة أي عميل متكرر لم يشتر منذ أكثر من 45 يوماً كحد أدنى عام
    if ($r['invoices_count'] >= 3 && $r['avg_gap_days'] > 0) {
        $r['at_risk'] = $r['days_since_last'] > ($r['avg_gap_days'] * 2);
    } else {
        $r['at_risk'] = $r['days_since_last'] > 45;
    }
}
unset($r);

usort($rows, fn($a, $b) => $b['days_since_last'] <=> $a['days_since_last']);
$atRisk = array_values(array_filter($rows, fn($r) => $r['at_risk']));
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">عملاء معرّضون للتوقف عن الشراء</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    يقارن عدد الأيام منذ آخر عملية شراء بمعدّل الفاصل الزمني المعتاد لكل عميل (لمن لديه 3 فواتير
    فأكثر)، أو بحد عام 45 يوماً لمن اشترى مرة أو مرتين. تواصل مع هؤلاء قبل أن يتحولوا لمورد آخر.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-user-clock"></i></div>
        <div><div class="value"><?= count($atRisk) ?></div><div class="label">عميل معرّض للفقد</div></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">التفاصيل (الأعلى غياباً أولاً)</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>العميل</th><th>الهاتف</th><th>عدد الفواتير</th><th>إجمالي ما اشتراه</th>
                <th>الفاصل المعتاد بين مشترياته</th><th>آخر عملية شراء</th><th>الحالة</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['at_risk'] ? 'table-danger' : '' ?>">
                    <td><a href="customer_statement.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a></td>
                    <td><?= e($r['phone']) ?></td>
                    <td><?= $r['invoices_count'] ?></td>
                    <td><?= money($r['total_spent']) ?></td>
                    <td><?= $r['avg_gap_days'] !== null ? $r['avg_gap_days'] . ' يوم' : '-' ?></td>
                    <td><?= e($r['last_date']) ?> <span class="text-muted">(منذ <?= $r['days_since_last'] ?> يوم)</span></td>
                    <td>
                        <?php if ($r['at_risk']): ?>
                            <span class="badge bg-danger">معرّض للفقد - تواصل معه</span>
                        <?php else: ?>
                            <span class="badge bg-success">نشط</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد بيانات عملاء بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
