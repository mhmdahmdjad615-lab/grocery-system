-- ============================================================
-- ملف ترحيل قاعدة البيانات: دعم التصنيفات الفرعية (Subcategories)
-- ============================================================
-- ملاحظة: هذا الترحيل يتم تلقائياً عند أول تحميل لأي صفحة من صفحات المتجر
-- (عبر دالة ensure_store_category_hierarchy() في store/includes/store_functions.php)
-- فلا حاجة لتنفيذه يدوياً في الوضع الطبيعي. هذا الملف للتوثيق والمراجعة اليدوية فقط.
--
-- [الجدول المُعدَّل]: categories
-- [العمود الجديد]:   parent_id INT NULL
--   - NULL  = تصنيف رئيسي (Main Category)
--   - قيمة  = تصنيف فرعي تابع للتصنيف صاحب هذا الـ id (Subcategory)
--
-- لم يتم تعديل أي ملف أو جدول آخر بلوحة تحكم الأدمن. لا توجد بعد واجهة إدارية
-- بلوحة التحكم لتعيين "الأب" لتصنيف فرعي — يمكن تعيينه حالياً مباشرة عبر
-- phpMyAdmin (عمود parent_id بجدول categories)، أو لاحقاً بإضافة حقل اختياري
-- "تصنيف رئيسي" لصفحة admin/categories.php عند الرغبة.
-- ============================================================

USE grocery_system;

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS parent_id INT NULL AFTER name;

ALTER TABLE categories
    ADD CONSTRAINT fk_categories_parent
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL;

-- مثال توضيحي لإنشاء تصنيف فرعي (اختياري، لا يُنفَّذ تلقائياً):
-- INSERT INTO categories (name, parent_id) VALUES ('أرز', (SELECT id FROM categories WHERE name = 'مواد غذائية' LIMIT 1));
