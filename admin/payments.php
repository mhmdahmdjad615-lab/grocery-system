<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$sql = "SELECT p.*,
        CASE WHEN p.party_type='customer' THEN (SELECT name FROM customers WHERE id=p.party_id)
             ELSE (SELECT name FROM suppliers WHERE id=p.party_id) END party_name
        FROM payments p WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND p.payment_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND p.payment_date <= ?"; $params[] = $to; }
$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$cash_in  = $pdo->query("SELECT COALESCE(SUM(amount),0) t FROM cash_movements WHERE type='in'")->fetch()['t'];
$cash_out = $pdo->query("SELECT COALESCE(SUM(amount),0) t FROM cash_movements WHERE type='out'")->fetch()['t'];
$cash_balance = $cash_in - $cash_out;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">الخزينة والمدفوعات</h4>
    <a href="payment_add.php" class="btn btn-success"><i class="fa-solid fa-plus"></i> تسجيل مقبوضات / مدفوعات</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-arrow-down"></i></div>
        <div><div class="value"><?= money($cash_in) ?></div><div class="label">إجمالي المقبوضات (كاش)</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-arrow-up"></i></div>
        <div><div class="value"><?= money($cash_out) ?></div><div class="label">إجمالي المدفوعات (كاش)</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="value"><?= money($cash_balance) ?></div><div class="label">رصيد الخزينة الحالي</div></div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-secondary w-100">فلترة</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">سجل عمليات القبض والدفع اليدوية (خارج الفواتير)</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>#</th><th>النوع</th><th>الجهة</th><th>المبلغ</th><th>التاريخ</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td>
                        <?php
                        $typeLabels = [
                            'receipt' => '<span class="badge bg-success">قبض من عميل</span>',
                            'payment' => '<span class="badge bg-danger">دفع لمورد</span>',
                            'refund_customer' => '<span class="badge bg-warning text-dark">استرداد لعميل</span>',
                            'refund_supplier' => '<span class="badge bg-info text-dark">استرداد من مورد</span>',
                        ];
                        echo $typeLabels[$p['type']] ?? e($p['type']);
                        ?>
                    </td>
                    <td><?= e($p['party_name']) ?></td>
                    <td><?= money($p['amount']) ?></td>
                    <td><?= e($p['payment_date']) ?></td>
                    <td><?= e($p['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payments)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد عمليات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
