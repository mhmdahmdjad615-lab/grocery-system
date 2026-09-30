<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

// إضافة أو تعديل بيانات المنتج + هيكل الوحدات الكامل عند الإنشاء لأول مرة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $name          = trim($_POST['name'] ?? '');
    $category_id   = $_POST['category_id'] ?: null;
    $unit          = trim($_POST['unit'] ?? 'قطعة');
    $min_quantity  = (float)($_POST['min_quantity'] ?? 0);
    $barcode       = trim($_POST['barcode'] ?? '') ?: null;
    $id            = $_POST['id'] ?? null;
    $show_in_store = isset($_POST['show_in_store']) ? 1 : 0;
    $store_price   = trim($_POST['store_price'] ?? '') !== '' ? (float)$_POST['store_price'] : null;
    $store_min_qty = trim($_POST['store_min_qty'] ?? '') !== '' ? (float)$_POST['store_min_qty'] : null;
    $store_description = trim($_POST['store_description'] ?? '') ?: null;
    $store_image   = trim($_POST['store_image'] ?? '') ?: null;
    // [حقول جديدة] تفاصيل العبوة/الوزن + سعر ما قبل الخصم (جدول products - أعمدة store_*)
    $store_package_info  = trim($_POST['store_package_info'] ?? '') ?: null;
    $store_net_weight     = trim($_POST['store_net_weight'] ?? '') !== '' ? (float)$_POST['store_net_weight'] : null;
    $store_weight_unit    = trim($_POST['store_weight_unit'] ?? '') ?: null;
    $store_compare_price  = trim($_POST['store_compare_price'] ?? '') !== '' ? (float)$_POST['store_compare_price'] : null;

    if ($name === '') {
        flash('اسم المنتج مطلوب', 'danger');
        redirect('products.php');
    }

    if ($id) {
        // لو فعّل "إظهار بالمتجر" وترك سعر المتجر فارغاً، نعبّئه تلقائياً بآخر سعر بيع فعلي بمخزن البيع
        if ($show_in_store && $store_price === null) {
            $store_price = get_latest_selling_price($pdo, $id);
        }
        $pdo->prepare("UPDATE products SET name=?, category_id=?, unit=?, min_quantity=?, barcode=?, show_in_store=?, store_price=?, store_min_qty=?, store_description=?, store_image=?, store_package_info=?, store_net_weight=?, store_weight_unit=?, store_compare_price=? WHERE id=?")
            ->execute([$name, $category_id, $unit, $min_quantity, $barcode, $show_in_store, $store_price, $store_min_qty, $store_description, $store_image, $store_package_info, $store_net_weight, $store_weight_unit, $store_compare_price, $id]);
        // مزامنة اسم الوحدة الأساسية مع التعديل
        $pdo->prepare("UPDATE product_units SET unit_name = ?, print_name = ? WHERE product_id = ? AND is_base = 1")->execute([$unit, $unit, $id]);

        // [جديد] رفع صور إضافية للمنتج (حتى 4 صور بالمعرض) — متاح فقط عند تعديل منتج موجود بالفعل
        if (!empty($_FILES['images']['name'][0] ?? '')) {
            $uploadErrors = [];
            foreach ($_FILES['images']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                try {
                    save_uploaded_product_image($pdo, $id, $tmpName, $_FILES['images']['name'][$i], $_FILES['images']['error'][$i]);
                } catch (Exception $e) {
                    $uploadErrors[] = $e->getMessage();
                }
            }
            if (!empty($uploadErrors)) {
                flash('تم حفظ بيانات المنتج، لكن حدثت مشاكل أثناء رفع بعض الصور: ' . implode(' | ', $uploadErrors), 'warning');
                redirect('products.php');
            }
        }

        flash('تم تحديث بيانات المنتج بنجاح');
        redirect('products.php');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO products (name, category_id, unit, min_quantity, barcode, show_in_store, store_price, store_min_qty, store_description, store_image, store_package_info, store_net_weight, store_weight_unit, store_compare_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name, $category_id, $unit, $min_quantity, $barcode, $show_in_store, $store_price, $store_min_qty, $store_description, $store_image, $store_package_info, $store_net_weight, $store_weight_unit, $store_compare_price]);
        $newProductId = $pdo->lastInsertId();

        // إنشاء الوحدة الأساسية (المستوى 0) - أصغر جزء قابل للبيع
        $pdo->prepare("INSERT INTO product_units (product_id, unit_name, print_name, factor, is_base, allow_purchase, allow_sell, allow_count, net_weight) VALUES (?,?,?,1,1,1,1,1,?)")
            ->execute([$newProductId, $unit, $unit, ($_POST['base_weight'] !== '' ? (float)$_POST['base_weight'] : null)]);

        // موضع 0 = الوحدة الأساسية؛ المواضع التالية = مستويات التعبئة الأكبر بالترتيب المُدخل
        $positionToId = [0 => $pdo->lastInsertId()];

        $levelNames = $_POST['level_name'] ?? [];
        $levelWraps = $_POST['level_wraps'] ?? [];
        $levelQty   = $_POST['level_qty'] ?? [];
        $levelPrint = $_POST['level_print'] ?? [];
        $levelWeight = $_POST['level_weight'] ?? [];
        $levelPurchase = $_POST['level_purchase'] ?? [];
        $levelSell = $_POST['level_sell'] ?? [];
        $levelCount = $_POST['level_count'] ?? [];

        foreach ($levelNames as $i => $lname) {
            $lname = trim($lname);
            if ($lname === '') continue;
            $wrapsPos = (int)($levelWraps[$i] ?? 0);
            $qty = (float)($levelQty[$i] ?? 0);
            if (!isset($positionToId[$wrapsPos]) || $qty <= 0) continue;

            $newId = add_product_unit_chain($pdo, $newProductId, $lname, $positionToId[$wrapsPos], $qty, [
                'print_name'      => trim($levelPrint[$i] ?? '') ?: $lname,
                'net_weight'      => ($levelWeight[$i] ?? '') !== '' ? (float)$levelWeight[$i] : null,
                'allow_purchase'  => isset($levelPurchase[$i]),
                'allow_sell'      => isset($levelSell[$i]),
                'allow_count'     => isset($levelCount[$i]),
            ]);
            $positionToId[$i + 1] = $newId;
        }

        $pdo->commit();
        flash('تمت إضافة المنتج وهيكل وحداته بنجاح. عدّله لاحقاً لإضافة صور المتجر (حتى 4 صور)، ثم أضفه إلى مخزن البيع، أو انتظار أول فاتورة شراء له');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('حدث خطأ أثناء حفظ هيكل الوحدات: ' . $e->getMessage(), 'danger');
    }
    redirect('products.php');
}

// إضافة المنتج إلى مخزن البيع برصيد افتتاحي صفر (ليظهر بقوائم الجرد والتنبيه)
if (isset($_GET['add_to_warehouse'])) {
    $id = (int)$_GET['add_to_warehouse'];
    add_selling_placeholder($pdo, $id);
    flash('تمت إضافة المنتج إلى مخزن البيع برصيد افتتاحي صفر. أضف له كمية عبر فاتورة شراء ثم رحّلها من المخزن المؤقت');
    redirect('products.php');
}

// تبديل سريع لإظهار/إخفاء المنتج بالمتجر الإلكتروني بدون فتح نموذج التعديل الكامل
if (isset($_GET['toggle_store'])) {
    $id = (int)$_GET['toggle_store'];
    $stmt = $pdo->prepare("SELECT show_in_store, store_price FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if ($p) {
        $newVal = $p['show_in_store'] ? 0 : 1;
        // عند التفعيل: لو لم يكن هناك سعر متجر محدد مسبقاً، نعبّئه تلقائياً بآخر سعر بيع فعلي بمخزن البيع
        $autoPrice = null;
        if ($newVal && !$p['store_price']) {
            $autoPrice = get_latest_selling_price($pdo, $id);
        }
        if ($autoPrice !== null) {
            $pdo->prepare("UPDATE products SET show_in_store = ?, store_price = ? WHERE id = ?")->execute([$newVal, $autoPrice, $id]);
        } else {
            $pdo->prepare("UPDATE products SET show_in_store = ? WHERE id = ?")->execute([$newVal, $id]);
        }

        if ($newVal && !$p['store_price'] && $autoPrice !== null) {
            flash('أصبح المنتج ظاهراً بالمتجر، وتم تعبئة سعره تلقائياً من آخر سعر بيع فعلي (' . money($autoPrice) . '). يمكنك تعديله يدوياً في أي وقت');
        } elseif ($newVal && !$p['store_price']) {
            flash('تم إظهار المنتج بالمتجر، لكن لا يوجد بعد أي سعر بيع مسجَّل له بمخزن البيع لتعبئته تلقائياً - عدّل المنتج وأضف "سعر البيع بالجملة المعروض بالمتجر" يدوياً ليتمكن العملاء من طلبه', 'warning');
        } else {
            flash($newVal ? 'أصبح المنتج ظاهراً بالمتجر الإلكتروني الآن' : 'تم إخفاء المنتج من المتجر الإلكتروني');
        }
    }
    redirect('products.php');
}

// [جديد] حذف صورة من معرض صور المنتج (من القرص وقاعدة البيانات)
if (isset($_GET['delete_image']) && isset($_GET['pid'])) {
    delete_product_image($pdo, (int)$_GET['delete_image'], (int)$_GET['pid']);
    flash('تم حذف الصورة');
    redirect('products.php?edit=' . (int)$_GET['pid']);
}

// حذف منتج نهائياً (فقط إذا لم تكن له أي حركة فواتير أو دفعات مخزون)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $used = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM purchase_items WHERE product_id=?) +
        (SELECT COUNT(*) FROM sale_items WHERE product_id=?) +
        (SELECT COUNT(*) FROM stock_batches WHERE product_id=?) c");
    $used->execute([$id, $id, $id]);
    if ($used->fetch()['c'] > 0) {
        flash('لا يمكن حذف المنتج لوجود حركات أو دفعات مخزون مرتبطة به', 'danger');
    } else {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        flash('تم حذف المنتج بنجاح');
    }
    redirect('products.php');
}

$edit = null;
$editImages = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) $editImages = get_product_images($pdo, $edit['id']);
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$search = trim($_GET['search'] ?? '');
$sql = "SELECT p.*, c.name category_name,
        EXISTS(SELECT 1 FROM stock_batches sb WHERE sb.product_id = p.id AND sb.location='selling') in_warehouse
        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allProducts = $stmt->fetchAll();

// [جديد] تجميع صور كل المنتجات دفعة واحدة (بدل استعلام منفصل لكل صف) لإرسالها لمعاينة زر التعديل
$imgRows = $pdo->query("SELECT * FROM product_images ORDER BY sort_order ASC, id ASC")->fetchAll();
$imagesByProduct = [];
foreach ($imgRows as $ir) { $imagesByProduct[$ir['product_id']][] = $ir; }
foreach ($allProducts as &$__p) { $__p['images'] = $imagesByProduct[$__p['id']] ?? []; }
unset($__p);

// هذه القائمة تُظهر فقط المنتجات التي لم تُضَف بعد لمخزن البيع (أو أُزيلت منه)
$pendingProducts = array_filter($allProducts, fn($p) => !$p['in_warehouse']);

$weightUnits = ['كجم', 'جرام', 'لتر', 'مل', 'طن'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">المنتجات (بيانات ثابتة + هيكل الوحدات)</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProductModal"><i class="fa-solid fa-plus"></i> منتج جديد</button>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    عند إضافة منتج جديد تُعرِّف هيكل وحداته بالكامل من أصغر وحدة قابلة للبيع وحتى أكبر عبوة تعبئة
    (مثال: كيس ← لفة تحتوي 10 أكياس ← طن يحتوي 100 لفة). سعر الشراء والكمية يأتيان لاحقاً من
    <strong>فاتورة الشراء</strong> بأي وحدة تختارها، وسعر البيع يُحدَّد من <strong>المخزن المؤقت</strong>
    عند الترحيل لـ<strong>مخزن البيع</strong>. القائمة أدناه تعرض المنتجات غير المضافة بعد لمخزن البيع.
    <br><strong>ملاحظة:</strong> صور المتجر (حتى 4 صور) وتفاصيل العبوة/الوزن تُضاف من زر "تعديل" بعد حفظ المنتج لأول مرة.
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-9"><input type="text" name="search" class="form-control" placeholder="بحث بالاسم أو الباركود..." value="<?= e($search) ?>"></div>
            <div class="col-md-3"><button class="btn btn-outline-secondary w-100" type="submit">بحث</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">منتجات بانتظار الإضافة إلى مخزن البيع</div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th>المنتج</th><th>الصنف</th><th>أصغر وحدة</th><th>الحد الأدنى</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($pendingProducts as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['category_name'] ?? '-') ?></td>
                    <td><?= e($p['unit']) ?></td>
                    <td><?= rtrim(rtrim(number_format($p['min_quantity'],2),'0'),'.') ?></td>
                    <td>
                        <a href="products.php?add_to_warehouse=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" title="إضافة إلى مخزن البيع برصيد صفر"><i class="fa-solid fa-warehouse"></i> إضافة لمخزن البيع</a>
                        <a href="product_units.php?product_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="هيكل الوحدات (كرتونة، طن...)"><i class="fa-solid fa-boxes-packing"></i> الوحدات</a>
                        <button class="btn btn-sm btn-outline-primary" title="تعديل البيانات الأساسية"
                            data-bs-toggle="modal" data-bs-target="#addProductModal"
                            onclick='fillEditProduct(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <a href="products.php?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="fa-solid fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pendingProducts)): ?><tr><td colspan="6" class="text-center text-muted py-3">لا توجد منتجات بانتظار الإضافة - كل المنتجات مضافة بالفعل لمخزن البيع</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>كل المنتجات - إدارة ظهورها بالمتجر الإلكتروني</span>
        <span class="badge bg-success">ظاهر بالمتجر = يمكن لمحلات التجزئة طلبه أونلاين</span>
    </div>
    <div class="alert alert-warning mb-0 rounded-0">
        <i class="fa-solid fa-triangle-exclamation"></i>
        لكي يظهر المنتج فعلياً بالمتجر ويمكن لمحلات التجزئة شراؤه، يجب توفر 3 شروط معاً:
        <strong>1)</strong> مفعّل "ظاهر بالمتجر" أدناه، <strong>2)</strong> له "سعر بيع بالجملة" محدد،
        <strong>3)</strong> له رصيد فعلي بـ<a href="selling_warehouse.php">مخزن البيع</a> (أكبر من صفر).
    </div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th>صورة</th><th>المنتج</th><th>الصنف</th><th>بمخزن البيع؟</th><th>سعر المتجر</th><th>ظاهر بالمتجر؟</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($allProducts as $p):
                $thumb = !empty($p['images']) ? '../uploads/products/' . $p['images'][0]['image_path'] : $p['store_image'];
            ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td>
                        <?php if ($thumb): ?>
                            <img src="<?= e($thumb) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px">
                        <?php else: ?>
                            <span class="text-muted"><i class="fa-solid fa-image"></i></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['category_name'] ?? '-') ?></td>
                    <td><?= $p['in_warehouse'] ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-secondary">لا</span>' ?></td>
                    <td>
                        <?php if ($p['store_price']): ?>
                            <?= money($p['store_price']) ?>
                            <?php if ($p['store_compare_price'] && $p['store_compare_price'] > $p['store_price']): ?>
                                <br><small class="text-muted text-decoration-line-through"><?= money($p['store_compare_price']) ?></small>
                                <span class="badge bg-danger">خصم <?= round((($p['store_compare_price']-$p['store_price'])/$p['store_compare_price'])*100) ?>%</span>
                            <?php endif; ?>
                        <?php else: $fallback = get_latest_selling_price($pdo, $p['id']); ?>
                            <?php if ($fallback): ?>
                                <span class="text-warning"><?= money($fallback) ?></span> <small class="text-muted">(تلقائي من آخر سعر بيع)</small>
                            <?php else: ?>
                                <span class="text-danger">غير محدد</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="products.php?toggle_store=<?= $p['id'] ?>" class="btn btn-sm <?= $p['show_in_store'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                            <i class="fa-solid <?= $p['show_in_store'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                            <?= $p['show_in_store'] ? 'ظاهر' : 'مخفي' ?>
                        </a>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" title="تعديل بيانات المنتج وصوره وسعر المتجر"
                            data-bs-toggle="modal" data-bs-target="#addProductModal"
                            onclick='fillEditProduct(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>
                            <i class="fa-solid fa-pen"></i> تعديل
                        </button>
                        <?php if ($p['show_in_store']): ?>
                        <a href="../store/product.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="معاينة بالمتجر"><i class="fa-solid fa-eye"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($allProducts)): ?><tr><td colspan="8" class="text-center text-muted py-3">لا توجد منتجات بعد</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال إضافة/تعديل منتج -->
<div class="modal fade" id="addProductModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" id="productForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="id" id="product_id">
        <div class="modal-header">
          <h5 class="modal-title" id="productModalTitle">إضافة منتج جديد</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" name="name" id="product_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">الصنف</label>
                    <select name="category_id" id="product_category" class="form-select">
                        <option value="">-- بدون --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">اسم أصغر وحدة (الوحدة الأساسية)</label>
                    <input type="text" name="unit" id="product_unit" class="form-control" value="قطعة" required oninput="syncBaseUnitLabel()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">وزن أصغر وحدة (اختياري، كجم)</label>
                    <input type="number" step="0.0001" name="base_weight" id="product_base_weight" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الحد الأدنى للتنبيه (بأصغر وحدة)</label>
                    <input type="number" step="0.01" name="min_quantity" id="product_min_quantity" class="form-control" value="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الباركود (اختياري)</label>
                    <input type="text" name="barcode" id="product_barcode" class="form-control">
                </div>
            </div>

            <div class="mt-4">
                <hr>
                <h6 class="mb-2"><i class="fa-solid fa-cart-shopping"></i> إعدادات ظهور المنتج بالمتجر الإلكتروني (اختياري)</h6>
                <div class="row g-3">
                    <div class="col-12 form-check">
                        <input type="checkbox" name="show_in_store" id="product_show_in_store" class="form-check-input" value="1" checked>
                        <label class="form-check-label" for="product_show_in_store">إظهار هذا المنتج بالمتجر الإلكتروني لمحلات التجزئة</label>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">سعر البيع بالجملة المعروض بالمتجر (لأصغر وحدة)</label>
                        <input type="number" step="0.01" name="store_price" id="product_store_price" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">السعر قبل الخصم (اختياري - لعرض شارة "عرض وخصم" بالمتجر)</label>
                        <input type="number" step="0.01" name="store_compare_price" id="product_store_compare_price" class="form-control" placeholder="اتركه فارغاً لو لا يوجد عرض">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الحد الأدنى للطلب (اختياري)</label>
                        <input type="number" step="0.01" name="store_min_qty" id="product_store_min_qty" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">وصف يظهر بصفحة المنتج بالمتجر (اختياري)</label>
                        <textarea name="store_description" id="product_store_description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <hr>
                <h6 class="mb-2"><i class="fa-solid fa-box-open"></i> تفاصيل العبوة والوزن (تظهر بصفحة المنتج بالمتجر)</h6>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">تفاصيل العبوة (نص حر، مثال: "كرتونة تحتوي 24 عبوة × 500 مل")</label>
                        <textarea name="store_package_info" id="product_store_package_info" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الوزن الصافي</label>
                        <input type="number" step="0.001" name="store_net_weight" id="product_store_net_weight" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">وحدة الوزن</label>
                        <select name="store_weight_unit" id="product_store_weight_unit" class="form-select">
                            <?php foreach ($weightUnits as $wu): ?><option value="<?= e($wu) ?>"><?= e($wu) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mt-4" id="imagesSection">
                <hr>
                <h6 class="mb-2"><i class="fa-solid fa-images"></i> صور المنتج بالمتجر (حتى 4 صور)</h6>
                <div id="existingImagesWrap" class="d-flex gap-2 flex-wrap mb-2"></div>
                <input type="file" name="images[]" id="productImagesInput" class="form-control" accept="image/png,image/jpeg,image/webp" multiple>
                <small class="text-muted">JPG / PNG / WEBP، حتى 4 ميجابايت لكل صورة. لن تُحفظ الصور إلا بعد حفظ المنتج.</small>
                <div class="alert alert-secondary mt-2 mb-0 py-2 d-none" id="noImagesYetNotice">
                    احفظ المنتج أولاً، ثم افتح "تعديل" مرة أخرى لإضافة الصور.
                </div>
            </div>

            <div id="unitsBuilderSection" class="mt-4">
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">هيكل وحدات التعبئة (اختياري - يمكن إضافته لاحقاً من صفحة "الوحدات")</h6>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="addUnitLevelRow()"><i class="fa-solid fa-plus"></i> إضافة مستوى تعبئة</button>
                </div>
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th>اسم الوحدة</th><th>تحتوي على</th><th>عدد</th><th>من الوحدة</th>
                        <th>وزن صافي (اختياري)</th><th>شراء</th><th>بيع</th><th>جرد</th><th></th>
                    </tr></thead>
                    <tbody id="unitLevelsBody"></tbody>
                </table>
                <small class="text-muted">مثال: أضف "لفة" تحتوي على 10 من "<span id="baseUnitNameHint">قطعة</span>"، ثم أضف "طن" تحتوي على 100 من "لفة".</small>
            </div>

            <div id="editNotice" class="alert alert-warning mt-3 d-none">
                لتعديل هيكل الوحدات لمنتج موجود بالفعل، استخدم زر "الوحدات" من قائمة المنتجات بعد الحفظ.
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-success">حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let unitLevelCount = 0;

function syncBaseUnitLabel() {
    const name = document.getElementById('product_unit').value || 'الوحدة الأساسية';
    document.getElementById('baseUnitNameHint').textContent = name;
    refreshWrapsDropdowns();
}

function addUnitLevelRow() {
    const idx = unitLevelCount++;
    const tbody = document.getElementById('unitLevelsBody');
    const row = document.createElement('tr');
    row.dataset.idx = idx;
    row.innerHTML = `
        <td><input type="text" name="level_name[]" class="form-control form-control-sm level-name-input" placeholder="مثال: لفة" required oninput="refreshWrapsDropdowns()"></td>
        <td>تحتوي على</td>
        <td style="width:90px"><input type="number" step="0.0001" min="0.0001" name="level_qty[]" class="form-control form-control-sm" required></td>
        <td><select name="level_wraps[]" class="form-select form-select-sm wraps-select"></select></td>
        <td style="width:110px"><input type="number" step="0.0001" name="level_weight[]" class="form-control form-control-sm" placeholder="كجم"></td>
        <td class="text-center"><input type="checkbox" name="level_purchase[${idx}]" class="form-check-input" checked></td>
        <td class="text-center"><input type="checkbox" name="level_sell[${idx}]" class="form-check-input" checked></td>
        <td class="text-center"><input type="checkbox" name="level_count[${idx}]" class="form-check-input" checked></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeUnitLevelRow(this)"><i class="fa-solid fa-trash"></i></button></td>
    `;
    tbody.appendChild(row);
    refreshWrapsDropdowns();
}

function removeUnitLevelRow(btn) {
    btn.closest('tr').remove();
    refreshWrapsDropdowns();
}

// تحديث كل قوائم "من الوحدة" لتشمل الوحدة الأساسية + كل الصفوف المُضافة قبل كل صف
function refreshWrapsDropdowns() {
    const baseUnitName = document.getElementById('product_unit').value || 'الوحدة الأساسية';
    const rows = Array.from(document.querySelectorAll('#unitLevelsBody tr'));
    rows.forEach(function (row, position) {
        const select = row.querySelector('.wraps-select');
        const currentVal = select.value;
        select.innerHTML = '<option value="0">' + baseUnitName + ' (الأساسية)</option>';
        for (let i = 0; i < position; i++) {
            const priorName = rows[i].querySelector('.level-name-input').value || ('مستوى ' + (i + 1));
            const opt = document.createElement('option');
            opt.value = (i + 1);
            opt.textContent = priorName;
            select.appendChild(opt);
        }
        // أقرب مستوى أصغر مباشرة هو الافتراضي المنطقي
        select.value = currentVal || String(position);
    });
}

function fillEditProduct(p) {
    document.getElementById('productModalTitle').innerText = 'تعديل منتج';
    document.getElementById('product_id').value = p.id;
    document.getElementById('product_name').value = p.name;
    document.getElementById('product_category').value = p.category_id || '';
    document.getElementById('product_unit').value = p.unit;
    document.getElementById('product_min_quantity').value = p.min_quantity;
    document.getElementById('product_barcode').value = p.barcode || '';
    document.getElementById('product_show_in_store').checked = !!(p.show_in_store == 1);
    document.getElementById('product_store_price').value = p.store_price || '';
    document.getElementById('product_store_compare_price').value = p.store_compare_price || '';
    document.getElementById('product_store_min_qty').value = p.store_min_qty || '';
    document.getElementById('product_store_description').value = p.store_description || '';
    document.getElementById('product_store_package_info').value = p.store_package_info || '';
    document.getElementById('product_store_net_weight').value = p.store_net_weight || '';
    if (p.store_weight_unit) document.getElementById('product_store_weight_unit').value = p.store_weight_unit;
    document.getElementById('unitsBuilderSection').classList.add('d-none');
    document.getElementById('editNotice').classList.remove('d-none');
    document.getElementById('noImagesYetNotice').classList.add('d-none');
    document.getElementById('productImagesInput').disabled = false;

    const wrap = document.getElementById('existingImagesWrap');
    wrap.innerHTML = '';
    (p.images || []).forEach(function (img) {
        const div = document.createElement('div');
        div.style.position = 'relative';
        div.innerHTML = `
            <img src="../uploads/products/${img.image_path}" style="width:70px;height:70px;object-fit:cover;border-radius:8px;border:1px solid #ddd">
            <a href="products.php?delete_image=${img.id}&pid=${p.id}" onclick="return confirm('حذف هذه الصورة؟')"
               style="position:absolute;top:-6px;left:-6px;background:#dc3545;color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;text-decoration:none">
               <i class="fa-solid fa-xmark"></i>
            </a>`;
        wrap.appendChild(div);
    });
    const remaining = 4 - (p.images || []).length;
    document.getElementById('productImagesInput').max = remaining;
    if (remaining <= 0) {
        document.getElementById('productImagesInput').disabled = true;
    }
}
document.getElementById('addProductModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('productForm').reset();
    document.getElementById('product_id').value = '';
    document.getElementById('product_show_in_store').checked = true;
    document.getElementById('productModalTitle').innerText = 'إضافة منتج جديد';
    document.getElementById('unitLevelsBody').innerHTML = '';
    document.getElementById('existingImagesWrap').innerHTML = '';
    document.getElementById('productImagesInput').disabled = false;
    document.getElementById('noImagesYetNotice').classList.remove('d-none');
    unitLevelCount = 0;
    document.getElementById('unitsBuilderSection').classList.remove('d-none');
    document.getElementById('editNotice').classList.add('d-none');
    syncBaseUnitLabel();
});
document.addEventListener('DOMContentLoaded', function () {
    syncBaseUnitLabel();
    document.getElementById('noImagesYetNotice').classList.remove('d-none');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
