<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// إضافة بانر جديد (رفع صورة إلزامي)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_banner') {
    $title = trim($_POST['title'] ?? '');
    $link_url = trim($_POST['link_url'] ?? '') ?: null;
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    if (empty($_FILES['image']['name'])) {
        flash('يجب اختيار صورة للبانر', 'danger');
        redirect('store_banners.php');
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || !in_array($ext, $allowedExt)) {
        flash('يجب رفع صورة صالحة بصيغة JPG أو PNG أو WEBP', 'danger');
        redirect('store_banners.php');
    }
    if (filesize($_FILES['image']['tmp_name']) > 5 * 1024 * 1024) {
        flash('حجم الصورة أكبر من 5 ميجابايت', 'danger');
        redirect('store_banners.php');
    }

    $dir = __DIR__ . '/../uploads/banners/';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $filename = 'banner_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . $filename)) {
        flash('تعذّر حفظ الصورة على السيرفر — تأكد من صلاحيات مجلد uploads/banners', 'danger');
        redirect('store_banners.php');
    }

    $pdo->prepare("INSERT INTO store_banners (title, image_path, link_url, sort_order, is_active) VALUES (?,?,?,?,1)")
        ->execute([$title ?: null, $filename, $link_url, $sort_order]);
    flash('تمت إضافة البانر بنجاح');
    redirect('store_banners.php');
}

// تبديل تفعيل/تعطيل بانر
if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE store_banners SET is_active = NOT is_active WHERE id = ?")->execute([(int)$_GET['toggle']]);
    flash('تم تحديث حالة البانر');
    redirect('store_banners.php');
}

// حذف بانر (من القرص وقاعدة البيانات)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT * FROM store_banners WHERE id = ?");
    $stmt->execute([$id]);
    $b = $stmt->fetch();
    if ($b) {
        $path = __DIR__ . '/../uploads/banners/' . $b['image_path'];
        if (is_file($path)) { @unlink($path); }
        $pdo->prepare("DELETE FROM store_banners WHERE id = ?")->execute([$id]);
        flash('تم حذف البانر');
    }
    redirect('store_banners.php');
}

// تحديث ترتيب بانر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_order') {
    $pdo->prepare("UPDATE store_banners SET sort_order = ? WHERE id = ?")->execute([(int)$_POST['sort_order'], (int)$_POST['id']]);
    redirect('store_banners.php');
}

$banners = $pdo->query("SELECT * FROM store_banners ORDER BY sort_order ASC, id DESC")->fetchAll();
?>
<h4 class="mb-4"><i class="fa-solid fa-image"></i> بانرات الصفحة الرئيسية بالمتجر الإلكتروني</h4>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    هذه البانرات تظهر بشريط متحرك أعلى الصفحة الرئيسية بالمتجر الإلكتروني لمحلات التجزئة. يمكن ربط
    كل بانر برابط اختياري (مثال: رابط منتج أو صنف معيّن بالمتجر). المقاس المقترح للصورة: 1600×500 بكسل تقريباً.
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">إضافة بانر جديد</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_banner">
                    <div class="mb-3">
                        <label class="form-label">صورة البانر</label>
                        <input type="file" name="image" class="form-control" accept="image/png,image/jpeg,image/webp" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">عنوان (اختياري، للتوثيق الداخلي فقط)</label>
                        <input type="text" name="title" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رابط عند الضغط على البانر (اختياري)</label>
                        <input type="text" name="link_url" class="form-control" placeholder="مثال: product.php?id=5 أو index.php?category_id=2">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ترتيب الظهور (الأصغر يظهر أولاً)</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                    <button type="submit" class="btn btn-success w-100">إضافة البانر</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header">البانرات الحالية</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>الصورة</th><th>العنوان</th><th>الرابط</th><th>الترتيب</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($banners as $b): ?>
                        <tr>
                            <td><img src="../uploads/banners/<?= e($b['image_path']) ?>" style="width:90px;height:45px;object-fit:cover;border-radius:6px"></td>
                            <td><?= e($b['title'] ?: '-') ?></td>
                            <td class="text-truncate" style="max-width:160px"><?= e($b['link_url'] ?: '-') ?></td>
                            <td style="width:90px">
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="action" value="update_order">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <input type="number" name="sort_order" value="<?= $b['sort_order'] ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                                </form>
                            </td>
                            <td>
                                <a href="store_banners.php?toggle=<?= $b['id'] ?>" class="btn btn-sm <?= $b['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                    <?= $b['is_active'] ? 'مفعّل' : 'معطّل' ?>
                                </a>
                            </td>
                            <td><a href="store_banners.php?delete=<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا البانر؟')"><i class="fa-solid fa-trash"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($banners)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد بانرات بعد</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
