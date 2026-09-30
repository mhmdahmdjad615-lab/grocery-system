<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$returns = $pdo->query("
    SELECT r.*, s.name supplier_name, p.invoice_number purchase_invoice_number
    FROM purchase_returns r
    JOIN suppliers s ON s.id = r.supplier_id
    JOIN purchase_invoices p ON p.id = r.purchase_invoice_id
    ORDER BY r.id DESC
")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">مرتجعات المشتريات</h4>
    <a href="purchase_return_add.php" class="btn btn-success"><i class="fa-solid fa-rotate-left"></i> مرتجع جديد</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>رقم المرتجع</th><th>الفاتورة الأصلية</th><th>المورد</th><th>التاريخ</th><th>القيمة</th><th>ملاحظات</th></tr></thead>
            <tbody>
            <?php foreach ($returns as $r): ?>
                <tr>
                    <td><?= e($r['return_number']) ?></td>
                    <td><a href="purchase_view.php?id=<?= $r['purchase_invoice_id'] ?>"><?= e($r['purchase_invoice_number']) ?></a></td>
                    <td><a href="supplier_statement.php?id=<?= $r['supplier_id'] ?>"><?= e($r['supplier_name']) ?></a></td>
                    <td><?= e($r['return_date']) ?></td>
                    <td class="text-danger fw-bold"><?= money($r['total']) ?></td>
                    <td><?= e($r['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($returns)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد مرتجعات مشتريات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
