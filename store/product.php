<?php
require_once __DIR__ . '/includes/store_functions.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, c.name category_name, c.id category_id FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.show_in_store = 1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { flash('المنتج غير متاح', 'danger'); redirect('index.php'); }

$stmt2 = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) t FROM stock_batches WHERE product_id = ? AND location='selling'");
$stmt2->execute([$id]);
$available_qty = (float)$stmt2->fetch()['t'];

$effectivePrice = store_display_price($pdo, $product);
$hasDiscount = $product['store_compare_price'] && $product['store_compare_price'] > $effectivePrice;
$discountPct = $hasDiscount ? round((($product['store_compare_price'] - $effectivePrice) / $product['store_compare_price']) * 100) : 0;

$units = get_product_units($pdo, $id); // الأكبر أولاً
$units = array_values(array_filter($units, fn($u) => $u['allow_sell'] || $u['is_base']));
$baseUnit = get_base_unit($pdo, $id);

// [جديد] معرض صور المنتج (حتى 4 صور) — مع الرجوع لعمود store_image القديم إن لم توجد صور بالمعرض الجديد
$galleryImages = get_product_images($pdo, $id);
$galleryUrls = [];
foreach ($galleryImages as $img) { $galleryUrls[] = '../uploads/products/' . $img['image_path']; }
if (empty($galleryUrls) && $product['store_image']) { $galleryUrls[] = $product['store_image']; }
if (empty($galleryUrls)) { $galleryUrls[] = 'https://placehold.co/900x650?text=' . urlencode($product['name']); }

// شرائح الأسعار الحقيقية المُعرَّفة لهذا المنتج (من صفحة إدارة أسعار المنتج بلوحة التحكم)
$tiersStmt = $pdo->prepare("SELECT * FROM product_prices WHERE product_id = ? ORDER BY price ASC");
$tiersStmt->execute([$id]);
$tiers = $tiersStmt->fetchAll();

// [جديد] منتجات مقترحة بناءً على طلبات المستخدم السابقة فعلياً (لو مسجّل دخول وله تاريخ طلبات)
$personalizedRecs = [];
if (store_logged_in()) {
    $personalizedRecs = get_personalized_recommendations($pdo, store_current_id(), $id, 6);
}

// منتجات مشابهة (نفس الصنف) — احتياطي دائماً يظهر بجانب/بدل المقترحة الشخصية
$related = $pdo->prepare("
    SELECT p.*, (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id=p.id AND location='selling') available_qty
    FROM products p WHERE p.show_in_store = 1 AND p.category_id <=> ? AND p.id != ?
    ORDER BY RAND() LIMIT 8
");
$related->execute([$product['category_id'], $id]);
$related = $related->fetchAll();
if (count($related) < 4) {
    $more = $pdo->prepare("SELECT p.*, (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id=p.id AND location='selling') available_qty
        FROM products p WHERE p.show_in_store = 1 AND p.id != ? AND p.id NOT IN (" . (empty($related) ? '0' : implode(',', array_column($related,'id'))) . ") ORDER BY RAND() LIMIT " . (8 - count($related)));
    $more->execute([$id]);
    $related = array_merge($related, $more->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    if ($effectivePrice <= 0) {
        flash('لم يتم تحديد سعر بيع لهذا المنتج بعد، يرجى التواصل معنا للاستفسار عن السعر', 'danger');
        redirect('product.php?id=' . $id);
    }
    $unit_id = (int)$_POST['unit_id'];
    $qty = (float)$_POST['qty'];
    if ($qty > 0) {
        cart_add($id, $unit_id, $qty);
        flash('تمت إضافة المنتج للسلة بنجاح');
        redirect('product.php?id=' . $id);
    }
}

function render_product_card_store($pdo, $r) {
    $rp = store_display_price($pdo, $r);
    $rDiscount = $r['store_compare_price'] && $r['store_compare_price'] > $rp;
    $rImages = get_product_images($pdo, $r['id']);
    $rImg = !empty($rImages) ? '../uploads/products/' . $rImages[0]['image_path'] : ($r['store_image'] ?: 'https://placehold.co/400x300?text=' . urlencode($r['name']));
    ob_start(); ?>
    <a href="product.php?id=<?= $r['id'] ?>" class="pcard" style="text-decoration:none">
        <div class="pcard-media">
            <img src="<?= e($rImg) ?>" loading="lazy">
            <?php if ($rDiscount): ?><span class="pcard-badge">-<?= round((($r['store_compare_price']-$rp)/$r['store_compare_price'])*100) ?>%</span><?php endif; ?>
            <?php if (($r['available_qty'] ?? 0) <= 0): ?><span class="pcard-stock out">غير متاح</span><?php endif; ?>
        </div>
        <div class="pcard-body">
            <span class="pcard-title"><?= e($r['name']) ?></span>
            <div class="pcard-price-row">
                <span class="pcard-price"><?= $rp>0?number_format($rp,2):'—' ?></span><small><?= CURRENCY ?></small>
                <?php if ($rDiscount): ?><small class="text-muted-store text-decoration-line-through" style="margin-inline-start:4px"><?= number_format($r['store_compare_price'],2) ?></small><?php endif; ?>
            </div>
        </div>
    </a>
    <?php return ob_get_clean();
}

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding-top:20px;padding-bottom:64px">
    <nav class="text-sm text-muted-store mb-3">
        <a href="index.php"><i class="fa-solid fa-house"></i> الرئيسية</a>
        <?php if ($product['category_name']): ?> <i class="fa-solid fa-chevron-left text-xs" style="margin:0 4px"></i> <a href="index.php?category_id=<?= $product['category_id'] ?>"><?= e($product['category_name']) ?></a><?php endif; ?>
        <i class="fa-solid fa-chevron-left text-xs" style="margin:0 4px"></i> <span class="text-navy fw-bold"><?= e($product['name']) ?></span>
    </nav>

    <div class="row g-4">
        <!-- المعرض -->
        <div class="col-12 col-lg-7">
            <div class="gcard fade-in pzoom-wrap" style="overflow:hidden;position:relative">
                <?php if ($hasDiscount): ?><span class="pcard-badge" style="position:absolute;top:14px;inset-inline-end:14px;z-index:2">خصم <?= $discountPct ?>%</span><?php endif; ?>
                <?php if ($available_qty <= 0): ?><span class="pcard-stock out" style="position:absolute;top:14px;inset-inline-start:14px;z-index:2">غير متاح</span>
                <?php else: ?><span class="pcard-stock in" style="position:absolute;top:14px;inset-inline-start:14px;z-index:2">متوفر بالمخزون</span><?php endif; ?>
                <div class="pzoom">
                    <img id="mainGalleryImg" src="<?= e($galleryUrls[0]) ?>" style="width:100%;aspect-ratio:4/3;object-fit:cover" alt="<?= e($product['name']) ?>">
                </div>
            </div>
            <?php if (count($galleryUrls) > 1): ?>
            <div class="d-flex gap-2 mt-2">
                <?php foreach ($galleryUrls as $i => $url): ?>
                    <img src="<?= e($url) ?>" class="gallery-thumb <?= $i===0?'active':'' ?>" onclick="qoSwitchGallery(this,'<?= e($url) ?>')" style="width:70px;height:70px;object-fit:cover;border-radius:8px;cursor:pointer">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="gcard mt-store-lg">
                <div class="ptabs">
                    <button class="ptab-btn active" data-tab="desc">الوصف</button>
                    <?php if ($product['store_package_info'] || $product['store_net_weight']): ?><button class="ptab-btn" data-tab="package">العبوة والوزن</button><?php endif; ?>
                    <button class="ptab-btn" data-tab="units">الوحدات والتعبئة</button>
                    <button class="ptab-btn" data-tab="shipping">الشحن والدفع</button>
                </div>
                <div class="gcard-body">
                    <div class="ptab-pane active" id="tab-desc">
                        <?php if ($product['store_description']): ?>
                            <p class="text-sm" style="line-height:1.9;margin:0"><?= nl2br(e($product['store_description'])) ?></p>
                        <?php else: ?>
                            <p class="text-sm text-muted-store mb-0">لا يوجد وصف تفصيلي لهذا المنتج بعد. للاستفسار عن أي تفاصيل، <a href="manual_order.php">تواصل معنا</a>.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ($product['store_package_info'] || $product['store_net_weight']): ?>
                    <div class="ptab-pane" id="tab-package">
                        <?php if ($product['store_package_info']): ?>
                            <p class="text-sm" style="line-height:1.9"><?= nl2br(e($product['store_package_info'])) ?></p>
                        <?php endif; ?>
                        <?php if ($product['store_net_weight']): ?>
                            <div class="badge-pill badge-info"><i class="fa-solid fa-weight-hanging"></i> الوزن الصافي: <?= rtrim(rtrim(number_format($product['store_net_weight'],3),'0'),'.') ?> <?= e($product['store_weight_unit'] ?: 'كجم') ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="ptab-pane" id="tab-units">
                        <table class="tier-table" style="width:100%">
                            <thead><tr><th>الوحدة</th><th>تعادل (بالوحدة الأساسية)</th><th>مسموح بها للبيع</th></tr></thead>
                            <tbody>
                            <?php foreach ($units as $u): ?>
                                <tr>
                                    <td><?= e($u['unit_name']) ?> <?= $u['is_base'] ? '<span class="badge-pill badge-info text-xs">أساسية</span>' : '' ?></td>
                                    <td class="tabular-num"><?= $u['is_base'] ? '1' : rtrim(rtrim(number_format($u['factor'],4),'0'),'.') ?> <?= e($baseUnit['unit_name']) ?></td>
                                    <td><i class="fa-solid fa-check text-success"></i></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="ptab-pane" id="tab-shipping">
                        <ul class="text-sm" style="line-height:2;padding-inline-start:20px;margin:0">
                            <li>التوصيل خلال 24 ساعة لطلبات القاهرة الكبرى، وحتى 3 أيام لباقي المحافظات.</li>
                            <li>الدفع فوري عند التسليم، أو آجل حسب حد الائتمان المتاح لحسابك.</li>
                            <li>فاتورة ضريبية تُرفق تلقائياً مع كل طلب معتمد.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- صندوق الشراء -->
        <div class="col-12 col-lg-5">
            <div class="fade-in" style="position:sticky;top:90px">
                <?php if ($product['category_name']): ?><span class="badge-pill badge-info mb-2"><?= e($product['category_name']) ?></span><?php endif; ?>
                <h1 class="h1" style="margin-bottom:6px"><?= e($product['name']) ?></h1>
                <p class="text-sm text-muted-store mb-3">SKU: <?= $product['barcode'] ? e($product['barcode']) : 'PRD-' . str_pad($product['id'],4,'0',STR_PAD_LEFT) ?></p>

                <?php if ($available_qty <= 0): ?>
                    <div class="gcard" style="padding:16px;background:var(--g-100);border:none">
                        <span class="badge-pill badge-warning mb-2"><i class="fa-solid fa-triangle-exclamation"></i> غير متاح بالمخزون حالياً</span>
                        <p class="text-sm mb-0">يمكنك <a href="manual_order.php">التواصل معنا</a> للاستفسار عن موعد التوفر.</p>
                    </div>
                <?php elseif ($effectivePrice <= 0): ?>
                    <div class="gcard" style="padding:16px;background:var(--g-100);border:none">
                        <p class="text-sm mb-0">السعر قيد التحديد حالياً. <a href="manual_order.php">تواصل معنا</a> للاستفسار.</p>
                    </div>
                <?php else: ?>

                <div style="display:flex;align-items:baseline;gap:8px;margin:10px 0 4px">
                    <span class="tabular-num" style="font-size:32px;font-weight:800;color:var(--p-700)" id="unitPriceDisplay"><?= number_format($effectivePrice,2) ?></span>
                    <span class="text-sm text-muted-store"><?= CURRENCY ?> / <span id="unitLabel"><?= e($baseUnit['unit_name']) ?></span></span>
                    <?php if ($hasDiscount): ?><span class="text-sm text-muted-store text-decoration-line-through"><?= number_format($product['store_compare_price'],2) ?></span><span class="badge-pill badge-danger">وفّر <?= $discountPct ?>%</span><?php endif; ?>
                </div>
                <span class="badge-pill badge-success mb-3"><i class="fa-solid fa-circle-check"></i> متوفر — <?= rtrim(rtrim(number_format($available_qty,2),'0'),'.') ?> <?= e($product['unit']) ?></span>

                <?php if (!empty($tiers)): ?>
                <div class="gcard" style="margin:16px 0;border-color:var(--p-100)">
                    <div class="gcard-header" style="background:var(--p-50);border-color:var(--p-100)"><i class="fa-solid fa-layer-group text-gold"></i> جدول الأسعار المتدرج</div>
                    <table class="tier-table" style="width:100%">
                        <thead><tr><th>مستوى السعر</th><th>السعر / <?= e($baseUnit['unit_name']) ?></th></tr></thead>
                        <tbody>
                        <tr class="best"><td>سعر الوحدة (دفعة حالية)</td><td class="text-gold"><?= number_format($effectivePrice,2) ?> <?= CURRENCY ?></td></tr>
                        <?php foreach ($tiers as $t): ?>
                            <tr><td><?= e($t['price_name']) ?></td><td><?= number_format($t['price'],2) ?> <?= CURRENCY ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="padding:0 var(--sp-lg) var(--sp-md)"><p class="text-xs text-muted-store mb-0">* الأسعار المعروضة لكل وحدة أساسية (<?= e($baseUnit['unit_name']) ?>)؛ تُحوَّل تلقائياً حسب الوحدة المختارة أدناه.</p></div>
                </div>
                <?php endif; ?>

                <form method="post" class="mt-store-lg">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="gform-label">الوحدة</label>
                            <select name="unit_id" id="unitSelect" class="gform-control product-unit-select" data-base-price="<?= $effectivePrice ?>" data-price-target="#unitPriceDisplay" data-label-target="#unitLabel">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" data-factor="<?= $u['factor'] ?>" data-name="<?= e($u['unit_name']) ?>"><?= e($u['unit_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="gform-label">الكمية</label>
                            <div class="qty-stepper">
                                <button type="button" class="qty-btn" id="qtyMinus"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="qty" id="qtyInput" step="0.01" min="<?= $product['store_min_qty'] ?: '0.01' ?>" value="<?= $product['store_min_qty'] ?: 1 ?>" required>
                                <button type="button" class="qty-btn" id="qtyPlus"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                    </div>

                    <?php if ($product['store_min_qty']): ?>
                    <div style="margin-top:14px">
                        <div class="text-xs text-muted-store mb-1" id="moqText">الحد الأدنى للطلب: <?= rtrim(rtrim(number_format($product['store_min_qty'],2),'0'),'.') ?> <span id="moqUnit"><?= e($baseUnit['unit_name']) ?></span></div>
                        <div style="height:6px;border-radius:99px;background:var(--g-200);overflow:hidden">
                            <div id="moqBar" style="height:100%;background:linear-gradient(90deg,var(--p-500),var(--c-success));width:100%;transition:width .25s var(--ease-out)"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0;padding:14px 16px;background:var(--g-50);border-radius:var(--r-md)">
                        <span class="text-sm text-muted-store">الإجمالي التقديري</span>
                        <span class="tabular-num" id="lineTotal" style="font-size:20px;font-weight:800;color:var(--n-800)"><?= number_format($effectivePrice * ($product['store_min_qty'] ?: 1),2) ?> <?= CURRENCY ?></span>
                    </div>

                    <?php if (store_logged_in()): ?>
                    <button type="submit" name="add_to_cart" value="1" class="btn btn-primary btn-lg btn-block"><i class="fa-solid fa-cart-plus"></i> أضف للسلة</button>
                    <?php else: ?>
                    <a href="login.php" class="btn btn-navy btn-lg btn-block mb-2"><i class="fa-solid fa-right-to-bracket"></i> سجّل الدخول للطلب</a>
                    <a href="manual_order.php" class="btn btn-outline btn-lg btn-block"><i class="fa-brands fa-whatsapp"></i> أو اطلب عبر واتساب</a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <div class="row g-2 mt-store-lg">
                    <div class="col-4"><div class="trust-item" style="flex-direction:column;text-align:center;gap:6px;padding:12px"><i class="fa-solid fa-file-invoice text-gold"></i><span class="text-xs">فاتورة ضريبية</span></div></div>
                    <div class="col-4"><div class="trust-item" style="flex-direction:column;text-align:center;gap:6px;padding:12px"><i class="fa-solid fa-truck text-gold"></i><span class="text-xs">توصيل 24 ساعة</span></div></div>
                    <div class="col-4"><div class="trust-item" style="flex-direction:column;text-align:center;gap:6px;padding:12px"><i class="fa-solid fa-rotate-left text-gold"></i><span class="text-xs">سياسة استرجاع</span></div></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($personalizedRecs)): ?>
    <div class="section-head"><div><h2><i class="fa-solid fa-clock-rotate-left text-gold"></i> بناءً على طلباتك السابقة</h2><p>منتجات طلبتها من قبل وقد تحتاجها مجدداً</p></div></div>
    <div class="related-scroll">
        <?php foreach ($personalizedRecs as $r): echo render_product_card_store($pdo, $r); ?><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($related)): ?>
    <div class="section-head"><div><h2>قد يعجبك أيضاً</h2><p>منتجات مشابهة قد تحتاجها لمحلك</p></div></div>
    <div class="related-scroll">
        <?php foreach ($related as $r): echo render_product_card_store($pdo, $r); ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function qoSwitchGallery(el, url) {
    document.getElementById('mainGalleryImg').src = url;
    document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function () {
    const unitSelect = document.getElementById('unitSelect');
    const qtyInput = document.getElementById('qtyInput');
    const totalEl = document.getElementById('lineTotal');
    const moqBar = document.getElementById('moqBar');
    const moqUnit = document.getElementById('moqUnit');
    const moqNeed = <?= (float)($product['store_min_qty'] ?: 0) ?>;

    function recalc() {
        if (!unitSelect) return;
        const factor = parseFloat(unitSelect.selectedOptions[0]?.dataset.factor || 1);
        const name = unitSelect.selectedOptions[0]?.dataset.name || '';
        const basePrice = parseFloat(unitSelect.dataset.basePrice || 0);
        const unitPrice = basePrice * factor;
        const qty = parseFloat(qtyInput.value) || 0;

        const priceTarget = document.querySelector(unitSelect.dataset.priceTarget);
        const labelTarget = document.querySelector(unitSelect.dataset.labelTarget);
        if (priceTarget) priceTarget.textContent = unitPrice.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
        if (labelTarget) labelTarget.textContent = name;
        if (moqUnit) moqUnit.textContent = name;

        if (totalEl) totalEl.textContent = (unitPrice * qty).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' <?= CURRENCY ?>';

        if (moqBar && moqNeed > 0) {
            const qtyInBase = qty * factor;
            const pct = Math.min(100, (qtyInBase / moqNeed) * 100);
            moqBar.style.width = pct + '%';
        }
    }
    if (unitSelect) unitSelect.addEventListener('change', recalc);
    if (qtyInput) qtyInput.addEventListener('input', recalc);
    recalc();

    const step = parseFloat(qtyInput?.step || 1) || 1;
    document.getElementById('qtyPlus')?.addEventListener('click', function () {
        qtyInput.value = (parseFloat(qtyInput.value || 0) + step).toFixed(2);
        recalc();
    });
    document.getElementById('qtyMinus')?.addEventListener('click', function () {
        const min = parseFloat(qtyInput.min || 0);
        qtyInput.value = Math.max(min, parseFloat(qtyInput.value || 0) - step).toFixed(2);
        recalc();
    });

    // Tabs
    document.querySelectorAll('.ptab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.ptab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.ptab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
