<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM store_orders WHERE id = ? AND store_customer_id = ?");
$stmt->execute([$id, store_current_id()]);
$order = $stmt->fetch();
if (!$order) { flash('الطلب غير موجود', 'danger'); redirect('my_orders.php'); }

$itemsStmt = $pdo->prepare("SELECT oi.*, p.name product_name, p.store_image FROM store_order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$isGift = $order['order_type'] === 'gift';
$customer = store_current_customer($pdo);

$statusLabels = [
    'pending'   => ['بانتظار الاعتماد', 'badge-warning'],
    'confirmed' => ['معتمد', 'badge-success'],
    'preparing' => ['قيد التجهيز', 'badge-info'],
    'shipped'   => ['تم الشحن', 'badge-info'],
    'completed' => ['مكتمل', 'badge-navy'],
    'cancelled' => ['ملغي', 'badge-danger'],
];
$meta = $statusLabels[$order['status']];

$steps = ['pending' => 'تم الطلب', 'confirmed' => 'مؤكد', 'preparing' => 'قيد التجهيز', 'shipped' => 'تم الشحن', 'completed' => 'تم التسليم'];
$stepKeys = array_keys($steps);
$currentIndex = $order['status'] === 'cancelled' ? -1 : array_search($order['status'], $stepKeys);

$giftHeaderText = $isGift ? get_store_setting($pdo, 'gift_header_text', 'هدية مجانية مقدمة من') : '';
$giftStampText  = $isGift ? get_store_setting($pdo, 'gift_stamp_text', 'هدية مجانية') : '';
$siteName       = get_store_setting($pdo, 'site_name', '');

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h1 mb-1">
                <?php if ($isGift): ?><span class="badge-pill badge-warning">🎁 هدية مجانية</span><?php endif; ?>
                طلب رقم <?= e($order['order_number']) ?> <span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span>
            </h1>
        </div>
        <a href="my_orders.php" class="btn btn-outline btn-sm">رجوع لطلباتي</a>
    </div>

    <?php if ($isGift): ?>
    <div class="gift-order-header fade-in">
        <i class="fa-solid fa-gift"></i>
        <div class="to-from"><?= e($giftHeaderText) ?> <?= e($siteName) ?> لـ <?= e($customer['shop_name']) ?></div>
        <div class="text-muted-store text-sm">بتاريخ <?= e(date('Y-m-d', strtotime($order['created_at']))) ?></div>
        <div class="gift-order-stamp"><?= e($giftStampText) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($order['status'] === 'cancelled'): ?>
        <div class="gcard mb-3" style="padding:16px;border-color:#fca5a5;background:#fef2f2">
            <span class="text-danger fw-bold"><i class="fa-solid fa-xmark"></i> تم إلغاء الطلب</span>
            <?php if ($order['cancelled_reason']): ?><p class="text-sm mb-0 mt-1">السبب: <?= e($order['cancelled_reason']) ?></p><?php endif; ?>
        </div>
    <?php else: ?>
    <div class="gcard mb-4" style="padding:28px 20px;overflow-x:auto">
        <div style="display:flex;min-width:520px">
            <?php foreach ($steps as $key => $label): $idx = array_search($key, $stepKeys); $done = $idx <= $currentIndex; ?>
            <div style="flex:1;text-align:center;position:relative">
                <?php if ($idx > 0): ?><div style="position:absolute;top:17px;right:50%;width:100%;height:3px;background:<?= $idx <= $currentIndex ? 'var(--p-500)' : 'var(--g-200)' ?>;z-index:0"></div><?php endif; ?>
                <div style="position:relative;z-index:1;width:36px;height:36px;border-radius:50%;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;
                    background:<?= $done ? 'var(--p-500)' : '#fff' ?>;border:2px solid <?= $done ? 'var(--p-500)' : 'var(--g-200)' ?>;color:<?= $done ? '#fff' : 'var(--g-500)' ?>;font-weight:800">
                    <?= $done ? '<i class="fa-solid fa-check"></i>' : ($idx+1) ?>
                </div>
                <span class="text-xs fw-bold" style="color:<?= $done ? 'var(--n-800)' : 'var(--g-500)' ?>"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="gcard mb-3">
                <div class="gcard-header"><i class="fa-solid fa-circle-info"></i> تفاصيل الطلب</div>
                <div class="gcard-body">
                    <div class="row g-3 text-sm">
                        <div class="col-6"><span class="text-muted-store d-block">تاريخ الطلب</span><strong><?= e($order['created_at']) ?></strong></div>
                        <div class="col-6"><span class="text-muted-store d-block">الحالة</span><span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span></div>
                        <?php if ($order['notes']): ?><div class="col-12"><span class="text-muted-store d-block">ملاحظاتك</span><?= e($order['notes']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="gcard">
                <div class="gcard-header"><i class="fa-solid fa-boxes-stacked"></i> بنود الطلب</div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>المنتج</th><th>الكمية</th><th>الوحدة</th><th>السعر</th><th>الإجمالي</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td><div style="display:flex;align-items:center;gap:10px">
                                <img src="<?= $it['store_image'] ? e($it['store_image']) : 'https://placehold.co/60x60?text=' . urlencode($it['product_name']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:8px">
                                <?= e($it['product_name']) ?>
                            </div></td>
                            <td><?= rtrim(rtrim(number_format($it['quantity'],4),'0'),'.') ?></td>
                            <td><?= e($it['unit_name']) ?></td>
                            <td class="tabular-num"><?= money($it['price']) ?></td>
                            <td class="tabular-num fw-bold"><?= money($it['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($isGift): ?>
                        <tr><td colspan="4">قيمة الأصناف قبل الخصم</td><td class="tabular-num fw-bold"><?= money($order['subtotal']) ?></td></tr>
                        <tr><td colspan="4">نسبة الخصم</td><td class="tabular-num fw-bold text-success">100%</td></tr>
                        <tr class="table-warning fw-bold"><td colspan="4">المطلوب سداده</td><td class="tabular-num">0.00 <?= CURRENCY ?> (هدية)</td></tr>
                        <?php else: ?>
                        <tr><td colspan="4">قيمة اجمالي الفاتورة</td><td class="tabular-num fw-bold"><?= money($order['total']) ?></td></tr>
                        <tr><td colspan="4">تم الدفع الآن</td><td class="tabular-num text-success"><?= money($order['paid_amount']) ?></td></tr>
                        <tr class="fw-bold"><td colspan="4">المطلوب سداده (آجل)</td><td class="tabular-num" style="color:var(--p-700)"><?= money(max(0, $order['total'] - $order['paid_amount'])) ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="gcard mb-3">
                <div class="gcard-header"><i class="fa-solid fa-bolt"></i> إجراءات سريعة</div>
                <div class="gcard-body d-flex flex-column gap-2">
                    <?php if ($order['sale_invoice_id']): ?>
                        <span class="badge-pill badge-success" style="width:fit-content"><i class="fa-solid fa-check"></i> تحوّل لفاتورة <?= $isGift ? 'هدية' : 'بيع' ?> بالحسابات</span>
                    <?php endif; ?>
                    <?php if ($order['status'] !== 'cancelled' && !$isGift): ?>
                    <a href="my_orders.php?reorder=<?= $order['id'] ?>" class="btn btn-primary" onclick="return confirm('إضافة كل أصناف هذا الطلب إلى السلة؟')"><i class="fa-solid fa-rotate"></i> أعِد هذا الطلب</a>
                    <?php endif; ?>
                    <a href="manual_order.php" class="btn btn-outline"><i class="fa-brands fa-whatsapp"></i> تواصل مع الدعم</a>
                    <a href="index.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> تسوّق مرة أخرى</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
