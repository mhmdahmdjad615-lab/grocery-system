<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();
$customer = store_current_customer($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $owner_name = trim($_POST['owner_name'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $pdo->prepare("UPDATE store_customers SET owner_name=?, whatsapp=?, email=?, city=?, address=? WHERE id=?")
        ->execute([$owner_name, $whatsapp ?: null, $email ?: null, $city ?: null, $address ?: null, $customer['id']]);

    if (!empty($_POST['new_password'])) {
        if (strlen($_POST['new_password']) >= 6) {
            $pdo->prepare("UPDATE store_customers SET password=? WHERE id=?")->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $customer['id']]);
        }
    }
    flash('تم تحديث بيانات الحساب');
    redirect('account.php');
}

// [جديد] رصيد الائتمان الديناميكي (نسبة من الإيراد المحقق) + بيانات الاستفادة الكلية
$creditInfo = get_merchant_credit_info($pdo, $customer['id']);
$savingsInfo = get_merchant_savings_info($pdo, $customer['id']);
$creditAlert = get_merchant_credit_alert($pdo, $customer['id'], $creditInfo);
$merchantBadge = get_merchant_badge($pdo, $savingsInfo['total_benefit']);

$statusLabels = [
    'pending'   => ['بانتظار موافقة الإدارة', 'badge-warning'],
    'approved'  => ['حساب مُفعّل', 'badge-success'],
    'rejected'  => ['تم رفض الحساب', 'badge-danger'],
    'suspended' => ['حساب معلّق', 'badge-navy'],
];
$meta = $statusLabels[$customer['status']];

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <h1 class="h1 mb-4"><i class="fa-solid fa-id-card text-gold"></i> بيانات الحساب</h1>
    <div class="row g-4">
        <div class="col-12 col-md-4">
            <div class="gcard mb-3" style="padding:28px;text-align:center">
                <div style="width:64px;height:64px;border-radius:50%;background:var(--p-50);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    <i class="fa-solid fa-shop text-gold" style="font-size:24px"></i>
                </div>
                <h3 style="margin-bottom:6px"><?= e($customer['shop_name']) ?></h3>
                <span class="badge-pill <?= $meta[1] ?>"><?= $meta[0] ?></span>
                <div class="mt-2"><span class="badge-pill" style="background:<?= $merchantBadge['color'] ?>;color:#fff"><i class="fa-solid <?= $merchantBadge['icon'] ?>"></i> <?= e($merchantBadge['label']) ?></span></div>
                <?php if ($customer['status'] === 'rejected' && $customer['reject_reason']): ?>
                    <p class="text-danger text-sm mt-2 mb-0"><?= e($customer['reject_reason']) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($creditAlert['is_over_limit'] || $creditAlert['is_near_limit']): ?>
            <div class="gcard mb-3" style="padding:14px 18px;background:<?= $creditAlert['is_over_limit'] ? '#fef2f2' : 'var(--p-50)' ?>;border-color:<?= $creditAlert['is_over_limit'] ? '#fca5a5' : 'var(--p-100)' ?>">
                <i class="fa-solid fa-triangle-exclamation" style="color:<?= $creditAlert['is_over_limit'] ? 'var(--c-danger)' : 'var(--p-600)' ?>"></i>
                <span class="text-sm fw-bold"><?= $creditAlert['is_over_limit'] ? 'لقد تجاوزت سقف الائتمان المتاح' : 'أنت قريب من سقف الائتمان (' . number_format($creditAlert['used_percentage'],0) . '%)' ?></span>
            </div>
            <?php endif; ?>

            <!-- [جديد] رصيد الائتمان الديناميكي -->
            <div class="gcard mb-3" style="padding:20px">
                <div class="text-sm fw-bold mb-2"><i class="fa-solid fa-credit-card text-gold"></i> رصيد الائتمان</div>
                <div class="d-flex justify-content-between text-sm mb-1">
                    <span class="text-muted-store">سقف الائتمان</span>
                    <span class="tabular-num fw-bold"><?= money($creditInfo['credit_limit']) ?></span>
                </div>
                <div class="d-flex justify-content-between text-sm mb-1">
                    <span class="text-muted-store">المطلوب سداده</span>
                    <span class="tabular-num fw-bold" style="color:<?= $creditInfo['due_from_merchant']>0?'var(--c-danger)':'var(--g-500)' ?>"><?= money($creditInfo['due_from_merchant']) ?></span>
                </div>
                <div class="d-flex justify-content-between text-sm mb-2">
                    <span class="text-muted-store">مستحق لك على المنصة</span>
                    <span class="tabular-num fw-bold" style="color:<?= $creditInfo['due_to_merchant']>0?'var(--c-success)':'var(--g-500)' ?>"><?= money($creditInfo['due_to_merchant']) ?></span>
                </div>
                <?php $pct = $creditInfo['credit_limit'] > 0 ? min(100, max(0, ($creditInfo['due_from_merchant'] / $creditInfo['credit_limit']) * 100)) : 0; ?>
                <div style="height:8px;border-radius:99px;background:var(--g-200);overflow:hidden;margin-bottom:6px">
                    <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--p-500),<?= $pct>85?'var(--c-danger)':'var(--p-600)' ?>)"></div>
                </div>
                <div class="text-xs text-muted-store">المتاح للطلب الآن: <strong class="text-gold"><?= money($creditInfo['available_credit']) ?></strong> (نسبتك: <?= number_format($creditInfo['percentage'],1) ?>%)</div>
                <div class="text-xs text-muted-store mt-1"><i class="fa-solid fa-calendar-days"></i> الإيراد محسوب <?= e(get_credit_period_label($creditInfo['period'])) ?></div>
                <a href="credit_statement.php" class="btn btn-outline btn-sm btn-block mt-3"><i class="fa-solid fa-file-invoice-dollar"></i> كشف حساب الائتمان الكامل</a>
            </div>

            <!-- [جديد] إجمالي الاستفادة -->
            <div class="gcard" style="padding:20px">
                <div class="text-sm fw-bold mb-2"><i class="fa-solid fa-hand-holding-heart text-gold"></i> إجمالي استفادتك</div>
                <div class="d-flex justify-content-between text-sm mb-1"><span class="text-muted-store">خصومات الفواتير</span><span class="tabular-num fw-bold"><?= money($savingsInfo['total_discounts']) ?></span></div>
                <div class="d-flex justify-content-between text-sm mb-1"><span class="text-muted-store">عدد الهدايا المجانية</span><span class="tabular-num fw-bold">🎁 <?= $savingsInfo['gift_count'] ?></span></div>
                <div class="d-flex justify-content-between text-sm mb-2"><span class="text-muted-store">قيمة الهدايا</span><span class="tabular-num fw-bold"><?= money($savingsInfo['gift_value']) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between"><span class="fw-bold">الإجمالي الكلي</span><span class="tabular-num fw-bold text-gold" style="font-size:18px"><?= money($savingsInfo['total_benefit']) ?></span></div>
            </div>
        </div>

        <div class="col-12 col-md-8">
            <div class="gcard">
                <div class="gcard-header"><i class="fa-solid fa-pen"></i> تعديل البيانات</div>
                <div class="gcard-body">
                    <form method="post">
                        <div class="mb-3"><label class="gform-label">اسم المسؤول</label><input type="text" name="owner_name" class="gform-control" value="<?= e($customer['owner_name']) ?>"></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="gform-label">واتساب</label><input type="text" name="whatsapp" class="gform-control" value="<?= e($customer['whatsapp']) ?>"></div>
                            <div class="col-md-6 mb-3"><label class="gform-label">البريد الإلكتروني</label><input type="email" name="email" class="gform-control" value="<?= e($customer['email']) ?>"></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="gform-label">المدينة (المحافظة)</label><input type="text" name="city" class="gform-control" value="<?= e($customer['city']) ?>"></div>
                            <div class="col-md-6 mb-3"><label class="gform-label">العنوان</label><input type="text" name="address" class="gform-control" value="<?= e($customer['address']) ?>"></div>
                        </div>
                        <div class="mb-3"><label class="gform-label">كلمة مرور جديدة (اتركها فارغة لعدم التغيير)</label><input type="password" name="new_password" class="gform-control"></div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ التعديلات</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
