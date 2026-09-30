<?php
require_once __DIR__ . '/includes/store_functions.php';
if (store_logged_in()) redirect('account.php');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_name = trim($_POST['shop_name'] ?? '');
    $owner_name = trim($_POST['owner_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($shop_name === '' || $owner_name === '' || $phone === '') $errors[] = 'يرجى تعبئة اسم المحل واسم المسؤول ورقم الهاتف';
    if (strlen($password) < 6) $errors[] = 'كلمة المرور يجب ألا تقل عن 6 أحرف';
    if ($password !== $password2) $errors[] = 'كلمتا المرور غير متطابقتين';

    if (empty($errors)) {
        $exists = $pdo->prepare("SELECT id FROM store_customers WHERE phone = ?");
        $exists->execute([$phone]);
        if ($exists->fetch()) {
            $errors[] = 'رقم الهاتف مسجّل بالفعل، جرّب تسجيل الدخول';
        }
    }

    if (empty($errors)) {
        $pdo->prepare("INSERT INTO store_customers (shop_name, owner_name, phone, whatsapp, email, password, city, address, status) VALUES (?,?,?,?,?,?,?,?,'pending')")
            ->execute([$shop_name, $owner_name, $phone, $whatsapp ?: null, $email ?: null, password_hash($password, PASSWORD_DEFAULT), $city ?: null, $address ?: null]);
        flash(get_store_setting($pdo, 'registration_note', 'سيتم مراجعة طلب تسجيلكم من الإدارة قبل تفعيل الحساب'));
        redirect('login.php');
    }
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container">
    <div class="auth-card fade-in" style="max-width:600px">
        <div style="text-align:center;margin-bottom:24px">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--p-50);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                <i class="fa-solid fa-shop text-gold" style="font-size:22px"></i>
            </div>
            <h2 style="margin-bottom:4px">تسجيل محل تجزئة جديد</h2>
            <p class="text-muted-store text-sm mb-0">انضم كتاجر وابدأ الطلب بأسعار الجملة</p>
        </div>
        <?php foreach ($errors as $err): ?><div class="alert alert-danger" style="border-radius:12px;border:none"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <div class="mb-3"><label class="gform-label">اسم المحل</label><input type="text" name="shop_name" class="gform-control" required value="<?= e($_POST['shop_name'] ?? '') ?>"></div>
            <div class="mb-3"><label class="gform-label">اسم المسؤول</label><input type="text" name="owner_name" class="gform-control" required value="<?= e($_POST['owner_name'] ?? '') ?>"></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="gform-label">رقم الهاتف</label><input type="text" name="phone" class="gform-control" required value="<?= e($_POST['phone'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label class="gform-label">رقم واتساب (اختياري)</label><input type="text" name="whatsapp" class="gform-control" value="<?= e($_POST['whatsapp'] ?? '') ?>"></div>
            </div>
            <div class="mb-3"><label class="gform-label">البريد الإلكتروني (اختياري)</label><input type="email" name="email" class="gform-control" value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="gform-label">المدينة</label><input type="text" name="city" class="gform-control" value="<?= e($_POST['city'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label class="gform-label">العنوان</label><input type="text" name="address" class="gform-control" value="<?= e($_POST['address'] ?? '') ?>"></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="gform-label">كلمة المرور</label><input type="password" name="password" class="gform-control" required></div>
                <div class="col-md-6 mb-3"><label class="gform-label">تأكيد كلمة المرور</label><input type="password" name="password2" class="gform-control" required></div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">إرسال طلب التسجيل</button>
        </form>
        <p class="text-center mt-3 text-sm">لديك حساب بالفعل؟ <a href="login.php" class="text-gold fw-bold">تسجيل الدخول</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
