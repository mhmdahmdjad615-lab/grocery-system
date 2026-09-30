<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();
$customer = store_current_customer($pdo);

// إحصائيات الطلبات لهذا المحل (حالة/عدد/إجمالي)
$statsStmt = $pdo->prepare("SELECT status, COUNT(*) c, COALESCE(SUM(total),0) t FROM store_orders WHERE store_customer_id = ? GROUP BY status");
$statsStmt->execute([store_current_id()]);
$byStatus = ['pending'=>0,'confirmed'=>0,'preparing'=>0,'shipped'=>0,'completed'=>0,'cancelled'=>0];
$totalOrders = 0; $totalSpent = 0;
foreach ($statsStmt->fetchAll() as $r) {
    $byStatus[$r['status']] = (int)$r['c'];
    $totalOrders += (int)$r['c'];
    if ($r['status'] !== 'cancelled') $totalSpent += (float)$r['t'];
}

// [جديد] رصيد الائتمان الديناميكي + بيانات الاستفادة (خصومات + هدايا)
$creditInfo = get_merchant_credit_info($pdo, store_current_id());
$savingsInfo = get_merchant_savings_info($pdo, store_current_id());
$creditAlert = get_merchant_credit_alert($pdo, store_current_id(), $creditInfo);
$merchantBadge = get_merchant_badge($pdo, $savingsInfo['total_benefit']);

$recentOrders = $pdo->prepare("SELECT * FROM store_orders WHERE store_customer_id = ? ORDER BY id DESC LIMIT 5");
$recentOrders->execute([store_current_id()]);
$recentOrders = $recentOrders->fetchAll();

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

    <div class="gcard fade-in mb-4" style="padding:28px;background:linear-gradient(120deg,var(--n-900),var(--n-700));border:none;color:#fff;overflow:hidden;position:relative">
        <div style="position:relative;z-index:1">
            <span class="badge-pill" style="background:rgba(212,160,23,.2);color:var(--p-500)"><i class="fa-solid fa-shop"></i> <?= e($customer['shop_name']) ?></span>
            <span class="badge-pill" style="background:<?= $merchantBadge['color'] ?>;color:#fff"><i class="fa-solid <?= $merchantBadge['icon'] ?>"></i> <?= e($merchantBadge['label']) ?></span>
            <h1 class="h1" style="color:#fff;margin:10px 0 4px">أهلاً <?= e($customer['owner_name']) ?> 👋</h1>
            <p style="color:rgba(255,255,255,.65);margin:0" class="text-sm">هذه لوحة تحكم محلك — تابع طلباتك، رصيدك، واطلب بسرعة من هنا.</p>
        </div>
    </div>

    <?php if ($creditAlert['is_over_limit']): ?>
    <div class="gcard mb-4" style="padding:16px 20px;background:#fef2f2;border-color:#fca5a5">
        <i class="fa-solid fa-circle-exclamation text-danger"></i>
        <strong class="text-danger">لقد تجاوزت سقف الائتمان المتاح لك</strong> — يرجى سداد جزء من رصيدك المستحق لتتمكن من إرسال طلبات جديدة.
    </div>
    <?php elseif ($creditAlert['is_near_limit']): ?>
    <div class="gcard mb-4" style="padding:16px 20px;background:var(--p-50);border-color:var(--p-100)">
        <i class="fa-solid fa-triangle-exclamation text-gold"></i>
        أنت تستخدم <strong><?= number_format($creditAlert['used_percentage'],0) ?>%</strong> من سقف ائتمانك المتاح. قد ترغب في سداد جزء من رصيدك قبل تقديم طلبات آجلة جديدة.
    </div>
    <?php endif; ?>

    <!-- [جديد] رصيد الائتمان -->
    <div class="section-head" style="margin-top:0"><div><h2><i class="fa-solid fa-credit-card text-gold"></i> رصيد الائتمان</h2><p>يُحسب كنسبة من الإيراد الذي حققته المنصة من مشترياتك (محسوب <?= e(get_credit_period_label($creditInfo['period'])) ?>)</p></div></div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">سقف الائتمان المتاح</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:var(--p-700)"><?= money($creditInfo['credit_limit']) ?></div>
                <div class="text-xs text-muted-store mt-1">نسبتك الحالية: <?= number_format($creditInfo['percentage'],1) ?>%</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">المتاح للطلب الآن</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:var(--c-success)"><?= money($creditInfo['available_credit']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">المطلوب سداده</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:<?= $creditInfo['due_from_merchant']>0?'var(--c-danger)':'var(--g-500)' ?>"><?= money($creditInfo['due_from_merchant']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">مستحق لك على المنصة</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:<?= $creditInfo['due_to_merchant']>0?'var(--c-success)':'var(--g-500)' ?>"><?= money($creditInfo['due_to_merchant']) ?></div>
            </div>
        </div>
    </div>
    <?php if ($creditInfo['credit_limit'] > 0): ?>
    <div class="gcard mb-4" style="padding:16px 20px">
        <?php $usedPct = $creditInfo['credit_limit'] > 0 ? min(100, ($creditInfo['due_from_merchant'] / $creditInfo['credit_limit']) * 100) : 0; ?>
        <div class="d-flex justify-content-between text-xs mb-2">
            <span class="text-muted-store">مستخدَم من سقف الائتمان</span>
            <span class="fw-bold tabular-num"><?= money($creditInfo['due_from_merchant']) ?> / <?= money($creditInfo['credit_limit']) ?></span>
        </div>
        <div style="height:8px;border-radius:99px;background:var(--g-200);overflow:hidden">
            <div style="height:100%;width:<?= $usedPct ?>%;background:linear-gradient(90deg,var(--p-500),<?= $usedPct>85?'var(--c-danger)':'var(--p-600)' ?>)"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- [جديد] استفادتك من المنصة -->
    <div class="section-head"><div><h2><i class="fa-solid fa-hand-holding-heart text-gold"></i> استفادتك من المنصة</h2><p>إجمالي ما وفّرته من خصومات وهدايا مجانية منذ انضمامك</p></div></div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">إجمالي خصومات الفواتير</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--n-800)"><?= money($savingsInfo['total_discounts']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">عدد الهدايا المجانية</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--n-800)"><i class="fa-solid fa-gift text-gold"></i> <?= $savingsInfo['gift_count'] ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">قيمة الهدايا المجانية</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--n-800)"><?= money($savingsInfo['gift_value']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px;background:var(--p-50);border-color:var(--p-100)">
                <div class="text-xs mb-1" style="color:var(--p-700)">إجمالي الاستفادة الكلية</div>
                <div class="tabular-num fw-bold" style="font-size:20px;color:var(--p-700)"><?= money($savingsInfo['total_benefit']) ?></div>
            </div>
        </div>
    </div>

    <!-- إحصائيات سريعة -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">إجمالي الطلبات</div>
                <div class="tabular-num fw-bold" style="font-size:24px;color:var(--n-800)"><?= $totalOrders ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">بانتظار الاعتماد</div>
                <div class="tabular-num fw-bold" style="font-size:24px;color:var(--c-warning)"><?= $byStatus['pending'] ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">إجمالي المشتريات</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:var(--p-700)"><?= money($totalSpent) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="gcard" style="padding:18px">
                <div class="text-xs text-muted-store mb-1">الرصيد الحالي</div>
                <div class="tabular-num fw-bold" style="font-size:22px;color:<?= $creditInfo['balance'] > 0 ? 'var(--c-danger)' : 'var(--c-success)' ?>"><?= money($creditInfo['balance']) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <!-- إجراءات سريعة -->
            <div class="section-head" style="margin-top:0"><div><h2>إجراءات سريعة</h2></div></div>
            <div class="row g-3 mb-2">
                <div class="col-6 col-md-4">
                    <a href="index.php" class="gcard d-block text-center" style="padding:20px 10px;text-decoration:none;transition:transform .2s var(--ease-out)" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='none'">
                        <i class="fa-solid fa-shop" style="font-size:20px;color:var(--p-600)"></i>
                        <div class="text-xs fw-bold mt-2" style="color:var(--g-900)">تصفّح المنتجات</div>
                    </a>
                </div>
                <div class="col-6 col-md-4">
                    <a href="quick_order.php" class="gcard d-block text-center" style="padding:20px 10px;text-decoration:none">
                        <i class="fa-solid fa-bolt" style="font-size:20px;color:var(--p-600)"></i>
                        <div class="text-xs fw-bold mt-2" style="color:var(--g-900)">طلب سريع</div>
                    </a>
                </div>
                <div class="col-6 col-md-4">
                    <a href="cart.php" class="gcard d-block text-center" style="padding:20px 10px;text-decoration:none">
                        <i class="fa-solid fa-cart-shopping" style="font-size:20px;color:var(--p-600)"></i>
                        <div class="text-xs fw-bold mt-2" style="color:var(--g-900)">السلة الحالية</div>
                    </a>
                </div>
                <div class="col-6 col-md-4">
                    <a href="manual_order.php" class="gcard d-block text-center" style="padding:20px 10px;text-decoration:none">
                        <i class="fa-brands fa-whatsapp" style="font-size:20px;color:#25D366"></i>
                        <div class="text-xs fw-bold mt-2" style="color:var(--g-900)">تواصل معنا</div>
                    </a>
                </div>
                <div class="col-6 col-md-4">
                    <a href="credit_statement.php" class="gcard d-block text-center" style="padding:20px 10px;text-decoration:none">
                        <i class="fa-solid fa-file-invoice-dollar" style="font-size:20px;color:var(--p-600)"></i>
                        <div class="text-xs fw-bold mt-2" style="color:var(--g-900)">كشف حساب الائتمان</div>
                    </a>
                </div>
            </div>

            <!-- آخر الطلبات -->
            <div class="section-head"><div><h2>آخر الطلبات</h2></div><a href="my_orders.php" class="btn btn-outline btn-sm">عرض الكل</a></div>
            <div class="gcard">
                <?php if (empty($recentOrders)): ?>
                <div style="padding:40px;text-align:center">
                    <i class="fa-solid fa-box-open" style="font-size:26px;color:var(--g-200);margin-bottom:10px"></i>
                    <p class="text-muted-store mb-0">لا توجد طلبات بعد</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>رقم الطلب</th><th>التاريخ</th><th>الإجمالي</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recentOrders as $o): $meta = $statusLabels[$o['status']]; ?>
                        <tr>
                            <td class="fw-bold">
                                <?php if ($o['order_type'] === 'gift'): ?><span class="badge-pill badge-warning">🎁 هدية</span><?php endif; ?>
                                <?= e($o['order_number']) ?>
                            </td>
                            <td class="text-sm text-muted-store"><?= e($o['created_at']) ?></td>
                            <td class="tabular-num fw-bold" style="color:var(--p-700)"><?= $o['order_type']==='gift' ? 'مجاناً' : money($o['total']) ?></td>
                            <td><span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span></td>
                            <td><a href="order_view.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">التفاصيل</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="section-head" style="margin-top:0"><div><h2>حالة الحساب</h2></div></div>
            <div class="gcard mb-3" style="padding:20px">
                <?php
                $statusMeta = [
                    'pending'   => ['بانتظار موافقة الإدارة', 'badge-warning'],
                    'approved'  => ['حساب مُفعّل', 'badge-success'],
                    'rejected'  => ['تم رفض الحساب', 'badge-danger'],
                    'suspended' => ['حساب معلّق', 'badge-navy'],
                ][$customer['status']];
                ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-sm text-muted-store">حالة الحساب</span>
                    <span class="badge-pill <?= $statusMeta[1] ?>"><?= $statusMeta[0] ?></span>
                </div>
                <a href="account.php" class="btn btn-outline btn-sm btn-block mt-3"><i class="fa-solid fa-id-card"></i> بيانات الحساب</a>
            </div>

            <div class="gcard" style="padding:20px">
                <div class="text-sm fw-bold mb-2"><i class="fa-solid fa-chart-pie text-gold"></i> توزيع الطلبات</div>
                <?php foreach (['pending'=>'بانتظار الاعتماد','confirmed'=>'معتمد','preparing'=>'قيد التجهيز','shipped'=>'تم الشحن','completed'=>'مكتمل'] as $k=>$lbl): ?>
                <div class="d-flex justify-content-between text-xs mb-2">
                    <span class="text-muted-store"><?= $lbl ?></span>
                    <span class="fw-bold tabular-num"><?= $byStatus[$k] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
