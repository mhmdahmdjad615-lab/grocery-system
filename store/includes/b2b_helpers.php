<?php
/* 🎨 B2B Wholesale Platform - Helper Functions
   Tiered pricing, MOQ validation, credit limit checking, etc. */

// ========== TIERED PRICING CALCULATOR ==========

/**
 * Get applicable price tier for a given quantity
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @param float $quantity Ordered quantity
 * @return array|null Tier data or null if not found
 */
function getPricingTier($pdo, $product_id, $quantity) {
    $stmt = $pdo->prepare("
        SELECT id, min_quantity, max_quantity, price, discount_percentage
        FROM product_pricing_tiers
        WHERE product_id = ? AND min_quantity <= ? AND (max_quantity IS NULL OR max_quantity >= ?)
        ORDER BY min_quantity DESC
        LIMIT 1
    ");
    $stmt->execute([$product_id, $quantity, $quantity]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all pricing tiers for a product
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @return array Array of tier data
 */
function getProductTiers($pdo, $product_id) {
    $stmt = $pdo->prepare("
        SELECT id, min_quantity, max_quantity, price, discount_percentage
        FROM product_pricing_tiers
        WHERE product_id = ?
        ORDER BY min_quantity ASC
    ");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calculate final price with tiered discount
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @param float $quantity Ordered quantity
 * @param float $base_price Base product price
 * @return array ['price' => final_unit_price, 'tier' => tier_data, 'discount' => discount_percentage]
 */
function calculateTieredPrice($pdo, $product_id, $quantity, $base_price) {
    $tier = getPricingTier($pdo, $product_id, $quantity);
    
    return [
        'price' => $tier ? $tier['price'] : $base_price,
        'tier' => $tier,
        'discount' => $tier ? $tier['discount_percentage'] : 0,
        'savings' => ($base_price - ($tier ? $tier['price'] : $base_price)) * $quantity
    ];
}

// ========== MINIMUM ORDER QUANTITY (MOQ) ==========

/**
 * Get MOQ information for a product
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @return array MOQ data
 */
function getProductMOQ($pdo, $product_id) {
    $stmt = $pdo->prepare("
        SELECT id, moq_quantity, moq_unit, free_shipping_threshold, warning_message
        FROM product_moq
        WHERE product_id = ?
    ");
    $stmt->execute([$product_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: [
        'moq_quantity' => 1,
        'moq_unit' => 'piece',
        'free_shipping_threshold' => 5000,
        'warning_message' => null
    ];
}

/**
 * Validate if quantity meets MOQ requirement
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @param float $quantity Requested quantity
 * @return array ['valid' => bool, 'moq' => min_qty, 'message' => string]
 */
function validateMOQ($pdo, $product_id, $quantity) {
    $moq = getProductMOQ($pdo, $product_id);
    $isValid = $quantity >= $moq['moq_quantity'];
    
    return [
        'valid' => $isValid,
        'moq' => $moq['moq_quantity'],
        'needed' => max(0, $moq['moq_quantity'] - $quantity),
        'message' => !$isValid ? "الحد الأدنى للطلب هو {$moq['moq_quantity']} {$moq['moq_unit']}" : null,
        'unit' => $moq['moq_unit']
    ];
}

// ========== CREDIT LIMIT MANAGEMENT ==========

/**
 * Get customer credit limit and balance
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @return array Credit limit data
 */
function getCustomerCreditLimit($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("
        SELECT ccl.*, sc.total_spent
        FROM customer_credit_limits ccl
        LEFT JOIN store_customers sc ON ccl.store_customer_id = sc.id
        WHERE ccl.store_customer_id = ?
    ");
    $stmt->execute([$store_customer_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if customer can place order based on credit limit
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @param float $order_amount Order total amount
 * @return array ['can_order' => bool, 'available_credit' => amount, 'message' => string]
 */
function validateCreditLimit($pdo, $store_customer_id, $order_amount) {
    $credit = getCustomerCreditLimit($pdo, $store_customer_id);
    
    if (!$credit) {
        return [
            'can_order' => false,
            'available_credit' => 0,
            'message' => 'لم يتم تحديد حد ائتمان للعميل'
        ];
    }
    
    $availableCredit = $credit['credit_limit'] - $credit['current_balance'];
    $canOrder = $availableCredit >= $order_amount && $credit['status'] === 'active';
    
    return [
        'can_order' => $canOrder,
        'available_credit' => $availableCredit,
        'credit_limit' => $credit['credit_limit'],
        'current_balance' => $credit['current_balance'],
        'message' => !$canOrder ? "الرصيد المتاح غير كافي. الرصيد المتاح: {$availableCredit} ج.م" : null,
        'payment_terms' => $credit['payment_terms']
    ];
}

/**
 * Update customer credit balance after order
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @param float $amount Amount to add to balance
 * @return bool Success status
 */
function updateCustomerCreditBalance($pdo, $store_customer_id, $amount) {
    $stmt = $pdo->prepare("
        UPDATE customer_credit_limits
        SET current_balance = current_balance + ?
        WHERE store_customer_id = ?
    ");
    return $stmt->execute([$amount, $store_customer_id]);
}

// ========== QUICK REORDER HISTORY ==========

/**
 * Add product to quick reorder history
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @param int $product_id Product ID
 * @param float $quantity Ordered quantity
 * @return bool Success status
 */
function addToReorderHistory($pdo, $store_customer_id, $product_id, $quantity) {
    $stmt = $pdo->prepare("
        INSERT INTO quick_reorder_history 
            (store_customer_id, product_id, quantity, order_frequency)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            quantity = ?,
            last_ordered = NOW(),
            order_frequency = order_frequency + 1
    ");
    return $stmt->execute([$store_customer_id, $product_id, $quantity, $quantity]);
}

/**
 * Get customer's reorder history
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @param int $limit Number of recent items
 * @return array Reorder history
 */
function getReorderHistory($pdo, $store_customer_id, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.barcode, qrh.quantity, qrh.last_ordered, qrh.order_frequency
        FROM quick_reorder_history qrh
        LEFT JOIN products p ON qrh.product_id = p.id
        WHERE qrh.store_customer_id = ?
        ORDER BY qrh.last_ordered DESC
        LIMIT ?
    ");
    $stmt->execute([$store_customer_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========== ORDER PAYMENT TERMS ==========

/**
 * Get payment method options for customer
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @return array Available payment methods
 */
function getAvailablePaymentMethods($pdo, $store_customer_id) {
    $credit = getCustomerCreditLimit($pdo, $store_customer_id);
    
    $methods = [
        'immediate' => [
            'label' => 'دفع فوري',
            'discount' => 2,
            'description' => 'خصم 2% عند الدفع الفوري'
        ]
    ];
    
    if ($credit && $credit['status'] === 'active') {
        if ($credit['payment_terms'] >= 30) {
            $methods['credit_30'] = [
                'label' => 'دفع آجل (30 يوم)',
                'discount' => 0,
                'description' => 'سداد خلال 30 يوم'
            ];
        }
        if ($credit['payment_terms'] >= 60) {
            $methods['credit_60'] = [
                'label' => 'دفع آجل (60 يوم)',
                'discount' => 0,
                'description' => 'سداد خلال 60 يوم'
            ];
        }
        if ($credit['payment_terms'] >= 90) {
            $methods['installment_3'] = [
                'label' => 'تقسيط على 3 أشهر',
                'discount' => 0,
                'description' => 'تقسيط الفاتورة على 3 دفعات شهرية'
            ];
        }
    }
    
    return $methods;
}

/**
 * Calculate payment amount with discount
 * @param float $total_amount Order total
 * @param string $payment_method Payment method code
 * @return array ['final_amount' => amount, 'discount' => discount_amount]
 */
function calculatePaymentAmount($total_amount, $payment_method) {
    $discount = 0;
    
    if ($payment_method === 'immediate') {
        $discount = $total_amount * 0.02; // 2% discount
    }
    
    return [
        'final_amount' => $total_amount - $discount,
        'discount' => $discount,
        'original_amount' => $total_amount
    ];
}

// ========== ORDER TRACKING ==========

/**
 * Update order status and create timeline entry
 * @param PDO $pdo Database connection
 * @param int $order_id Order ID
 * @param string $status New status
 * @param string $notes Optional notes
 * @param string $tracking_number Optional tracking number
 * @param int $user_id User ID making the change
 * @return bool Success status
 */
function updateOrderStatus($pdo, $order_id, $status, $notes = '', $tracking_number = '', $user_id = null) {
    try {
        $pdo->beginTransaction();
        
        // Update order status
        $stmt = $pdo->prepare("
            UPDATE store_orders SET status = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$status, $order_id]);
        
        // Create timeline entry
        $stmt = $pdo->prepare("
            INSERT INTO order_tracking_timeline 
                (order_id, status, notes, tracking_number, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$order_id, $status, $notes, $tracking_number, $user_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Order status update error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get order tracking timeline
 * @param PDO $pdo Database connection
 * @param int $order_id Order ID
 * @return array Tracking timeline
 */
function getOrderTimeline($pdo, $order_id) {
    $stmt = $pdo->prepare("
        SELECT status, status_timestamp, notes, tracking_number, location
        FROM order_tracking_timeline
        WHERE order_id = ?
        ORDER BY status_timestamp ASC
    ");
    $stmt->execute([$order_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========== UNIT CONVERSION ==========

/**
 * Convert units for a product
 * @param PDO $pdo Database connection
 * @param int $product_id Product ID
 * @param float $quantity Quantity to convert
 * @param string $from_unit Source unit
 * @param string $to_unit Target unit
 * @return float|null Converted quantity or null if conversion not found
 */
function convertUnits($pdo, $product_id, $quantity, $from_unit, $to_unit) {
    if ($from_unit === $to_unit) {
        return $quantity;
    }
    
    $stmt = $pdo->prepare("
        SELECT conversion_factor FROM product_unit_conversions
        WHERE product_id = ? AND from_unit = ? AND to_unit = ?
    ");
    $stmt->execute([$product_id, $from_unit, $to_unit]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $quantity * $result['conversion_factor'] : null;
}

// ========== PROMO CODES ==========

/**
 * Validate and apply promo code to order
 * @param PDO $pdo Database connection
 * @param string $code Promo code
 * @param float $order_total Order total amount
 * @return array ['valid' => bool, 'discount' => amount, 'message' => string]
 */
function validatePromoCode($pdo, $code, $order_total) {
    $stmt = $pdo->prepare("
        SELECT id, discount_type, discount_value, min_order_amount, max_uses, times_used, valid_until, status
        FROM promo_codes
        WHERE code = ? AND status = 'active' AND NOW() BETWEEN valid_from AND valid_until
    ");
    $stmt->execute([$code]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promo) {
        return [
            'valid' => false,
            'discount' => 0,
            'message' => 'الكود غير صحيح أو منتهي الصلاحية'
        ];
    }
    
    if ($promo['min_order_amount'] && $order_total < $promo['min_order_amount']) {
        return [
            'valid' => false,
            'discount' => 0,
            'message' => "الحد الأدنى للطلب هو {$promo['min_order_amount']} ج.م"
        ];
    }
    
    if ($promo['max_uses'] && $promo['times_used'] >= $promo['max_uses']) {
        return [
            'valid' => false,
            'discount' => 0,
            'message' => 'انتهت عدد مرات استخدام هذا الكود'
        ];
    }
    
    $discount = 0;
    if ($promo['discount_type'] === 'percentage') {
        $discount = $order_total * ($promo['discount_value'] / 100);
    } else {
        $discount = $promo['discount_value'];
    }
    
    return [
        'valid' => true,
        'discount' => $discount,
        'promo_id' => $promo['id'],
        'message' => "تم تطبيق الخصم: {$discount} ج.م"
    ];
}

// ========== CUSTOMER STATISTICS ==========

/**
 * Get customer order statistics
 * @param PDO $pdo Database connection
 * @param int $store_customer_id Store customer ID
 * @return array Customer statistics
 */
function getCustomerStats($pdo, $store_customer_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(total) as total_spent,
            AVG(total) as average_order,
            MAX(created_at) as last_order_date
        FROM store_orders
        WHERE store_customer_id = ? AND status != 'cancelled'
    ");
    $stmt->execute([$store_customer_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

?>
