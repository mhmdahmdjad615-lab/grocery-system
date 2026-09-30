<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/functions.php';

// إنشاء حساب مدير افتراضي تلقائياً إذا لم يوجد أي مستخدم
$count = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
if ($count == 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'admin')")
        ->execute(['admin', $hash, 'مدير النظام']);
}
ensure_schema($pdo);

if (!empty($_SESSION['user_id'])) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        redirect('index.php');
    } else {
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول - نظام حسابات ومخازن</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family:'Cairo',sans-serif; background: linear-gradient(135deg,#14532d,#1e7e34); min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .login-card { background:#fff; border-radius:16px; padding:40px; width:100%; max-width:400px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
    .login-card h3 { text-align:center; margin-bottom:5px; color:#14532d; font-weight:700; }
    .login-card p.sub { text-align:center; color:#888; margin-bottom:25px; }
</style>
</head>
<body>
<div class="login-card">
    <h3><i class="fa-solid fa-boxes-stacked"></i> محل الجملة</h3>
    <p class="sub">نظام إدارة الحسابات والمخازن</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">اسم المستخدم</label>
            <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">كلمة المرور</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-success w-100">دخول</button>
    </form>
    <p class="text-center text-muted mt-3" style="font-size:13px;">
        بيانات الدخول الافتراضية: admin / admin123
    </p>
</div>
</body>
</html>
