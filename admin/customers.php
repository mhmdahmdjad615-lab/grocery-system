<?php
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $id = $_POST['id'] ?? null;
    $credit_limit_input = trim($_POST['credit_limit'] ?? '');
    $credit_limit = $credit_limit_input === '' ? null : (float)$credit_limit_input;

    if ($name === '') {
        flash('اسم العميل مطلوب', 'danger');
    } elseif ($id) {
        if (is_admin()) {
            $pdo->prepare("UPDATE customers SET name=?, phone=?, address=?, credit_limit=? WHERE id=?")->execute([$name, $phone, $address, $credit_limit, $id]);
        } else {
            $pdo->prepare("UPDATE customers SET name=?, phone=?, address=? WHERE id=?")->execute([$name, $phone, $address, $id]);
        }
        flash('تم تحديث بيانات العميل بنجاح');
    } else {
        $pdo->prepare("INSERT INTO customers (name, phone, address, credit_limit) VALUES (?,?,?,?)")->execute([$name, $phone, $address, is_admin() ? $credit_limit : null]);
        flash('تمت إضافة العميل بنجاح');
    }
    redirect('customers.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $used = $pdo->prepare("SELECT COUNT(*) c FROM sale_invoices WHERE customer_id = ?");
    $used->execute([$id]);
    if ($used->fetch()['c'] > 0) {
        flash('لا يمكن حذف العميل لوجود فواتير مرتبطة به', 'danger');
    } else {
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
        flash('تم حذف العميل بنجاح');
    }
    redirect('customers.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$search = trim($_GET['search'] ?? '');
$sql = "SELECT * FROM customers WHERE 1=1";
$params = [];
if ($search !== '') { $sql .= " AND (name LIKE ? OR phone LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<h4 class="mb-4">إدارة العملاء</h4>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $edit ? 'تعديل عميل' : 'إضافة عميل جديد' ?></div>
            <div class="card-body">
                <form method="post">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">اسم العميل</label>
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
                    <?php if (is_admin()): ?>
                    <div class="mb-3">
                        <label class="form-label">حد الائتمان (أقصى دين مسموح - اتركه فارغاً لبدون حد)</label>
                        <input type="number" step="0.01" name="credit_limit" class="form-control" value="<?= isset($edit['credit_limit']) && $edit['credit_limit'] !== null ? e($edit['credit_limit']) : '' ?>">
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success w-100"><?= $edit ? 'تحديث' : 'إضافة' ?></button>
                    <?php if ($edit): ?><a href="customers.php" class="btn btn-secondary w-100 mt-2">إلغاء</a><?php endif; ?>
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
                    <thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد</th><th>حد الائتمان</th><th>إجراءات</th></tr></thead>
                    <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td><a href="customer_statement.php?id=<?= $c['id'] ?>"><?= e($c['name']) ?></a></td>
                            <td><?= e($c['phone']) ?></td>
                            <td>
                                <?php if ($c['balance'] > 0): ?>
                                    <span class="text-danger fw-bold"><?= money($c['balance']) ?> عليه</span>
                                <?php elseif ($c['balance'] < 0): ?>
                                    <span class="text-success fw-bold"><?= money(abs($c['balance'])) ?> له (رصيد دائن)</span>
                                <?php else: ?>
                                    <span class="text-muted">صفر</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $c['credit_limit'] !== null ? money($c['credit_limit']) : '<span class="text-muted">بدون حد</span>' ?></td>
                            <td>
                                <a href="customer_statement.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-file-invoice"></i></a>
                                <a href="customers.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <a href="customers.php?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($customers)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا يوجد عملاء</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
