<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $id = $_POST['id'] ?? null;
    if ($name === '') {
        flash('اسم المورد مطلوب', 'danger');
    } elseif ($id) {
        $pdo->prepare("UPDATE suppliers SET name=?, phone=?, address=? WHERE id=?")->execute([$name, $phone, $address, $id]);
        flash('تم تحديث بيانات المورد بنجاح');
    } else {
        $pdo->prepare("INSERT INTO suppliers (name, phone, address) VALUES (?,?,?)")->execute([$name, $phone, $address]);
        flash('تمت إضافة المورد بنجاح');
    }
    redirect('suppliers.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $used = $pdo->prepare("SELECT COUNT(*) c FROM purchase_invoices WHERE supplier_id = ?");
    $used->execute([$id]);
    if ($used->fetch()['c'] > 0) {
        flash('لا يمكن حذف المورد لوجود فواتير مرتبطة به', 'danger');
    } else {
        $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
        flash('تم حذف المورد بنجاح');
    }
    redirect('suppliers.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$search = trim($_GET['search'] ?? '');
$sql = "SELECT * FROM suppliers WHERE 1=1";
$params = [];
if ($search !== '') { $sql .= " AND (name LIKE ? OR phone LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();
?>
<h4 class="mb-4">إدارة الموردين</h4>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $edit ? 'تعديل مورد' : 'إضافة مورد جديد' ?></div>
            <div class="card-body">
                <form method="post">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">اسم المورد</label>
                        <input type="text" name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" value="<?= e($edit['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"><?= e($edit['address'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><?= $edit ? 'تحديث' : 'إضافة' ?></button>
                    <?php if ($edit): ?><a href="suppliers.php" class="btn btn-secondary w-100 mt-2">إلغاء</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2">
                    <div class="col-9"><input type="text" name="search" class="form-control" placeholder="بحث بالاسم أو الهاتف..." value="<?= e($search) ?>"></div>
                    <div class="col-3"><button class="btn btn-outline-secondary w-100">بحث</button></div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد المستحق له</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><?= $s['id'] ?></td>
                            <td><a href="supplier_statement.php?id=<?= $s['id'] ?>"><?= e($s['name']) ?></a></td>
                            <td><?= e($s['phone']) ?></td>
                            <td class="<?= $s['balance']>0?'text-danger fw-bold':'' ?>"><?= money($s['balance']) ?></td>
                            <td>
                                <a href="supplier_statement.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-file-invoice"></i></a>
                                <a href="suppliers.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <a href="suppliers.php?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($suppliers)): ?><tr><td colspan="5" class="text-center text-muted py-3">لا يوجد موردين</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
