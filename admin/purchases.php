<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$sql = "SELECT p.*, s.name supplier_name FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND p.invoice_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND p.invoice_date <= ?"; $params[] = $to; }
$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$total_sum = array_sum(array_column($invoices, 'total'));
$paid_sum = array_sum(array_column($invoices, 'paid'));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">فواتير المشتريات</h4>
    <a href="purchase_add.php" class="btn btn-success"><i class="fa-solid fa-plus"></i> فاتورة شراء جديدة</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-secondary w-100">فلترة</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>رقم الفاتورة</th><th>المورد</th><th>التاريخ</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv): $remain = $inv['total'] - $inv['paid']; ?>
                <tr>
                    <td><?= e($inv['invoice_number']) ?></td>
                    <td><?= e($inv['supplier_name']) ?></td>
                    <td><?= e($inv['invoice_date']) ?></td>
                    <td><?= money($inv['total']) ?></td>
                    <td><?= money($inv['paid']) ?></td>
                    <td class="<?= $remain>0?'text-danger fw-bold':'text-success' ?>"><?= money($remain) ?></td>
                    <td><a href="purchase_view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i> عرض</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($invoices)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد فواتير</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($invoices)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="3">الإجمالي</td>
                    <td><?= money($total_sum) ?></td>
                    <td><?= money($paid_sum) ?></td>
                    <td colspan="2"><?= money($total_sum - $paid_sum) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
