<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// إضافة أو تعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $id = $_POST['id'] ?? null;
    if ($name === '') {
        flash('اسم الصنف مطلوب', 'danger');
    } elseif ($id) {
        $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
        flash('تم تحديث الصنف بنجاح');
    } else {
        $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
        flash('تمت إضافة الصنف بنجاح');
    }
    redirect('categories.php');
}

// حذف
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $inUse = $pdo->prepare("SELECT COUNT(*) c FROM products WHERE category_id = ?");
    $inUse->execute([$id]);
    if ($inUse->fetch()['c'] > 0) {
        flash('لا يمكن حذف الصنف لوجود منتجات مرتبطة به', 'danger');
    } else {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        flash('تم حذف الصنف بنجاح');
    }
    redirect('categories.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) products_count FROM categories c ORDER BY c.id DESC")->fetchAll();
?>
<h4 class="mb-4">إدارة الأصناف</h4>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $edit ? 'تعديل صنف' : 'إضافة صنف جديد' ?></div>
            <div class="card-body">
                <form method="post">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">اسم الصنف</label>
                        <input type="text" name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><?= $edit ? 'تحديث' : 'إضافة' ?></button>
                    <?php if ($edit): ?><a href="categories.php" class="btn btn-secondary w-100 mt-2">إلغاء</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">قائمة الأصناف</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>#</th><th>اسم الصنف</th><th>عدد المنتجات</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td><?= e($c['name']) ?></td>
                            <td><span class="badge bg-secondary"><?= $c['products_count'] ?></span></td>
                            <td>
                                <a href="categories.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <a href="categories.php?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا توجد أصناف</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
