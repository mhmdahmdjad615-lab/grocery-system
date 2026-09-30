<?php
$waLink = store_whatsapp_link($pdo, 'مرحباً، أرغب بالاستفسار عن الطلب من ' . get_store_setting($pdo, 'site_name', ''));
$tgLink = store_telegram_link($pdo);
?>
</main>

<footer class="gstore-footer">
    <div class="container" style="padding:64px 16px 24px">
        <div class="row g-4">
            <div class="col-12 col-md-4">
                <div class="gstore-brand" style="color:#fff;margin-bottom:12px">
                    <i class="fa-solid fa-boxes-stacked" style="color:var(--p-500)"></i>
                    <span><?= e(get_store_setting($pdo, 'site_name', '')) ?></span>
                </div>
                <p class="text-sm mb-0"><?= e(get_store_setting($pdo, 'banner_text', '')) ?></p>
            </div>
            <div class="col-6 col-md-4">
                <h6>روابط سريعة</h6>
                <ul class="list-unstyled text-sm d-flex flex-column gap-2">
                    <li><a href="index.php">المنتجات</a></li>
                    <li><a href="register.php">تسجيل محل جديد</a></li>
                    <li><a href="manual_order.php">اطلب عبر واتساب/تليجرام</a></li>
                    <li><a href="my_orders.php">طلباتي</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-4">
                <h6>تواصل معنا</h6>
                <ul class="list-unstyled text-sm d-flex flex-column gap-2">
                    <?php if (get_store_setting($pdo,'contact_phone')): ?><li><i class="fa-solid fa-phone"></i> <?= e(get_store_setting($pdo,'contact_phone')) ?></li><?php endif; ?>
                    <?php if ($waLink): ?><li><a href="<?= $waLink ?>" target="_blank"><i class="fa-brands fa-whatsapp"></i> واتساب</a></li><?php endif; ?>
                    <?php if ($tgLink): ?><li><a href="<?= $tgLink ?>" target="_blank"><i class="fa-brands fa-telegram"></i> تليجرام</a></li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="bottom">© <?= date('Y') ?> <?= e(get_store_setting($pdo, 'site_name', '')) ?> — جميع الحقوق محفوظة</div>
</footer>

<?php if ($waLink): ?>
<a href="<?= $waLink ?>" target="_blank" class="floating-whatsapp" title="تواصل عبر واتساب"><i class="fa-brands fa-whatsapp"></i></a>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/store.js"></script>
</body>
</html>
