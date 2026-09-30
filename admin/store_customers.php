<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int)$_POST['id'];
    if ($_POST['action'] === 'approve') {
        $pdo->prepare("UPDATE store_customers SET status='approved', approved_at=NOW(), reject_reason=NULL WHERE id=?")->execute([$id]);
        flash('تمت الموافقة على حساب المحل، ويمكنه الآن الدخول والطلب من المتجر');
    } elseif ($_POST['action'] === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        $pdo->prepare("UPDATE store_customers SET status='rejected', reject_reason=? WHERE id=?")->execute([$reason, $id]);
        flash('تم رفض حساب المحل');
    } elseif ($_POST['action'] === 'suspend') {
        $pdo->prepare("UPDATE store_customers SET status='suspended' WHERE id=?")->execute([$id]);
        flash('تم تعليق حساب المحل مؤقتاً');
    } elseif ($_POST['action'] === 'reactivate') {
        $pdo->prepare("UPDATE store_customers SET status='approved' WHERE id=?")->execute([$id]);
        flash('تمت إعادة تفعيل الحساب');
    }
    redirect('store_customers.php');
}

$status = $_GET['status'] ?? '';
$sql = "SELECT sc.*, c.balance FROM store_customers sc LEFT JOIN customers c ON c.id = sc.customer_id WHERE 1=1";
$params = [];
if ($status !== '') { $sql .= " AND sc.status = ?"; $params[] = $status; }
$sql .= " ORDER BY sc.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shops = $stmt->fetchAll();

$statusLabels = [
    'pending'   => ['بانتظار الموافقة', 'bg-warning text-dark'],
    'approved'  => ['مُفعّل', 'bg-success'],
    'rejected'  => ['مرفوض', 'bg-danger'],
    'suspended' => ['معلّق', 'bg-secondary'],
];
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'suspended'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM store_customers GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; }
?>
<h4 class="mb-4">حسابات محلات التجزئة المسجّلة بالمتجر</h4>

<div class="row g-2 mb-4">
    <?php foreach ($statusLabels as $key => $meta): ?>
    <div class="col-md-3 col-6">
        <a href="?status=<?= $key ?>" class="text-decoration-none">
            <div class="stat-card <?= $status===$key?'border border-3 border-dark':'' ?>">
                <div class="icon-box <?= $meta[1] ?>"><i class="fa-solid fa-shop"></i></div>
                <div><div class="value"><?= $counts[$key] ?></div><div class="label"><?= $meta[0] ?></div></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($status): ?><a href="store_customers.php" class="btn btn-sm btn-outline-secondary mb-3">إلغاء الفلتر</a><?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>المحل</th><th>المسؤول</th><th>الهاتف</th><th>المدينة</th><th>الرصيد الحالي</th><th>الحالة</th><th>تاريخ التسجيل</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($shops as $s): $meta = $statusLabels[$s['status']]; ?>
                <tr>
                    <td><?= e($s['shop_name']) ?></td>
                    <td><?= e($s['owner_name']) ?></td>
                    <td><?= e($s['phone']) ?></td>
                    <td><?= e($s['city']) ?></td>
                    <td><?= $s['customer_id'] ? '<a href="customer_statement.php?id='.$s['customer_id'].'">'.money($s['balance'] ?? 0).'</a>' : '<span class="text-muted">لا يوجد بعد</span>' ?></td>
                    <td><span class="badge <?= $meta[1] ?>"><?= $meta[0] ?></span> <?php if ($s['status']==='rejected' && $s['reject_reason']): ?><br><small class="text-muted"><?= e($s['reject_reason']) ?></small><?php endif; ?></td>
                    <td><?= e($s['created_at']) ?></td>
                    <td>
                        <?php if ($s['status'] === 'pending'): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-success" onclick="return confirm('الموافقة على هذا الحساب؟')"><i class="fa-solid fa-check"></i> موافقة</button></form>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="document.getElementById('reject_id').value='<?= $s['id'] ?>'"><i class="fa-solid fa-xmark"></i> رفض</button>
                        <?php elseif ($s['status'] === 'approved'): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-secondary" onclick="return confirm('تعليق هذا الحساب؟')"><i class="fa-solid fa-ban"></i> تعليق</button></form>
                        <?php elseif (in_array($s['status'], ['rejected','suspended'])): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="fa-solid fa-rotate-right"></i> إعادة تفعيل</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($shops)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد حسابات محلات مسجّلة بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" id="reject_id">
        <div class="modal-header"><h5 class="modal-title">رفض الحساب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">سبب الرفض (اختياري)</label>
            <input type="text" name="reason" class="form-control">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">تراجع</button>
            <button type="submit" class="btn btn-danger">تأكيد الرفض</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
