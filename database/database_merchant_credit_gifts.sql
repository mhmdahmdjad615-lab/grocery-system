-- ============================================================
-- ملف ترحيل قاعدة البيانات: رصيد الائتمان الديناميكي للتجار
-- + فواتير الهدايا المجانية + تتبّع المدفوع الفوري بطلبات المتجر
-- ============================================================
-- ملاحظة: هذا الترحيل يتم تلقائياً عند أول تحميل لأي صفحة (عبر دالة
-- ensure_merchant_credit_schema() المُستدعاة داخل ensure_schema() في
-- admin/includes/functions.php)، فلا حاجة لتنفيذه يدوياً في الوضع الطبيعي.
-- هذا الملف موجود فقط للتوثيق والمراجعة اليدوية.
--
-- ملخص التغييرات:
--   [جدول جديد]   merchant_credit_overrides  - نسبة ائتمان خاصة لتاجر معيّن
--                                                (تجاوز اختياري للنسبة العامة)
--   [تعديل جدول]  sale_invoices              - عمود جديد: invoice_type
--                                                ENUM('sale','gift','gift_cancelled') DEFAULT 'sale'
--                                                (القيمة 'gift_cancelled' تُستخدم عند إلغاء هدية
--                                                 صادرة بالخطأ، وتُستبعد تلقائياً من كل الإحصائيات)
--   [تعديل جدول]  store_orders               - عمودان جديدان:
--                                                paid_amount DECIMAL(14,2) DEFAULT 0
--                                                order_type ENUM('sale','gift') DEFAULT 'sale'
--   [بيانات جديدة] store_settings (بدون تعديل هيكل، صفوف إعدادات جديدة فقط):
--                                                credit_limit_percentage  (نسبة الائتمان العامة %)
--                                                gift_header_text         (نص أعلى فاتورة الهدية)
--                                                gift_stamp_text          (نص ختم الهدية المجانية)
--                                                credit_alert_threshold   (نسبة % لتنبيه اقتراب حد الائتمان)
--                                                badge_silver_threshold   (حد الاستفادة لشارة "تاجر فضي")
--                                                badge_gold_threshold     (حد الاستفادة لشارة "تاجر ذهبي VIP")
--                                                credit_period_mode       (مدة حساب الإيراد المحقق: all_time/
--                                                                          last_1_month/last_2_months/
--                                                                          last_3_months/custom)
--                                                credit_period_from       (بداية الفترة المخصصة - وضع custom فقط)
--                                                credit_period_to         (نهاية الفترة المخصصة - وضع custom فقط)
-- ============================================================

USE grocery_system;

-- [جدول جديد] merchant_credit_overrides
CREATE TABLE IF NOT EXISTS merchant_credit_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_customer_id INT NOT NULL,
    credit_percentage DECIMAL(6,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_merchant_override (store_customer_id)
) ENGINE=InnoDB;

-- [تعديل جدول] sale_invoices: تمييز فواتير الهدايا المجانية عن فواتير البيع الحقيقية
--                              (والقيمة الثالثة gift_cancelled لهدية أُلغيت واسترجع مخزونها)
ALTER TABLE sale_invoices
    ADD COLUMN IF NOT EXISTS invoice_type ENUM('sale','gift','gift_cancelled') NOT NULL DEFAULT 'sale' AFTER discount;

-- [تعديل جدول] store_orders: تسجيل المبلغ المدفوع فوراً عند الطلب، ونوع الطلب
ALTER TABLE store_orders
    ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total,
    ADD COLUMN IF NOT EXISTS order_type ENUM('sale','gift') NOT NULL DEFAULT 'sale' AFTER status;

-- إعدادات افتراضية جديدة بجدول store_settings الموجود بالفعل (بدون تعديل هيكله)
INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES
    ('credit_limit_percentage', '20'),
    ('gift_header_text', 'هدية مجانية مقدمة من'),
    ('gift_stamp_text', 'هدية مجانية'),
    ('credit_alert_threshold', '85'),
    ('badge_silver_threshold', '2000'),
    ('badge_gold_threshold', '8000'),
    ('credit_period_mode', 'all_time'),
    ('credit_period_from', ''),
    ('credit_period_to', '');

-- ============================================================
-- ملاحظات حساب رصيد الائتمان (لا تُخزَّن كأعمدة، تُحسب لحظياً):
--   الإيراد المحقق من التاجر = SUM((sale_items.price - sale_items.cost_price) * sale_items.quantity)
--     لكل بنود فواتير sale_invoices.invoice_type = 'sale' المرتبطة بحساب هذا التاجر
--     (عبر store_customers.customer_id = sale_invoices.customer_id)
--   سقف الائتمان = الإيراد المحقق × (نسبة التاجر الخاصة إن وُجدت بجدول merchant_credit_overrides،
--                    وإلا النسبة العامة credit_limit_percentage بجدول store_settings) / 100
--   المطلوب سداده من التاجر  = customers.balance إن كانت موجبة
--   المستحق للتاجر لدى المنصة = customers.balance إن كانت سالبة (رصيد دائن)
--   المتاح فعلياً للطلب الآن  = سقف الائتمان - المطلوب سداده (لا يقل عن صفر)
-- ============================================================
