<?php
require_once __DIR__ . '/includes/store_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        foreach ($_POST['qty'] as $key => $qty) {
            cart_update_qty($key, (float)$qty);
        }
        flash('تم تحديث السلة');
    } elseif (isset($_POST['remove_key'])) {
        cart_remove($_POST['remove_key']);
        flash('تم حذف الصنف من السلة');
    }
    redirect('cart.php');
}

$cart = cart_details($pdo);
$minOrder = (float)get_store_setting($pdo, 'min_order_amount', 0);
$remainingForMin = max(0, $minOrder - $cart['total']);
$minPct = $minOrder > 0 ? min(100, ($cart['total'] / $minOrder) * 100) : 100;

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <h1 class="h1" style="margin-bottom:4px"><i class="fa-solid fa-cart-shopping text-gold"></i> سلة المشتريات</h1>
    <p class="text-muted-store mb-4"><?= count($cart['items']) ?> منتج بالسلة</p>

    <?php if (empty($cart['items'])): ?>
        <div class="gcard fade-in" style="padding:56px;text-align:center">
            <i class="fa-solid fa-cart-shopping" style="font-size:36px;color:var(--g-200);margin-bottom:14px"></i>
            <h3 style="margin-bottom:8px">السلة فارغة حالياً</h3>
            <p class="text-muted-store mb-4">تصفّح منتجاتنا وابدأ طلبك الآن</p>
            <a href="index.php" class="btn btn-primary">تصفّح المنتجات</a>
        </div>
    <?php else: ?>
    <form method="post">
        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <?php if ($minOrder > 0 && $remainingForMin > 0): ?>
                <div class="gcard mb-3" style="padding:16px;border-color:var(--p-100);background:var(--p-50)">
                    <div class="text-sm mb-2"><i class="fa-solid fa-circle-info text-gold"></i> ينقصك <b class="text-gold"><?= money($remainingForMin) ?></b> للوصول للحد الأدنى للطلب (<?= money($minOrder) ?>)</div>
                    <div style="height:8px;border-radius:99px;background:#fff;overflow:hidden"><div style="height:100%;width:<?= $minPct ?>%;background:linear-gradient(90deg,var(--p-500),var(--p-600))"></div></div>
                </div>
                <?php endif; ?>

                <div class="gcard">
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle" style="font-family:var(--font-body)">
                            <thead><tr><th>المنتج</th><th>الوحدة</th><th>سعر الوحدة</th><th style="width:130px">الكمية</th><th>الإجمالي</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($cart['items'] as $key => $it): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px">
                                            <img src="<?= $it['product']['store_image'] ? e($it['product']['store_image']) : 'https://placehold.co/80x80?text=' . urlencode($it['product']['name']) ?>" style="width:52px;height:52px;object-fit:cover;border-radius:10px">
                                            <a href="product.php?id=<?= $it['product']['id'] ?>" class="fw-bold" style="color:var(--g-900)"><?= e($it['product']['name']) ?></a>
                                        </div>
                                    </td>
                                    <td><span class="badge-pill badge-info"><?= e($it['unit']['unit_name']) ?></span></td>
                                    <td class="tabular-num"><?= money($it['unit_price']) ?></td>
                                    <td><input type="number" step="0.01" min="0" name="qty[<?= e($key) ?>]" value="<?= $it['qty'] ?>" class="gform-control"></td>
                                    <td class="tabular-num fw-bold text-gold"><?= money($it['line_total']) ?></td>
                                    <td><button type="submit" name="remove_key" value="<?= e($key) ?>" class="btn btn-ghost" formnovalidate title="حذف"><i class="fa-solid fa-trash text-danger"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-3">
                    <button type="submit" name="update" value="1" class="btn btn-outline"><i class="fa-solid fa-rotate"></i> تحديث الكميات</button>
                    <a href="index.php" class="btn btn-ghost"><i class="fa-solid fa-plus"></i> إضافة منتج آخر</a>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="gcard" style="position:sticky;top:90px">
                    <div class="gcard-header"><i class="fa-solid fa-receipt"></i> ملخص الطلب</div>
                    <div class="gcard-body">
                        <div class="d-flex justify-content-between text-sm mb-2"><span class="text-muted-store">المجموع الفرعي</span><span class="tabular-num fw-bold"><?= money($cart['total']) ?></span></div>
                        <div class="d-flex justify-content-between text-sm mb-3"><span class="text-muted-store">الشحن</span><span class="text-success fw-bold">يُحدَّد عند الاعتماد</span></div>
                        <hr>
                        <div class="d-flex justify-content-between mb-4"><span class="fw-bold">الإجمالي</span><span class="tabular-num fw-bold" style="font-size:22px;color:var(--p-700)"><?= money($cart['total']) ?></span></div>

                        <?php if ($minOrder > 0 && $cart['total'] < $minOrder): ?>
                            <div class="badge-pill badge-warning mb-3" style="display:block;text-align:center;padding:10px"><i class="fa-solid fa-triangle-exclamation"></i> إجمالي الطلب أقل من الحد الأدنى المسموح (<?= money($minOrder) ?>)</div>
                        <?php elseif (store_logged_in()): ?>
                            <a href="checkout.php" class="btn btn-primary btn-lg btn-block mb-2"><i class="fa-solid fa-check"></i> إتمام الطلب</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-primary btn-lg btn-block mb-2">سجّل الدخول لإتمام الطلب</a>
                        <?php endif; ?>

                        <p class="text-xs text-muted-store text-center mb-0"><i class="fa-solid fa-lock"></i> يتحوّل طلبك إلى رصيد آجل على حساب محلك بعد الاعتماد</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
