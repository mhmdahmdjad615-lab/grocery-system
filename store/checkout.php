<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();

$customer = store_current_customer($pdo);
if ($customer['status'] !== 'approved') {
    flash('حسابك لم يُفعَّل بعد من الإدارة، سيتم إشعارك فور الموافقة عليه', 'warning');
    redirect('account.php');
}

$cart = cart_details($pdo);
if (empty($cart['items'])) {
    flash('السلة فارغة', 'danger');
    redirect('cart.php');
}
$minOrder = (float)get_store_setting($pdo, 'min_order_amount', 0);
if ($minOrder > 0 && $cart['total'] < $minOrder) {
    flash('إجمالي طلبك أقل من الحد الأدنى المسموح ' . money($minOrder), 'danger');
    redirect('cart.php');
}

// [جديد] رصيد الائتمان المتاح لهذا التاجر الآن
$creditInfo = get_merchant_credit_info($pdo, store_current_id());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['notes'] ?? '');
    $paidNow = (float)($_POST['paid_now'] ?? 0);
    if ($paidNow < 0) $paidNow = 0;
    if ($paidNow > $cart['total']) $paidNow = $cart['total'];

    $remainingAfterPayment = $cart['total'] - $paidNow;
    if ($remainingAfterPayment > $creditInfo['available_credit']) {
        flash('المبلغ الآجل المطلوب (' . money($remainingAfterPayment) . ') يتجاوز رصيد الائتمان المتاح لك (' . money($creditInfo['available_credit']) . '). يمكنك زيادة المبلغ المدفوع الآن أو تقليل حجم الطلب.', 'danger');
        redirect('checkout.php');
    }

    try {
        $pdo->beginTransaction();
        $order_number = generate_store_order_number($pdo);
        $pdo->prepare("INSERT INTO store_orders (order_number, store_customer_id, status, subtotal, total, paid_amount, order_type, notes) VALUES (?,?,?,?,?,?,'sale',?)")
            ->execute([$order_number, store_current_id(), 'pending', $cart['total'], $cart['total'], $paidNow, $notes]);
        $order_id = $pdo->lastInsertId();

        foreach ($cart['items'] as $it) {
            $pdo->prepare("INSERT INTO store_order_items (order_id, product_id, unit_id, unit_name, quantity, price, total) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order_id, $it['product']['id'], $it['unit']['id'], $it['unit']['unit_name'], $it['qty'], $it['unit_price'], $it['line_total']]);
        }

        $pdo->commit();
        cart_clear();
        redirect('order_success.php?id=' . $order_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء إرسال الطلب: ' . $e->getMessage(), 'danger');
        redirect('checkout.php');
    }
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <h1 class="h1 mb-4"><i class="fa-solid fa-check-double text-gold"></i> إتمام الطلب</h1>
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="gcard mb-3">
                <div class="gcard-header"><i class="fa-solid fa-shop"></i> بيانات المحل</div>
                <div class="gcard-body">
                    <div class="row g-3 text-sm">
                        <div class="col-6"><span class="text-muted-store d-block">المحل</span><strong><?= e($customer['shop_name']) ?></strong></div>
                        <div class="col-6"><span class="text-muted-store d-block">الهاتف</span><strong><?= e($customer['phone']) ?></strong></div>
                        <div class="col-12"><span class="text-muted-store d-block">العنوان</span><strong><?= e($customer['city']) ?> - <?= e($customer['address']) ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- [جديد] رصيد الائتمان المتاح -->
            <div class="gcard mb-3" style="padding:16px;background:var(--p-50);border-color:var(--p-100)">
                <div class="d-flex justify-content-between text-sm mb-1"><span class="text-muted-store">رصيد الائتمان المتاح لك الآن</span><span class="tabular-num fw-bold text-gold"><?= money($creditInfo['available_credit']) ?></span></div>
                <div class="d-flex justify-content-between text-sm"><span class="text-muted-store">المطلوب سداده حالياً</span><span class="tabular-num fw-bold"><?= money($creditInfo['due_from_merchant']) ?></span></div>
            </div>

            <form method="post" id="checkoutForm">
                <div class="gcard mb-3">
                    <div class="gcard-header"><i class="fa-solid fa-money-bill-wave"></i> الدفع</div>
                    <div class="gcard-body">
                        <label class="gform-label">المبلغ المدفوع الآن (اختياري — الباقي يُسجَّل آجلاً على حساب محلك)</label>
                        <input type="number" step="0.01" min="0" max="<?= $cart['total'] ?>" name="paid_now" id="paidNowInput" class="gform-control" value="0" oninput="recalcRemain()">
                        <div class="text-xs text-muted-store mt-2">
                            المتبقي آجلاً: <strong id="remainDisplay" class="tabular-num"><?= number_format($cart['total'],2) ?></strong> <?= CURRENCY ?>
                        </div>
                    </div>
                </div>

                <div class="gcard mb-3">
                    <div class="gcard-header"><i class="fa-solid fa-note-sticky"></i> ملاحظات على الطلب</div>
                    <div class="gcard-body">
                        <textarea name="notes" class="gform-control" rows="3" placeholder="اختياري — مثال: التوصيل صباحاً"></textarea>
                    </div>
                </div>

                <div class="gcard mb-4" style="background:var(--p-50);border-color:var(--p-100)">
                    <div class="gcard-body text-sm">
                        <i class="fa-solid fa-circle-info text-gold"></i>
                        سيتم إرسال طلبك للمراجعة والاعتماد من الإدارة، وسيتحول المبلغ الآجل (إن وُجد) إلى رصيد
                        على حساب محلك كأي عميل جملة، ويمكنك متابعة حالته من صفحة "طلباتي".
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fa-solid fa-paper-plane"></i> إرسال الطلب</button>
            </form>
        </div>

        <div class="col-12 col-lg-5">
            <div class="gcard" style="position:sticky;top:90px">
                <div class="gcard-header"><i class="fa-solid fa-receipt"></i> ملخص الطلب</div>
                <div class="gcard-body p-0">
                    <table class="table mb-0">
                        <?php foreach ($cart['items'] as $it): ?>
                        <tr>
                            <td class="text-sm"><?= e($it['product']['name']) ?><br><span class="text-xs text-muted-store"><?= $it['qty'] ?> <?= e($it['unit']['unit_name']) ?></span></td>
                            <td class="text-end tabular-num fw-bold"><?= money($it['line_total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold"><td>الإجمالي</td><td class="text-end tabular-num" style="font-size:18px;color:var(--p-700)"><?= money($cart['total']) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const CART_TOTAL = <?= (float)$cart['total'] ?>;
function recalcRemain() {
    const paid = parseFloat(document.getElementById('paidNowInput').value) || 0;
    const remain = Math.max(0, CART_TOTAL - paid);
    document.getElementById('remainDisplay').textContent = remain.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
</script>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
