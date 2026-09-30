<?php
// اسم العملة المستخدمة في النظام - عدّلها حسب بلدك
define('CURRENCY', 'ج.م');

function money($num) {
    return number_format((float)$num, 2) . ' ' . CURRENCY;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// توليد رقم فاتورة تسلسلي مثل PUR-000001 أو SAL-000001
function generate_invoice_number($pdo, $table, $prefix) {
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM {$table}");
    $row = $stmt->fetch();
    $next = ($row['max_id'] ?? 0) + 1;
    return $prefix . '-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

// تسجيل حركة مخزون
function log_stock_movement($pdo, $product_id, $type, $qty, $ref_type, $ref_id, $notes = null) {
    $stmt = $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reference_type, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$product_id, $type, $qty, $ref_type, $ref_id, $notes]);
}

// تسجيل حركة خزينة (كاش)
function log_cash_movement($pdo, $type, $amount, $source, $ref_id, $date, $notes = null) {
    if ($amount <= 0) return;
    $stmt = $pdo->prepare("INSERT INTO cash_movements (type, amount, source, reference_id, movement_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$type, $amount, $source, $ref_id, $date, $notes]);
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

// ============================================================
// ترحيل تلقائي لقاعدة البيانات (يضيف الجداول/الأعمدة الجديدة بأمان
// دون التأثير على البيانات الحالية، يُستدعى في كل تحميل صفحة)
// ============================================================
function ensure_schema($pdo) {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasStockBatches = in_array('stock_batches', $tables);

    // جدول مستويات أسعار البيع المقترحة لكل منتج (جملة/قطاعي/خاص...)
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        price_name VARCHAR(60) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // سجل تاريخي بكل سعر بيع يُحدَّد لأي دفعة عبر الزمن
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_price_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        old_price DECIMAL(12,2) NOT NULL,
        new_price DECIMAL(12,2) NOT NULL,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        user_id INT NULL,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // النفقات والإيرادات العامة (إيجار، رواتب، فواتير... وأي إيراد خارج المبيعات)
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('expense','income') NOT NULL DEFAULT 'expense',
        category VARCHAR(100) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        expense_date DATE NOT NULL,
        notes VARCHAR(255),
        user_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // دفعات المخزون: المخزن المؤقت (بسعر شراء فقط) ومخزن البيع (بسعر شراء وبيع)
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        location ENUM('temp','selling') NOT NULL DEFAULT 'temp',
        quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        sale_price DECIMAL(12,2) NULL,
        purchase_invoice_id INT NULL,
        purchase_date DATE NULL,
        source_batch_id INT NULL,
        is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        moved_at DATETIME NULL,
        FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB");

    // إضافة عمود تكلفة الوحدة وقت البيع في بنود فواتير المبيعات (لحساب ربح دقيق)
    $col = $pdo->query("SHOW COLUMNS FROM sale_items LIKE 'cost_price'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE sale_items ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price");
        $prodHasPurchasePrice = $pdo->query("SHOW COLUMNS FROM products LIKE 'purchase_price'")->fetch();
        if ($prodHasPurchasePrice) {
            $pdo->exec("UPDATE sale_items si JOIN products p ON p.id = si.product_id SET si.cost_price = p.purchase_price WHERE si.cost_price = 0");
        }
    }

    // ترحيل تلقائي لمرة واحدة فقط: أي منتج من النظام القديم كان له كمية وسعر شراء/بيع
    // مباشرة على جدول المنتجات، يُنقل رصيده كدفعة جاهزة في "مخزن البيع" حفاظاً على البيانات
    if (!$hasStockBatches) {
        $prodHasQty = $pdo->query("SHOW COLUMNS FROM products LIKE 'quantity'")->fetch();
        if ($prodHasQty) {
            $old = $pdo->query("SELECT id, quantity, purchase_price, sale_price FROM products WHERE quantity > 0")->fetchAll();
            foreach ($old as $op) {
                $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, sale_price, created_at) VALUES (?, 'selling', ?, ?, ?, NOW())")
                    ->execute([$op['id'], $op['quantity'], $op['purchase_price'], $op['sale_price']]);
            }
        }
    }

    // تاريخ الصلاحية لكل دفعة (اختياري - مهم للمواد الغذائية)
    $col = $pdo->query("SHOW COLUMNS FROM stock_batches LIKE 'expiry_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE stock_batches ADD COLUMN expiry_date DATE NULL AFTER purchase_date");
    }

    // حد الائتمان لكل عميل (NULL = بدون حد أقصى)
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'credit_limit'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(14,2) NULL AFTER balance");
    }

    // توسيع أنواع المدفوعات لدعم استرداد نقدي للعميل/من المورد (مرتبط بالمرتجعات)
    $col = $pdo->query("SHOW COLUMNS FROM payments LIKE 'type'")->fetch();
    if ($col && strpos($col['Type'], 'refund_customer') === false) {
        $pdo->exec("ALTER TABLE payments MODIFY COLUMN type ENUM('receipt','payment','refund_customer','refund_supplier') NOT NULL");
    }

    // مرتجعات المبيعات (من العميل إلينا)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_returns (
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
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL,
        restocked TINYINT(1) NOT NULL DEFAULT 1,
        FOREIGN KEY (return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB");

    // مرتجعات المشتريات (منا إلى المورد)
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_returns (
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
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        total DECIMAL(14,2) NOT NULL,
        FOREIGN KEY (return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB");

    // ============================================================
    // نظام تحويل الوحدات: كل منتج له "وحدة أساسية" (الأصغر، تُخزَّن بها
    // كل الكميات داخلياً) وقد يكون له وحدات تعبئة أكبر (كرتونة/طن/لفة...)
    // كل وحدة تُعرَّف بأنها "تحتوي على" عدداً من وحدة أصغر محدَّدة (wraps_unit_id)
    // ويُحسب معامل التحويل الكلي للوحدة الأساسية (factor) تلقائياً بضرب السلسلة
    // ============================================================
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        factor DECIMAL(18,4) NOT NULL DEFAULT 1, -- عدد الوحدات الأساسية في الوحدة الواحدة (محسوب تلقائياً)
        is_base TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // ترحيل: إنشاء صف "الوحدة الأساسية" تلقائياً لكل منتج ليس له وحدات مُعرَّفة بعد
    $productsWithoutUnits = $pdo->query("
        SELECT p.id, p.unit FROM products p
        WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id)
    ")->fetchAll();
    foreach ($productsWithoutUnits as $p) {
        $pdo->prepare("INSERT INTO product_units (product_id, unit_name, factor, is_base) VALUES (?, ?, 1, 1)")
            ->execute([$p['id'], $p['unit'] ?: 'قطعة']);
    }

    // أعمدة سلسلة التحويل والأعلام والتسعير التلقائي/اليدوي والوزن الصافي
    $col = $pdo->query("SHOW COLUMNS FROM product_units LIKE 'wraps_unit_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE product_units
            ADD COLUMN print_name VARCHAR(50) NULL AFTER unit_name,
            ADD COLUMN wraps_unit_id INT NULL AFTER factor,
            ADD COLUMN qty_of_wrapped DECIMAL(14,4) NULL AFTER wraps_unit_id,
            ADD COLUMN net_weight DECIMAL(12,4) NULL AFTER qty_of_wrapped,
            ADD COLUMN allow_purchase TINYINT(1) NOT NULL DEFAULT 1 AFTER net_weight,
            ADD COLUMN allow_sell TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_purchase,
            ADD COLUMN allow_count TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_sell,
            ADD COLUMN price_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER allow_count,
            ADD COLUMN manual_price DECIMAL(12,2) NULL AFTER price_mode");

        // ترحيل الوحدات القديمة (كانت تُدخَل بمعامل مباشر للوحدة الأساسية) لتصبح
        // "تحتوي على" الوحدة الأساسية مباشرة بنفس المعامل - تبقى صحيحة تماماً دون فقد بيانات
        $pdo->exec("
            UPDATE product_units pu
            JOIN product_units base ON base.product_id = pu.product_id AND base.is_base = 1
            SET pu.wraps_unit_id = base.id, pu.qty_of_wrapped = pu.factor
            WHERE pu.is_base = 0 AND pu.wraps_unit_id IS NULL
        ");
        $pdo->exec("UPDATE product_units SET print_name = unit_name WHERE print_name IS NULL");
    }

    // إضافة أعمدة عرض الوحدة المُستخدمة وقت الشراء/البيع (للفوترة الواضحة) مع
    // بقاء أعمدة quantity/price بالوحدة الأساسية دائماً للحسابات الداخلية
    foreach (['purchase_items', 'sale_items'] as $tbl) {
        $col = $pdo->query("SHOW COLUMNS FROM {$tbl} LIKE 'unit_name'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN unit_name VARCHAR(50) NULL, ADD COLUMN display_qty DECIMAL(14,4) NULL");
        }
    }

    // خصم اختياري على مستوى فاتورة البيع (نسبة مئوية أو مبلغ ثابت) - لدعم العروض البسيطة
    $col = $pdo->query("SHOW COLUMNS FROM sale_invoices LIKE 'discount'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE sale_invoices ADD COLUMN discount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total");
    }

    // ============================================================
    // منصة تجارة الجملة الإلكترونية (Wholesale B2B Store) - جداول جديدة
    // ============================================================
    ensure_store_schema($pdo);

    // ============================================================
    // [إضافة جديدة] معرض صور المنتج المتعدد + تفاصيل العبوة/الوزن + بانرات
    // المتجر + سعر ما قبل الخصم — راجع تفاصيل كل جدول/عمود داخل الدالة
    // ============================================================
    ensure_product_extras_schema($pdo);

    // ============================================================
    // [إضافة جديدة] سجل تتبّع حالة الطلب (من قام بالتحديث/ملاحظات/مرفقات)
    // ============================================================
    ensure_order_status_log_schema($pdo);
}

// ============================================================
// جداول ومنطق منصة البيع بالجملة إلكترونياً (محلات التجزئة تشتري من الموقع)
// جميع الجداول الجديدة موثّقة هنا بوضوح لسهولة المراجعة عند الترقية
// ============================================================
function ensure_store_schema($pdo) {
    // --- جدول جديد: store_customers (حسابات محلات التجزئة المسجّلة بالمتجر) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_customers (
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
        customer_id INT NULL, -- يُربط تلقائياً بسجل عميل في جدول customers الحالي عند أول اعتماد/طلب
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // --- جدول جديد: store_settings (إعدادات عامة للمتجر: واتساب، تليجرام، اسم الموقع...) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL
    ) ENGINE=InnoDB");

    // --- جدول جديد: store_orders (طلبات الشراء المُنشأة من المتجر الإلكتروني عبر السلة) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(30) NOT NULL UNIQUE,
        store_customer_id INT NOT NULL,
        status ENUM('pending','confirmed','preparing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        cancelled_reason VARCHAR(255) NULL,
        sale_invoice_id INT NULL, -- يُملأ عند اعتماد المدير للطلب وتحويله لفاتورة بيع حقيقية بالحسابات
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        confirmed_at DATETIME NULL,
        FOREIGN KEY (store_customer_id) REFERENCES store_customers(id),
        FOREIGN KEY (sale_invoice_id) REFERENCES sale_invoices(id)
    ) ENGINE=InnoDB");

    // --- جدول جديد: store_order_items (بنود كل طلب من طلبات المتجر) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_order_items (
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
    ) ENGINE=InnoDB");

    // --- جدول جديد: manual_order_requests (طلبات مُرسَلة عبر واتساب/تليجرام - مسجّلين أو زوّار) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS manual_order_requests (
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
    ) ENGINE=InnoDB");

    // --- تعديل على جدول موجود: products (إضافة أعمدة ظهور المنتج بالمتجر الإلكتروني) ---
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'show_in_store'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products
            ADD COLUMN show_in_store TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode,
            ADD COLUMN store_price DECIMAL(12,2) NULL AFTER show_in_store,
            ADD COLUMN store_min_qty DECIMAL(12,2) NULL AFTER store_price,
            ADD COLUMN store_description TEXT NULL AFTER store_min_qty,
            ADD COLUMN store_image VARCHAR(255) NULL AFTER store_description");
    }

    // القيم الافتراضية لإعدادات المتجر إن لم تكن موجودة بعد
    $defaults = [
        'site_name'        => 'منصة البيع بالجملة',
        'whatsapp_number'  => '',
        'telegram_username'=> '',
        'min_order_amount' => '0',
        'banner_text'      => 'اطلب احتياجات محلك بالجملة أونلاين بأسعار تنافسية',
        'contact_phone'    => '',
        'registration_note'=> 'سيتم مراجعة طلب تسجيلكم من الإدارة قبل تفعيل الحساب',
    ];
    $existing = $pdo->query("SELECT setting_key FROM store_settings")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($defaults as $k => $v) {
        if (!in_array($k, $existing)) {
            $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (?,?)")->execute([$k, $v]);
        }
    }
}

// ============================================================
// [إضافة جديدة] معرض صور متعدد للمنتج (حتى 4 صور) + تفاصيل العبوة/الوزن
// + سعر ما قبل الخصم (لعرض عروض المتجر) + بانرات الصفحة الرئيسية
// ------------------------------------------------------------
// [جدول جديد]  product_images  — صور إضافية للمنتج (حتى 4 لكل منتج)
// [جدول جديد]  store_banners   — بانرات إعلانية تُدار من admin/store_banners.php
// [جدول مُعدَّل] products:
//    - store_package_info   TEXT NULL          تفاصيل العبوة (نص حر)
//    - store_net_weight     DECIMAL(10,3) NULL الوزن الصافي
//    - store_weight_unit    VARCHAR(20) NULL   وحدة الوزن (كجم/جرام...)
//    - store_compare_price  DECIMAL(12,2) NULL السعر قبل الخصم (لعرض شارة الخصم بالمتجر)
// ============================================================
function ensure_product_extras_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS store_banners (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NULL,
        image_path VARCHAR(255) NOT NULL,
        link_url VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'store_package_info'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products
            ADD COLUMN store_package_info TEXT NULL AFTER store_image,
            ADD COLUMN store_net_weight DECIMAL(10,3) NULL AFTER store_package_info,
            ADD COLUMN store_weight_unit VARCHAR(20) NULL DEFAULT 'كجم' AFTER store_net_weight,
            ADD COLUMN store_compare_price DECIMAL(12,2) NULL AFTER store_weight_unit");
    }
}

// أقصى عدد صور مسموح به لكل منتج بمعرض المتجر
define('PRODUCT_MAX_IMAGES', 4);

// مسار مجلد رفع صور المنتجات على القرص، ورابطه النسبي من كل من admin/ و store/
function product_images_upload_dir() {
    return __DIR__ . '/../../uploads/products/';
}

// جلب كل صور منتج معيّن مرتبة (الأقدم/الأساسية أولاً)
function get_product_images($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll();
}

// الصورة الأساسية المعروضة بالبطاقات: أول صورة بالمعرض الجديد، وإلا عمود store_image القديم للتوافق
function get_product_primary_image($pdo, $product) {
    if (is_int($product)) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product]);
        $product = $stmt->fetch();
    }
    if (!$product) return null;
    $images = get_product_images($pdo, $product['id']);
    if (!empty($images)) return '../uploads/products/' . $images[0]['image_path'];
    return $product['store_image'] ?: null;
}

// رفع صورة واحدة وربطها بمنتج (يُستدعى لكل ملف من مصفوفة $_FILES['images'])
// يرمي Exception عند تجاوز الحد الأقصى أو نوع ملف غير مسموح
function save_uploaded_product_image($pdo, $product_id, $tmpPath, $originalName, $errorCode) {
    if ($errorCode !== UPLOAD_ERR_OK) return null; // تجاهل خانات الرفع الفارغة بصمت

    $current = $pdo->prepare("SELECT COUNT(*) c FROM product_images WHERE product_id = ?");
    $current->execute([$product_id]);
    if ((int)$current->fetch()['c'] >= PRODUCT_MAX_IMAGES) {
        throw new Exception('لا يمكن إضافة أكثر من ' . PRODUCT_MAX_IMAGES . ' صور لكل منتج. احذف صورة قديمة أولاً.');
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        throw new Exception('صيغة الصورة غير مدعومة (المسموح: JPG, PNG, WEBP فقط)');
    }
    if (filesize($tmpPath) > 4 * 1024 * 1024) {
        throw new Exception('حجم الصورة أكبر من 4 ميجابايت');
    }

    $dir = product_images_upload_dir();
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }

    $filename = 'p' . $product_id . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $dir . $filename)) {
        throw new Exception('تعذّر حفظ الصورة على السيرفر — تأكد من صلاحيات مجلد uploads/products');
    }

    $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1) m FROM product_images WHERE product_id = ?");
    $maxOrder->execute([$product_id]);
    $nextOrder = (int)$maxOrder->fetch()['m'] + 1;

    $pdo->prepare("INSERT INTO product_images (product_id, image_path, sort_order) VALUES (?,?,?)")
        ->execute([$product_id, $filename, $nextOrder]);
    return $filename;
}

// حذف صورة منتج (من القرص وقاعدة البيانات معاً)
function delete_product_image($pdo, $image_id, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = ? AND product_id = ?");
    $stmt->execute([$image_id, $product_id]);
    $img = $stmt->fetch();
    if (!$img) return;
    $path = product_images_upload_dir() . $img['image_path'];
    if (is_file($path)) { @unlink($path); }
    $pdo->prepare("DELETE FROM product_images WHERE id = ?")->execute([$image_id]);
}

// البانرات الإعلانية النشطة للصفحة الرئيسية بالمتجر (مرتبة حسب sort_order)
function get_store_active_banners($pdo) {
    return $pdo->query("SELECT * FROM store_banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll();
}

// الأكثر طلباً بالمتجر الإلكتروني خلال آخر $days يوماً (بحسب إجمالي الكمية بطلبات المتجر غير الملغاة)
function get_best_selling_store_products($pdo, $limit = 8, $days = 90) {
    $stmt = $pdo->prepare("
        SELECT p.*, SUM(oi.quantity) total_qty
        FROM store_order_items oi
        JOIN store_orders o ON o.id = oi.order_id AND o.status != 'cancelled' AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        JOIN products p ON p.id = oi.product_id AND p.show_in_store = 1
        GROUP BY p.id
        ORDER BY total_qty DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// أحدث المنتجات المضافة للمتجر
function get_newest_store_products($pdo, $limit = 8) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE show_in_store = 1 ORDER BY created_at DESC LIMIT " . (int)$limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

// المنتجات التي عليها عرض/خصم حالياً (لها سعر مقارنة أعلى من السعر الحالي)
function get_discounted_store_products($pdo, $limit = 8) {
    $stmt = $pdo->prepare("
        SELECT * FROM products
        WHERE show_in_store = 1 AND store_compare_price IS NOT NULL AND store_price IS NOT NULL
              AND store_compare_price > store_price
        ORDER BY (store_compare_price - store_price) / store_compare_price DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

// توصيات مخصّصة لعميل متجر معيّن بناءً على منتجات طلباته السابقة (الأكثر تكراراً أولاً)
function get_personalized_recommendations($pdo, $store_customer_id, $exclude_product_id = null, $limit = 6) {
    if (!$store_customer_id) return [];
    $sql = "
        SELECT p.*, COUNT(*) times_ordered
        FROM store_order_items oi
        JOIN store_orders o ON o.id = oi.order_id AND o.store_customer_id = ?
        JOIN products p ON p.id = oi.product_id AND p.show_in_store = 1
        WHERE 1=1 " . ($exclude_product_id ? " AND p.id != " . (int)$exclude_product_id : "") . "
        GROUP BY p.id
        ORDER BY times_ordered DESC, o.created_at DESC
        LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_customer_id]);
    return $stmt->fetchAll();
}

// ============================================================
// دوال مساعدة لمنصة البيع بالجملة الإلكترونية
// ============================================================

function get_store_setting($pdo, $key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = $pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll();
        foreach ($rows as $r) { $cache[$r['setting_key']] = $r['setting_value']; }
    }
    return $cache[$key] ?? $default;
}

function set_store_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

// توليد رقم طلب متجر تسلسلي مثل STO-000001
function generate_store_order_number($pdo) {
    $row = $pdo->query("SELECT MAX(id) as max_id FROM store_orders")->fetch();
    $next = ($row['max_id'] ?? 0) + 1;
    return 'STO-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

// تحويل طلب متجر (سلة) إلى فاتورة بيع حقيقية داخل نظام الحسابات:
// - ينشئ عميلاً بجدول customers لأول مرة لو لم يكن مربوطاً بعد بمحل التجزئة
// - يسحب المخزون بنظام FIFO مثل فاتورة البيع اليدوية تماماً (نفس دالة consume_selling_stock_fifo)
// - يربط الطلب بفاتورة البيع الناتجة عبر sale_invoice_id
function confirm_store_order($pdo, $order_id) {
    $stmt = $pdo->prepare("SELECT * FROM store_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) throw new Exception('الطلب غير موجود');
    if ($order['status'] !== 'pending') throw new Exception('هذا الطلب تم اعتماده أو إلغاؤه بالفعل');

    $scStmt = $pdo->prepare("SELECT * FROM store_customers WHERE id = ?");
    $scStmt->execute([$order['store_customer_id']]);
    $sc = $scStmt->fetch();
    if (!$sc) throw new Exception('حساب محل التجزئة غير موجود');

    // ربط/إنشاء عميل بجدول العملاء الرئيسي (يظهر بكل التقارير وكشوف الحسابات المحاسبية العادية)
    $customer_id = $sc['customer_id'];
    if (!$customer_id) {
        $pdo->prepare("INSERT INTO customers (name, phone, address) VALUES (?,?,?)")
            ->execute([$sc['shop_name'], $sc['phone'], trim(($sc['city'] ?? '') . ' - ' . ($sc['address'] ?? ''))]);
        $customer_id = $pdo->lastInsertId();
        $pdo->prepare("UPDATE store_customers SET customer_id = ? WHERE id = ?")->execute([$customer_id, $sc['id']]);
    }

    $itemsStmt = $pdo->prepare("SELECT * FROM store_order_items WHERE order_id = ?");
    $itemsStmt->execute([$order_id]);
    $orderItems = $itemsStmt->fetchAll();
    if (empty($orderItems)) throw new Exception('لا توجد أصناف بهذا الطلب');

    $subtotal = 0;
    $saleItems = [];
    foreach ($orderItems as $it) {
        $unit = $it['unit_id'] ? get_unit_by_id($pdo, $it['product_id'], $it['unit_id']) : get_base_unit($pdo, $it['product_id']);
        $factor = (float)$unit['factor'];
        $qty_base = convert_to_base_qty((float)$it['quantity'], $factor);
        $price_base = convert_price_to_base((float)$it['price'], $factor);

        // سحب من مخزن البيع بنظام FIFO تماماً كما تفعل فاتورة البيع اليدوية
        $cost_price_base = consume_selling_stock_fifo($pdo, $it['product_id'], $qty_base);

        $lineTotal = (float)$it['quantity'] * (float)$it['price'];
        $subtotal += $lineTotal;
        $saleItems[] = [
            'product_id' => $it['product_id'], 'qty' => $qty_base, 'price' => $price_base,
            'cost' => $cost_price_base, 'total' => $lineTotal,
            'unit_name' => $unit['unit_name'], 'display_qty' => $it['quantity'],
        ];
    }

    $invoice_number = generate_invoice_number($pdo, 'sale_invoices', 'SAL');
    $pdo->prepare("INSERT INTO sale_invoices (invoice_number, customer_id, invoice_date, total, discount, paid, notes, user_id) VALUES (?,?,CURDATE(),?,0,0,?,?)")
        ->execute([$invoice_number, $customer_id, $subtotal, 'طلب من المتجر الإلكتروني رقم ' . $order['order_number'], current_user_id()]);
    $invoice_id = $pdo->lastInsertId();

    foreach ($saleItems as $it) {
        $pdo->prepare("INSERT INTO sale_items (invoice_id, product_id, quantity, price, cost_price, total, unit_name, display_qty) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$invoice_id, $it['product_id'], $it['qty'], $it['price'], $it['cost'], $it['total'], $it['unit_name'], $it['display_qty']]);
        log_stock_movement($pdo, $it['product_id'], 'out', $it['qty'], 'store_order', $order_id, 'طلب متجر إلكتروني ' . $order['order_number'] . ' - فاتورة ' . $invoice_number);
    }

    // الطلب آجل بالكامل افتراضياً (يُسدَّد لاحقاً كباقي عملاء الجملة عبر صفحة المدفوعات)
    $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$subtotal, $customer_id]);

    $pdo->prepare("UPDATE store_orders SET status='confirmed', sale_invoice_id=?, confirmed_at=NOW() WHERE id=?")
        ->execute([$invoice_id, $order_id]);

    // [جديد] تسجيل خطوة "تم الاعتماد" بسجل حالة الطلب مع هوية المدير الذي اعتمد
    log_order_status_change($pdo, $order_id, 'confirmed', 'admin', current_user_id(), current_admin_name($pdo), 'dashboard', null);

    return $invoice_id;
}

// تسجيل تغيّر/تحديد سعر بيع دفعة في سجل التاريخ (فقط إذا تغيّر فعلياً)
function log_price_history($pdo, $product_id, $old_price, $new_price) {
    if ((float)$old_price == (float)$new_price) return;
    $stmt = $pdo->prepare("INSERT INTO product_price_history (product_id, old_price, new_price, user_id) VALUES (?,?,?,?)");
    $stmt->execute([$product_id, $old_price, $new_price, current_user_id()]);
}

// جلب مستويات الأسعار المقترحة لمنتج معيّن
function get_product_price_tiers($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_prices WHERE product_id = ? ORDER BY id");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll();
}

// جلب آخر سعر بيع تم تحديده لهذا المنتج (لعرضه كاقتراح ولتسجيله في سجل التغيّر)
function get_last_sale_price($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT new_price FROM product_price_history WHERE product_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    return $row ? (float)$row['new_price'] : 0;
}

// ============================================================
// نظام تحويل الوحدات (كرتونة ↔ طن ↔ لفة ↔ قطعة ...)
// كل وحدة (عدا الأساسية) "تحتوي على" عدداً من وحدة أصغر محدَّدة (wraps_unit_id)،
// ويُحسب معامل التحويل للوحدة الأساسية (factor) تلقائياً بضرب السلسلة كاملة.
// ============================================================

// جلب كل وحدات منتج معيّن (الأكبر أولاً، وهي الأنسب للعرض المركّب وواجهة الاختيار)
function get_product_units($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_units WHERE product_id = ? ORDER BY factor DESC");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll();
}

// جلب الوحدة الأساسية لمنتج (اسمها ومعاملها = 1 دائماً)
function get_base_unit($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_units WHERE product_id = ? AND is_base = 1 LIMIT 1");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    if ($row) return $row;
    // احتياطي: لو لم توجد وحدة أساسية لأي سبب، أنشئها من عمود products.unit
    $p = $pdo->prepare("SELECT unit FROM products WHERE id = ?");
    $p->execute([$product_id]);
    $unitName = $p->fetch()['unit'] ?? 'قطعة';
    $pdo->prepare("INSERT INTO product_units (product_id, unit_name, print_name, factor, is_base, allow_purchase, allow_sell, allow_count) VALUES (?,?,?,1,1,1,1,1)")
        ->execute([$product_id, $unitName, $unitName]);
    return get_base_unit($pdo, $product_id);
}

// إضافة وحدة تعبئة جديدة تحتوي على عدد معيّن من وحدة أصغر محدَّدة مسبقاً (سلسلة تحويل)
// مثال: إضافة "لفة" تحتوي على 10 من "كيس" الموجودة بالفعل
function add_product_unit_chain($pdo, $product_id, $unit_name, $wraps_unit_id, $qty_of_wrapped, $opts = []) {
    if ($qty_of_wrapped <= 0) throw new Exception('عدد الوحدات داخل الوحدة السابقة يجب أن يكون أكبر من صفر');
    $wrapped = $pdo->prepare("SELECT factor FROM product_units WHERE id = ? AND product_id = ?");
    $wrapped->execute([$wraps_unit_id, $product_id]);
    $w = $wrapped->fetch();
    if (!$w) throw new Exception('الوحدة الأصغر المرجعية غير موجودة');
    $factor_to_base = $qty_of_wrapped * (float)$w['factor'];

    $stmt = $pdo->prepare("INSERT INTO product_units
        (product_id, unit_name, print_name, wraps_unit_id, qty_of_wrapped, factor, net_weight, allow_purchase, allow_sell, allow_count, price_mode, manual_price, is_base)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)");
    $stmt->execute([
        $product_id, $unit_name, ($opts['print_name'] ?? '') ?: $unit_name, $wraps_unit_id, $qty_of_wrapped, $factor_to_base,
        $opts['net_weight'] ?? null,
        array_key_exists('allow_purchase', $opts) ? (int)!!$opts['allow_purchase'] : 1,
        array_key_exists('allow_sell', $opts) ? (int)!!$opts['allow_sell'] : 1,
        array_key_exists('allow_count', $opts) ? (int)!!$opts['allow_count'] : 1,
        $opts['price_mode'] ?? 'auto',
        $opts['manual_price'] ?? null,
    ]);
    return $pdo->lastInsertId();
}

// إعادة حساب معاملات التحويل لكل وحدات منتج (احتياطي بعد أي تعديل يدوي مباشر على القاعدة)
function recalc_product_unit_factors($pdo, $product_id) {
    $units = $pdo->prepare("SELECT * FROM product_units WHERE product_id = ?");
    $units->execute([$product_id]);
    $units = $units->fetchAll();
    $byId = [];
    foreach ($units as $u) { $byId[$u['id']] = $u; }

    $resolve = function ($id) use (&$resolve, &$byId, $pdo) {
        $u = $byId[$id];
        if ($u['is_base'] || !$u['wraps_unit_id']) return 1;
        $parentFactor = $resolve($u['wraps_unit_id']);
        $factor = (float)$u['qty_of_wrapped'] * $parentFactor;
        $pdo->prepare("UPDATE product_units SET factor = ? WHERE id = ?")->execute([$factor, $id]);
        $byId[$id]['factor'] = $factor;
        return $factor;
    };
    foreach ($units as $u) { $resolve($u['id']); }
}

// معامل تحويل وحدة معيّنة لمنتج (كم من الوحدة الأساسية تعادل وحدة واحدة منها)
function get_unit_factor($pdo, $product_id, $unit_id) {
    $stmt = $pdo->prepare("SELECT factor FROM product_units WHERE id = ? AND product_id = ?");
    $stmt->execute([$unit_id, $product_id]);
    $row = $stmt->fetch();
    return $row ? (float)$row['factor'] : 1;
}

// جلب بيانات وحدة كاملة بمعرفها (للاستخدام عند حفظ الفواتير: الاسم والمعامل معاً)
function get_unit_by_id($pdo, $product_id, $unit_id) {
    $stmt = $pdo->prepare("SELECT * FROM product_units WHERE id = ? AND product_id = ?");
    $stmt->execute([$unit_id, $product_id]);
    $row = $stmt->fetch();
    if ($row) return $row;
    return get_base_unit($pdo, $product_id);
}

// تحويل كمية من وحدة مختارة إلى الوحدة الأساسية
function convert_to_base_qty($qty, $factor) { return $qty * $factor; }
// تحويل سعر "لكل وحدة مختارة" إلى سعر "لكل وحدة أساسية"
function convert_price_to_base($price_per_unit, $factor) { return $factor > 0 ? $price_per_unit / $factor : $price_per_unit; }
// تحويل سعر "لكل وحدة أساسية" إلى سعر "لكل وحدة مختارة" (للعرض والاقتراح)
function convert_price_from_base($price_per_base, $factor) { return $price_per_base * $factor; }

// أحدث سعر بيع فعلي مسجَّل بمخزن البيع لهذا المنتج (لأصغر وحدة/الوحدة الأساسية)
// تُستخدم لتعبئة "سعر المتجر" تلقائياً بدل إدخاله يدوياً بشكل مكرر
function get_latest_selling_price($pdo, $product_id) {
    $stmt = $pdo->prepare("
        SELECT sale_price FROM stock_batches
        WHERE product_id = ? AND location = 'selling' AND sale_price IS NOT NULL AND quantity > 0
        ORDER BY created_at DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    if ($row) return (float)$row['sale_price'];

    // احتياطي: لو كل الدفعات نفدت كميتها، خذ آخر سعر بيع سُجِّل ولو كانت الكمية صفراً الآن
    $stmt = $pdo->prepare("
        SELECT sale_price FROM stock_batches
        WHERE product_id = ? AND location = 'selling' AND sale_price IS NOT NULL
        ORDER BY created_at DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    return $row ? (float)$row['sale_price'] : null;
}

// السعر التلقائي لوحدة معيّنة انطلاقاً من سعر الوحدة الأساسية، إلا إذا كانت هذه الوحدة
// مضبوطة على "تسعير يدوي مستقل" فيُستخدم السعر اليدوي المحفوظ لها بدلاً من الحساب التلقائي
function get_unit_price($base_price_per_unit, $unit) {
    if (($unit['price_mode'] ?? 'auto') === 'manual' && $unit['manual_price'] !== null) {
        return (float)$unit['manual_price'];
    }
    return $base_price_per_unit * (float)$unit['factor'];
}

// عرض كمية بالوحدة الأساسية بصيغة مركّبة قابلة للقراءة (مثال: "4 طن و25 لفة و8 أكياس")
// يعتمد فقط على الوحدات المسموح استخدامها بالجرد (allow_count) تفادياً لعرض وحدات غير منطقية
function format_qty_composite($pdo, $product_id, $qty_base) {
    $units = get_product_units($pdo, $product_id); // مرتبة تنازلياً حسب المعامل (الأكبر أولاً)
    $countable = array_values(array_filter($units, fn($u) => !empty($u['allow_count']) || $u['is_base']));
    if (empty($countable)) $countable = $units;

    $remaining = round((float)$qty_base, 6);
    $parts = [];
    foreach ($countable as $u) {
        $factor = (float)$u['factor'];
        if ($factor <= 0) continue;
        if ($u['is_base']) continue; // الوحدة الأساسية تُترك للباقي النهائي
        $count = floor(($remaining + 0.000001) / $factor);
        if ($count > 0) {
            $parts[] = rtrim(rtrim(number_format($count, 2), '0'), '.') . ' ' . $u['unit_name'];
            $remaining -= $count * $factor;
        }
    }
    $base = get_base_unit($pdo, $product_id);
    $remaining = round($remaining, 4);
    if ($remaining > 0 || empty($parts)) {
        $parts[] = rtrim(rtrim(number_format($remaining, 2), '0'), '.') . ' ' . $base['unit_name'];
    }
    return implode(' و', $parts);
}

// ============================================================
// إحصائيات لوحة التحكم (مُستخدمة في الصفحة نفسها وفي نقطة التحديث الحي AJAX)
// ============================================================
// ============================================================
// [مُهم] دوال محاسبية موحّدة ودقيقة 100% — تُستخدم بكل صفحات التقارير
// والأرباح لضمان رقم واحد صحيح لا يتناقض بين صفحة وأخرى.
// ------------------------------------------------------------
// المبدأ المحاسبي المعتمد (Accrual/COGS الصحيح):
//   - "المخزون" أصل (Asset) وليس إيراداً - لا يُضاف أبداً لأي حساب ربح.
//   - "المشتريات" إنفاق على بناء المخزون (أصل) وليست تكلفة مباشرة لحظة
//     الشراء - التكلفة الفعلية تُحتسب فقط لحظة البيع (COGS) عبر
//     sale_items.cost_price المُسجَّل وقت البيع بنظام FIFO الفعلي.
//   - "صافي المبيعات" = إجمالي فواتير البيع بالفترة ناقص مرتجعات
//     المبيعات بنفس الفترة (كانت المرتجعات غير مخصومة إطلاقاً سابقاً).
//   - "صافي تكلفة البضاعة المباعة" = تكلفة ما بيع فعلياً ناقص تكلفة أي
//     مرتجع أُعيد فعلياً للمخزون (restocked=1) فقط - لأن المرتجع التالف
//     غير المُعاد للمخزون تبقى تكلفته خسارة محقّقة ولا تُخصم.
// ============================================================

// صافي المبيعات خلال فترة (فواتير البيع ناقص مرتجعات المبيعات بنفس الفترة)
function get_net_sales_revenue($pdo, $from, $to) {
    $sales = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM sale_invoices WHERE invoice_date BETWEEN ? AND ?");
    $sales->execute([$from, $to]);
    $returns = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM sale_returns WHERE return_date BETWEEN ? AND ?");
    $returns->execute([$from, $to]);
    return (float)$sales->fetch()['t'] - (float)$returns->fetch()['t'];
}

// صافي تكلفة البضاعة المباعة خلال فترة (COGS الفعلي وقت البيع، ناقص تكلفة أي مرتجع أُعيد فعلياً للمخزون)
function get_net_cogs($pdo, $from, $to) {
    $cogs = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * si.cost_price),0) t
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ?
    ");
    $cogs->execute([$from, $to]);
    $returnedCost = $pdo->prepare("
        SELECT COALESCE(SUM(sri.quantity * sri.cost_price),0) t
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ? AND sri.restocked = 1
    ");
    $returnedCost->execute([$from, $to]);
    return (float)$cogs->fetch()['t'] - (float)$returnedCost->fetch()['t'];
}

// صافي المشتريات خلال فترة (فواتير الشراء ناقص مرتجعات المشتريات بنفس الفترة) - رقم إعلامي (تدفق نقدي/بناء مخزون)، وليس جزءاً من معادلة الربح
function get_net_purchases($pdo, $from, $to) {
    $purch = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_invoices WHERE invoice_date BETWEEN ? AND ?");
    $purch->execute([$from, $to]);
    $ret = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_returns WHERE return_date BETWEEN ? AND ?");
    $ret->execute([$from, $to]);
    return (float)$purch->fetch()['t'] - (float)$ret->fetch()['t'];
}

// الإيرادات والنفقات الأخرى (خارج المبيعات/المشتريات) خلال فترة
function get_other_income_expense($pdo, $from, $to) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) inc,
               COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) exp
        FROM expenses WHERE expense_date BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    return $stmt->fetch();
}

// التقرير المالي الدقيق الموحّد لفترة معيّنة (تُستخدم بكل صفحات الأرباح/الخسائر/التقارير)
function get_accurate_profit_report($pdo, $from, $to) {
    $revenue = get_net_sales_revenue($pdo, $from, $to);
    $cogs = get_net_cogs($pdo, $from, $to);
    $oi = get_other_income_expense($pdo, $from, $to);
    $gross_profit = $revenue - $cogs;
    $net_profit = $gross_profit + (float)$oi['inc'] - (float)$oi['exp'];
    return [
        'net_sales_revenue' => $revenue,
        'net_cogs' => $cogs,
        'gross_profit' => $gross_profit,
        'other_income' => (float)$oi['inc'],
        'other_expenses' => (float)$oi['exp'],
        'net_profit' => $net_profit,
        'net_purchases' => get_net_purchases($pdo, $from, $to),
    ];
}

function get_dashboard_stats($pdo) {
    $stats = [];

    $today = date('Y-m-d');
    // [مُصحَّح] صافي مبيعات اليوم = فواتير اليوم ناقص أي مرتجع بنفس اليوم
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM sale_invoices WHERE invoice_date = ?");
    $stmt->execute([$today]);
    $row = $stmt->fetch();
    $todayReturns = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM sale_returns WHERE return_date = ?");
    $todayReturns->execute([$today]);
    $stats['sales_today'] = (float)$row['t'] - (float)$todayReturns->fetch()['t'];
    $stats['sales_today_count'] = (int)$row['c'];

    // [مُصحَّح] صافي مشتريات اليوم = فواتير الشراء اليوم ناقص أي مرتجع شراء بنفس اليوم
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_invoices WHERE invoice_date = ?");
    $stmt->execute([$today]);
    $todayPurchReturns = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_returns WHERE return_date = ?");
    $todayPurchReturns->execute([$today]);
    $stats['purchases_today'] = (float)$stmt->fetch()['t'] - (float)$todayPurchReturns->fetch()['t'];

    $stats['low_stock'] = (int)$pdo->query("
        SELECT COUNT(*) c FROM (
            SELECT p.id, p.min_quantity, COALESCE(SUM(sb.quantity),0) total_qty
            FROM products p JOIN stock_batches sb ON sb.product_id = p.id AND sb.location = 'selling'
            GROUP BY p.id HAVING total_qty <= p.min_quantity
        ) t
    ")->fetch()['c'];

    $stats['products_count'] = (int)$pdo->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
    $stats['customers_count'] = (int)$pdo->query("SELECT COUNT(*) c FROM customers")->fetch()['c'];
    $stats['suppliers_count'] = (int)$pdo->query("SELECT COUNT(*) c FROM suppliers")->fetch()['c'];

    $cash_in  = $pdo->query("SELECT COALESCE(SUM(amount),0) t FROM cash_movements WHERE type='in'")->fetch()['t'];
    $cash_out = $pdo->query("SELECT COALESCE(SUM(amount),0) t FROM cash_movements WHERE type='out'")->fetch()['t'];
    $stats['cash_balance'] = (float)$cash_in - (float)$cash_out;

    $stats['total_customer_debt'] = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) t FROM customers")->fetch()['t'];
    $stats['total_supplier_debt'] = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) t FROM suppliers")->fetch()['t'];

    $month_start = date('Y-m-01');
    $today_date = date('Y-m-d');

    // [مُصحَّح بالكامل] صافي الربح الشهري = صافي المبيعات (ناقص مرتجعاتها) - صافي تكلفة
    // البضاعة المباعة فعلياً (COGS الحقيقي وقت البيع) + الإيرادات الأخرى - النفقات الأخرى.
    // لا تُستخدم "المشتريات" ولا "قيمة المخزون" في هذه المعادلة إطلاقاً لأنهما ليسا ربحاً
    // ولا خسارة بذاتهما (المخزون أصل، والمشتريات إنفاق لبناء ذلك الأصل).
    $monthlyReport = get_accurate_profit_report($pdo, $month_start, $today_date);
    $stats['net_profit_month'] = $monthlyReport['net_profit'];
    $stats['sales_month'] = $monthlyReport['net_sales_revenue'];
    $stats['purchases_month'] = get_net_purchases($pdo, $month_start, $today_date);

    // قيمة المخزون الحالي — تُعرض كرقم إعلامي (أصل) منفصل تماماً عن الربح
    $stats['inventory_value'] = (float)$pdo->query("SELECT COALESCE(SUM(quantity * purchase_price),0) v FROM stock_batches WHERE location='selling'")->fetch()['v'];

    $bestSupplier = $pdo->query("
        SELECT s.name, COALESCE(SUM(pi.total),0) total
        FROM suppliers s JOIN purchase_invoices pi ON pi.supplier_id = s.id
        WHERE pi.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        GROUP BY s.id ORDER BY total DESC LIMIT 1
    ")->fetch();
    $stats['best_supplier_name'] = $bestSupplier['name'] ?? null;

    // [مُصحَّح] أفضل منتج/عميل بالربح المحقق الفعلي، بعد خصم أي مرتجعات (كانت غير مخصومة سابقاً)
    $topProduct = $pdo->query("
        SELECT p.name,
               (COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0)) - (COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0)) profit
        FROM products p
        JOIN (SELECT product_id, SUM(total) revenue, SUM(quantity*cost_price) cost FROM sale_items GROUP BY product_id) sales ON sales.product_id = p.id
        LEFT JOIN (
            SELECT product_id, SUM(total) revenue, SUM(CASE WHEN restocked=1 THEN quantity*cost_price ELSE 0 END) restocked_cost
            FROM sale_return_items GROUP BY product_id
        ) ret ON ret.product_id = p.id
        ORDER BY profit DESC LIMIT 1
    ")->fetch();
    $stats['top_product_name'] = $topProduct['name'] ?? null;
    $stats['top_product_profit'] = $topProduct ? (float)$topProduct['profit'] : null;

    $topCustomer = $pdo->query("
        SELECT c.name,
               (COALESCE(sales.revenue,0) - COALESCE(ret.revenue,0)) - (COALESCE(sales.cost,0) - COALESCE(ret.restocked_cost,0)) profit
        FROM customers c
        JOIN (
            SELECT s.customer_id, SUM(si.total) revenue, SUM(si.quantity*si.cost_price) cost
            FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id GROUP BY s.customer_id
        ) sales ON sales.customer_id = c.id
        LEFT JOIN (
            SELECT sr.customer_id, SUM(sri.total) revenue, SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END) restocked_cost
            FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id GROUP BY sr.customer_id
        ) ret ON ret.customer_id = c.id
        ORDER BY profit DESC LIMIT 1
    ")->fetch();
    $stats['top_customer_name'] = $topCustomer['name'] ?? null;
    $stats['top_customer_profit'] = $topCustomer ? (float)$topCustomer['profit'] : null;

    $stats['dead_stock'] = (int)$pdo->query("
        SELECT COUNT(*) c FROM (
            SELECT p.id
            FROM products p
            JOIN (SELECT DISTINCT product_id FROM stock_batches WHERE location='selling' AND quantity > 0) sw ON sw.product_id = p.id
            LEFT JOIN (
                SELECT si.product_id, MAX(s.invoice_date) last_date
                FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
                GROUP BY si.product_id
            ) ls ON ls.product_id = p.id
            WHERE ls.last_date IS NULL OR ls.last_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ) t
    ")->fetch()['c'];

    $recent_sales = $pdo->query("SELECT s.id, s.invoice_number, s.invoice_date, s.total, c.name customer_name FROM sale_invoices s JOIN customers c ON c.id = s.customer_id ORDER BY s.id DESC LIMIT 5")->fetchAll();
    $stats['recent_sales'] = $recent_sales;

    $recent_purchases = $pdo->query("SELECT p.id, p.invoice_number, p.invoice_date, p.total, s.name supplier_name FROM purchase_invoices p JOIN suppliers s ON s.id = p.supplier_id ORDER BY p.id DESC LIMIT 5")->fetchAll();
    $stats['recent_purchases'] = $recent_purchases;

    return $stats;
}

// ============================================================
// إدارة دفعات المخزون: المخزن المؤقت (استلام) ↔ مخزن البيع (فعلي)
// ============================================================

// إجمالي الكمية المتاحة للبيع فعلياً لمنتج معيّن (مجموع كل دفعات مخزن البيع)
function get_total_selling_quantity($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) t FROM stock_batches WHERE product_id = ? AND location = 'selling'");
    $stmt->execute([$product_id]);
    return (float)$stmt->fetch()['t'];
}

// هل للمنتج أي وجود (ولو بصف صفر) في مخزن البيع؟
function has_selling_presence($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM stock_batches WHERE product_id = ? AND location = 'selling'");
    $stmt->execute([$product_id]);
    return $stmt->fetch()['c'] > 0;
}

// إضافة المنتج إلى مخزن البيع كصف فارغ (رصيد افتتاحي صفر) ليظهر في قوائم الجرد والتنبيه
function add_selling_placeholder($pdo, $product_id) {
    if (has_selling_presence($pdo, $product_id)) return;
    $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, sale_price, is_placeholder) VALUES (?, 'selling', 0, 0, NULL, 1)")
        ->execute([$product_id]);
}

// إنشاء دفعة جديدة في المخزن المؤقت من بند فاتورة شراء (مع تاريخ صلاحية اختياري)
function create_temp_batch($pdo, $product_id, $qty, $purchase_price, $invoice_id, $invoice_date, $expiry_date = null) {
    $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, purchase_invoice_id, purchase_date, expiry_date) VALUES (?, 'temp', ?, ?, ?, ?, ?)")
        ->execute([$product_id, $qty, $purchase_price, $invoice_id, $invoice_date, $expiry_date ?: null]);
}

// نقل كمية (كل الدفعة أو جزء منها) من المخزن المؤقت إلى مخزن البيع بتحديد سعر بيع
function move_to_selling($pdo, $temp_batch_id, $qty, $sale_price) {
    $stmt = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ? AND location = 'temp'");
    $stmt->execute([$temp_batch_id]);
    $batch = $stmt->fetch();
    if (!$batch) throw new Exception('الدفعة غير موجودة بالمخزن المؤقت');
    if ($qty <= 0 || $qty > $batch['quantity']) throw new Exception('الكمية المطلوب نقلها غير صحيحة');

    // إنقاص/حذف دفعة المخزن المؤقت
    $remaining = $batch['quantity'] - $qty;
    if ($remaining > 0) {
        $pdo->prepare("UPDATE stock_batches SET quantity = ? WHERE id = ?")->execute([$remaining, $temp_batch_id]);
    } else {
        $pdo->prepare("DELETE FROM stock_batches WHERE id = ?")->execute([$temp_batch_id]);
    }

    // إزالة أي صف فارغ (placeholder) لنفس المنتج بمخزن البيع لأنه سيصبح له رصيد حقيقي الآن
    $pdo->prepare("DELETE FROM stock_batches WHERE product_id = ? AND location = 'selling' AND is_placeholder = 1")->execute([$batch['product_id']]);

    // إنشاء دفعة جديدة بمخزن البيع بسعر البيع المحدد (مع نقل تاريخ الصلاحية كما هو)
    $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, sale_price, purchase_invoice_id, purchase_date, expiry_date, source_batch_id, moved_at) VALUES (?, 'selling', ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$batch['product_id'], $qty, $batch['purchase_price'], $sale_price, $batch['purchase_invoice_id'], $batch['purchase_date'], $batch['expiry_date'], $temp_batch_id]);

    log_price_history($pdo, $batch['product_id'], get_last_sale_price($pdo, $batch['product_id']), $sale_price);
    log_stock_movement($pdo, $batch['product_id'], 'in', $qty, 'batch_transfer', $temp_batch_id, 'نقل من المخزن المؤقت إلى مخزن البيع بسعر ' . $sale_price);
}

// إرجاع كمية من دفعة بمخزن البيع إلى المخزن المؤقت (لتعديل سعر البيع مثلاً)
function return_to_temp($pdo, $selling_batch_id, $qty) {
    $stmt = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ? AND location = 'selling'");
    $stmt->execute([$selling_batch_id]);
    $batch = $stmt->fetch();
    if (!$batch) throw new Exception('الدفعة غير موجودة بمخزن البيع');
    if ($qty <= 0 || $qty > $batch['quantity']) throw new Exception('الكمية المطلوب إرجاعها غير صحيحة');

    deplete_selling_batch($pdo, $selling_batch_id, $qty);

    $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, purchase_invoice_id, purchase_date, source_batch_id) VALUES (?, 'temp', ?, ?, ?, ?, ?)")
        ->execute([$batch['product_id'], $qty, $batch['purchase_price'], $batch['purchase_invoice_id'], $batch['purchase_date'], $selling_batch_id]);

    log_stock_movement($pdo, $batch['product_id'], 'out', $qty, 'batch_return', $selling_batch_id, 'إرجاع من مخزن البيع إلى المخزن المؤقت لتعديل السعر');
}

// إنقاص كمية من دفعة بمخزن البيع (بسبب بيع أو إرجاع للمخزن المؤقت) مع الحفاظ
// على صف واحد على الأقل بالمنتج (بكمية صفر) للتنبيه عند نفاذ المخزون بالكامل
function deplete_selling_batch($pdo, $batch_id, $qty) {
    $stmt = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch();
    if (!$batch) return;

    $remaining = $batch['quantity'] - $qty;
    if ($remaining < 0) $remaining = 0;

    $otherStmt = $pdo->prepare("SELECT COUNT(*) c FROM stock_batches WHERE product_id = ? AND location = 'selling' AND id != ? AND quantity > 0");
    $otherStmt->execute([$batch['product_id'], $batch_id]);
    $hasOthers = $otherStmt->fetch()['c'] > 0;

    if ($remaining > 0) {
        $pdo->prepare("UPDATE stock_batches SET quantity = ? WHERE id = ?")->execute([$remaining, $batch_id]);
    } elseif ($hasOthers) {
        // توجد دفعات أخرى بها كمية، فلا داعي لإبقاء هذا الصف الفارغ
        $pdo->prepare("DELETE FROM stock_batches WHERE id = ?")->execute([$batch_id]);
    } else {
        // آخر دفعة لهذا المنتج: تبقى بكمية صفر كصف تنبيه نفاذ المخزون
        $pdo->prepare("UPDATE stock_batches SET quantity = 0, is_placeholder = 1 WHERE id = ?")->execute([$batch_id]);
    }
}

// إزالة المنتج بالكامل من مخزن البيع (لا يُسمح إلا إذا كان الرصيد صفر) - يعيده لقائمة
// المنتجات غير المضافة بعد في products.php
function remove_product_from_selling($pdo, $product_id) {
    $total = get_total_selling_quantity($pdo, $product_id);
    if ($total > 0) throw new Exception('لا يمكن إزالة المنتج من مخزن البيع لوجود رصيد متبقٍ به');
    $pdo->prepare("DELETE FROM stock_batches WHERE product_id = ? AND location = 'selling'")->execute([$product_id]);
}

// سحب كمية من مخزن البيع بنظام الوارد أولاً يصرف أولاً (FIFO) عند البيع للعملاء
// تُعيد التكلفة الموزونة للوحدة (لحساب الربح) وتُنقص/تحذف الدفعات المستهلكة
function consume_selling_stock_fifo($pdo, $product_id, $qty_needed) {
    $total_available = get_total_selling_quantity($pdo, $product_id);
    if ($total_available < $qty_needed) {
        $nameStmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $nameStmt->execute([$product_id]);
        $name = $nameStmt->fetch()['name'] ?? '';
        throw new Exception('الكمية المتاحة من "' . $name . '" بمخزن البيع غير كافية');
    }

    $stmt = $pdo->prepare("SELECT * FROM stock_batches WHERE product_id = ? AND location = 'selling' AND quantity > 0 ORDER BY created_at ASC, id ASC FOR UPDATE");
    $stmt->execute([$product_id]);
    $batches = $stmt->fetchAll();

    $remaining = $qty_needed;
    $totalCost = 0;
    foreach ($batches as $b) {
        if ($remaining <= 0) break;
        $take = min($remaining, (float)$b['quantity']);
        $totalCost += $take * (float)$b['purchase_price'];
        deplete_selling_batch($pdo, $b['id'], $take);
        $remaining -= $take;
    }

    $weighted_cost = $qty_needed > 0 ? $totalCost / $qty_needed : 0;
    return $weighted_cost;
}

// ============================================================
// المرتجعات (مبيعات ومشتريات)
// ============================================================

// إنشاء مرتجع مبيعات: يعيد الكمية لمخزن البيع (إن لم تكن تالفة) ويُنقص دين العميل
function create_sale_return($pdo, $sale_invoice_id, $customer_id, $return_date, $items, $notes) {
    // $items: [['product_id'=>, 'quantity'=>, 'price'=>, 'cost_price'=>, 'restock'=>bool], ...]
    $total = 0;
    foreach ($items as $it) { $total += $it['quantity'] * $it['price']; }
    if ($total <= 0) throw new Exception('لا توجد كمية صحيحة للإرجاع');

    $return_number = generate_invoice_number($pdo, 'sale_returns', 'SRET');
    $pdo->prepare("INSERT INTO sale_returns (return_number, sale_invoice_id, customer_id, return_date, total, notes, user_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$return_number, $sale_invoice_id, $customer_id, $return_date, $total, $notes, current_user_id()]);
    $return_id = $pdo->lastInsertId();

    foreach ($items as $it) {
        $line_total = $it['quantity'] * $it['price'];
        $pdo->prepare("INSERT INTO sale_return_items (return_id, product_id, quantity, price, cost_price, total, restocked) VALUES (?,?,?,?,?,?,?)")
            ->execute([$return_id, $it['product_id'], $it['quantity'], $it['price'], $it['cost_price'], $line_total, $it['restock'] ? 1 : 0]);

        if ($it['restock']) {
            // إعادة الكمية كدفعة جديدة بمخزن البيع بنفس اقتصاديات البيع الأصلي
            $pdo->prepare("DELETE FROM stock_batches WHERE product_id = ? AND location = 'selling' AND is_placeholder = 1")->execute([$it['product_id']]);
            $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, sale_price, moved_at) VALUES (?, 'selling', ?, ?, ?, NOW())")
                ->execute([$it['product_id'], $it['quantity'], $it['cost_price'], $it['price']]);
            log_stock_movement($pdo, $it['product_id'], 'in', $it['quantity'], 'sale_return', $return_id, 'مرتجع مبيعات ' . $return_number . ' - أُعيد للمخزون');
        } else {
            log_stock_movement($pdo, $it['product_id'], 'adjust', $it['quantity'], 'sale_return_damaged', $return_id, 'مرتجع مبيعات ' . $return_number . ' - بضاعة تالفة لم تُعَد للمخزون');
        }
    }

    // تخفيض دين العميل (قد يصبح سالباً = رصيد دائن له عندنا)
    $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$total, $customer_id]);

    return $return_id;
}

// إنشاء مرتجع مشتريات: يخصم الكمية من المخزن (المؤقت أولاً ثم مخزن البيع) وينقص ما ندين به للمورد
function create_purchase_return($pdo, $purchase_invoice_id, $supplier_id, $return_date, $items, $notes) {
    // $items: [['product_id'=>, 'quantity'=>, 'price'=>], ...]
    $total = 0;
    foreach ($items as $it) { $total += $it['quantity'] * $it['price']; }
    if ($total <= 0) throw new Exception('لا توجد كمية صحيحة للإرجاع');

    $return_number = generate_invoice_number($pdo, 'purchase_returns', 'PRET');
    $pdo->prepare("INSERT INTO purchase_returns (return_number, purchase_invoice_id, supplier_id, return_date, total, notes, user_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$return_number, $purchase_invoice_id, $supplier_id, $return_date, $total, $notes, current_user_id()]);
    $return_id = $pdo->lastInsertId();

    foreach ($items as $it) {
        $line_total = $it['quantity'] * $it['price'];
        $pdo->prepare("INSERT INTO purchase_return_items (return_id, product_id, quantity, price, total) VALUES (?,?,?,?,?)")
            ->execute([$return_id, $it['product_id'], $it['quantity'], $it['price'], $line_total]);

        $remaining_to_remove = $it['quantity'];

        // أولاً: نخصم من دفعات المخزن المؤقت الخاصة بنفس فاتورة الشراء إن وُجدت كمية كافية
        $tempStmt = $pdo->prepare("SELECT * FROM stock_batches WHERE product_id = ? AND location = 'temp' AND purchase_invoice_id = ? ORDER BY id");
        $tempStmt->execute([$it['product_id'], $purchase_invoice_id]);
        foreach ($tempStmt->fetchAll() as $tb) {
            if ($remaining_to_remove <= 0) break;
            $take = min($remaining_to_remove, (float)$tb['quantity']);
            $newQty = $tb['quantity'] - $take;
            if ($newQty > 0) {
                $pdo->prepare("UPDATE stock_batches SET quantity = ? WHERE id = ?")->execute([$newQty, $tb['id']]);
            } else {
                $pdo->prepare("DELETE FROM stock_batches WHERE id = ?")->execute([$tb['id']]);
            }
            $remaining_to_remove -= $take;
        }

        // إن بقيت كمية غير مغطاة (لأنها رُحّلت بالفعل لمخزن البيع)، نخصمها من هناك بنظام FIFO
        if ($remaining_to_remove > 0) {
            $available = get_total_selling_quantity($pdo, $it['product_id']);
            if ($available < $remaining_to_remove) {
                throw new Exception('الكمية المتاحة (مخزن مؤقت + مخزن بيع) غير كافية لإتمام إرجاع هذا الصنف للمورد');
            }
            $sellStmt = $pdo->prepare("SELECT * FROM stock_batches WHERE product_id = ? AND location = 'selling' AND quantity > 0 ORDER BY created_at ASC, id ASC");
            $sellStmt->execute([$it['product_id']]);
            foreach ($sellStmt->fetchAll() as $sb) {
                if ($remaining_to_remove <= 0) break;
                $take = min($remaining_to_remove, (float)$sb['quantity']);
                deplete_selling_batch($pdo, $sb['id'], $take);
                $remaining_to_remove -= $take;
            }
        }

        log_stock_movement($pdo, $it['product_id'], 'out', $it['quantity'], 'purchase_return', $return_id, 'مرتجع مشتريات ' . $return_number . ' للمورد');
    }

    // تخفيض ما ندين به للمورد (قد يصبح سالباً = المورد مدين لنا)
    $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ?")->execute([$total, $supplier_id]);

    return $return_id;
}

// ============================================================
// حد الائتمان للعملاء
// ============================================================

// يتحقق هل ستتجاوز عملية بيع جديدة بمبلغ آجل $new_remaining حد ائتمان العميل
// يُعيد null إن لم يوجد حد أقصى، أو مصفوفة تفاصيل التجاوز إن حدث تجاوز
function check_credit_limit($pdo, $customer_id, $new_remaining) {
    $stmt = $pdo->prepare("SELECT balance, credit_limit, name FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $c = $stmt->fetch();
    if (!$c || $c['credit_limit'] === null) return null;

    $projected = (float)$c['balance'] + (float)$new_remaining;
    if ($projected > (float)$c['credit_limit']) {
        return [
            'customer_name' => $c['name'],
            'current_balance' => (float)$c['balance'],
            'credit_limit' => (float)$c['credit_limit'],
            'projected_balance' => $projected,
            'excess' => $projected - (float)$c['credit_limit'],
        ];
    }
    return null;
}

// ============================================================
// خريطة أقسام التنقل (تُستخدم بالصفحة الرئيسية "المحاور" وبالشريط الجانبي السياقي)
// كل قسم: label, icon, color, landing (الصفحة الافتراضية عند الضغط على المربع),
// pages: [[ملف, تسمية, أيقونة, admin_only], ...]
// ============================================================
function get_nav_categories() {
    return [
        'inventory' => [
            'label' => 'المخزون والمنتجات', 'icon' => 'fa-boxes-stacked', 'color' => '#0d6efd',
            'landing' => 'products.php',
            'pages' => [
                ['categories.php', 'الأصناف', 'fa-tags', true],
                ['products.php', 'المنتجات ووحداتها', 'fa-basket-shopping', true],
                ['temp_warehouse.php', 'المخزن المؤقت', 'fa-dolly', true],
                ['selling_warehouse.php', 'مخزن البيع', 'fa-warehouse', true],
                ['inventory_valuation.php', 'جرد المخزون', 'fa-clipboard-list', true],
                ['expiry_alerts.php', 'تنبيه الصلاحية', 'fa-calendar-xmark', true],
                ['reorder_suggestions.php', 'اقتراح إعادة الطلب', 'fa-truck-ramp-box', true],
            ],
            'detail_pages' => ['product_units.php', 'product_prices.php'],
        ],
        'sales' => [
            'label' => 'المبيعات', 'icon' => 'fa-cash-register', 'color' => '#198754',
            'landing' => 'sales.php',
            'pages' => [
                ['sales.php', 'فواتير المبيعات', 'fa-file-invoice', false],
                ['sale_add.php', 'فاتورة بيع جديدة', 'fa-plus', false],
                ['sale_returns.php', 'مرتجعات المبيعات', 'fa-rotate-left', false],
            ],
            'detail_pages' => ['sale_view.php', 'sale_return_add.php'],
        ],
        'purchases' => [
            'label' => 'المشتريات', 'icon' => 'fa-cart-arrow-down', 'color' => '#fd7e14',
            'landing' => 'purchases.php',
            'pages' => [
                ['purchases.php', 'فواتير المشتريات', 'fa-file-invoice', true],
                ['purchase_add.php', 'فاتورة شراء جديدة', 'fa-plus', true],
                ['purchase_returns.php', 'مرتجعات المشتريات', 'fa-rotate-left', true],
            ],
            'detail_pages' => ['purchase_view.php', 'purchase_return_add.php'],
        ],
        'partners' => [
            'label' => 'العملاء والموردين', 'icon' => 'fa-people-arrows', 'color' => '#20c997',
            'landing' => 'customers.php',
            'pages' => [
                ['customers.php', 'العملاء', 'fa-users', false],
                ['suppliers.php', 'الموردين', 'fa-truck', true],
                ['reminder_messages.php', 'تذكير العملاء المتأخرين', 'fa-comment-dollar', true],
            ],
            'detail_pages' => ['customer_statement.php', 'supplier_statement.php'],
        ],
        'finance' => [
            'label' => 'الحسابات والخزينة', 'icon' => 'fa-sack-dollar', 'color' => '#6f42c1',
            'landing' => 'expenses.php',
            'pages' => [
                ['expenses.php', 'النفقات والإيرادات', 'fa-file-invoice-dollar', true],
                ['payments.php', 'الخزينة والمدفوعات', 'fa-money-bill-transfer', true],
                ['debt_aging.php', 'أعمار الديون', 'fa-hourglass-half', true],
                ['cash_flow_forecast.php', 'توقع التدفق النقدي', 'fa-money-bill-trend-up', true],
            ],
            'detail_pages' => ['payment_add.php'],
        ],
        'reports' => [
            'label' => 'التقارير والتحليلات', 'icon' => 'fa-chart-column', 'color' => '#dc3545',
            'landing' => 'reports.php',
            'pages' => [
                ['reports.php', 'نظرة عامة', 'fa-gauge-high', true],
                ['profit_loss.php', 'الأرباح والخسائر', 'fa-scale-balanced', true],
                ['product_profit.php', 'ربح المنتجات', 'fa-box', true],
                ['customer_profit.php', 'ربح العملاء', 'fa-user-tag', true],
                ['category_profit.php', 'الربح حسب الصنف', 'fa-layer-group', true],
                ['product_velocity.php', 'سرعة الدوران', 'fa-gauge-high', true],
                ['product_sales_purchase_ratio.php', 'بيع مقابل شراء', 'fa-scale-unbalanced', true],
                ['product_demand_report.php', 'متوسط الطلب', 'fa-calendar-days', true],
                ['staff_performance.php', 'أداء الموظفين', 'fa-user-tie', true],
                ['sales_pattern_report.php', 'أوقات البيع الأنشط', 'fa-clock', true],
                ['period_comparison.php', 'مقارنة فترتين', 'fa-arrow-trend-up', true],
                ['supplier_report.php', 'تحليل الموردين', 'fa-trophy', true],
                ['inactive_customers.php', 'عملاء معرّضون للفقد', 'fa-user-clock', true],
                ['expenses_report.php', 'تقرير النفقات والإيرادات', 'fa-chart-pie', true],
            ],
            'detail_pages' => [],
        ],
        'store' => [
            'label' => 'المتجر الإلكتروني', 'icon' => 'fa-cart-shopping', 'color' => '#0dcaf0',
            'landing' => 'store_orders.php',
            'pages' => [
                ['store_orders.php', 'طلبات المتجر', 'fa-bag-shopping', true],
                ['store_customers.php', 'حسابات محلات التجزئة', 'fa-shop', true],
                ['manual_requests.php', 'طلبات واتساب/تليجرام', 'fa-comments', true],
                ['store_banners.php', 'بانرات الصفحة الرئيسية', 'fa-image', true],
                ['store_settings.php', 'إعدادات المتجر', 'fa-gear', true],
            ],
            'detail_pages' => ['store_order_view.php'],
        ],
        'admin' => [
            'label' => 'الإدارة', 'icon' => 'fa-user-shield', 'color' => '#495057',
            'landing' => 'users.php',
            'pages' => [
                ['users.php', 'المستخدمون والصلاحيات', 'fa-user-shield', true],
            ],
            'detail_pages' => [],
        ],
    ];
}

// جلب القسم الذي تنتمي إليه صفحة معيّنة (للشريط الجانبي السياقي)
function get_category_for_page($page) {
    foreach (get_nav_categories() as $key => $cat) {
        $all = array_merge(array_column($cat['pages'], 0), $cat['detail_pages']);
        if (in_array($page, $all)) return $key;
    }
    return null;
}

// تصفية صفحات قسم معيّن حسب صلاحية المستخدم الحالي (مدير/موظف)
function get_visible_pages_in_category($cat) {
    return array_values(array_filter($cat['pages'], fn($p) => !$p[3] || is_admin()));
}

// ============================================================
// [إضافة جديدة] سجل تتبّع حالة طلبات المتجر (Order Status Audit Trail)
// ------------------------------------------------------------
// [جدول جديد] store_order_status_log
//   - كل صف = خطوة واحدة بتاريخ حالة الطلب: من قام بها (أدمن/عميل متجر)،
//     عبر أي قناة (لوحة تحكم/واتساب/تليجرام/تلقائي بالنظام)، وملاحظة اختيارية
// [جدول جديد] store_order_status_attachments
//   - صور/مستندات مرفقة بأي خطوة من خطوات السجل أعلاه (حتى 5 ملفات لكل تحديث)
// ============================================================
function ensure_order_status_log_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_order_status_log (
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
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS store_order_status_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status_log_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        file_type ENUM('image','document') NOT NULL DEFAULT 'document',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (status_log_id) REFERENCES store_order_status_log(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

// اسم المستخدم الإداري الحالي (لعرضه كـ"من قام بالتحديث" بسجل حالة الطلب)
function current_admin_name($pdo) {
    if (!current_user_id()) return 'النظام';
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([current_user_id()]);
    return $stmt->fetch()['full_name'] ?? 'مدير';
}

// تسجيل خطوة جديدة بسجل تتبّع حالة الطلب — يُعيد id الصف الجديد (لإرفاق ملفات به لاحقاً)
function log_order_status_change($pdo, $order_id, $status, $changed_by_type, $changed_by_id, $changed_by_name, $channel = 'dashboard', $notes = null) {
    $stmt = $pdo->prepare("INSERT INTO store_order_status_log (order_id, status, changed_by_type, changed_by_id, changed_by_name, channel, notes) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$order_id, $status, $changed_by_type, $changed_by_id, $changed_by_name, $channel, $notes]);
    return $pdo->lastInsertId();
}

// رفع ملف مرفق (صورة أو مستند) وربطه بخطوة معيّنة من سجل حالة الطلب
function save_order_status_attachment($pdo, $status_log_id, $tmpPath, $originalName, $errorCode) {
    if ($errorCode !== UPLOAD_ERR_OK) return null;

    $imageExt = ['jpg', 'jpeg', 'png', 'webp'];
    $docExt = ['pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, array_merge($imageExt, $docExt))) {
        throw new Exception('صيغة الملف غير مدعومة (المسموح: صور JPG/PNG/WEBP أو مستندات PDF/DOC/DOCX)');
    }
    if (filesize($tmpPath) > 6 * 1024 * 1024) {
        throw new Exception('حجم الملف أكبر من 6 ميجابايت');
    }

    $dir = __DIR__ . '/../../uploads/order_attachments/';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }

    $filename = 'ord' . $status_log_id . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $dir . $filename)) {
        throw new Exception('تعذّر حفظ الملف على السيرفر — تأكد من صلاحيات مجلد uploads/order_attachments');
    }

    $type = in_array($ext, $imageExt) ? 'image' : 'document';
    $pdo->prepare("INSERT INTO store_order_status_attachments (status_log_id, file_path, original_name, file_type) VALUES (?,?,?,?)")
        ->execute([$status_log_id, $filename, $originalName, $type]);
    return $filename;
}

// معالجة رفع عدّة ملفات دفعة واحدة (مصفوفة $_FILES) وربطها بخطوة سجل حالة معيّنة
function save_order_status_attachments_batch($pdo, $status_log_id, $filesArray) {
    if (empty($filesArray['name'][0] ?? '')) return [];
    $errors = [];
    foreach ($filesArray['tmp_name'] as $i => $tmpName) {
        if ($filesArray['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        try {
            save_order_status_attachment($pdo, $status_log_id, $tmpName, $filesArray['name'][$i], $filesArray['error'][$i]);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
    return $errors;
}

// سجل تتبّع كامل لحالة طلب معيّن (الأحدث أولاً) مع كل مرفقاته
function get_order_status_log($pdo, $order_id) {
    $stmt = $pdo->prepare("SELECT * FROM store_order_status_log WHERE order_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$order_id]);
    $log = $stmt->fetchAll();

    if (!empty($log)) {
        $ids = array_column($log, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $attStmt = $pdo->prepare("SELECT * FROM store_order_status_attachments WHERE status_log_id IN ($in) ORDER BY id ASC");
        $attStmt->execute($ids);
        $byLog = [];
        foreach ($attStmt->fetchAll() as $a) { $byLog[$a['status_log_id']][] = $a; }
        foreach ($log as &$row) { $row['attachments'] = $byLog[$row['id']] ?? []; }
        unset($row);
    }
    return $log;
}

// التسميات الموحّدة لحالات الطلب وقنوات التحديث (مُستخدمة بلوحة الأدمن ولوحة المتجر معاً)
function store_order_status_labels() {
    return [
        'pending'   => 'بانتظار الاعتماد',
        'confirmed' => 'تم الاعتماد',
        'preparing' => 'قيد التجهيز',
        'shipped'   => 'تم الشحن',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
    ];
}
function store_order_channel_labels() {
    return [
        'dashboard' => 'لوحة التحكم',
        'whatsapp'  => 'واتساب',
        'telegram'  => 'تليجرام',
        'system'    => 'تلقائي بالنظام',
    ];
}
