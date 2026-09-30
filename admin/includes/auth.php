<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/functions.php';

// إنشاء حساب مدير افتراضي تلقائياً إذا لم يوجد أي مستخدم بعد
$count = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
if ($count == 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'admin')")
        ->execute(['admin', $hash, 'مدير النظام']);
}
ensure_schema($pdo);

function require_login() {
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }
}

// المدير فقط يرى البيانات المالية الحساسة (تكاليف، أرباح، أرصدة، تقارير، مشتريات، مستخدمين)
// الموظف يقتصر عمله على البيع للعملاء والاطلاع على الأصناف/المنتجات فقط
function is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_admin() {
    if (!is_admin()) {
        flash('ليس لديك صلاحية للوصول لهذه الصفحة، هذه الصفحة للمدير فقط', 'danger');
        redirect('index.php');
    }
}
