<?php
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, c.name customer_name, c.phone customer_phone FROM sale_invoices s JOIN customers c ON c.id = s.customer_id WHERE s.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) { flash('الفاتورة غير موجودة', 'danger'); redirect('sales.php'); }

$itemsStmt = $pdo->prepare("SELECT si.*, pr.name product_name, pr.unit FROM sale_items si JOIN products pr ON pr.id = si.product_id WHERE si.invoice_id = ?");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extra_payment'])) {
    $amount = (float)$_POST['extra_payment'];
    $remain = $invoice['total'] - $invoice['paid'];
    if ($amount > 0 && $amount <= $remain) {
        $pdo->prepare("UPDATE sale_invoices SET paid = paid + ? WHERE id = ?")->execute([$amount, $id]);
        $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $invoice['customer_id']]);
        log_cash_movement($pdo, 'in', $amount, 'sale', $id, date('Y-m-d'), 'تحصيل إضافي على فاتورة ' . $invoice['invoice_number']);
        flash('تم تسجيل التحصيل بنجاح');
    } else {
        flash('المبلغ غير صحيح', 'danger');
    }
    redirect('sale_view.php?id=' . $id);
}

$remain = $invoice['total'] - $invoice['paid'];
$isGift = ($invoice['invoice_type'] ?? 'sale') === 'gift';

// [جديد] بيانات فاتورة الهدية المجانية (نص الترويسة وختم الهدية، قابلان للتعديل من إعدادات المتجر)
$giftHeaderText = $isGift ? get_store_setting($pdo, 'gift_header_text', 'هدية مجانية مقدمة من') : '';
$giftStampText  = $isGift ? get_store_setting($pdo, 'gift_stamp_text', 'هدية مجانية') : '';
$siteName       = $isGift ? get_store_setting($pdo, 'site_name', '') : '';
?>
<?php if ($isGift): ?>
<style>
    .gift-invoice-wrap { position: relative; }
    .gift-invoice-header {
        text-align: center; background: linear-gradient(135deg,#fff8e1,#fff3cd);
        border: 2px dashed #f59e0b; border-radius: 10px; padding: 14px 10px; margin-bottom: 16px;
    }
    .gift-invoice-header .to-from { font-size: 20px; font-weight: 800; color: #92400e; }
    .gift-stamp {
        position: absolute; top: 40px; left: 40px; z-index: 5;
        border: 5px solid #dc3545; color: #dc3545; border-radius: 50%;
        width: 150px; height: 150px; display: flex; align-items: center; justify-content: center;
        text-align: center; font-weight: 900; font-size: 18px; line-height: 1.2;
        transform: rotate(-18deg); opacity: 0.85; padding: 8px;
    }
    @media print { .gift-stamp { top: 20px; left: 20px; } }
</style>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">
        <?php if ($isGift): ?><span class="badge bg-warning text-dark"><i class="fa-solid fa-gift"></i> فاتورة هدية مجانية</span><?php endif; ?>
        فاتورة بيع رقم <?= e($invoice['invoice_number']) ?>
    </h4>
    <div>
        <?php if (!$isGift): ?>
        <a href="sale_return_add.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-warning"><i class="fa-solid fa-rotate-left"></i> إنشاء مرتجع</a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="sales.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="card mb-3 gift-invoice-wrap">
    <?php if ($isGift): ?><div class="gift-stamp"><?= e($giftStampText) ?></div><?php endif; ?>
    <div class="card-body">
        <?php if ($isGift): ?>
        <div class="gift-invoice-header">
            <div class="to-from">
                <i class="fa-solid fa-gift"></i>
                <?= e($giftHeaderText) ?> <?= e($siteName) ?> لـ <?= e($invoice['customer_name']) ?>
            </div>
            <div class="text-muted">بتاريخ <?= e($invoice['invoice_date']) ?></div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <p><strong>العميل:</strong> <?= e($invoice['customer_name']) ?></p>
                <p><strong>الهاتف:</strong> <?= e($invoice['customer_phone']) ?></p>
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
            <div class="col-md-5">
                <table class="table table-sm">
                    <?php if ($isGift): ?>
                        <tr><td>قيمة اجمالي الفاتورة (قبل الخصم)</td><td class="text-end fw-bold"><?= money($invoice['discount']) ?></td></tr>
                        <tr><td>نسبة الخصم</td><td class="text-end text-success fw-bold">100%</td></tr>
                        <tr class="table-warning border-top"><td class="fw-bold">المطلوب سداده</td><td class="text-end fw-bold">0.00 <?= CURRENCY ?> (هدية مجانية)</td></tr>
                    <?php else: ?>
                        <tr><td>قيمة اجمالي الفاتورة</td><td class="text-end fw-bold"><?= money($invoice['total'] + $invoice['discount']) ?></td></tr>
                        <?php if ($invoice['discount'] > 0): ?>
                        <tr><td>قيمة الخصم على الفاتورة</td><td class="text-end text-danger">- <?= money($invoice['discount']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td>تم الدفع</td><td class="text-end text-success"><?= money($invoice['paid']) ?></td></tr>
                        <tr class="border-top"><td class="fw-bold">المطلوب سداده</td><td class="text-end text-danger fw-bold"><?= money($remain) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!$isGift && $remain > 0): ?>
<div class="card no-print">
    <div class="card-header">تسجيل تحصيل إضافي على الفاتورة</div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <div class="col-md-4">
                <input type="number" step="0.01" name="extra_payment" class="form-control" placeholder="المبلغ" max="<?= $remain ?>" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100">تسجيل التحصيل</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
