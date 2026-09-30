-- ============================================================
-- ملف ترحيل قاعدة البيانات: معرض صور المنتج + البانرات + تفاصيل
-- العبوة/الوزن + سعر ما قبل الخصم
-- ============================================================
-- ملاحظة: هذا الترحيل يتم تلقائياً عند أول تحميل لأي صفحة (عبر دالة
-- ensure_product_extras_schema() المُستدعاة داخل ensure_schema() في
-- admin/includes/functions.php)، فلا حاجة لتنفيذه يدوياً في الوضع الطبيعي.
-- هذا الملف للتوثيق والمراجعة اليدوية فقط.
--
-- [جدول جديد] product_images  — معرض صور إضافية لكل منتج (حتى 4 صور)
-- [جدول جديد] store_banners   — بانرات إعلانية للصفحة الرئيسية بالمتجر
--                                (تُدار من admin/store_banners.php)
-- [جدول مُعدَّل] products:
--    - store_package_info   TEXT NULL           تفاصيل العبوة (نص حر)
--    - store_net_weight     DECIMAL(10,3) NULL  الوزن الصافي
--    - store_weight_unit    VARCHAR(20) NULL    وحدة الوزن (كجم/جرام/لتر...)
--    - store_compare_price  DECIMAL(12,2) NULL  السعر قبل الخصم (لعرض شارة
--                            الخصم بالمتجر عند وجود عرض على المنتج)
-- ============================================================

USE grocery_system;

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NULL,
    image_path VARCHAR(255) NOT NULL,
    link_url VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS store_package_info TEXT NULL AFTER store_image,
    ADD COLUMN IF NOT EXISTS store_net_weight DECIMAL(10,3) NULL AFTER store_package_info,
    ADD COLUMN IF NOT EXISTS store_weight_unit VARCHAR(20) NULL DEFAULT 'كجم' AFTER store_net_weight,
    ADD COLUMN IF NOT EXISTS store_compare_price DECIMAL(12,2) NULL AFTER store_weight_unit;

-- ============================================================
-- [متطلب على السيرفر] مجلدات رفع الملفات — يجب أن تكون موجودة وقابلة
-- للكتابة من قبل خادم الويب (لا تُنشأ بواسطة SQL، بل بواسطة الكود تلقائياً
-- عند أول رفع؛ لكن يُفضّل إنشاؤها وضبط الصلاحيات يدوياً مسبقاً):
--   grocery-system/uploads/products/   (صور المنتجات، حتى 4 لكل منتج)
--   grocery-system/uploads/banners/    (بانرات الصفحة الرئيسية)
-- على Linux: chmod 755 (أو 775 حسب مستخدم خادم الويب) لهذين المجلدين.
-- ============================================================
