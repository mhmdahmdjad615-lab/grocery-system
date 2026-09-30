<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_user') {
    $id = $_POST['id'] ?? null;
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] === 'admin' ? 'admin' : 'employee';
    $password = $_POST['password'] ?? '';

    if ($username === '' || $full_name === '') {
        flash('اسم المستخدم والاسم الكامل مطلوبان', 'danger');
        redirect('users.php');
    }

    $exists = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $exists->execute([$username, $id ?: 0]);
    if ($exists->fetch()) {
        flash('اسم المستخدم مستخدم بالفعل', 'danger');
        redirect('users.php');
    }

    if ($id) {
        if ($password !== '') {
            $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, password=? WHERE id=?")
                ->execute([$username, $full_name, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare("UPDATE users SET username=?, full_name=?, role=? WHERE id=?")
                ->execute([$username, $full_name, $role, $id]);
        }
        flash('تم تحديث بيانات المستخدم بنجاح');
    } else {
        if ($password === '') {
            flash('كلمة المرور مطلوبة عند إضافة مستخدم جديد', 'danger');
            redirect('users.php');
        }
        $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $role]);
        flash('تمت إضافة المستخدم بنجاح');
    }
    redirect('users.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === (int)current_user_id()) {
        flash('لا يمكنك حذف حسابك الحالي', 'danger');
    } else {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        flash('تم حذف المستخدم بنجاح');
    }
    redirect('users.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll();
?>
<h4 class="mb-4">إدارة المستخدمين والصلاحيات</h4>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    <strong>مدير:</strong> يرى كل شيء (الأرباح، التكاليف، الأرصدة، التقارير، المخازن، المشتريات، النفقات، إدارة المستخدمين).
    <strong>موظف:</strong> يقتصر عمله على تسجيل فواتير البيع للعملاء والاطلاع على المنتجات والعملاء فقط، بدون رؤية أي بيانات مالية حساسة.
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $edit ? 'تعديل مستخدم' : 'إضافة مستخدم جديد' ?></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="save_user">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">الاسم الكامل</label>
                        <input type="text" name="full_name" class="form-control" value="<?= e($edit['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">اسم المستخدم (للدخول)</label>
                        <input type="text" name="username" class="form-control" value="<?= e($edit['username'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصلاحية</label>
                        <select name="role" class="form-select">
                            <option value="employee" <?= (isset($edit['role']) && $edit['role']==='employee') ? 'selected' : '' ?>>موظف (بيع فقط)</option>
                            <option value="admin" <?= (isset($edit['role']) && $edit['role']==='admin') ? 'selected' : '' ?>>مدير (كل الصلاحيات)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كلمة المرور <?= $edit ? '(اتركها فارغة لعدم التغيير)' : '' ?></label>
                        <input type="password" name="password" class="form-control" <?= $edit ? '' : 'required' ?>>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><?= $edit ? 'تحديث' : 'إضافة' ?></button>
                    <?php if ($edit): ?><a href="users.php" class="btn btn-secondary w-100 mt-2">إلغاء</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">المستخدمون الحاليون</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>#</th><th>الاسم</th><th>اسم المستخدم</th><th>الصلاحية</th><th>تاريخ الإضافة</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= e($u['full_name']) ?> <?= $u['id']==current_user_id() ? '<span class="badge bg-secondary">أنت</span>' : '' ?></td>
                            <td><?= e($u['username']) ?></td>
                            <td><span class="badge <?= $u['role']==='admin'?'bg-success':'bg-primary' ?>"><?= $u['role']==='admin' ? 'مدير' : 'موظف' ?></span></td>
                            <td><?= e($u['created_at']) ?></td>
                            <td>
                                <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <?php if ($u['id'] != current_user_id()): ?>
                                <a href="users.php?delete=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="fa-solid fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
