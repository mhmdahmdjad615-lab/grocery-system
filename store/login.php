<?php
require_once __DIR__ . '/includes/store_functions.php';
if (store_logged_in()) redirect('account.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM store_customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $sc = $stmt->fetch();
    if ($sc && password_verify($password, $sc['password'])) {
        $_SESSION['store_customer_id'] = $sc['id'];
        $redirect = $_SESSION['store_redirect_after_login'] ?? 'dashboard.php';
        unset($_SESSION['store_redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    }
    $error = 'رقم الهاتف أو كلمة المرور غير صحيحة';
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container">
    <div class="auth-card fade-in">
        <div style="text-align:center;margin-bottom:24px">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--p-50);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                <i class="fa-solid fa-right-to-bracket text-gold" style="font-size:22px"></i>
            </div>
            <h2 style="margin-bottom:4px">تسجيل الدخول</h2>
            <p class="text-muted-store text-sm mb-0">إلى حساب محلك بالمتجر</p>
        </div>
        <?php if ($error): ?><div class="alert alert-danger" style="border-radius:12px;border:none"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3"><label class="gform-label">رقم الهاتف</label><input type="text" name="phone" class="gform-control" required autofocus></div>
            <div class="mb-3"><label class="gform-label">كلمة المرور</label><input type="password" name="password" class="gform-control" required></div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">دخول</button>
        </form>
        <p class="text-center mt-3 text-sm">ليس لديك حساب؟ <a href="register.php" class="text-gold fw-bold">سجّل محلك الآن</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
