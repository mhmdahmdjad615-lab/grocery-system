-- ============================================================
-- قاعدة بيانات: نظام حسابات ومخازن لمحل مواد غذائية وبقالة جملة
-- ============================================================

CREATE DATABASE IF NOT EXISTS grocery_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grocery_system;

-- جدول المستخدمين
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- جدول الأصناف (تصنيفات المنتجات)
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- جدول المنتجات (بيانات ثابتة فقط - بدون سعر أو كمية، فهذه تُدار على مستوى الدفعات)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category_id INT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'قطعة',
    min_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    barcode VARCHAR(80) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- دفعات المخزون (جوهر النظام): كل فاتورة شراء تنشئ دفعة في "المخزن المؤقت"
-- بسعر شراء فقط بدون سعر بيع. عند تحديد سعر بيع لكمية (كل الدفعة أو جزء منها)
-- تُنقل هذه الكمية كدفعة جديدة إلى "المخزن الذي يُباع منه". كل دفعة تحتفظ بسعر
-- الشراء والبيع الخاصين بها بشكل منفصل، فيظهر نفس المنتج بعدة صفوف مختلفة
-- الأسعار إن وُجدت أكثر من دفعة له في نفس الوقت.
CREATE TABLE IF NOT EXISTS stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    location ENUM('temp','selling') NOT NULL DEFAULT 'temp',
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NULL,
    purchase_invoice_id INT NULL,
    purchase_date DATE NULL,
    expiry_date DATE NULL,
    source_batch_id INT NULL,
    is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    moved_at DATETIME NULL,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- جدول الموردين
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address VARCHAR(255),
    balance DECIMAL(14,2) NOT NULL DEFAULT 0, -- المبلغ المستحق له علينا
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- جدول العملاء
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address VARCHAR(255),
    balance DECIMAL(14,2) NOT NULL DEFAULT 0, -- المبلغ المستحق لنا عليه
    credit_limit DECIMAL(14,2) NULL, -- الحد الأقصى المسموح به للدين (NULL = بدون حد)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- فواتير المشتريات
CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- بنود فواتير المشتريات
CREATE TABLE IF NOT EXISTS purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL, -- بالوحدة الأساسية دائماً
    price DECIMAL(12,2) NOT NULL,    -- سعر الوحدة الأساسية دائماً
    unit_name VARCHAR(50) NULL,      -- الوحدة التي اختارها المستخدم فعلياً وقت الشراء (كرتونة مثلاً)
    display_qty DECIMAL(14,4) NULL,  -- الكمية بتلك الوحدة المختارة (للعرض بالفاتورة)
    total DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- فواتير المبيعات
CREATE TABLE IF NOT EXISTS sale_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0, -- خصم اختياري على مستوى الفاتورة
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- بنود فواتير المبيعات
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL, -- بالوحدة الأساسية دائماً
    price DECIMAL(12,2) NOT NULL,    -- سعر الوحدة الأساسية دائماً
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0, -- تكلفة الوحدة الأساسية وقت البيع
    unit_name VARCHAR(50) NULL,      -- الوحدة التي اختارها المستخدم فعلياً وقت البيع
    display_qty DECIMAL(14,4) NULL,  -- الكمية بتلك الوحدة المختارة (للعرض بالفاتورة)
    total DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES sale_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- وحدات القياس المتعددة لكل منتج (طن/كرتونة/لفة/كيس... بسلسلة تحويل متتالية)
-- كل وحدة (عدا الأساسية) "تحتوي على" عدداً من وحدة أصغر محدَّدة (wraps_unit_id + qty_of_wrapped)
-- ويُحسب factor (المعامل الكلي للوحدة الأساسية) تلقائياً بضرب السلسلة كاملة
CREATE TABLE IF NOT EXISTS product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_name VARCHAR(50) NOT NULL,
    print_name VARCHAR(50) NULL, -- الاسم الظاهر بالفواتير والطباعة (افتراضياً = unit_name)
    factor DECIMAL(18,4) NOT NULL DEFAULT 1, -- عدد الوحدات الأساسية في الوحدة الواحدة (محسوب تلقائياً)
    wraps_unit_id INT NULL, -- الوحدة الأصغر المباشرة التي تتكوّن منها هذه الوحدة (NULL للأساسية)
    qty_of_wrapped DECIMAL(14,4) NULL, -- كم عدد من "الوحدة الأصغر المباشرة" داخل هذه الوحدة
    net_weight DECIMAL(12,4) NULL, -- الوزن الصافي لوحدة واحدة (اختياري)
    allow_purchase TINYINT(1) NOT NULL DEFAULT 1, -- هل يمكن الشراء بهذه الوحدة
    allow_sell TINYINT(1) NOT NULL DEFAULT 1,     -- هل يمكن البيع بهذه الوحدة
    allow_count TINYINT(1) NOT NULL DEFAULT 1,    -- هل تُستخدم بالجرد والعرض المركّب للكمية
    price_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto', -- تسعير تلقائي من الوحدة الأساسية أم سعر مستقل
    manual_price DECIMAL(12,2) NULL, -- السعر المستقل إن كان price_mode = manual
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- مستويات أسعار بيع متعددة لكل منتج (جملة / قطاعي / سعر خاص...)
CREATE TABLE IF NOT EXISTS product_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_name VARCHAR(60) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- سجل تاريخي لتغييرات سعر البيع الافتراضي للمنتج
CREATE TABLE IF NOT EXISTS product_price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    old_price DECIMAL(12,2) NOT NULL,
    new_price DECIMAL(12,2) NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- النفقات والإيرادات العامة (خارج فواتير المبيعات والمشتريات)
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('expense','income') NOT NULL DEFAULT 'expense',
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    expense_date DATE NOT NULL,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- حركة المخزون (تسجيل تلقائي لكل دخول/خروج/تسوية)
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('in','out','adjust') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(30), -- purchase / sale / adjust
    reference_id INT NULL,
    notes VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- المدفوعات / المقبوضات (سداد ديون العملاء والموردين، وكذلك الاسترداد النقدي للمرتجعات)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('receipt','payment','refund_customer','refund_supplier') NOT NULL,
    -- receipt = قبض من عميل, payment = دفع لمورد
    -- refund_customer = استرداد نقدي للعميل (مرتجع), refund_supplier = استرداد نقدي من المورد (مرتجع)
    party_type ENUM('customer','supplier') NOT NULL,
    party_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATE NOT NULL,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- حركة الخزينة (كل ما يدخل ويخرج من كاش فعلي)
CREATE TABLE IF NOT EXISTS cash_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('in','out') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    source VARCHAR(30) NOT NULL, -- sale / purchase / receipt / payment / expense
    reference_id INT NULL,
    movement_date DATE NOT NULL,
    notes VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- مرتجعات المبيعات (من العميل إلينا)
CREATE TABLE IF NOT EXISTS sale_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(30) NOT NULL UNIQUE,
    sale_invoice_id INT NOT NULL,
    customer_id INT NOT NULL,
    return_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_invoice_id) REFERENCES sale_invoices(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL,
    restocked TINYINT(1) NOT NULL DEFAULT 1, -- هل أُعيدت للمخزون أم بضاعة تالفة (لا تُعاد)
    FOREIGN KEY (return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- مرتجعات المشتريات (منا إلى المورد)
CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(30) NOT NULL UNIQUE,
    purchase_invoice_id INT NOT NULL,
    supplier_id INT NOT NULL,
    return_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255),
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- بيانات أولية اختيارية للأصناف
INSERT INTO categories (name) VALUES ('مواد غذائية'), ('منظفات'), ('مشروبات'), ('أخرى');
