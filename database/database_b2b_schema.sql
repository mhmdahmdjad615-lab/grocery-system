-- ============================================================
-- B2B Wholesale E-commerce Platform - Database Schema Extensions
-- ============================================================
-- This SQL adds tiered pricing, credit management, and MOQ tracking

USE grocery_system;

-- ========== TIERED PRICING TABLE ==========
CREATE TABLE IF NOT EXISTS product_pricing_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    min_quantity DECIMAL(12,2) NOT NULL,
    max_quantity DECIMAL(12,2) NULL,
    price DECIMAL(12,2) NOT NULL,
    discount_percentage DECIMAL(5,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tier (product_id, min_quantity)
) ENGINE=InnoDB;

-- ========== CUSTOMER CREDIT LIMITS TABLE ==========
CREATE TABLE IF NOT EXISTS customer_credit_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_customer_id INT NOT NULL,
    credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_terms VARCHAR(50) DEFAULT '30', -- days
    status ENUM('active','suspended','inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_customer (store_customer_id)
) ENGINE=InnoDB;

-- ========== ORDER PAYMENT TERMS TABLE ==========
CREATE TABLE IF NOT EXISTS order_payment_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('immediate','credit_30','credit_60','installment_3') NOT NULL,
    immediate_discount DECIMAL(5,2) DEFAULT 0, -- percentage
    due_date DATE NULL,
    paid_amount DECIMAL(14,2) DEFAULT 0,
    payment_status ENUM('pending','partial','completed','overdue') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========== MINIMUM ORDER QUANTITY (MOQ) TRACKING TABLE ==========
CREATE TABLE IF NOT EXISTS product_moq (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    moq_quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    moq_unit VARCHAR(50) DEFAULT 'piece',
    free_shipping_threshold DECIMAL(14,2) NULL,
    warning_message VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_moq (product_id)
) ENGINE=InnoDB;

-- ========== ORDER TRACKING TIMELINE TABLE ==========
CREATE TABLE IF NOT EXISTS order_tracking_timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status ENUM('order_placed','confirmed','preparing','shipped','in_transit','delivered','cancelled','returned') NOT NULL,
    status_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255) NULL,
    tracking_number VARCHAR(100) NULL,
    location VARCHAR(150) NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (order_id, status_timestamp)
) ENGINE=InnoDB;

-- ========== QUICK REORDER HISTORY TABLE ==========
CREATE TABLE IF NOT EXISTS quick_reorder_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    last_ordered DATETIME DEFAULT CURRENT_TIMESTAMP,
    order_frequency INT DEFAULT 0, -- number of times ordered
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_history (store_customer_id, product_id),
    INDEX (last_ordered)
) ENGINE=InnoDB;

-- ========== BULK ORDER CALCULATOR CART TABLE ==========
CREATE TABLE IF NOT EXISTS bulk_order_carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    selected_tier_id INT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (selected_tier_id) REFERENCES product_pricing_tiers(id) ON DELETE SET NULL,
    UNIQUE KEY unique_cart_item (store_customer_id, product_id)
) ENGINE=InnoDB;

-- ========== PROMO CODES & DISCOUNTS TABLE ==========
CREATE TABLE IF NOT EXISTS promo_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('percentage','fixed_amount') NOT NULL,
    discount_value DECIMAL(12,2) NOT NULL,
    max_uses INT NULL,
    times_used INT DEFAULT 0,
    min_order_amount DECIMAL(14,2) NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    status ENUM('active','inactive','expired') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (code, status),
    INDEX (valid_from, valid_until)
) ENGINE=InnoDB;

-- ========== ORDER PROMO APPLICATION TABLE ==========
CREATE TABLE IF NOT EXISTS order_promos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    promo_code_id INT NOT NULL,
    discount_amount DECIMAL(14,2) NOT NULL,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========== UNIT CONVERTER TABLE ==========
CREATE TABLE IF NOT EXISTS product_unit_conversions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    from_unit VARCHAR(50) NOT NULL,
    to_unit VARCHAR(50) NOT NULL,
    conversion_factor DECIMAL(12,4) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_conversion (product_id, from_unit, to_unit)
) ENGINE=InnoDB;

-- ========== CUSTOMER PREFERENCES TABLE ==========
CREATE TABLE IF NOT EXISTS customer_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_customer_id INT NOT NULL,
    preferred_payment_method VARCHAR(50),
    preferred_shipping_method VARCHAR(50),
    preferred_contact_method VARCHAR(50),
    notification_email TINYINT(1) DEFAULT 1,
    notification_sms TINYINT(1) DEFAULT 1,
    notification_whatsapp TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_customer_id) REFERENCES store_customers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_preferences (store_customer_id)
) ENGINE=InnoDB;

-- ========== ADD COLUMNS TO EXISTING TABLES ==========

-- Add B2B features to store_customers
ALTER TABLE store_customers
    ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(14,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(50) DEFAULT '30',
    ADD COLUMN IF NOT EXISTS business_registration VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS tax_id VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS total_orders INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_spent DECIMAL(14,2) DEFAULT 0;

-- Add tier pricing to store_orders
ALTER TABLE store_orders
    ADD COLUMN IF NOT EXISTS payment_method ENUM('immediate','credit_30','credit_60','installment_3') DEFAULT 'immediate',
    ADD COLUMN IF NOT EXISTS promo_code VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(14,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(14,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS shipping_address VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS shipping_cost DECIMAL(12,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS due_date DATE NULL;

-- Add pricing tier to store_order_items
ALTER TABLE store_order_items
    ADD COLUMN IF NOT EXISTS discount_percentage DECIMAL(5,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS tier_id INT NULL,
    ADD FOREIGN KEY (tier_id) REFERENCES product_pricing_tiers(id) ON DELETE SET NULL;

-- Add B2B fields to products
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS store_bulk_description TEXT NULL,
    ADD COLUMN IF NOT EXISTS store_max_quantity DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS store_lead_time INT DEFAULT 1,
    ADD COLUMN IF NOT EXISTS is_wholesale_only TINYINT(1) DEFAULT 0;

-- ========== INDEXES FOR PERFORMANCE ==========

CREATE INDEX IF NOT EXISTS idx_tiered_pricing_product ON product_pricing_tiers(product_id, min_quantity);
CREATE INDEX IF NOT EXISTS idx_credit_limit_customer ON customer_credit_limits(store_customer_id);
CREATE INDEX IF NOT EXISTS idx_moq_product ON product_moq(product_id);
CREATE INDEX IF NOT EXISTS idx_reorder_customer ON quick_reorder_history(store_customer_id, last_ordered);
CREATE INDEX IF NOT EXISTS idx_tracking_order ON order_tracking_timeline(order_id, status_timestamp);
CREATE INDEX IF NOT EXISTS idx_order_payment_method ON order_payment_terms(order_id, payment_method);

-- ========== SAMPLE DATA ==========

-- Insert default store settings
INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES
    ('site_name', 'منصة البيع بالجملة'),
    ('site_description', 'منصة بيع بالجملة إلكترونية متخصصة'),
    ('support_whatsapp', ''),
    ('support_telegram', ''),
    ('support_email', ''),
    ('free_shipping_threshold', '5000'),
    ('default_currency', 'EGP'),
    ('store_timezone', 'Africa/Cairo');
