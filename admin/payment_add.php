<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type']; // receipt | refund_customer | payment | refund_supplier
    $party_type = in_array($type, ['receipt', 'refund_customer']) ? 'customer' : 'supplier';
    $party_id = (int)$_POST['party_id'];
    $amount = (float)$_POST['amount'];
    $date = $_POST['payment_date'];
    $notes = trim($_POST['notes'] ?? '');

    if ($party_id <= 0 || $amount <= 0) {
        flash('يرجى اختيار الجهة وإدخال مبلغ صحيح', 'danger');
        redirect('payment_add.php');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO payments (type, party_type, party_id, amount, payment_date, notes, user_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$type, $party_type, $party_id, $amount, $date, $notes, current_user_id()]);
        $payment_id = $pdo->lastInsertId();

        if ($type === 'receipt') {
            // قبض من عميل: يقلل رصيد العميل، والكاش يدخل الخزينة
            $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $party_id]);
            log_cash_movement($pdo, 'in', $amount, 'receipt', $payment_id, $date, $notes);
        } elseif ($type === 'payment') {
            // دفع لمورد: يقلل رصيد المورد، والكاش يخرج من الخزينة
            $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ?")->execute([$amount, $party_id]);
            log_cash_movement($pdo, 'out', $amount, 'payment', $payment_id, $date, $notes);
        } elseif ($type === 'refund_customer') {
            // استرداد نقدي لعميل (تسوية مرتجع): يرفع رصيد العميل (يقلل الدائن له)، والكاش يخرج من الخزينة
            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$amount, $party_id]);
            log_cash_movement($pdo, 'out', $amount, 'refund_customer', $payment_id, $date, $notes);
        } else {
            // استرداد نقدي من مورد (تسوية مرتجع): يرفع رصيد المورد (يقلل الدائن لنا)، والكاش يدخل الخزينة
            $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id = ?")->execute([$amount, $party_id]);
            log_cash_movement($pdo, 'in', $amount, 'refund_supplier', $payment_id, $date, $notes);
        }

        $pdo->commit();
        flash('تم تسجيل العملية بنجاح');
        redirect('payments.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
        redirect('payment_add.php');
    }
}

$customers = $pdo->query("SELECT * FROM customers WHERE balance != 0 ORDER BY name")->fetchAll();
$all_customers = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
?>
<h4 class="mb-4">تسجيل مقبوضات / مدفوعات</h4>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-success text-white">قبض مبلغ من عميل (تحصيل دين)</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">نوع العملية</label>
                        <select name="type" class="form-select">
                            <option value="receipt">قبض من العميل (تحصيل دين)</option>
                            <option value="refund_customer">استرداد نقدي للعميل (تسوية مرتجع)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العميل</label>
                        <select name="party_id" class="form-select" required>
                            <option value="">-- اختر عميل --</option>
                            <?php foreach ($all_customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= e($c['name']) ?> (الرصيد: <?= money($c['balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التاريخ</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" name="notes" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-success w-100">تسجيل القبض</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-danger text-white">دفع مبلغ لمورد (سداد دين)</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">نوع العملية</label>
                        <select name="type" class="form-select">
                            <option value="payment">دفع للمورد (سداد دين)</option>
                            <option value="refund_supplier">استرداد نقدي من المورد (تسوية مرتجع)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المورد</label>
                        <select name="party_id" class="form-select" required>
                            <option value="">-- اختر مورد --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>">
                                    <?= e($s['name']) ?> (الرصيد: <?= money($s['balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التاريخ</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" name="notes" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-danger w-100">تسجيل الدفع</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
