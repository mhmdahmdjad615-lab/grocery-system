<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$common_expense_categories = ['إيجار', 'رواتب', 'فواتير كهرباء ومياه', 'صيانة', 'نقل وشحن', 'تسويق وإعلان', 'أخرى'];
$common_income_categories = ['إيراد إضافي', 'عمولة', 'أخرى'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] === 'income' ? 'income' : 'expense';
    $category = trim($_POST['category'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['expense_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($category === '' || $amount <= 0) {
        flash('يرجى إدخال التصنيف والمبلغ بشكل صحيح', 'danger');
        redirect('expenses.php');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO expenses (type, category, amount, expense_date, notes, user_id) VALUES (?,?,?,?,?,?)")
            ->execute([$type, $category, $amount, $date, $notes, current_user_id()]);
        $expense_id = $pdo->lastInsertId();

        // كل نفقة تخرج من الخزينة، وكل إيراد إضافي يدخل للخزينة
        log_cash_movement($pdo, $type === 'expense' ? 'out' : 'in', $amount, $type === 'expense' ? 'expense' : 'income', $expense_id, $date, $category . ($notes ? ' - ' . $notes : ''));

        $pdo->commit();
        flash('تم تسجيل العملية بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ: ' . $e->getMessage(), 'danger');
    }
    redirect('expenses.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    // ملاحظة: لا يتم حذف حركة الخزينة المرتبطة تلقائياً حفاظاً على سجل تاريخي دقيق للتدقيق
    flash('تم حذف السجل من قائمة النفقات (حركة الخزينة المرتبطة تبقى في السجل التاريخي)');
    redirect('expenses.php');
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT e.*, u.full_name FROM expenses e LEFT JOIN users u ON u.id = e.user_id WHERE expense_date BETWEEN ? AND ? ORDER BY e.id DESC");
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();

$total_expenses = array_sum(array_map(fn($r) => $r['type']==='expense' ? $r['amount'] : 0, $rows));
$total_income = array_sum(array_map(fn($r) => $r['type']==='income' ? $r['amount'] : 0, $rows));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">النفقات والإيرادات</h4>
    <a href="expenses_report.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-chart-pie"></i> عرض التقرير التفصيلي</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div><div class="value"><?= money($total_expenses) ?></div><div class="label">إجمالي النفقات بالفترة</div></div></div>
    </div>
    <div class="col-md-6">
        <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div><div class="value"><?= money($total_income) ?></div><div class="label">إجمالي الإيرادات الإضافية بالفترة</div></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">تسجيل عملية جديدة</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">النوع</label>
                        <select name="type" id="expenseType" class="form-select" onchange="toggleCategoryList()">
                            <option value="expense">نفقة (مصروف)</option>
                            <option value="income">إيراد إضافي</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التصنيف</label>
                        <input type="text" name="category" id="categoryInput" class="form-control" list="categoryList" required>
                        <datalist id="categoryList">
                            <?php foreach ($common_expense_categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التاريخ</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" name="notes" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-success w-100">حفظ</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2">
                    <div class="col-md-5"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
                    <div class="col-md-5"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
                    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">فلترة</button></div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>التصنيف</th><th>المبلغ</th><th>ملاحظات</th><th>بواسطة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e($r['expense_date']) ?></td>
                            <td><?= $r['type']==='expense' ? '<span class="badge bg-danger">نفقة</span>' : '<span class="badge bg-success">إيراد</span>' ?></td>
                            <td><?= e($r['category']) ?></td>
                            <td><?= money($r['amount']) ?></td>
                            <td><?= e($r['notes']) ?></td>
                            <td><?= e($r['full_name'] ?? '-') ?></td>
                            <td><a href="expenses.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا السجل؟')"><i class="fa-solid fa-trash"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-3">لا توجد سجلات</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const expenseCategories = <?= json_encode($common_expense_categories, JSON_UNESCAPED_UNICODE) ?>;
const incomeCategories = <?= json_encode($common_income_categories, JSON_UNESCAPED_UNICODE) ?>;
function toggleCategoryList() {
    const type = document.getElementById('expenseType').value;
    const list = document.getElementById('categoryList');
    list.innerHTML = '';
    (type === 'income' ? incomeCategories : expenseCategories).forEach(function (c) {
        const o = document.createElement('option');
        o.value = c;
        list.appendChild(o);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
