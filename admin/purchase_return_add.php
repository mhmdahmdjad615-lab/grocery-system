<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchase_invoice_id = (int)$_POST['purchase_invoice_id'];
    $supplier_id = (int)$_POST['supplier_id'];
    $return_date = $_POST['return_date'];
    $notes = trim($_POST['notes'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $maxQtys = $_POST['max_qty'] ?? [];

    $items = [];
    foreach ($product_ids as $i => $pid) {
        $qty = (float)($quantities[$i] ?? 0);
        if ($qty <= 0) continue;
        $max = (float)($maxQtys[$i] ?? 0);
        if ($qty > $max) {
            flash('الكمية المرتجعة لأحد الأصناف أكبر من الكمية القابلة للإرجاع', 'danger');
            redirect('purchase_return_add.php?invoice_id=' . $purchase_invoice_id);
        }
        $items[] = ['product_id' => (int)$pid, 'quantity' => $qty, 'price' => (float)$prices[$i]];
    }

    if (empty($items)) {
        flash('يرجى إدخال كمية إرجاع لصنف واحد على الأقل', 'danger');
        redirect('purchase_return_add.php?invoice_id=' . $purchase_invoice_id);
    }

    try {
        $pdo->beginTransaction();
        create_purchase_return($pdo, $purchase_invoice_id, $supplier_id, $return_date, $items, $notes);
        $pdo->commit();
        flash('تم تسجيل مرتجع المشتريات بنجاح');
        redirect('purchase_returns.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
        redirect('purchase_return_add.php?invoice_id=' . $purchase_invoice_id);
    }
}

if (!$invoice_id) {
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT p.*, s.name supplier_name FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (p.invoice_number LIKE ? OR s.name LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $sql .= " ORDER BY p.id DESC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">مرتجع مشتريات جديد - اختر الفاتورة الأصلية</h4>
        <a href="purchase_returns.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2">
                <div class="col-md-9"><input type="text" name="search" class="form-control" placeholder="ابحث برقم الفاتورة أو اسم المورد..." value="<?= e($search) ?>"></div>
                <div class="col-md-3"><button class="btn btn-outline-secondary w-100">بحث</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table mb-0 align-middle">
                <thead><tr><th>رقم الفاتورة</th><th>المورد</th><th>التاريخ</th><th>الإجمالي</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?= e($inv['invoice_number']) ?></td>
                        <td><?= e($inv['supplier_name']) ?></td>
                        <td><?= e($inv['invoice_date']) ?></td>
                        <td><?= money($inv['total']) ?></td>
                        <td><a href="purchase_return_add.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-success">اختيار</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices)): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد نتائج</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; exit; ?>
<?php } ?>

<?php
$invStmt = $pdo->prepare("SELECT p.*, s.name supplier_name FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ?");
$invStmt->execute([$invoice_id]);
$invoice = $invStmt->fetch();
if (!$invoice) { flash('الفاتورة غير موجودة', 'danger'); redirect('purchase_returns.php'); }

$itemsStmt = $pdo->prepare("
    SELECT pi.*, pr.name product_name, pr.unit,
        (SELECT COALESCE(SUM(pri.quantity),0) FROM purchase_return_items pri JOIN purchase_returns prr ON prr.id = pri.return_id
         WHERE prr.purchase_invoice_id = pi.invoice_id AND pri.product_id = pi.product_id) already_returned
    FROM purchase_items pi JOIN products pr ON pr.id = pi.product_id
    WHERE pi.invoice_id = ?
");
$itemsStmt->execute([$invoice_id]);
$items = $itemsStmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">مرتجع مشتريات لفاتورة <?= e($invoice['invoice_number']) ?></h4>
    <a href="purchase_return_add.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> اختيار فاتورة أخرى</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    يُخصم أولاً من دفعة المخزن المؤقت الخاصة بنفس الفاتورة إن وُجدت كمية كافية بها،
    وإلا يُخصم الباقي من مخزن البيع (الأقدم أولاً).
</div>

<form method="post">
    <input type="hidden" name="purchase_invoice_id" value="<?= $invoice['id'] ?>">
    <input type="hidden" name="supplier_id" value="<?= $invoice['supplier_id'] ?>">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><strong>المورد:</strong> <?= e($invoice['supplier_name']) ?></div>
                <div class="col-md-4">
                    <label class="form-label">تاريخ المرتجع</label>
                    <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ملاحظات</label>
                    <input type="text" name="notes" class="form-control" placeholder="سبب الإرجاع (اختياري)">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">أصناف الفاتورة</div>
        <div class="card-body p-0">
            <table class="table mb-0 align-middle">
                <thead><tr>
                    <th>المنتج</th><th>الكمية الأصلية</th><th>مرتجع سابقاً</th>
                    <th>القابل للإرجاع</th><th>الكمية المراد إرجاعها</th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $it): $maxReturn = $it['quantity'] - $it['already_returned']; ?>
                    <tr>
                        <td>
                            <?= e($it['product_name']) ?>
                            <input type="hidden" name="product_id[]" value="<?= $it['product_id'] ?>">
                            <input type="hidden" name="price[]" value="<?= $it['price'] ?>">
                            <input type="hidden" name="max_qty[]" value="<?= $maxReturn ?>">
                        </td>
                        <td><?= rtrim(rtrim(number_format($it['quantity'],2),'0'),'.') ?> <?= e($it['unit']) ?></td>
                        <td><?= rtrim(rtrim(number_format($it['already_returned'],2),'0'),'.') ?></td>
                        <td><?= rtrim(rtrim(number_format($maxReturn,2),'0'),'.') ?></td>
                        <td style="width:150px">
                            <input type="number" step="0.01" min="0" max="<?= $maxReturn ?>" name="quantity[]" class="form-control" value="0" <?= $maxReturn<=0?'readonly':'' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?><tr><td colspan="5" class="text-center text-muted py-3">لا توجد أصناف</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk"></i> حفظ المرتجع</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
