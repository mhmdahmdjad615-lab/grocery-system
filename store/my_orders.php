<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();

// إعادة طلب سابق: يضيف كل أصنافه للسلة الحالية دفعة واحدة
if (isset($_GET['reorder'])) {
    $order_id = (int)$_GET['reorder'];
    $chk = $pdo->prepare("SELECT id, order_type FROM store_orders WHERE id = ? AND store_customer_id = ?");
    $chk->execute([$order_id, store_current_id()]);
    $ordRow = $chk->fetch();
    if ($ordRow) {
        if ($ordRow['order_type'] === 'gift') {
            flash('لا يمكن إعادة طلب هدية مجانية للسلة العادية', 'warning');
            redirect('my_orders.php');
        }
        $itemsStmt = $pdo->prepare("SELECT oi.*, p.show_in_store FROM store_order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll();

        $added = 0; $skipped = 0;
        foreach ($items as $it) {
            if (!$it['show_in_store']) { $skipped++; continue; }
            // التأكد من أن الوحدة المستخدمة سابقاً ما زالت موجودة، وإلا الرجوع للوحدة الأساسية تلقائياً
            $unit = $it['unit_id'] ? get_unit_by_id($pdo, $it['product_id'], $it['unit_id']) : get_base_unit($pdo, $it['product_id']);
            cart_add($it['product_id'], $unit['id'], (float)$it['quantity']);
            $added++;
        }

        if ($added > 0) {
            flash($skipped > 0
                ? "تمت إضافة {$added} صنف للسلة، وتعذّر إضافة {$skipped} صنف لم يعد متاحاً بالمتجر"
                : 'تمت إضافة كل أصناف الطلب للسلة بنجاح', $skipped > 0 ? 'warning' : 'success');
            redirect('cart.php');
        } else {
            flash('تعذّر إعادة هذا الطلب — كل أصنافه لم تعد متاحة بالمتجر حالياً', 'danger');
        }
    } else {
        flash('الطلب غير موجود', 'danger');
    }
    redirect('my_orders.php');
}

$stmt = $pdo->prepare("SELECT * FROM store_orders WHERE store_customer_id = ? ORDER BY id DESC");
$stmt->execute([store_current_id()]);
$orders = $stmt->fetchAll();

$statusLabels = [
    'pending'   => ['بانتظار الاعتماد', 'badge-warning'],
    'confirmed' => ['معتمد', 'badge-success'],
    'preparing' => ['قيد التجهيز', 'badge-info'],
    'shipped'   => ['تم الشحن', 'badge-info'],
    'completed' => ['مكتمل', 'badge-navy'],
    'cancelled' => ['ملغي', 'badge-danger'],
];

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <h1 class="h1 mb-4"><i class="fa-solid fa-box text-gold"></i> طلباتي</h1>

    <?php if (empty($orders)): ?>
    <div class="gcard" style="padding:56px;text-align:center">
        <i class="fa-solid fa-box-open" style="font-size:32px;color:var(--g-200);margin-bottom:12px"></i>
        <h3 style="margin-bottom:8px">لا توجد طلبات بعد</h3>
        <p class="text-muted-store mb-4">تصفّح منتجاتنا وابدأ أول طلب لمحلك</p>
        <a href="index.php" class="btn btn-primary">تصفّح المنتجات</a>
    </div>
    <?php else: ?>

    <!-- عرض بطاقات على الموبايل -->
    <div class="d-lg-none d-flex flex-column gap-3">
        <?php foreach ($orders as $o): $meta = $statusLabels[$o['status']]; $isGift = $o['order_type'] === 'gift'; ?>
        <div class="gcard" style="padding:16px<?= $isGift ? ';border-color:var(--p-100);background:var(--p-50)' : '' ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-bold">
                        <?php if ($isGift): ?><span class="badge-pill badge-warning">🎁 هدية مجانية</span><?php endif; ?>
                        <?= e($o['order_number']) ?>
                    </div>
                    <div class="text-xs text-muted-store"><?= e($o['created_at']) ?></div>
                </div>
                <span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span>
            </div>
            <div class="tabular-num fw-bold mb-3" style="color:var(--p-700);font-size:18px"><?= $isGift ? 'مجاناً' : money($o['total']) ?></div>
            <div class="d-flex gap-2">
                <a href="order_view.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">التفاصيل</a>
                <?php if ($o['status'] !== 'cancelled' && !$isGift): ?>
                <a href="my_orders.php?reorder=<?= $o['id'] ?>" class="btn btn-primary btn-sm" style="flex:1" onclick="return confirm('إضافة كل أصناف هذا الطلب إلى السلة؟')"><i class="fa-solid fa-rotate"></i> أعِد الطلب</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- عرض جدول على الديسكتوب -->
    <div class="gcard d-none d-lg-block">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>رقم الطلب</th><th>التاريخ</th><th>الإجمالي</th><th>الحالة</th><th style="width:280px"></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): $meta = $statusLabels[$o['status']]; $isGift = $o['order_type'] === 'gift'; ?>
                    <tr class="<?= $isGift ? 'table-warning' : '' ?>">
                        <td class="fw-bold">
                            <?php if ($isGift): ?><span class="badge-pill badge-warning me-1">🎁 هدية</span><?php endif; ?>
                            <?= e($o['order_number']) ?>
                        </td>
                        <td class="text-sm text-muted-store"><?= e($o['created_at']) ?></td>
                        <td class="tabular-num fw-bold" style="color:var(--p-700)"><?= $isGift ? 'مجاناً' : money($o['total']) ?></td>
                        <td><span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span></td>
                        <td>
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="order_view.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">التفاصيل</a>
                                <?php if ($o['status'] !== 'cancelled' && !$isGift): ?>
                                <a href="my_orders.php?reorder=<?= $o['id'] ?>" class="btn btn-primary btn-sm" onclick="return confirm('إضافة كل أصناف هذا الطلب إلى السلة؟')"><i class="fa-solid fa-rotate"></i> أعِد الطلب</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
