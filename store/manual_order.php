<?php
require_once __DIR__ . '/includes/store_functions.php';

$customer = store_logged_in() ? store_current_customer($pdo) : null;
$saved = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel = $_POST['channel'] === 'telegram' ? 'telegram' : 'whatsapp';
    $shop_name = trim($_POST['shop_name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($shop_name === '' || $phone === '' || $message === '') {
        flash('يرجى تعبئة اسم المحل ورقم الهاتف وتفاصيل الطلب', 'danger');
        redirect('manual_order.php');
    }

    $pdo->prepare("INSERT INTO manual_order_requests (channel, shop_name, contact_name, phone, city, message, store_customer_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$channel, $shop_name, $contact_name ?: null, $phone, $city ?: null, $message, $customer['id'] ?? null]);

    $fullText = "طلب جديد من: {$shop_name}\nالمسؤول: {$contact_name}\nالهاتف: {$phone}\nالمدينة: {$city}\n---\n{$message}";

    $saved = [
        'channel' => $channel,
        'wa_link' => store_whatsapp_link($pdo, $fullText),
        'tg_link' => store_telegram_link($pdo),
        'text'    => $fullText,
    ];
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:24px;padding-bottom:56px">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="fade-in" style="text-align:center;margin-bottom:24px">
                <div style="width:56px;height:56px;border-radius:50%;background:rgba(37,211,102,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    <i class="fa-brands fa-whatsapp" style="font-size:24px;color:#25D366"></i>
                </div>
                <h1 class="h1" style="margin-bottom:6px">اطلب عبر واتساب / تليجرام</h1>
                <p class="text-muted-store">مناسب إن كنت لا تريد التسجيل بحساب كامل الآن، أو تفضّل إرسال طلبك مباشرة عبر الشات.</p>
            </div>

            <?php if ($saved): ?>
                <div class="gcard" style="padding:28px;text-align:center">
                    <div class="badge-pill badge-success mb-3" style="padding:8px 16px"><i class="fa-solid fa-check"></i> تم استلام طلبك، سيتواصل معك فريقنا قريباً</div>
                    <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                        <?php if ($saved['wa_link']): ?><a href="<?= $saved['wa_link'] ?>" target="_blank" class="btn btn-lg" style="background:#25D366;color:#fff"><i class="fa-brands fa-whatsapp"></i> فتح واتساب وإرسال الطلب</a><?php endif; ?>
                        <?php if ($saved['tg_link']): ?><a href="<?= $saved['tg_link'] ?>" target="_blank" class="btn btn-lg btn-navy"><i class="fa-brands fa-telegram"></i> فتح تليجرام</a><?php endif; ?>
                    </div>
                    <a href="manual_order.php" class="btn btn-ghost btn-sm">إرسال طلب آخر</a>
                </div>
            <?php else: ?>
            <form method="post" class="gcard" style="padding:28px">
                <div class="mb-3">
                    <label class="gform-label">القناة المفضّلة للتواصل</label>
                    <div class="d-flex gap-3">
                        <label class="d-flex align-items-center gap-2"><input type="radio" name="channel" value="whatsapp" checked> <i class="fa-brands fa-whatsapp" style="color:#25D366"></i> واتساب</label>
                        <label class="d-flex align-items-center gap-2"><input type="radio" name="channel" value="telegram"> <i class="fa-brands fa-telegram" style="color:#3B82F6"></i> تليجرام</label>
                    </div>
                </div>
                <div class="mb-3"><label class="gform-label">اسم المحل</label><input type="text" name="shop_name" class="gform-control" required value="<?= e($customer['shop_name'] ?? '') ?>"></div>
                <div class="mb-3"><label class="gform-label">اسم المسؤول</label><input type="text" name="contact_name" class="gform-control" value="<?= e($customer['owner_name'] ?? '') ?>"></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="gform-label">رقم الهاتف</label><input type="text" name="phone" class="gform-control" required value="<?= e($customer['phone'] ?? '') ?>"></div>
                    <div class="col-md-6 mb-3"><label class="gform-label">المدينة</label><input type="text" name="city" class="gform-control" value="<?= e($customer['city'] ?? '') ?>"></div>
                </div>
                <div class="mb-3">
                    <label class="gform-label">تفاصيل الطلب (الأصناف والكميات)</label>
                    <textarea name="message" class="gform-control" rows="5" placeholder="مثال: 10 كرتونة زيت، 20 كيس سكر..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">إرسال الطلب</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
