<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// اعتماد طلب وتحويله لفاتورة بيع حقيقية بالحسابات
if (isset($_GET['confirm'])) {
    $id = (int)$_GET['confirm'];
    try {
        $pdo->beginTransaction();
        $invoice_id = confirm_store_order($pdo, $id); // يسجّل خطوة "تم الاعتماد" بسجل الحالة تلقائياً بداخلها
        $pdo->commit();
        flash('تم اعتماد الطلب وإنشاء فاتورة بيع رقم مرتبطة به بنجاح');
        redirect('sale_view.php?id=' . $invoice_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء اعتماد الطلب: ' . $e->getMessage(), 'danger');
        redirect('store_orders.php');
    }
}

// إلغاء طلب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $id = (int)$_POST['id'];
    $reason = trim($_POST['reason'] ?? '');
    $stmt = $pdo->prepare("SELECT status FROM store_orders WHERE id = ?");
    $stmt->execute([$id]);
    $st = $stmt->fetch();
    if ($st && $st['status'] === 'pending') {
        $pdo->prepare("UPDATE store_orders SET status='cancelled', cancelled_reason=? WHERE id=?")->execute([$reason, $id]);
        log_order_status_change($pdo, $id, 'cancelled', 'admin', current_user_id(), current_admin_name($pdo), 'dashboard', $reason ?: null);
        flash('تم إلغاء الطلب');
    } else {
        flash('لا يمكن إلغاء هذا الطلب (تم اعتماده بالفعل أو غير موجود)', 'danger');
    }
    redirect('store_orders.php');
}

// تحديث سريع لحالة الشحن/التجهيز لطلب معتمد بالفعل (بدون ملاحظات/مرفقات - للتحديث بالتفاصيل استخدم صفحة عرض الطلب)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    if (in_array($status, ['preparing', 'shipped', 'completed'])) {
        $pdo->prepare("UPDATE store_orders SET status=? WHERE id=? AND status NOT IN ('pending','cancelled')")->execute([$status, $id]);
        log_order_status_change($pdo, $id, $status, 'admin', current_user_id(), current_admin_name($pdo), 'dashboard', null);
        flash('تم تحديث حالة الطلب');
    }
    redirect('store_orders.php');
}

$status = $_GET['status'] ?? '';
$sql = "SELECT o.*, sc.shop_name, sc.phone, sc.owner_name FROM store_orders o JOIN store_customers sc ON sc.id = o.store_customer_id WHERE 1=1";
$params = [];
if ($status !== '') { $sql .= " AND o.status = ?"; $params[] = $status; }
$sql .= " ORDER BY o.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statusLabels = [
    'pending'   => ['بانتظار الاعتماد', 'bg-warning text-dark'],
    'confirmed' => ['تم الاعتماد', 'bg-success'],
    'preparing' => ['قيد التجهيز', 'bg-info text-dark'],
    'shipped'   => ['تم الشحن', 'bg-primary'],
    'completed' => ['مكتمل', 'bg-secondary'],
    'cancelled' => ['ملغي', 'bg-danger'],
];
$counts = ['pending'=>0,'confirmed'=>0,'preparing'=>0,'shipped'=>0,'completed'=>0,'cancelled'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM store_orders GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; }
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">طلبات المتجر الإلكتروني</h4>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    عند الضغط على "اعتماد الطلب" يتم إنشاء <strong>فاتورة بيع حقيقية</strong> تلقائياً بنظام الحسابات (يُخصم المخزون
    بنظام FIFO كأي فاتورة بيع عادية)، ويُنشأ للمحل حساب عميل تلقائياً في حال لم يكن لديه واحد بعد.
    حالة "مكتمل" يمكن أن يحدّثها <strong>أنت (المدير)</strong> من هنا، أو <strong>صاحب المحل نفسه</strong>
    من لوحة تحكمه بعد استلام الطلب — وفي الحالتين يُسجَّل بوضوح من قام بالتحديث ومتى، وأي ملاحظات أو
    مرفقات (صور/مستندات) أضافها. راجع "سجل تتبّع الطلب" بصفحة تفاصيل كل طلب.
</div>

<div class="row g-2 mb-4">
    <?php foreach ($statusLabels as $key => $meta): ?>
    <div class="col-md-2 col-4">
        <a href="?status=<?= $key ?>" class="text-decoration-none">
            <div class="stat-card <?= $status===$key?'border border-3 border-dark':'' ?>">
                <div class="icon-box <?= $meta[1] ?>"><i class="fa-solid fa-bag-shopping"></i></div>
                <div><div class="value"><?= $counts[$key] ?></div><div class="label"><?= $meta[0] ?></div></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($status): ?><a href="store_orders.php" class="btn btn-sm btn-outline-secondary mb-3">إلغاء الفلتر وعرض الكل</a><?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>رقم الطلب</th><th>المحل</th><th>الهاتف</th><th>التاريخ</th><th>الإجمالي</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): $meta = $statusLabels[$o['status']]; ?>
                <tr>
                    <td><a href="store_order_view.php?id=<?= $o['id'] ?>"><?= e($o['order_number']) ?></a></td>
                    <td><?= e($o['shop_name']) ?> <br><small class="text-muted"><?= e($o['owner_name']) ?></small></td>
                    <td><?= e($o['phone']) ?></td>
                    <td><?= e($o['created_at']) ?></td>
                    <td><?= money($o['total']) ?></td>
                    <td><span class="badge <?= $meta[1] ?>"><?= $meta[0] ?></span></td>
                    <td>
                        <a href="store_order_view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i> التفاصيل والسجل</a>
                        <?php if ($o['status'] === 'pending'): ?>
                            <a href="store_orders.php?confirm=<?= $o['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('اعتماد الطلب وإنشاء فاتورة بيع به؟')"><i class="fa-solid fa-check"></i> اعتماد</a>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal" onclick="document.getElementById('cancel_id').value='<?= $o['id'] ?>'"><i class="fa-solid fa-xmark"></i></button>
                        <?php elseif (in_array($o['status'], ['confirmed','preparing','shipped'])): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                <select name="status" class="form-select form-select-sm d-inline-block" style="width:auto" onchange="this.form.submit()">
                                    <option value="confirmed" <?= $o['status']==='confirmed'?'selected':'' ?>>تم الاعتماد</option>
                                    <option value="preparing" <?= $o['status']==='preparing'?'selected':'' ?>>قيد التجهيز</option>
                                    <option value="shipped" <?= $o['status']==='shipped'?'selected':'' ?>>تم الشحن</option>
                                    <option value="completed" <?= $o['status']==='completed'?'selected':'' ?>>مكتمل</option>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد طلبات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="id" id="cancel_id">
        <div class="modal-header"><h5 class="modal-title">إلغاء الطلب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">سبب الإلغاء (اختياري)</label>
            <input type="text" name="reason" class="form-control">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">تراجع</button>
            <button type="submit" class="btn btn-danger">تأكيد الإلغاء</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
