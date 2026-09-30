<?php
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { flash('العميل غير موجود', 'danger'); redirect('customers.php'); }

$invoices = $pdo->prepare("SELECT id, invoice_number, invoice_date, total, paid, 'sale' as kind FROM sale_invoices WHERE customer_id = ?");
$invoices->execute([$id]);
$invoices = $invoices->fetchAll();

$paymentsStmt = $pdo->prepare("SELECT * FROM payments WHERE party_type='customer' AND party_id = ?");
$paymentsStmt->execute([$id]);
$payments = $paymentsStmt->fetchAll();

$returnsStmt = $pdo->prepare("SELECT r.*, s.invoice_number FROM sale_returns r JOIN sale_invoices s ON s.id = r.sale_invoice_id WHERE r.customer_id = ?");
$returnsStmt->execute([$id]);
$returns = $returnsStmt->fetchAll();

// دمج الحركات مرتبة بالتاريخ
$rows = [];
foreach ($invoices as $inv) {
    $rows[] = ['date' => $inv['invoice_date'], 'desc' => 'فاتورة بيع رقم ' . $inv['invoice_number'], 'debit' => $inv['total'], 'credit' => $inv['paid'], 'link' => 'sale_view.php?id=' . $inv['id']];
}
foreach ($payments as $p) {
    $label = $p['type'] === 'refund_customer' ? 'استرداد نقدي' : 'تحصيل نقدي';
    if ($p['type'] === 'refund_customer') {
        $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'debit' => $p['amount'], 'credit' => 0, 'link' => null];
    } else {
        $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'debit' => 0, 'credit' => $p['amount'], 'link' => null];
    }
}
foreach ($returns as $r) {
    $rows[] = ['date' => $r['return_date'], 'desc' => 'مرتجع مبيعات ' . $r['return_number'] . ' (فاتورة ' . $r['invoice_number'] . ')', 'debit' => 0, 'credit' => $r['total'], 'link' => null];
}
usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">كشف حساب العميل: <?= e($customer['name']) ?></h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="customers.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <p><strong>الهاتف:</strong> <?= e($customer['phone']) ?></p>
        <p>
            <strong>الرصيد الحالي:</strong>
            <?php if ($customer['balance'] > 0): ?>
                <span class="text-danger fw-bold"><?= money($customer['balance']) ?> مستحق علينا تحصيله</span>
            <?php elseif ($customer['balance'] < 0): ?>
                <span class="text-success fw-bold"><?= money(abs($customer['balance'])) ?> رصيد دائن له عندنا</span>
            <?php else: ?>
                <span class="text-muted">صفر</span>
            <?php endif; ?>
        </p>
        <?php if ($customer['credit_limit'] !== null): ?>
        <p><strong>حد الائتمان:</strong> <?= money($customer['credit_limit']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>التاريخ</th><th>البيان</th><th>مدين (عليه)</th><th>دائن (له)</th></tr></thead>
            <tbody>
            <?php $balance = 0; foreach ($rows as $r): $balance += $r['debit'] - $r['credit']; ?>
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
