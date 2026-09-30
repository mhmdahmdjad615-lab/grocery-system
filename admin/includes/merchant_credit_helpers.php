<?php
// ============================================================
// نظام رصيد الائتمان الديناميكي للتجار + فواتير الهدايا المجانية
// ============================================================
// [جدول جديد]   merchant_credit_overrides  - نسبة ائتمان خاصة لتاجر (تجاوز النسبة العامة)
// [تعديل جدول]  sale_invoices              - عمود جديد invoice_type ENUM('sale','gift')
// [تعديل جدول]  store_orders               - عمودان جديدان paid_amount, order_type
// [بيانات جديدة] store_settings            - مفاتيح: credit_limit_percentage, gift_header_text, gift_stamp_text
// راجع database/database_merchant_credit_gifts.sql للتفاصيل الكاملة
// ============================================================

function ensure_merchant_credit_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchant_credit_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_customer_id INT NOT NULL,
        credit_percentage DECIMAL(6,2) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
        UNIQUE KEY unique_merchant_override (store_customer_id)
    ) ENGINE=InnoDB");

    $col = $pdo->query("SHOW COLUMNS FROM sale_invoices LIKE 'invoice_type'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE sale_invoices ADD COLUMN invoice_type ENUM('sale','gift','gift_cancelled') NOT NULL DEFAULT 'sale' AFTER discount");
    } elseif (strpos($col['Type'], 'gift_cancelled') === false) {
        // [ترقية] توسيع القيم المسموحة لدعم إلغاء/استرجاع فواتير الهدايا لاحقاً
        $pdo->exec("ALTER TABLE sale_invoices MODIFY COLUMN invoice_type ENUM('sale','gift','gift_cancelled') NOT NULL DEFAULT 'sale'");
    }

    $col = $pdo->query("SHOW COLUMNS FROM store_orders LIKE 'paid_amount'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE store_orders ADD COLUMN paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total");
    }
    $col = $pdo->query("SHOW COLUMNS FROM store_orders LIKE 'order_type'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE store_orders ADD COLUMN order_type ENUM('sale','gift') NOT NULL DEFAULT 'sale' AFTER status");
    }

    $defaults = [
        'credit_limit_percentage' => '20',
        'gift_header_text' => 'هدية مجانية مقدمة من',
        'gift_stamp_text' => 'هدية مجانية',
        'credit_alert_threshold' => '85',   // [جديد] النسبة % من سقف الائتمان التي يبدأ عندها التنبيه
        'badge_silver_threshold' => '2000', // [جديد] حد إجمالي الاستفادة (خصومات+هدايا) لشارة "تاجر فضي"
        'credit_period_mode' => 'all_time', // [جديد] مدة حساب الإيراد المحقق: all_time/last_1_month/last_2_months/last_3_months/custom
        'credit_period_from' => '',         // [جديد] بداية الفترة المخصصة (تُستخدم فقط لو credit_period_mode = custom)
        'credit_period_to'   => '',         // [جديد] نهاية الفترة المخصصة
        'badge_gold_threshold'   => '8000', // [جديد] حد إجمالي الاستفادة لشارة "تاجر ذهبي/VIP"
    ];
    $existing = $pdo->query("SELECT setting_key FROM store_settings")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($defaults as $k => $v) {
        if (!in_array($k, $existing)) {
            $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (?,?)")->execute([$k, $v]);
        }
    }
}

/* ------------------------------------------------------------
 * نسبة الائتمان
 * ------------------------------------------------------------ */
function get_merchant_credit_percentage($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("SELECT credit_percentage FROM merchant_credit_overrides WHERE store_customer_id = ?");
    $stmt->execute([$store_customer_id]);
    $row = $stmt->fetch();
    if ($row) return (float)$row['credit_percentage'];
    return (float)get_store_setting($pdo, 'credit_limit_percentage', 20);
}

function set_merchant_credit_percentage($pdo, $store_customer_id, $percentage) {
    $stmt = $pdo->prepare("INSERT INTO merchant_credit_overrides (store_customer_id, credit_percentage) VALUES (?,?)
        ON DUPLICATE KEY UPDATE credit_percentage = VALUES(credit_percentage)");
    $stmt->execute([$store_customer_id, $percentage]);
}

function clear_merchant_credit_override($pdo, $store_customer_id) {
    $pdo->prepare("DELETE FROM merchant_credit_overrides WHERE store_customer_id = ?")->execute([$store_customer_id]);
}

/* ------------------------------------------------------------
 * [جديد] تحديد الفترة الزمنية المُعتمدة لحساب الإيراد المحقق من التاجر (ومن ثم سقف ائتمانه)
 * يُتحكَّم بها من إعدادات المتجر: كل الفترة، آخر شهر/شهرين/3 أشهر، أو فترة مخصصة من/إلى
 * تُعيد null لكل من from/to إن كان الوضع "كل الفترة" (بدون أي تقييد بتاريخ)
 * ------------------------------------------------------------ */
function get_credit_calculation_period($pdo) {
    $mode = get_store_setting($pdo, 'credit_period_mode', 'all_time');
    $today = date('Y-m-d');

    switch ($mode) {
        case 'last_1_month':
            return ['mode' => $mode, 'from' => date('Y-m-d', strtotime('-1 month')), 'to' => $today];
        case 'last_2_months':
            return ['mode' => $mode, 'from' => date('Y-m-d', strtotime('-2 months')), 'to' => $today];
        case 'last_3_months':
            return ['mode' => $mode, 'from' => date('Y-m-d', strtotime('-3 months')), 'to' => $today];
        case 'custom':
            $from = get_store_setting($pdo, 'credit_period_from', '');
            $to = get_store_setting($pdo, 'credit_period_to', '');
            // احتياطاً: لو لم تُحدَّد فترة مخصصة صحيحة بعد، ارجع لكل الفترة تجنباً لنتائج فارغة بالخطأ
            if ($from === '' || $to === '') return ['mode' => 'all_time', 'from' => null, 'to' => null];
            return ['mode' => $mode, 'from' => $from, 'to' => $to];
        case 'all_time':
        default:
            return ['mode' => 'all_time', 'from' => null, 'to' => null];
    }
}

/* ------------------------------------------------------------
 * الإيراد المحقق من التاجر = مجموع (سعر البيع - تكلفة الشراء) × الكمية
 * لكل بنود فواتير البيع الحقيقية (وليس فواتير الهدايا) المرتبطة بحسابه
 * خلال الفترة الزمنية المُعتمدة حالياً (تُضبط من إعدادات المتجر)
 * ------------------------------------------------------------ */
function get_merchant_revenue_generated($pdo, $store_customer_id, $period = null) {
    $period = $period ?? get_credit_calculation_period($pdo);

    $sql = "
        SELECT COALESCE(SUM((si.price - si.cost_price) * si.quantity), 0) revenue
        FROM sale_items si
        JOIN sale_invoices s ON s.id = si.invoice_id
        JOIN store_customers sc ON sc.customer_id = s.customer_id
        WHERE sc.id = ? AND s.invoice_type = 'sale'
    ";
    $params = [$store_customer_id];
    if ($period['from'] && $period['to']) {
        $sql .= " AND s.invoice_date BETWEEN ? AND ?";
        $params[] = $period['from'];
        $params[] = $period['to'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetch()['revenue'];
}

/* ------------------------------------------------------------
 * [جديد] نص عربي واضح يصف الفترة الزمنية المُعتمدة حالياً لحساب الإيراد (لعرضه بالواجهات)
 * ------------------------------------------------------------ */
function get_credit_period_label($period) {
    switch ($period['mode']) {
        case 'last_1_month':   return 'خلال آخر شهر';
        case 'last_2_months':  return 'خلال آخر شهرين';
        case 'last_3_months':  return 'خلال آخر 3 أشهر';
        case 'custom':         return 'خلال الفترة من ' . $period['from'] . ' إلى ' . $period['to'];
        case 'all_time':
        default:               return 'منذ بداية التعامل (كل الفترة)';
    }
}

/* ------------------------------------------------------------
 * بيانات رصيد الائتمان الكاملة لتاجر
 * ------------------------------------------------------------ */
function get_merchant_credit_info($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("SELECT customer_id FROM store_customers WHERE id = ?");
    $stmt->execute([$store_customer_id]);
    $customer_id = $stmt->fetch()['customer_id'] ?? null;

    $period = get_credit_calculation_period($pdo);
    $revenue = get_merchant_revenue_generated($pdo, $store_customer_id, $period);
    $percentage = get_merchant_credit_percentage($pdo, $store_customer_id);
    $credit_limit = round($revenue * $percentage / 100, 2);

    $balance = 0;
    if ($customer_id) {
        $b = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
        $b->execute([$customer_id]);
        $balance = (float)($b->fetch()['balance'] ?? 0);
    }
    $due = max(0, $balance);          // المطلوب سداده من التاجر (رصيد مدين)
    $receivable = max(0, -$balance);  // المستحق للتاجر لدى المنصة (رصيد دائن)
    $available = max(0, $credit_limit - $due);

    return [
        'revenue_generated'  => $revenue,
        'percentage'         => $percentage,
        'credit_limit'       => $credit_limit,
        'balance'            => $balance,
        'due_from_merchant'  => $due,
        'due_to_merchant'    => $receivable,
        'available_credit'   => $available,
        'period'             => $period,
    ];
}

/* ------------------------------------------------------------
 * [جديد] هل تاجر معيّن قريب من استنفاد سقف ائتمانه؟ (بحسب نسبة تنبيه قابلة للتحكم)
 * ------------------------------------------------------------ */
function get_merchant_credit_alert($pdo, $store_customer_id, $creditInfo = null) {
    $creditInfo = $creditInfo ?? get_merchant_credit_info($pdo, $store_customer_id);
    $threshold = (float)get_store_setting($pdo, 'credit_alert_threshold', 85);
    if ($creditInfo['credit_limit'] <= 0) {
        return ['is_near_limit' => false, 'is_over_limit' => false, 'used_percentage' => 0, 'threshold' => $threshold];
    }
    $usedPct = min(999, ($creditInfo['due_from_merchant'] / $creditInfo['credit_limit']) * 100);
    return [
        'is_near_limit'   => $usedPct >= $threshold && $usedPct < 100,
        'is_over_limit'   => $usedPct >= 100,
        'used_percentage' => $usedPct,
        'threshold'       => $threshold,
    ];
}

/* ------------------------------------------------------------
 * [جديد] شارة التاجر (موثوق/فضي/ذهبي) بحسب إجمالي استفادته الكلية - عتبات قابلة للتحكم من الإعدادات
 * ------------------------------------------------------------ */
function get_merchant_badge($pdo, $totalBenefit) {
    $goldThreshold   = (float)get_store_setting($pdo, 'badge_gold_threshold', 8000);
    $silverThreshold = (float)get_store_setting($pdo, 'badge_silver_threshold', 2000);
    if ($totalBenefit >= $goldThreshold) {
        return ['key' => 'gold', 'label' => 'تاجر ذهبي VIP', 'icon' => 'fa-crown', 'color' => '#B8860B'];
    }
    if ($totalBenefit >= $silverThreshold) {
        return ['key' => 'silver', 'label' => 'تاجر فضي موثوق', 'icon' => 'fa-medal', 'color' => '#64748B'];
    }
    return ['key' => 'bronze', 'label' => 'تاجر جديد', 'icon' => 'fa-star', 'color' => '#94A3B8'];
}


function get_merchant_savings_info($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("SELECT customer_id FROM store_customers WHERE id = ?");
    $stmt->execute([$store_customer_id]);
    $customer_id = $stmt->fetch()['customer_id'] ?? null;
    if (!$customer_id) {
        return ['total_discounts' => 0, 'gift_value' => 0, 'gift_count' => 0, 'total_benefit' => 0];
    }

    $d = $pdo->prepare("SELECT COALESCE(SUM(discount),0) t FROM sale_invoices WHERE customer_id = ? AND invoice_type = 'sale'");
    $d->execute([$customer_id]);
    $totalDiscounts = (float)$d->fetch()['t'];

    $g = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) cnt, COALESCE(SUM(si.total),0) val
        FROM sale_invoices s JOIN sale_items si ON si.invoice_id = s.id
        WHERE s.customer_id = ? AND s.invoice_type = 'gift'
    ");
    $g->execute([$customer_id]);
    $giftRow = $g->fetch();

    return [
        'total_discounts' => $totalDiscounts,
        'gift_value'       => (float)$giftRow['val'],
        'gift_count'       => (int)$giftRow['cnt'],
        'total_benefit'    => $totalDiscounts + (float)$giftRow['val'],
    ];
}

/* ------------------------------------------------------------
 * إحصائيات المنصة الشاملة: عدد التجار حسب المحافظة، الإجمالي، الاستفادة الكلية
 * ------------------------------------------------------------ */
function get_platform_merchant_stats($pdo) {
    $byCity = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(city), ''), 'غير محدد') city, COUNT(*) cnt
        FROM store_customers
        WHERE status = 'approved'
        GROUP BY city
        ORDER BY cnt DESC
    ")->fetchAll();

    $totalMerchants = (int)$pdo->query("SELECT COUNT(*) c FROM store_customers WHERE status='approved'")->fetch()['c'];

    $discountsTotal = (float)$pdo->query("SELECT COALESCE(SUM(discount),0) t FROM sale_invoices WHERE invoice_type='sale'")->fetch()['t'];
    $giftTotal = (float)$pdo->query("
        SELECT COALESCE(SUM(si.total),0) t FROM sale_items si
        JOIN sale_invoices s ON s.id = si.invoice_id WHERE s.invoice_type = 'gift'
    ")->fetch()['t'];

    return [
        'by_city'          => $byCity,
        'total_merchants'  => $totalMerchants,
        'total_discounts'  => $discountsTotal,
        'total_gift_value' => $giftTotal,
        'total_benefit'    => $discountsTotal + $giftTotal,
    ];
}

/* ------------------------------------------------------------
 * [جديد] الاتجاه الشهري لإجمالي استفادة كل التجار (خصومات + هدايا) لآخر N شهر - لرسم بياني
 * ------------------------------------------------------------ */
function get_platform_monthly_benefit_trend($pdo, $months = 6) {
    $discountRows = $pdo->query("
        SELECT DATE_FORMAT(invoice_date, '%Y-%m') ym, COALESCE(SUM(discount),0) t
        FROM sale_invoices WHERE invoice_type = 'sale' AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
        GROUP BY ym
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $giftRows = $pdo->query("
        SELECT DATE_FORMAT(s.invoice_date, '%Y-%m') ym, COALESCE(SUM(si.total),0) t
        FROM sale_invoices s JOIN sale_items si ON si.invoice_id = s.id
        WHERE s.invoice_type = 'gift' AND s.invoice_date >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
        GROUP BY ym
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $trend = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $trend[] = [
            'ym' => $ym,
            'discounts' => (float)($discountRows[$ym] ?? 0),
            'gifts' => (float)($giftRows[$ym] ?? 0),
            'total' => (float)($discountRows[$ym] ?? 0) + (float)($giftRows[$ym] ?? 0),
        ];
    }
    return $trend;
}

/* ------------------------------------------------------------
 * [جديد] أعلى N تاجر استفادة (خصومات + هدايا) - لمقارنة الأداء
 * ------------------------------------------------------------ */
function get_top_merchants_by_benefit($pdo, $limit = 10) {
    $merchants = $pdo->query("SELECT id, shop_name FROM store_customers WHERE status = 'approved'")->fetchAll();
    $rows = [];
    foreach ($merchants as $m) {
        $s = get_merchant_savings_info($pdo, $m['id']);
        if ($s['total_benefit'] <= 0) continue;
        $rows[] = ['shop_name' => $m['shop_name'], 'total_benefit' => $s['total_benefit']];
    }
    usort($rows, fn($a, $b) => $b['total_benefit'] <=> $a['total_benefit']);
    return array_slice($rows, 0, $limit);
}


/* ------------------------------------------------------------
 * إنشاء فاتورة/طلب هدية مجانية لتاجر مباشرة من لوحة تحكم الإدارة
 * (خصم 100% تلقائياً، لا يُضاف كدين على التاجر إطلاقاً)
 * $items: [['product_id'=>, 'quantity'=>, 'unit_id'=>(اختياري)], ...]
 * ------------------------------------------------------------ */
function create_gift_store_order($pdo, $store_customer_id, $items, $notes = '') {
    $scStmt = $pdo->prepare("SELECT * FROM store_customers WHERE id = ?");
    $scStmt->execute([$store_customer_id]);
    $sc = $scStmt->fetch();
    if (!$sc) throw new Exception('حساب التاجر غير موجود');

    $customer_id = $sc['customer_id'];
    if (!$customer_id) {
        $pdo->prepare("INSERT INTO customers (name, phone, address) VALUES (?,?,?)")
            ->execute([$sc['shop_name'], $sc['phone'], trim(($sc['city'] ?? '') . ' - ' . ($sc['address'] ?? ''))]);
        $customer_id = $pdo->lastInsertId();
        $pdo->prepare("UPDATE store_customers SET customer_id = ? WHERE id = ?")->execute([$customer_id, $sc['id']]);
    }

    $subtotal = 0;
    $lines = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (float)($it['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $unit = !empty($it['unit_id']) ? get_unit_by_id($pdo, $pid, (int)$it['unit_id']) : get_base_unit($pdo, $pid);

        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $pStmt->execute([$pid]);
        $product = $pStmt->fetch();
        if (!$product) continue;

        $unitPrice = get_unit_price(store_display_price($pdo, $product), $unit);
        $lineTotal = $unitPrice * $qty;
        $subtotal += $lineTotal;
        $lines[] = ['product_id' => $pid, 'qty_display' => $qty, 'unit' => $unit, 'price' => $unitPrice, 'total' => $lineTotal];
    }
    if (empty($lines)) throw new Exception('يجب اختيار منتج واحد على الأقل بكمية صحيحة ومتوفرة بمخزن البيع');

    $order_number = generate_store_order_number($pdo);
    $pdo->prepare("INSERT INTO store_orders (order_number, store_customer_id, status, subtotal, total, paid_amount, order_type, notes) VALUES (?,?,?,?,?,0,'gift',?)")
        ->execute([$order_number, $store_customer_id, 'confirmed', $subtotal, 0, $notes ?: null]);
    $order_id = $pdo->lastInsertId();

    $saleItems = [];
    foreach ($lines as $l) {
        $factor = (float)$l['unit']['factor'];
        $qty_base = convert_to_base_qty($l['qty_display'], $factor);
        $price_base = convert_price_to_base($l['price'], $factor);
        $cost_price_base = consume_selling_stock_fifo($pdo, $l['product_id'], $qty_base);

        $pdo->prepare("INSERT INTO store_order_items (order_id, product_id, unit_id, unit_name, quantity, price, total) VALUES (?,?,?,?,?,?,?)")
            ->execute([$order_id, $l['product_id'], $l['unit']['id'], $l['unit']['unit_name'], $l['qty_display'], $l['price'], $l['total']]);

        $saleItems[] = [
            'product_id' => $l['product_id'], 'qty' => $qty_base, 'price' => $price_base,
            'cost' => $cost_price_base, 'total' => $l['total'],
            'unit_name' => $l['unit']['unit_name'], 'display_qty' => $l['qty_display'],
        ];
    }

    $invoice_number = generate_invoice_number($pdo, 'sale_invoices', 'GFT');
    $giftLabel = get_store_setting($pdo, 'gift_header_text', 'هدية مجانية مقدمة من') . ' ' .
                 get_store_setting($pdo, 'site_name', '') . ' لـ ' . $sc['shop_name'];
    $invoiceNotes = $notes !== '' ? ($giftLabel . ' - ' . $notes) : $giftLabel;

    $pdo->prepare("INSERT INTO sale_invoices (invoice_number, customer_id, invoice_date, total, discount, paid, notes, user_id, invoice_type) VALUES (?,?,CURDATE(),0,?,0,?,?, 'gift')")
        ->execute([$invoice_number, $customer_id, $subtotal, $invoiceNotes, current_user_id()]);
    $invoice_id = $pdo->lastInsertId();

    foreach ($saleItems as $it) {
        $pdo->prepare("INSERT INTO sale_items (invoice_id, product_id, quantity, price, cost_price, total, unit_name, display_qty) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$invoice_id, $it['product_id'], $it['qty'], $it['price'], $it['cost'], $it['total'], $it['unit_name'], $it['display_qty']]);
        log_stock_movement($pdo, $it['product_id'], 'out', $it['qty'], 'gift_order', $order_id, 'هدية مجانية للتاجر - طلب ' . $order_number . ' - فاتورة ' . $invoice_number);
    }

    // فاتورة الهدية خصمها 100% ولا تُسجَّل كدين على التاجر إطلاقاً
    $pdo->prepare("UPDATE store_orders SET sale_invoice_id=?, confirmed_at=NOW() WHERE id=?")->execute([$invoice_id, $order_id]);

    // تسجيل خطوة "تم الاعتماد" بسجل تتبّع حالة الطلب (الهدية تُصدَر مُعتمدة مباشرة من الإدارة)
    log_order_status_change($pdo, $order_id, 'confirmed', 'admin', current_user_id(), current_admin_name($pdo), 'dashboard', $notes !== '' ? $notes : 'إصدار هدية مجانية');

    return $invoice_id;
}

/* ------------------------------------------------------------
 * [جديد] إلغاء فاتورة هدية مجانية صادرة بالخطأ: يُعيد الكمية إلى مخزن البيع فعلياً،
 * يُعلِّم الفاتورة كـ 'gift_cancelled' (تُستبعد تلقائياً من كل إحصائيات الهدايا)،
 * ويُحوِّل حالة الطلب إلى 'cancelled'. لا يمكن التراجع عن هذا الإجراء.
 * ------------------------------------------------------------ */
function void_gift_order($pdo, $order_id) {
    $stmt = $pdo->prepare("SELECT * FROM store_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) throw new Exception('الطلب غير موجود');
    if ($order['order_type'] !== 'gift') throw new Exception('هذا الطلب ليس فاتورة هدية مجانية');
    if ($order['status'] === 'cancelled') throw new Exception('تم إلغاء هذه الهدية بالفعل');
    if (!$order['sale_invoice_id']) throw new Exception('لا توجد فاتورة مرتبطة بهذا الطلب');

    $invStmt = $pdo->prepare("SELECT * FROM sale_invoices WHERE id = ?");
    $invStmt->execute([$order['sale_invoice_id']]);
    $invoice = $invStmt->fetch();
    if (!$invoice || $invoice['invoice_type'] !== 'gift') throw new Exception('الفاتورة المرتبطة ليست فاتورة هدية صالحة للإلغاء');

    $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE invoice_id = ?");
    $itemsStmt->execute([$invoice['id']]);
    $items = $itemsStmt->fetchAll();

    foreach ($items as $it) {
        // إعادة الكمية كدفعة جديدة بمخزن البيع بنفس تكلفتها الأصلية (بدون سعر بيع لأنها كانت هدية)
        $pdo->prepare("DELETE FROM stock_batches WHERE product_id = ? AND location = 'selling' AND is_placeholder = 1")->execute([$it['product_id']]);
        $pdo->prepare("INSERT INTO stock_batches (product_id, location, quantity, purchase_price, sale_price, moved_at) VALUES (?, 'selling', ?, ?, NULL, NOW())")
            ->execute([$it['product_id'], $it['quantity'], $it['cost_price']]);
        log_stock_movement($pdo, $it['product_id'], 'in', $it['quantity'], 'gift_order_cancelled', $order_id, 'إلغاء هدية مجانية - إعادة للمخزون - طلب ' . $order['order_number']);
    }

    $pdo->prepare("UPDATE sale_invoices SET invoice_type = 'gift_cancelled' WHERE id = ?")->execute([$invoice['id']]);
    $pdo->prepare("UPDATE store_orders SET status = 'cancelled', cancelled_reason = 'تم إلغاء الهدية واسترجاع المخزون' WHERE id = ?")->execute([$order_id]);

    // تسجيل خطوة الإلغاء بسجل تتبّع حالة الطلب (نفس السجل المستخدم لكل تحديثات حالة الطلبات)
    log_order_status_change($pdo, $order_id, 'cancelled', 'admin', current_user_id(), current_admin_name($pdo), 'dashboard', 'إلغاء هدية مجانية واسترجاع المخزون');
}

/* ------------------------------------------------------------
 * [جديد] كشف حساب موحّد لتاجر: كل فواتيره (بيع/هدية/هدية ملغاة) + مدفوعاته النقدية،
 * مرتبة زمنياً مع رصيد تراكمي - يُستخدم بصفحتي كشف الحساب (الأدمن والتاجر)
 * ------------------------------------------------------------ */
function get_merchant_credit_statement($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("SELECT customer_id FROM store_customers WHERE id = ?");
    $stmt->execute([$store_customer_id]);
    $customer_id = $stmt->fetch()['customer_id'] ?? null;
    if (!$customer_id) return [];

    $invoices = $pdo->prepare("SELECT id, invoice_number, invoice_date, total, discount, paid, invoice_type FROM sale_invoices WHERE customer_id = ? ORDER BY invoice_date, id");
    $invoices->execute([$customer_id]);

    $payments = $pdo->prepare("SELECT * FROM payments WHERE party_type='customer' AND party_id = ? ORDER BY payment_date, id");
    $payments->execute([$customer_id]);

    $rows = [];
    foreach ($invoices->fetchAll() as $inv) {
        if ($inv['invoice_type'] === 'gift') {
            $rows[] = ['date' => $inv['invoice_date'], 'desc' => 'فاتورة هدية مجانية ' . $inv['invoice_number'], 'debit' => 0, 'credit' => 0, 'type' => 'gift', 'link' => 'sale_view.php?id=' . $inv['id']];
        } elseif ($inv['invoice_type'] === 'gift_cancelled') {
            $rows[] = ['date' => $inv['invoice_date'], 'desc' => 'هدية ملغاة ' . $inv['invoice_number'] . ' (أُعيدت للمخزون)', 'debit' => 0, 'credit' => 0, 'type' => 'gift_cancelled', 'link' => 'sale_view.php?id=' . $inv['id']];
        } else {
            $rows[] = ['date' => $inv['invoice_date'], 'desc' => 'فاتورة بيع رقم ' . $inv['invoice_number'] . ($inv['discount'] > 0 ? ' (خصم ' . money($inv['discount']) . ')' : ''), 'debit' => $inv['total'], 'credit' => $inv['paid'], 'type' => 'sale', 'link' => 'sale_view.php?id=' . $inv['id']];
        }
    }
    foreach ($payments->fetchAll() as $p) {
        $label = $p['type'] === 'refund_customer' ? 'استرداد نقدي' : 'تحصيل نقدي';
        if ($p['type'] === 'refund_customer') {
            $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'debit' => $p['amount'], 'credit' => 0, 'type' => 'payment', 'link' => null];
        } else {
            $rows[] = ['date' => $p['payment_date'], 'desc' => $label . ' - ' . ($p['notes'] ?: 'بدون ملاحظات'), 'debit' => 0, 'credit' => $p['amount'], 'type' => 'payment', 'link' => null];
        }
    }
    usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

    $balance = 0;
    foreach ($rows as &$r) {
        $balance += $r['debit'] - $r['credit'];
        $r['running_balance'] = $balance;
    }
    unset($r);

    return $rows;
}

