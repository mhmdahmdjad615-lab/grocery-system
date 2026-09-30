<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$product_id = (int)($_GET['product_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) { flash('المنتج غير موجود', 'danger'); redirect('products.php'); }

// إضافة مستوى سعر جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_price') {
    $price_name = trim($_POST['price_name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    if ($price_name === '' || $price <= 0) {
        flash('يجب إدخال اسم السعر وقيمة صحيحة', 'danger');
    } else {
        $pdo->prepare("INSERT INTO product_prices (product_id, price_name, price) VALUES (?,?,?)")->execute([$product_id, $price_name, $price]);
        flash('تمت إضافة مستوى السعر بنجاح');
    }
    redirect('product_prices.php?product_id=' . $product_id);
}

// حذف مستوى سعر
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM product_prices WHERE id = ? AND product_id = ?")->execute([(int)$_GET['delete'], $product_id]);
    flash('تم حذف مستوى السعر');
    redirect('product_prices.php?product_id=' . $product_id);
}

$tiers = get_product_price_tiers($pdo, $product_id);

$historyStmt = $pdo->prepare("SELECT h.*, u.full_name FROM product_price_history h LEFT JOIN users u ON u.id = h.user_id WHERE product_id = ? ORDER BY h.id DESC");
$historyStmt->execute([$product_id]);
$history = $historyStmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">إدارة أسعار المنتج: <?= e($product['name']) ?></h4>
    <a href="products.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للمنتجات</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    الأسعار هنا هي أسعار <strong>مقترحة</strong> تظهر كاختصارات سريعة عند تحديد سعر بيع للدفعة
    في <a href="temp_warehouse.php">المخزن المؤقت</a> أو عند البيع للعملاء. سعر البيع الفعلي
    يُحدَّد لكل دفعة على حدة ولا يؤثر على الدفعات أو الفواتير السابقة.
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header">إضافة سعر مقترح جديد</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add_price">
                    <div class="mb-3">
                        <label class="form-label">اسم السعر</label>
                        <input type="text" name="price_name" class="form-control" placeholder="مثال: سعر الجملة، سعر نصف الجملة، سعر عميل مميز" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">قيمة السعر</label>
                        <input type="number" step="0.01" name="price" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">إضافة</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">الأسعار المقترحة الحالية</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>الاسم</th><th>السعر</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($tiers as $t): ?>
                        <tr>
                            <td><?= e($t['price_name']) ?></td>
                            <td><?= money($t['price']) ?></td>
                            <td><a href="product_prices.php?product_id=<?= $product_id ?>&delete=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا المستوى؟')"><i class="fa-solid fa-trash"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tiers)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد أسعار مقترحة بعد</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header">سجل كل سعر بيع تم تحديده لدفعات هذا المنتج عبر الزمن</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>التاريخ</th><th>السعر السابق</th><th>السعر الجديد</th><th>بواسطة</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= e($h['changed_at']) ?></td>
                            <td><?= money($h['old_price']) ?></td>
                            <td class="fw-bold"><?= money($h['new_price']) ?></td>
                            <td><?= e($h['full_name'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($history)): ?><tr><td colspan="4" class="text-center text-muted py-3">لا يوجد سجل بعد</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
