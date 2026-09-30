<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int)$_POST['id'];
    if ($_POST['action'] === 'set_status') {
        $status = $_POST['status'];
        if (in_array($status, ['new','contacted','converted','closed'])) {
            $pdo->prepare("UPDATE manual_order_requests SET status=? WHERE id=?")->execute([$status, $id]);
            flash('تم تحديث حالة الطلب');
        }
    } elseif ($_POST['action'] === 'add_note') {
        $pdo->prepare("UPDATE manual_order_requests SET admin_notes=? WHERE id=?")->execute([trim($_POST['admin_notes'] ?? ''), $id]);
        flash('تم حفظ الملاحظة');
    }
    redirect('manual_requests.php');
}

$status = $_GET['status'] ?? '';
$channel = $_GET['channel'] ?? '';
$sql = "SELECT * FROM manual_order_requests WHERE 1=1";
$params = [];
if ($status !== '') { $sql .= " AND status = ?"; $params[] = $status; }
if ($channel !== '') { $sql .= " AND channel = ?"; $params[] = $channel; }
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statusLabels = [
    'new' => ['جديد', 'bg-danger'],
    'contacted' => ['تم التواصل', 'bg-warning text-dark'],
    'converted' => ['تحوّل لطلب/عميل', 'bg-success'],
    'closed' => ['مغلق', 'bg-secondary'],
];
$counts = ['new'=>0,'contacted'=>0,'converted'=>0,'closed'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM manual_order_requests GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; }
?>
<h4 class="mb-4">طلبات واردة عبر واتساب / تليجرام</h4>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    هذه رسائل أرسلها زوّار الموقع أو أصحاب محلات (مسجّلين أو غير مسجّلين) عبر صفحة "اطلب عبر واتساب/تليجرام"
    بالمتجر. راجعها وتواصل معهم، ويمكنك تحويل أي طلب جاهز إلى فاتورة بيع مباشرة من صفحة
    <a href="sale_add.php">فاتورة بيع جديدة</a> يدوياً.
</div>

<div class="row g-2 mb-4">
    <?php foreach ($statusLabels as $key => $meta): ?>
    <div class="col-md-3 col-6">
        <a href="?status=<?= $key ?>" class="text-decoration-none">
            <div class="stat-card <?= $status===$key?'border border-3 border-dark':'' ?>">
                <div class="icon-box <?= $meta[1] ?>"><i class="fa-solid fa-comments"></i></div>
                <div><div class="value"><?= $counts[$key] ?></div><div class="label"><?= $meta[0] ?></div></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<div class="mb-3 d-flex gap-2">
    <?php if ($status): ?><a href="manual_requests.php<?= $channel? '?channel='.$channel:''?>" class="btn btn-sm btn-outline-secondary">إلغاء فلتر الحالة</a><?php endif; ?>
    <a href="?channel=whatsapp<?= $status?'&status='.$status:'' ?>" class="btn btn-sm btn-outline-success"><i class="fa-brands fa-whatsapp"></i> واتساب فقط</a>
    <a href="?channel=telegram<?= $status?'&status='.$status:'' ?>" class="btn btn-sm btn-outline-info"><i class="fa-brands fa-telegram"></i> تليجرام فقط</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>القناة</th><th>المحل/الاسم</th><th>الهاتف</th><th>المدينة</th><th>تفاصيل الطلب</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $meta = $statusLabels[$r['status']];
                $phoneDigits = preg_replace('/[^0-9]/', '', $r['phone'] ?? '');
                $waLink = $phoneDigits ? 'https://wa.me/' . $phoneDigits : null;
            ?>
                <tr>
                    <td>
                        <?php if ($r['channel']==='whatsapp'): ?><span class="badge bg-success"><i class="fa-brands fa-whatsapp"></i> واتساب</span>
                        <?php else: ?><span class="badge bg-info text-dark"><i class="fa-brands fa-telegram"></i> تليجرام</span><?php endif; ?>
                    </td>
                    <td><?= e($r['shop_name'] ?: '-') ?><br><small class="text-muted"><?= e($r['contact_name']) ?></small></td>
                    <td><?= e($r['phone']) ?> <?php if ($waLink): ?><a href="<?= $waLink ?>" target="_blank" class="ms-1"><i class="fa-brands fa-whatsapp text-success"></i></a><?php endif; ?></td>
                    <td><?= e($r['city']) ?></td>
                    <td style="max-width:280px; white-space:pre-wrap;"><?= e($r['message']) ?></td>
                    <td>
                        <span class="badge <?= $meta[1] ?>"><?= $meta[0] ?></span>
                        <form method="post" class="mt-1">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($statusLabels as $k => $m): ?>
                                <option value="<?= $k ?>" <?= $r['status']===$k?'selected':'' ?>><?= $m[0] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td><?= e($r['created_at']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#noteModal<?= $r['id'] ?>"><i class="fa-solid fa-note-sticky"></i></button>
                        <a href="sale_add.php" class="btn btn-sm btn-outline-success" title="تسجيل فاتورة بيع لهذا الطلب"><i class="fa-solid fa-plus"></i></a>
                    </td>
                </tr>
                <div class="modal fade" id="noteModal<?= $r['id'] ?>" tabindex="-1">
                  <div class="modal-dialog"><div class="modal-content">
                    <form method="post">
                      <input type="hidden" name="action" value="add_note">
                      <input type="hidden" name="id" value="<?= $r['id'] ?>">
                      <div class="modal-header"><h5 class="modal-title">ملاحظات إدارية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                      <div class="modal-body"><textarea name="admin_notes" class="form-control" rows="3"><?= e($r['admin_notes']) ?></textarea></div>
                      <div class="modal-footer"><button type="submit" class="btn btn-success">حفظ</button></div>
                    </form>
                  </div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد طلبات واردة بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
