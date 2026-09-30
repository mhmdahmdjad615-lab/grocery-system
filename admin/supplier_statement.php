<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$id]);
$supplier = $stmt->fetch();
if (!$supplier) { flash('المورد غير موجود', 'danger'); redirect('suppliers.php'); }

$invoices = $pdo->prepare("SELECT id, invoice_number, invoice_date, total, paid FROM purchase_invoices WHERE supplier_id = ?");
$invoices->execute([$id]);
$invoices = $invoices->fetchAll();

$paymentsStmt = $pdo->prepare("SELECT * FROM payments WHERE party_type='supplier' AND party_id = ?");
$paymentsStmt->execute([$id]);
$payments = $paymentsStmt->fetchAll();

$returnsStmt = $pdo->prepare("SELECT r.*, p.invoice_number FROM purchase_returns r JOIN purchase_invoices p ON p.id = r.purchase_invoice_id WHERE r.supplier_id = ?");
$returnsStmt->execute([$id]);
$returns = $returnsStmt->fetchAll();

$rows = [];
foreach ($invoices as $inv) {
    $rows[] = ['date' => $inv['invoice_date'], 'desc' => 'فاتورة شراء رقم ' . $inv['invoice_number'], 'credit' => $inv['total'], 'debit' => $inv['paid'], 'link' => 'purchase_view.php?id=' . $inv['id']];
}
foreach ($payments as $p) {
    $label = $p['type'] === 'refund_supplier' ? 'استرداد نقدي منه' : 'دفع نقدي';
    if ($p['type'] === 'refund_supplier') {
        $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'credit' => $p['amount'], 'debit' => 0, 'link' => null];
    } else {
        $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'credit' => 0, 'debit' => $p['amount'], 'link' => null];
    }
}
foreach ($returns as $r) {
    $rows[] = ['date' => $r['return_date'], 'desc' => 'مرتجع مشتريات ' . $r['return_number'] . ' (فاتورة ' . $r['invoice_number'] . ')', 'credit' => 0, 'debit' => $r['total'], 'link' => null];
}
usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">كشف حساب المورد: <?= e($supplier['name']) ?></h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="suppliers.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <p><strong>الهاتف:</strong> <?= e($supplier['phone']) ?></p>
        <p>
            <strong>الرصيد الحالي:</strong>
            <?php if ($supplier['balance'] > 0): ?>
                <span class="text-danger fw-bold"><?= money($supplier['balance']) ?> مستحق له علينا</span>
            <?php elseif ($supplier['balance'] < 0): ?>
                <span class="text-success fw-bold"><?= money(abs($supplier['balance'])) ?> هو مدين لنا</span>
            <?php else: ?>
                <span class="text-muted">صفر</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>التاريخ</th><th>البيان</th><th>مدفوع منّا</th><th>مستحق عليه لنا</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['date']) ?></td>
                    <td><?= $r['link'] ? '<a href="'.$r['link'].'">'.e($r['desc']).'</a>' : e($r['desc']) ?></td>
                    <td><?= $r['debit'] > 0 ? money($r['debit']) : '-' ?></td>
                    <td><?= $r['credit'] > 0 ? money($r['credit']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد حركات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
