-- ============================================================
-- ملف ترحيل قاعدة البيانات: منصة البيع بالجملة الإلكترونية
-- ============================================================
-- ملاحظة: هذا الترحيل يتم تلقائياً عند أول تحميل لأي صفحة (عبر دالة
-- ensure_store_schema() في admin/includes/functions.php)، فلا حاجة لتنفيذه
-- يدوياً في الوضع الطبيعي. هذا الملف موجود فقط للتوثيق والمراجعة اليدوية
-- إن احتجت تنفيذه مباشرة على قاعدة بيانات production بدون تشغيل الموقع أولاً.
--
-- ملخص التغييرات:
--   [جدول جديد] store_customers        - حسابات محلات التجزئة المسجّلة بالمتجر
--   [جدول جديد] store_settings         - إعدادات عامة للمتجر (واتساب/تليجرام/اسم الموقع...)
--   [جدول جديد] store_orders           - طلبات الشراء عبر سلة المتجر الإلكتروني
--   [جدول جديد] store_order_items      - بنود كل طلب من طلبات المتجر
--   [جدول جديد] manual_order_requests  - طلبات واردة عبر واتساب/تليجرام (مسجّلين أو زوّار)
--   [تعديل جدول] products              - إضافة أعمدة: show_in_store, store_price,
--                                         store_min_qty, store_description, store_image
-- ============================================================

USE grocery_system;

-- [جدول جديد] store_customers
CREATE TABLE IF NOT EXISTS store_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    whatsapp VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    password VARCHAR(255) NOT NULL,
    city VARCHAR(100) NULL,
    address VARCHAR(255) NULL,
    status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(255) NULL,
    customer_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- [جدول جديد] store_settings
CREATE TABLE IF NOT EXISTS store_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
) ENGINE=InnoDB;

-- [جدول جديد] store_orders
CREATE TABLE IF NOT EXISTS store_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) NOT NULL UNIQUE,
    store_customer_id INT NOT NULL,
    status ENUM('pending','confirmed','preparing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    cancelled_reason VARCHAR(255) NULL,
    sale_invoice_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id),
    FOREIGN KEY (sale_invoice_id) REFERENCES sale_invoices(id)
) ENGINE=InnoDB;

-- [جدول جديد] store_order_items
CREATE TABLE IF NOT EXISTS store_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    unit_id INT NULL,
    unit_name VARCHAR(50) NULL,
    quantity DECIMAL(14,4) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- [جدول جديد] manual_order_requests
CREATE TABLE IF NOT EXISTS manual_order_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('whatsapp','telegram') NOT NULL,
    shop_name VARCHAR(150) NULL,
    contact_name VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    city VARCHAR(100) NULL,
    message TEXT NULL,
    store_customer_id INT NULL,
    status ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
    admin_notes VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- [تعديل جدول] products: أعمدة ظهور المنتج بالمتجر الإلكتروني
-- (تُنفَّذ فقط إن لم تكن الأعمدة موجودة بالفعل)
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS show_in_store TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode,
    ADD COLUMN IF NOT EXISTS store_price DECIMAL(12,2) NULL AFTER show_in_store,
    ADD COLUMN IF NOT EXISTS store_min_qty DECIMAL(12,2) NULL AFTER store_price,
    ADD COLUMN IF NOT EXISTS store_description TEXT NULL AFTER store_min_qty,
    ADD COLUMN IF NOT EXISTS store_image VARCHAR(255) NULL AFTER store_description;

-- إعدادات افتراضية أولية للمتجر
INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES
    ('site_name', 'منصة البيع بالجملة'),
    ('whatsapp_number', ''),
    ('telegram_username', ''),
    ('min_order_amount', '0'),
    ('banner_text', 'اطلب احتياجات محلك بالجملة أونلاين بأسعار تنافسية'),
    ('contact_phone', ''),
    ('registration_note', 'سيتم مراجعة طلب تسجيلكم من الإدارة قبل تفعيل الحساب');
