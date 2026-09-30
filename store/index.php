<?php
require_once __DIR__ . '/includes/store_functions.php';

$search = trim($_GET['search'] ?? '');
$category_id = (int)($_GET['category_id'] ?? 0);

$sql = "SELECT p.*, c.name category_name,
        (SELECT COALESCE(SUM(quantity),0) FROM stock_batches WHERE product_id = p.id AND location='selling') available_qty
        FROM products p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.show_in_store = 1";
$params = [];
if ($search !== '') { $sql .= " AND p.name LIKE ?"; $params[] = "%$search%"; }
if ($category_id > 0) { $sql .= " AND p.category_id = ?"; $params[] = $category_id; }
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// أسعار متدرجة حقيقية من جدول product_prices (تُعرض كشرائح على كل بطاقة منتج)
$tiersByProduct = [];
if (!empty($products)) {
    $ids = array_column($products, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tstmt = $pdo->prepare("SELECT * FROM product_prices WHERE product_id IN ($in) ORDER BY price ASC");
    $tstmt->execute($ids);
    foreach ($tstmt->fetchAll() as $t) { $tiersByProduct[$t['product_id']][] = $t; }
}

$categories = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.show_in_store = 1) cnt
    FROM categories c
    WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.show_in_store = 1)
    ORDER BY cnt DESC
")->fetchAll();

$catIcons = ['fa-wheat-awn','fa-jar','fa-bottle-droplet','fa-cookie','fa-soap','fa-mug-hot','fa-boxes-stacked','fa-basket-shopping'];
$catGradients = [
    'linear-gradient(135deg,#B8860B,#8B6914)',
    'linear-gradient(135deg,#2D3E63,#0A1628)',
    'linear-gradient(135deg,#4A5F8A,#1A2847)',
    'linear-gradient(135deg,#D4A017,#B8860B)',
];

$totalProductsInStore = (int)$pdo->query("SELECT COUNT(*) c FROM products WHERE show_in_store = 1")->fetch()['c'];

// [جديد] بيانات الأقسام الإضافية بالرئيسية — كل منها من بيانات حقيقية بقاعدة البيانات
$isHome = ($search === '' && $category_id === 0);
$banners = $isHome ? get_store_active_banners($pdo) : [];
$bestSellers = $isHome ? get_best_selling_store_products($pdo, 8, 90) : [];
$newestProducts = $isHome ? get_newest_store_products($pdo, 8) : [];
$discountedProducts = $isHome ? get_discounted_store_products($pdo, 8) : [];

// [جديد] إحصائيات التجار اللحظية (عدد التجار حسب المحافظة + إجمالي استفادتهم) لشريط الصفحة الرئيسية
$platformStats = $isHome ? get_platform_merchant_stats($pdo) : null;

// دالة عرض بطاقة منتج موحّدة (تُستخدم بكل أقسام الصفحة الرئيسية) — تعرض شارة خصم حقيقية عند وجودها
function render_home_pcard($pdo, $p, $badge = null) {
    $price = store_display_price($pdo, $p);
    $hasDiscount = $p['store_compare_price'] && $p['store_compare_price'] > $price;
    $images = get_product_images($pdo, $p['id']);
    $img = !empty($images) ? '../uploads/products/' . $images[0]['image_path'] : ($p['store_image'] ?: 'https://placehold.co/400x300?text=' . urlencode($p['name']));
    $availableQty = $p['available_qty'] ?? null;
    if ($availableQty === null) {
        $q = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) t FROM stock_batches WHERE product_id=? AND location='selling'");
        $q->execute([$p['id']]);
        $availableQty = (float)$q->fetch()['t'];
    }
    ob_start(); ?>
    <div class="pcard fade-in">
        <a href="product.php?id=<?= $p['id'] ?>" class="pcard-media">
            <img src="<?= e($img) ?>" loading="lazy" alt="<?= e($p['name']) ?>">
            <?php if ($badge): ?><span class="pcard-badge" style="background:<?= $badge['color'] ?>"><?= $badge['label'] ?></span>
            <?php elseif ($hasDiscount): ?><span class="pcard-badge">-<?= round((($p['store_compare_price']-$price)/$p['store_compare_price'])*100) ?>%</span><?php endif; ?>
            <?php if ($availableQty <= 0): ?><span class="pcard-stock out">غير متاح</span><?php else: ?><span class="pcard-stock in">متوفر</span><?php endif; ?>
        </a>
        <div class="pcard-body">
            <span class="pcard-cat"><?= e($p['category_name'] ?? '') ?></span>
            <a href="product.php?id=<?= $p['id'] ?>" class="pcard-title"><?= e($p['name']) ?></a>
            <div class="pcard-price-row">
                <?php if ($price > 0): ?>
                    <span class="pcard-price"><?= number_format($price,2) ?></span><small><?= CURRENCY ?></small>
                    <?php if ($hasDiscount): ?><small class="text-muted-store text-decoration-line-through" style="margin-inline-start:4px"><?= number_format($p['store_compare_price'],2) ?></small><?php endif; ?>
                <?php else: ?>
                    <span class="pcard-price text-muted-store" style="font-size:14px">اتصل للسعر</span>
                <?php endif; ?>
            </div>
            <div class="pcard-actions">
                <a href="product.php?id=<?= $p['id'] ?>" class="btn btn-outline"><i class="fa-solid fa-eye"></i> عرض</a>
                <a href="product.php?id=<?= $p['id'] ?>" class="btn btn-primary"><i class="fa-solid fa-cart-plus"></i> أضف</a>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

require_once __DIR__ . '/includes/store_header.php';
?>

<?php if ($isHome): ?>
<section class="hero">
    <div class="container">
        <div class="fade-in">
            <span class="hero-eyebrow"><i class="fa-solid fa-bolt"></i> منصة B2B للبيع بالجملة</span>
            <h1>جملة بذكاء، بأسعار<br>تنافسية وشفافة</h1>
            <p class="lead">تصفّح <?= $totalProductsInStore ?> منتجاً بأسعار متدرجة حسب الكمية، واطلب مباشرة لمحلك — أو تواصل معنا عبر واتساب في أي وقت.</p>
            <div class="hero-cta">
                <a href="#catalog" class="btn btn-primary btn-lg"><i class="fa-solid fa-cart-shopping"></i> تسوّق الآن</a>
                <a href="register.php" class="btn btn-outline btn-lg" style="border-color:rgba(255,255,255,.25);color:#fff">سجّل محلك مجاناً</a>
            </div>
        </div>
        <div class="hero-visual">
            <div class="hero-stat"><div class="num"><?= $totalProductsInStore ?>+</div><div class="lbl">منتج متاح للطلب</div></div>
            <div class="hero-stat"><div class="num">24س</div><div class="lbl">متوسط وقت التوصيل</div></div>
            <div class="hero-stat"><div class="num"><?= count($categories) ?></div><div class="lbl">صنف رئيسي</div></div>
            <div class="hero-stat"><div class="num">آجل</div><div class="lbl">دفع متاح حتى 30 يوم</div></div>
        </div>
    </div>
</section>

<div class="container">
    <!-- [جديد] شريط إحصائيات التجار اللحظي: عدد التجار بكل محافظة + إجمالي استفادتهم -->
    <div class="merchant-ticker fade-in" id="merchantTicker">
        <div class="merchant-ticker-summary">
            <span class="item"><i class="fa-solid fa-shop"></i> إجمالي التجار: <b id="mtTotalMerchants"><?= (int)$platformStats['total_merchants'] ?></b></span>
            <span class="item"><i class="fa-solid fa-hand-holding-heart"></i> إجمالي استفادة التجار: <b id="mtTotalBenefit"><?= number_format($platformStats['total_benefit'],0) ?> <?= CURRENCY ?></b></span>
            <span class="item"><i class="fa-solid fa-circle" style="font-size:7px;color:#10B981"></i> تحديث حي</span>
        </div>
        <div class="merchant-ticker-track-wrap">
            <div class="merchant-ticker-track" id="merchantTickerTrack">
                <?php foreach (array_merge($platformStats['by_city'], $platformStats['by_city']) as $c): ?>
                <span class="merchant-ticker-chip"><i class="fa-solid fa-location-dot"></i> <?= e($c['city']) ?>: <b><?= (int)$c['cnt'] ?></b> تاجر</span>
                <?php endforeach; ?>
                <?php if (empty($platformStats['by_city'])): ?>
                <span class="merchant-ticker-chip">لا يوجد تجار مُفعَّلون بعد</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($banners)): ?>
    <!-- [جديد] شريط البانرات الإعلانية — يُدار بالكامل من admin/store_banners.php -->
    <div class="banner-slider fade-in" id="bannerSlider">
        <div class="banner-track" id="bannerTrack">
            <?php foreach ($banners as $b):
                $img = '<img src="../uploads/banners/' . e($b['image_path']) . '" alt="' . e($b['title'] ?: '') . '">';
            ?>
                <div class="banner-slide">
                    <?php if ($b['link_url']): ?><a href="<?= e($b['link_url']) ?>"><?= $img ?></a><?php else: ?><?= $img ?><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($banners) > 1): ?>
        <div class="banner-dots" id="bannerDots">
            <?php foreach ($banners as $i => $b): ?><span class="banner-dot <?= $i===0?'active':'' ?>" onclick="bannerGoTo(<?= $i ?>)"></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="trust-strip">
        <div class="trust-item"><div class="ic"><i class="fa-solid fa-tags"></i></div><div><strong>أسعار الجملة</strong><span>شرائح مخفّضة حسب الكمية</span></div></div>
        <div class="trust-item"><div class="ic"><i class="fa-solid fa-truck-fast"></i></div><div><strong>توصيل سريع</strong><span>خلال 24 ساعة</span></div></div>
        <div class="trust-item"><div class="ic"><i class="fa-solid fa-shield-halved"></i></div><div><strong>دفع آمن</strong><span>فوري أو آجل حسب حد ائتمانك</span></div></div>
        <div class="trust-item"><div class="ic"><i class="fa-brands fa-whatsapp"></i></div><div><strong>دعم دائم</strong><span>تواصل مباشر عبر واتساب</span></div></div>
    </div>

    <?php if (!empty($categories)): ?>
    <div class="section-head">
        <div><h2>تصفّح حسب الصنف</h2><p>اختر الصنف المناسب لمحلك</p></div>
    </div>
    <div class="bento-grid">
        <?php foreach (array_slice($categories, 0, 8) as $i => $c): ?>
        <a href="index.php?category_id=<?= $c['id'] ?>" class="bento-card <?= $i===0?'span-2':'' ?>" style="background:<?= $catGradients[$i % count($catGradients)] ?>">
            <i class="fa-solid <?= $catIcons[$i % count($catIcons)] ?> ic"></i>
            <span class="name"><?= e($c['name']) ?></span>
            <span class="count"><?= $c['cnt'] ?> منتج</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($discountedProducts)): ?>
    <div class="section-head">
        <div><h2><i class="fa-solid fa-tags text-gold"></i> عروض وخصومات</h2><p>أسعار مخفّضة لفترة محدودة</p></div>
        <span class="badge-pill badge-danger">حتى نفاد الكمية</span>
    </div>
    <div class="related-scroll">
        <?php foreach ($discountedProducts as $p): echo render_home_pcard($pdo, $p); ?><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($bestSellers)): ?>
    <div class="section-head">
        <div><h2><i class="fa-solid fa-fire text-gold"></i> الأكثر طلباً</h2><p>أكثر المنتجات طلباً من تجار الجملة آخر 90 يوماً</p></div>
    </div>
    <div class="related-scroll">
        <?php foreach ($bestSellers as $p): echo render_home_pcard($pdo, $p, ['label' => 'الأكثر طلباً', 'color' => 'var(--n-800)']); ?><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($newestProducts)): ?>
    <div class="section-head">
        <div><h2><i class="fa-solid fa-sparkles text-gold"></i> منتجات جديدة</h2><p>أحدث ما أضفناه لكتالوج المتجر</p></div>
    </div>
    <div class="related-scroll">
        <?php foreach ($newestProducts as $p): echo render_home_pcard($pdo, $p, ['label' => 'جديد', 'color' => 'var(--c-info)']); ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="container" id="catalog">
    <div class="section-head">
        <div>
            <h2><?= $category_id ? e(array_values(array_filter($categories, fn($c)=>$c['id']==$category_id))[0]['name'] ?? 'المنتجات') : 'كل المنتجات' ?></h2>
            <p><?= count($products) ?> منتج متاح</p>
        </div>
        <form method="get" style="min-width:240px">
            <?php if ($category_id): ?><input type="hidden" name="category_id" value="<?= $category_id ?>"><?php endif; ?>
            <input type="text" name="search" class="gform-control" placeholder="بحث في هذا القسم..." value="<?= e($search) ?>">
        </form>
    </div>

    <div class="product-grid">
        <?php foreach ($products as $p): echo render_home_pcard($pdo, $p); ?><?php endforeach; ?>
        <?php if (empty($products)): ?>
        <div style="grid-column:1/-1">
            <div class="gcard" style="padding:48px;text-align:center">
                <i class="fa-solid fa-box-open" style="font-size:32px;color:var(--g-500);margin-bottom:12px"></i>
                <p class="mb-0 text-muted-store">لا توجد منتجات مطابقة حالياً.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let bannerIndex = 0;
function bannerGoTo(i) {
    const track = document.getElementById('bannerTrack');
    if (!track) return;
    const slides = track.children;
    bannerIndex = ((i % slides.length) + slides.length) % slides.length;
    track.style.transform = 'translateX(' + (bannerIndex * 100) + '%)';
    document.querySelectorAll('.banner-dot').forEach((d, idx) => d.classList.toggle('active', idx === bannerIndex));
}
document.addEventListener('DOMContentLoaded', function () {
    const track = document.getElementById('bannerTrack');
    if (track && track.children.length > 1) {
        setInterval(() => bannerGoTo(bannerIndex + 1), 5000);
    }
});

<?php if ($isHome): ?>
// [جديد] تحديث شريط إحصائيات التجار حياً كل 20 ثانية دون إعادة تحميل الصفحة
function refreshMerchantTicker() {
    fetch('ajax/merchant_stats.php')
        .then(r => r.json())
        .then(function (data) {
            const totalEl = document.getElementById('mtTotalMerchants');
            const benefitEl = document.getElementById('mtTotalBenefit');
            if (totalEl) totalEl.textContent = data.total_merchants;
            if (benefitEl) benefitEl.textContent = Number(data.total_benefit).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' <?= CURRENCY ?>';

            const track = document.getElementById('merchantTickerTrack');
            if (track && Array.isArray(data.by_city)) {
                const cities = data.by_city.length ? data.by_city : [{city: 'لا يوجد تجار مُفعَّلون بعد', cnt: null}];
                const chips = cities.concat(cities).map(function (c) {
                    return c.cnt === null
                        ? '<span class="merchant-ticker-chip">' + c.city + '</span>'
                        : '<span class="merchant-ticker-chip"><i class="fa-solid fa-location-dot"></i> ' + c.city + ': <b>' + c.cnt + '</b> تاجر</span>';
                }).join('');
                track.innerHTML = chips;
            }
        })
        .catch(() => {});
}
setInterval(refreshMerchantTicker, 20000);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
