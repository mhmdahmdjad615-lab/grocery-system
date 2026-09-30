<?php
require_once __DIR__ . '/includes/header.php';

$returns = $pdo->query("
    SELECT r.*, c.name customer_name, s.invoice_number sale_invoice_number
    FROM sale_returns r
    JOIN customers c ON c.id = r.customer_id
    JOIN sale_invoices s ON s.id = r.sale_invoice_id
    ORDER BY r.id DESC
")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">مرتجعات المبيعات</h4>
    <a href="sale_return_add.php" class="btn btn-success"><i class="fa-solid fa-rotate-left"></i> مرتجع جديد</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>رقم المرتجع</th><th>الفاتورة الأصلية</th><th>العميل</th><th>التاريخ</th><th>القيمة</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($returns as $r): ?>
                <tr>
                    <td><?= e($r['return_number']) ?></td>
                    <td><a href="sale_view.php?id=<?= $r['sale_invoice_id'] ?>"><?= e($r['sale_invoice_number']) ?></a></td>
                    <td><a href="customer_statement.php?id=<?= $r['customer_id'] ?>"><?= e($r['customer_name']) ?></a></td>
                    <td><?= e($r['return_date']) ?></td>
                    <td class="text-danger fw-bold"><?= money($r['total']) ?></td>
                    <td><?= e($r['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($returns)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد مرتجعات مبيعات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
