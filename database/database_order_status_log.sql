-- ============================================================
-- ملف ترحيل قاعدة البيانات: سجل تتبّع حالة طلبات المتجر
-- (Order Status Audit Trail: من قام بالتحديث + ملاحظات + مرفقات)
-- ============================================================
-- ملاحظة: هذا الترحيل يتم تلقائياً عند أول تحميل لأي صفحة (عبر دالة
-- ensure_order_status_log_schema() المُستدعاة داخل ensure_schema() في
-- admin/includes/functions.php)، فلا حاجة لتنفيذه يدوياً في الوضع الطبيعي.
-- هذا الملف للتوثيق والمراجعة اليدوية فقط.
--
-- [جدول جديد] store_order_status_log
--   كل صف = خطوة واحدة من تاريخ حالة الطلب:
--     - status:            الحالة التي تحوّل الطلب إليها في هذه الخطوة
--     - changed_by_type:   admin (إدارة) / store_customer (صاحب المحل) / system (تلقائي)
--     - changed_by_id:     users.id أو store_customers.id بحسب النوع أعلاه
--     - changed_by_name:   اسم القائم بالتحديث (نسخة ثابتة وقت الحفظ، تبقى
--                          صحيحة للعرض حتى لو تغيّر اسم الحساب لاحقاً)
--     - channel:           dashboard (من لوحة التحكم مباشرة) / whatsapp / telegram
--                          (تُستخدم عندما يُبلّغ صاحب المحل بواتساب/تليجرام
--                          فيسجّل المدير التحديث بلوحة التحكم مع تحديد أن
--                          مصدر المعلومة كان تلك المحادثة) / system (تلقائي)
--     - notes:             ملاحظة اختيارية مرفقة بهذه الخطوة
--
-- [جدول جديد] store_order_status_attachments
--   ملفات (صور أو مستندات) مرفقة بخطوة معيّنة من السجل أعلاه — حتى 5 ملفات
--   لكل تحديث، صور JPG/PNG/WEBP أو مستندات PDF/DOC/DOCX بحد أقصى 6 ميجابايت للملف
-- ============================================================

USE grocery_system;

CREATE TABLE IF NOT EXISTS store_order_status_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status ENUM('pending','confirmed','preparing','shipped','completed','cancelled') NOT NULL,
    changed_by_type ENUM('admin','store_customer','system') NOT NULL DEFAULT 'system',
    changed_by_id INT NULL,
    changed_by_name VARCHAR(150) NULL,
    channel ENUM('dashboard','whatsapp','telegram','system') NOT NULL DEFAULT 'dashboard',
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_order_status_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status_log_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    file_type ENUM('image','document') NOT NULL DEFAULT 'document',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (status_log_id) REFERENCES store_order_status_log(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- [متطلب على السيرفر] مجلد رفع مرفقات تتبّع الطلبات — يجب أن يكون موجوداً
-- وقابلاً للكتابة من قبل خادم الويب (يُنشأ تلقائياً بالكود عند أول رفع،
-- لكن يُفضّل تجهيزه وضبط صلاحياته مسبقاً):
--   grocery-system/uploads/order_attachments/
-- ============================================================
