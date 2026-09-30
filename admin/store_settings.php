<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$fields = [
    'site_name'         => 'اسم المتجر الظاهر للعملاء',
    'whatsapp_number'   => 'رقم واتساب لاستقبال الطلبات (بصيغة دولية بدون + مثل 201234567890)',
    'telegram_username' => 'معرّف/يوزر تليجرام (بدون @)',
    'contact_phone'     => 'رقم هاتف التواصل العام',
    'min_order_amount'  => 'الحد الأدنى لقيمة الطلب (اتركه 0 لعدم التقييد)',
    'banner_text'       => 'نص شريط الإعلان بالصفحة الرئيسية',
    'registration_note' => 'رسالة تظهر بعد تسجيل محل جديد',
];

// [جديد] إعدادات رصيد الائتمان الديناميكي للتجار + نصوص فاتورة الهدية المجانية
$creditFields = [
    'credit_limit_percentage' => 'النسبة المئوية العامة لسقف الائتمان (من إجمالي الإيراد المحقق من التاجر)',
    'gift_header_text'        => 'النص الذي يظهر أعلى فاتورة الهدية المجانية (قبل اسم المنصة واسم التاجر)',
    'gift_stamp_text'         => 'نص ختم "هدية مجانية" المطبوع على فاتورة الهدية',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $key => $label) {
        set_store_setting($pdo, $key, trim($_POST[$key] ?? ''));
    }
    $pct = (float)($_POST['credit_limit_percentage'] ?? 20);
    if ($pct < 0) $pct = 0;
    if ($pct > 100) $pct = 100;
    set_store_setting($pdo, 'credit_limit_percentage', (string)$pct);
    set_store_setting($pdo, 'gift_header_text', trim($_POST['gift_header_text'] ?? ''));
    set_store_setting($pdo, 'gift_stamp_text', trim($_POST['gift_stamp_text'] ?? ''));

    $alertPct = (float)($_POST['credit_alert_threshold'] ?? 85);
    if ($alertPct < 1) $alertPct = 1;
    if ($alertPct > 99) $alertPct = 99;
    set_store_setting($pdo, 'credit_alert_threshold', (string)$alertPct);
    set_store_setting($pdo, 'badge_silver_threshold', (string)max(0, (float)($_POST['badge_silver_threshold'] ?? 2000)));
    set_store_setting($pdo, 'badge_gold_threshold', (string)max(0, (float)($_POST['badge_gold_threshold'] ?? 8000)));

    // [جديد] مدة حساب الإيراد المحقق من التاجر (المعتمد لحساب سقف الائتمان)
    $periodMode = $_POST['credit_period_mode'] ?? 'all_time';
    $allowedModes = ['all_time', 'last_1_month', 'last_2_months', 'last_3_months', 'custom'];
    if (!in_array($periodMode, $allowedModes)) $periodMode = 'all_time';

    $periodFrom = trim($_POST['credit_period_from'] ?? '');
    $periodTo   = trim($_POST['credit_period_to'] ?? '');
    if ($periodMode === 'custom') {
        if ($periodFrom === '' || $periodTo === '' || $periodFrom > $periodTo) {
            flash('يجب تحديد تاريخ بداية ونهاية صحيحين (البداية قبل النهاية) عند اختيار "فترة مخصصة"', 'danger');
            redirect('store_settings.php');
        }
    }
    set_store_setting($pdo, 'credit_period_mode', $periodMode);
    set_store_setting($pdo, 'credit_period_from', $periodFrom);
    set_store_setting($pdo, 'credit_period_to', $periodTo);

    flash('تم حفظ إعدادات المتجر بنجاح');
    redirect('store_settings.php');
}
?>
<h4 class="mb-4">إعدادات المتجر الإلكتروني</h4>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">الإعدادات العامة</div>
            <div class="card-body">
                <form method="post">
                    <?php foreach ($fields as $key => $label): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= e($label) ?></label>
                        <?php if (in_array($key, ['banner_text','registration_note'])): ?>
                            <textarea name="<?= $key ?>" class="form-control" rows="2"><?= e(get_store_setting($pdo, $key)) ?></textarea>
                        <?php else: ?>
                            <input type="text" name="<?= $key ?>" class="form-control" value="<?= e(get_store_setting($pdo, $key)) ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <hr>
                    <h6 class="mb-3"><i class="fa-solid fa-credit-card text-primary"></i> رصيد الائتمان وفواتير الهدايا</h6>
                    <div class="mb-3">
                        <label class="form-label"><?= e($creditFields['credit_limit_percentage']) ?></label>
                        <div class="input-group" style="max-width:200px">
                            <input type="number" step="0.01" min="0" max="100" name="credit_limit_percentage" class="form-control" value="<?= e(get_store_setting($pdo, 'credit_limit_percentage', '20')) ?>">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">يمكن تخصيص نسبة مختلفة لتاجر معيّن من صفحة <a href="store_merchant_credit.php">رصيد ائتمان التجار</a>.</small>
                    </div>

                    <?php $currentPeriodMode = get_store_setting($pdo, 'credit_period_mode', 'all_time'); ?>
                    <div class="mb-3">
                        <label class="form-label">مدة حساب "الإيراد المحقق من التاجر" (المعتمد لحساب سقف الائتمان)</label>
                        <select name="credit_period_mode" id="creditPeriodMode" class="form-select" style="max-width:320px" onchange="toggleCustomPeriod()">
                            <option value="all_time" <?= $currentPeriodMode==='all_time'?'selected':'' ?>>كل الفترة (منذ بداية التعامل)</option>
                            <option value="last_1_month" <?= $currentPeriodMode==='last_1_month'?'selected':'' ?>>آخر شهر</option>
                            <option value="last_2_months" <?= $currentPeriodMode==='last_2_months'?'selected':'' ?>>آخر شهرين</option>
                            <option value="last_3_months" <?= $currentPeriodMode==='last_3_months'?'selected':'' ?>>آخر 3 أشهر</option>
                            <option value="custom" <?= $currentPeriodMode==='custom'?'selected':'' ?>>فترة مخصصة (من - إلى)</option>
                        </select>
                        <div id="customPeriodRow" class="row g-2 mt-2" style="<?= $currentPeriodMode==='custom' ? '' : 'display:none' ?>; max-width:420px">
                            <div class="col-6">
                                <label class="form-label small mb-1">من تاريخ</label>
                                <input type="date" name="credit_period_from" class="form-control" value="<?= e(get_store_setting($pdo, 'credit_period_from', '')) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">إلى تاريخ</label>
                                <input type="date" name="credit_period_to" class="form-control" value="<?= e(get_store_setting($pdo, 'credit_period_to', '')) ?>">
                            </div>
                        </div>
                        <small class="text-muted d-block mt-1">
                            يُحدِّد هذا الخيار الفترة الزمنية التي يُحسب خلالها إيراد كل تاجر (الفرق بين سعر شراء
                            المنصة وسعر بيعها له) قبل ضربه بالنسبة أعلاه للحصول على سقف الائتمان. تغييره يُحدِّث
                            سقف الائتمان لكل التجار فوراً بالحساب اللحظي.
                        </small>
                    </div>
                    <script>
                    function toggleCustomPeriod() {
                        const mode = document.getElementById('creditPeriodMode').value;
                        document.getElementById('customPeriodRow').style.display = (mode === 'custom') ? '' : 'none';
                    }
                    </script>
                    <div class="mb-3">
                        <label class="form-label"><?= e($creditFields['gift_header_text']) ?></label>
                        <input type="text" name="gift_header_text" class="form-control" value="<?= e(get_store_setting($pdo, 'gift_header_text', 'هدية مجانية مقدمة من')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e($creditFields['gift_stamp_text']) ?></label>
                        <input type="text" name="gift_stamp_text" class="form-control" value="<?= e(get_store_setting($pdo, 'gift_stamp_text', 'هدية مجانية')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">نسبة التنبيه عند اقتراب التاجر من حد الائتمان</label>
                        <div class="input-group" style="max-width:200px">
                            <input type="number" step="1" min="1" max="99" name="credit_alert_threshold" class="form-control" value="<?= e(get_store_setting($pdo, 'credit_alert_threshold', '85')) ?>">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">عند استخدام هذه النسبة من سقف الائتمان، يظهر تنبيه للتاجر ويُميَّز بلوحة تحكم الإدارة.</small>
                    </div>

                    <hr>
                    <h6 class="mb-3"><i class="fa-solid fa-award text-warning"></i> شارات التجار (تُحسب تلقائياً من إجمالي الاستفادة)</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fa-solid fa-medal" style="color:#64748B"></i> حد شارة "تاجر فضي موثوق"</label>
                            <input type="number" step="0.01" min="0" name="badge_silver_threshold" class="form-control" value="<?= e(get_store_setting($pdo, 'badge_silver_threshold', '2000')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fa-solid fa-crown" style="color:#B8860B"></i> حد شارة "تاجر ذهبي VIP"</label>
                            <input type="number" step="0.01" min="0" name="badge_gold_threshold" class="form-control" value="<?= e(get_store_setting($pdo, 'badge_gold_threshold', '8000')) ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">حفظ الإعدادات</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">روابط ذات صلة</div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="store_merchant_credit.php" class="btn btn-outline-primary text-start"><i class="fa-solid fa-credit-card"></i> رصيد ائتمان التجار (نسب خاصة لكل تاجر)</a>
                <a href="store_gift_invoice_add.php" class="btn btn-outline-success text-start"><i class="fa-solid fa-gift"></i> إصدار فاتورة هدية مجانية لتاجر</a>
                <a href="store_merchant_benefits.php" class="btn btn-outline-info text-start"><i class="fa-solid fa-chart-line"></i> تقرير استفادة التجار وإحصائيات المحافظات</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
