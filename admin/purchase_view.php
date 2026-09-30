<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, s.name supplier_name, s.phone supplier_phone FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) { flash('الفاتورة غير موجودة', 'danger'); redirect('purchases.php'); }

$itemsStmt = $pdo->prepare("SELECT pi.*, pr.name product_name, pr.unit FROM purchase_items pi JOIN products pr ON pr.id = pi.product_id WHERE pi.invoice_id = ?");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

// تسجيل دفعة إضافية على الفاتورة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extra_payment'])) {
    $amount = (float)$_POST['extra_payment'];
    $remain = $invoice['total'] - $invoice['paid'];
    if ($amount > 0 && $amount <= $remain) {
        $pdo->prepare("UPDATE purchase_invoices SET paid = paid + ? WHERE id = ?")->execute([$amount, $id]);
        $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ?")->execute([$amount, $invoice['supplier_id']]);
        log_cash_movement($pdo, 'out', $amount, 'purchase', $id, date('Y-m-d'), 'دفعة إضافية على فاتورة ' . $invoice['invoice_number']);
        flash('تم تسجيل الدفعة بنجاح');
    } else {
        flash('المبلغ غير صحيح', 'danger');
    }
    redirect('purchase_view.php?id=' . $id);
}

$remain = $invoice['total'] - $invoice['paid'];
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">فاتورة شراء رقم <?= e($invoice['invoice_number']) ?></h4>
    <div>
        <a href="purchase_return_add.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-warning"><i class="fa-solid fa-rotate-left"></i> إنشاء مرتجع</a>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="purchases.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>المورد:</strong> <?= e($invoice['supplier_name']) ?></p>
                <p><strong>الهاتف:</strong> <?= e($invoice['supplier_phone']) ?></p>
            </div>
            <div class="col-md-6 text-md-end">
                <p><strong>رقم الفاتورة:</strong> <?= e($invoice['invoice_number']) ?></p>
                <p><strong>التاريخ:</strong> <?= e($invoice['invoice_date']) ?></p>
            </div>
        </div>
        <?php if ($invoice['notes']): ?><p><strong>ملاحظات:</strong> <?= e($invoice['notes']) ?></p><?php endif; ?>

        <table class="table table-bordered mt-3">
            <thead><tr><th>المنتج</th><th>الكمية</th><th>الوحدة</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it):
                $displayQty = $it['display_qty'] !== null ? $it['display_qty'] : $it['quantity'];
                $displayUnit = $it['unit_name'] ?: $it['unit'];
                $displayPrice = $it['display_qty'] ? ($it['total'] / $it['display_qty']) : $it['price'];
            ?>
                <tr>
                    <td><?= e($it['product_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($displayQty,4),'0'),'.') ?></td>
                    <td><?= e($displayUnit) ?></td>
                    <td><?= money($displayPrice) ?></td>
                    <td><?= money($it['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="row justify-content-end">
            <div class="col-md-4">
                <table class="table table-sm">
                    <tr><td>الإجمالي</td><td class="text-end fw-bold"><?= money($invoice['total']) ?></td></tr>
                    <tr><td>المدفوع</td><td class="text-end text-success"><?= money($invoice['paid']) ?></td></tr>
                    <tr><td>المتبقي</td><td class="text-end text-danger fw-bold"><?= money($remain) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($remain > 0): ?>
<div class="card no-print">
    <div class="card-header">تسجيل دفعة إضافية على الفاتورة</div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="extra_payment_marker" value="1">
            <div class="col-md-4">
                <input type="number" step="0.01" name="extra_payment" class="form-control" placeholder="المبلغ" max="<?= $remain ?>" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100">تسجيل الدفعة</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
