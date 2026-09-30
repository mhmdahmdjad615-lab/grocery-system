<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);

// [جديد] إلغاء فاتورة هدية مجانية صادرة بالخطأ (يُعيد الكمية للمخزون تلقائياً)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_gift') {
    try {
        $pdo->beginTransaction();
        void_gift_order($pdo, $id);
        $pdo->commit();
        flash('تم إلغاء الهدية المجانية وإعادة الكمية إلى مخزن البيع بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
    }
    redirect('store_order_view.php?id=' . $id);
}

$stmt = $pdo->prepare("SELECT o.*, sc.shop_name, sc.owner_name, sc.phone, sc.whatsapp, sc.city, sc.address, sc.customer_id
    FROM store_orders o JOIN store_customers sc ON sc.id = o.store_customer_id WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { flash('الطلب غير موجود', 'danger'); redirect('store_orders.php'); }

$itemsStmt = $pdo->prepare("SELECT oi.*, p.name product_name FROM store_order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$invoice = null;
if ($order['sale_invoice_id']) {
    $invStmt = $pdo->prepare("SELECT * FROM sale_invoices WHERE id = ?");
    $invStmt->execute([$order['sale_invoice_id']]);
    $invoice = $invStmt->fetch();
}

$isGift = $order['order_type'] === 'gift';

$statusLabels = [
    'pending'   => ['بانتظار الاعتماد', 'bg-warning text-dark'],
    'confirmed' => ['معتمد', 'bg-success'],
    'preparing' => ['قيد التجهيز', 'bg-info text-dark'],
    'shipped'   => ['تم الشحن', 'bg-primary'],
    'completed' => ['مكتمل', 'bg-secondary'],
    'cancelled' => ['ملغي', 'bg-danger'],
];
$meta = $statusLabels[$order['status']];
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">
        <?php if ($isGift): ?><span class="badge bg-warning text-dark"><i class="fa-solid fa-gift"></i> هدية مجانية</span><?php endif; ?>
        طلب متجر رقم <?= e($order['order_number']) ?> <span class="badge <?= $meta[1] ?>"><?= $meta[0] ?></span>
    </h4>
    <div>
        <?php if ($order['status'] === 'pending'): ?>
        <a href="store_orders.php?confirm=<?= $order['id'] ?>" class="btn btn-success" onclick="return confirm('اعتماد الطلب وإنشاء فاتورة بيع به؟')"><i class="fa-solid fa-check"></i> اعتماد الطلب</a>
        <?php endif; ?>
        <?php if ($order['sale_invoice_id']): ?>
        <a href="sale_view.php?id=<?= $order['sale_invoice_id'] ?>" class="btn btn-outline-primary"><i class="fa-solid fa-file-invoice"></i> عرض فاتورة <?= $isGift ? 'الهدية' : 'البيع' ?></a>
        <?php endif; ?>
        <?php if ($isGift && $order['status'] !== 'cancelled'): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('سيتم إلغاء الهدية وإعادة الكمية إلى مخزن البيع تلقائياً. هل أنت متأكد؟')">
            <input type="hidden" name="action" value="void_gift">
            <button type="submit" class="btn btn-outline-danger"><i class="fa-solid fa-rotate-left"></i> إلغاء الهدية واسترجاع المخزون</button>
        </form>
        <?php endif; ?>
        <a href="store_orders.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>المحل:</strong> <?= e($order['shop_name']) ?></p>
                <p><strong>المسؤول:</strong> <?= e($order['owner_name']) ?></p>
                <p><strong>الهاتف:</strong> <?= e($order['phone']) ?> <?php if ($order['whatsapp']): ?>| واتساب: <?= e($order['whatsapp']) ?><?php endif; ?></p>
                <p><strong>العنوان:</strong> <?= e($order['city']) ?> - <?= e($order['address']) ?></p>
                <?php if ($order['customer_id']): ?>
                <p><a href="customer_statement.php?id=<?= $order['customer_id'] ?>">عرض كشف حساب هذا المحل بالحسابات</a></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <p><strong>تاريخ الطلب:</strong> <?= e($order['created_at']) ?></p>
                <?php if ($order['notes']): ?><p><strong>ملاحظات:</strong> <?= e($order['notes']) ?></p><?php endif; ?>
                <?php if ($order['cancelled_reason']): ?><p class="text-danger"><strong>سبب الإلغاء:</strong> <?= e($order['cancelled_reason']) ?></p><?php endif; ?>
            </div>
        </div>

        <table class="table table-bordered mt-3">
            <thead><tr><th>المنتج</th><th>الكمية</th><th>الوحدة</th><th>السعر</th><th>الإجمالي</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= e($it['product_name']) ?></td>
                    <td><?= rtrim(rtrim(number_format($it['quantity'],4),'0'),'.') ?></td>
                    <td><?= e($it['unit_name']) ?></td>
                    <td><?= $isGift ? money($it['price']) . ' <span class="badge bg-warning text-dark">هدية</span>' : money($it['price']) ?></td>
                    <td><?= money($it['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="row justify-content-end">
            <div class="col-md-5">
                <table class="table table-sm">
                    <?php if ($isGift): ?>
                        <tr><td>قيمة الأصناف قبل الخصم</td><td class="text-end fw-bold"><?= money($order['subtotal']) ?></td></tr>
                        <tr><td>نسبة الخصم</td><td class="text-end text-success fw-bold">100%</td></tr>
                        <tr class="table-warning"><td class="fw-bold">الإجمالي المطلوب سداده</td><td class="text-end fw-bold">0.00 <?= CURRENCY ?> (هدية مجانية)</td></tr>
                    <?php else: ?>
                        <tr><td>قيمة اجمالي الفاتورة</td><td class="text-end fw-bold"><?= money($order['total']) ?></td></tr>
                        <tr><td>تم الدفع (فوري عند الطلب)</td><td class="text-end text-success"><?= money($order['paid_amount']) ?></td></tr>
                        <tr class="border-top"><td class="fw-bold">المطلوب سداده (آجل على حساب التاجر)</td><td class="text-end text-danger fw-bold"><?= money(max(0, $order['total'] - $order['paid_amount'])) ?></td></tr>
                        <?php if ($invoice): ?>
                        <tr><td colspan="2" class="text-muted small pt-2">تحصيل لاحق مسجَّل بالفاتورة: <?= money($invoice['paid']) ?> من أصل <?= money($invoice['total']) ?></td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
