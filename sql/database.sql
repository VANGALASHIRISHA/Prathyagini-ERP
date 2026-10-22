CREATE TABLE locations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 warehouse VARCHAR(120) NULL,
 floor_name VARCHAR(80) NULL,
 section_name VARCHAR(80) NULL,
 rack VARCHAR(80) NULL,
 shelf VARCHAR(80) NULL,
 shelf_bin VARCHAR(80) NULL,
 online_fulfillment_enabled TINYINT(1) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 username VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','manager','cashier','godown','auditor') NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 auth_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE user_locations (
 user_id INT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,location_id),
 CONSTRAINT fk_ul_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_ul_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
 role VARCHAR(40) NOT NULL,
 permission VARCHAR(80) NOT NULL,
 PRIMARY KEY(role,permission)
) ENGINE=InnoDB;

INSERT INTO role_permissions(role,permission) VALUES
('manager','view_stock'),('manager','view_stock_reports'),('manager','pos'),('manager','manage_products'),('manager','manage_customers'),('manager','stock_transfer'),('manager','view_reports'),('manager','manage_online_orders'),('manager','fulfill_online_orders'),('manager','dispatch_online_orders'),('manager','print_location_labels'),('manager','stock_count'),('manager','process_online_returns'),
('cashier','view_stock'),('cashier','pos'),
('godown','view_stock'),('godown','view_stock_reports'),('godown','stock_transfer'),('godown','fulfill_online_orders'),('godown','print_location_labels'),('godown','stock_count'),('godown','process_online_returns'),
('auditor','view_stock'),('auditor','view_stock_reports'),('auditor','view_reports');

CREATE TABLE categories (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE suppliers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 party_code VARCHAR(40) NOT NULL UNIQUE,
 party_name VARCHAR(160) NOT NULL,
 mobile VARCHAR(30) NULL,
 gstin VARCHAR(30) NULL,
 address TEXT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(60) NOT NULL UNIQUE,
 product_barcode VARCHAR(80) NOT NULL UNIQUE,
 product_name VARCHAR(180) NOT NULL,
 product_image VARCHAR(255) NULL,
 category_id INT UNSIGNED NULL,
 brand VARCHAR(100) NOT NULL DEFAULT 'Pratyangi Fashions',
 fabric VARCHAR(100) NULL,
 pattern_name VARCHAR(100) NULL,
 gender VARCHAR(20) NULL,
 season VARCHAR(60) NULL,
 color VARCHAR(80) NULL,
 size VARCHAR(40) NULL,
 design_model VARCHAR(100) NULL,
 hsn_code VARCHAR(20) NULL,
 unit VARCHAR(20) NOT NULL DEFAULT 'PCS',
 purchase_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 cost_code VARCHAR(80) NULL,
 mrp DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 retail_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 dealer_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 offer_price DECIMAL(12,2) NULL,
 tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
 min_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 serial_tracking TINYINT(1) NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_product_category FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 purchase_no VARCHAR(50) NOT NULL UNIQUE,
 supplier_id INT UNSIGNED NOT NULL,
 invoice_no VARCHAR(80) NULL,
 invoice_date DATE NOT NULL,
 total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_purchase_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 CONSTRAINT fk_purchase_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_no VARCHAR(60) NOT NULL,
 batch_barcode VARCHAR(100) NOT NULL UNIQUE,
 product_id INT UNSIGNED NOT NULL,
 supplier_id INT UNSIGNED NOT NULL,
 purchase_id BIGINT UNSIGNED NULL,
 purchase_invoice_no VARCHAR(80) NULL,
 purchased_at DATE NOT NULL,
 cost_price DECIMAL(12,2) NOT NULL,
 cost_code VARCHAR(80) NULL,
 qty_received DECIMAL(12,3) NOT NULL,
 qty_available DECIMAL(12,3) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(product_id,batch_no),
 CONSTRAINT fk_batch_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_batch_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 CONSTRAINT fk_batch_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 purchase_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 quantity DECIMAL(12,3) NOT NULL,
 cost_price DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_pi_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
 CONSTRAINT fk_pi_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_pi_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_pi_location FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE inventory_balances (
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 reserved_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(product_id,batch_id,location_id),
 CONSTRAINT fk_ib_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_ib_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_ib_location FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE stock_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 txn_type ENUM('PURCHASE','SALE','SALE_RETURN','SALE_VOID','PURCHASE_RETURN','TRANSFER_IN','TRANSFER_OUT','ADJUST_IN','ADJUST_OUT','DAMAGE','ONLINE_DISPATCH','ONLINE_RETURN') NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 qty_in DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 qty_out DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 reference_type VARCHAR(40) NULL,
 reference_id BIGINT UNSIGNED NULL,
 unit_rate DECIMAL(12,2) NULL,
 remarks VARCHAR(255) NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_stock_product(product_id), INDEX idx_stock_batch(batch_id), INDEX idx_stock_location(location_id),
 CONSTRAINT fk_sl_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_sl_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_sl_location FOREIGN KEY(location_id) REFERENCES locations(id),
 CONSTRAINT fk_sl_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE customers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 customer_code VARCHAR(50) NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 mobile VARCHAR(30) NULL,
 gstin VARCHAR(30) NULL,
 address TEXT NULL,
 state_code VARCHAR(2) NULL,
 price_tier ENUM('retail','wholesale','dealer','offer') NOT NULL DEFAULT 'retail',
 credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE sales (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invoice_no VARCHAR(50) NOT NULL UNIQUE,
 customer_id BIGINT UNSIGNED NULL,
 customer_name VARCHAR(150) NULL,
 customer_mobile VARCHAR(30) NULL,
 price_tier ENUM('retail','wholesale','dealer','offer') NOT NULL DEFAULT 'retail',
 subtotal DECIMAL(14,2) NOT NULL,
 tax_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 discount_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 grand_total DECIMAL(14,2) NOT NULL,
 refund_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 payment_mode ENUM('CASH','CARD','UPI','CREDIT','MIXED') NOT NULL DEFAULT 'CASH',
 paid_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 credit_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 place_of_supply VARCHAR(2) NULL,
 gst_type ENUM('CGST_SGST','IGST') NOT NULL DEFAULT 'CGST_SGST',
 status ENUM('COMPLETED','CANCELLED','RETURNED_PARTIAL','RETURNED_FULL') NOT NULL DEFAULT 'COMPLETED',
 cancel_reason VARCHAR(255) NULL,
 cancelled_by INT UNSIGNED NULL,
 cancelled_at DATETIME NULL,
 cashier_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sales_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,
 CONSTRAINT fk_sales_user FOREIGN KEY(cashier_id) REFERENCES users(id),
 CONSTRAINT fk_sales_cancel_user FOREIGN KEY(cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE sale_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sale_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 barcode VARCHAR(100) NOT NULL,
 serial_id BIGINT UNSIGNED NULL,
 quantity DECIMAL(12,3) NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
 line_total DECIMAL(14,2) NOT NULL,
 discount_share DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 taxable_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 cost_snapshot DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_si_sale FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,
 CONSTRAINT fk_si_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_si_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_si_location FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE stock_transfers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transfer_no VARCHAR(50) NOT NULL UNIQUE,
 from_location_id INT UNSIGNED NOT NULL,
 to_location_id INT UNSIGNED NOT NULL,
 status ENUM('DRAFT','COMPLETED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 CONSTRAINT fk_st_from FOREIGN KEY(from_location_id) REFERENCES locations(id),
 CONSTRAINT fk_st_to FOREIGN KEY(to_location_id) REFERENCES locations(id),
 CONSTRAINT fk_st_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_transfer_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transfer_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(12,3) NOT NULL,
 CONSTRAINT fk_sti_transfer FOREIGN KEY(transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
 CONSTRAINT fk_sti_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_sti_batch FOREIGN KEY(batch_id) REFERENCES batches(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(60) NULL,
 entity_id BIGINT UNSIGNED NULL,
 meta_json JSON NULL,
 ip_address VARCHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_user(user_id), INDEX idx_audit_created(created_at),
 CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value TEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings(setting_key,setting_value) VALUES
('company_name','Pratyangi Fashions'),('invoice_prefix','PF'),('currency','INR'),('low_stock_alert','1'),
('company_gstin',''),('company_address',''),('company_state_code',''),
('cashier_max_discount_pct','0'),('manager_max_discount_pct','0'),('session_timeout_minutes','30');

INSERT INTO categories(name) VALUES ('Sarees'),('Dress Materials'),('Kurtis'),('Shirts'),('Trousers'),('Kids Wear'),('Accessories');
INSERT INTO locations(code,name,warehouse,floor_name,section_name,rack,shelf_bin,online_fulfillment_enabled) VALUES
('MAIN-GDN','Main Godown','Main Godown','Ground','General','R01','B01',1),
('SHOWROOM','Showroom Stock','Showroom','Ground','Retail','R01','B01',0);

CREATE TABLE product_serials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 serial_barcode VARCHAR(100) NOT NULL UNIQUE,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 status ENUM('AVAILABLE','RESERVED','SOLD','DAMAGED','RETURNED_SUPPLIER') NOT NULL DEFAULT 'AVAILABLE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ps_batch(batch_id), INDEX idx_ps_location(location_id), INDEX idx_ps_status(status),
 CONSTRAINT fk_ps_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_ps_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_ps_location FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE sale_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sale_id BIGINT UNSIGNED NOT NULL,
 mode ENUM('CASH','CARD','UPI','CREDIT') NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 reference_no VARCHAR(100) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sp_sale(sale_id),
 CONSTRAINT fk_sp_sale FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE customer_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL,
 txn_type ENUM('SALE_CREDIT','RECEIPT','RETURN_CREDIT','VOID_REVERSAL','ADJUSTMENT') NOT NULL,
 debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 reference_type VARCHAR(40) NULL,
 reference_id BIGINT UNSIGNED NULL,
 remarks VARCHAR(255) NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_cl_customer(customer_id), INDEX idx_cl_created(created_at),
 CONSTRAINT fk_cl_customer FOREIGN KEY(customer_id) REFERENCES customers(id),
 CONSTRAINT fk_cl_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(80) NOT NULL,
 ip_address VARCHAR(64) NULL,
 success TINYINT(1) NOT NULL DEFAULT 0,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_la_user_time(username,attempted_at), INDEX idx_la_ip_time(ip_address,attempted_at)
) ENGINE=InnoDB;


CREATE TABLE purchase_returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 return_no VARCHAR(50) NOT NULL UNIQUE,
 purchase_id BIGINT UNSIGNED NULL,
 supplier_id INT UNSIGNED NOT NULL,
 total_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 reason VARCHAR(255) NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_pr_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
 CONSTRAINT fk_pr_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 CONSTRAINT fk_pr_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_return_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 purchase_return_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 serial_id BIGINT UNSIGNED NULL,
 quantity DECIMAL(12,3) NOT NULL,
 cost_price DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_pri_return FOREIGN KEY(purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
 CONSTRAINT fk_pri_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_pri_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_pri_location FOREIGN KEY(location_id) REFERENCES locations(id),
 CONSTRAINT fk_pri_serial FOREIGN KEY(serial_id) REFERENCES product_serials(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE supplier_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 txn_type ENUM('PURCHASE','PAYMENT','PURCHASE_RETURN','ADJUSTMENT') NOT NULL,
 debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 reference_type VARCHAR(40) NULL,
 reference_id BIGINT UNSIGNED NULL,
 remarks VARCHAR(255) NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_supplier_ledger(supplier_id,created_at),
 CONSTRAINT fk_supl_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 CONSTRAINT fk_supl_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sale_returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 return_no VARCHAR(50) NOT NULL UNIQUE,
 sale_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(255) NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_sr_sale FOREIGN KEY(sale_id) REFERENCES sales(id),
 CONSTRAINT fk_sr_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sale_return_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sale_return_id BIGINT UNSIGNED NOT NULL,
 sale_item_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(12,3) NOT NULL,
 refund_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 CONSTRAINT fk_sri_return FOREIGN KEY(sale_return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
 CONSTRAINT fk_sri_item FOREIGN KEY(sale_item_id) REFERENCES sale_items(id)
) ENGINE=InnoDB;

ALTER TABLE sale_items ADD CONSTRAINT fk_si_serial FOREIGN KEY(serial_id) REFERENCES product_serials(id) ON DELETE SET NULL;


-- V1.4 ONLINE ORDER / GODOWN PICK-PACK-DISPATCH
CREATE TABLE online_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_no VARCHAR(60) NOT NULL UNIQUE,
 source ENUM('WEBSITE','MARKETPLACE','MANUAL','API') NOT NULL DEFAULT 'MANUAL',
 external_order_id VARCHAR(100) NULL,
 customer_name VARCHAR(150) NULL,
 customer_mobile VARCHAR(30) NULL,
 shipping_address TEXT NULL,
 order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 payment_status ENUM('PAID','COD','PENDING','REFUNDED') NOT NULL DEFAULT 'PENDING',
 status ENUM('NEW','ALLOCATED','PICKING','PICKED','PACKING','PACKED','DISPATCHED','DELIVERED','RETURN_IN_TRANSIT','RETURNED','CANCELLED') NOT NULL DEFAULT 'NEW',
 courier VARCHAR(100) NULL,
 awb VARCHAR(120) NULL,
 package_weight DECIMAL(10,3) NULL,
 notes VARCHAR(500) NULL,
 created_by INT UNSIGNED NULL,
 packed_by INT UNSIGNED NULL,
 dispatched_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 packed_at DATETIME NULL,
 dispatched_at DATETIME NULL,
 UNIQUE KEY uq_online_source_external(source,external_order_id),
 INDEX idx_online_status(status), INDEX idx_online_created(created_at),
 CONSTRAINT fk_oo_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_oo_packed_by FOREIGN KEY(packed_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_oo_dispatched_by FOREIGN KEY(dispatched_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE online_order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 online_order_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 quantity DECIMAL(12,3) NOT NULL,
 CONSTRAINT fk_ooi_order FOREIGN KEY(online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_ooi_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE online_order_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 online_order_id BIGINT UNSIGNED NOT NULL,
 order_item_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 serial_id BIGINT UNSIGNED NULL,
 quantity DECIMAL(12,3) NOT NULL,
 picked_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 packed_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 status ENUM('RESERVED','PICKED','PACKED','DISPATCHED','RETURNED','RELEASED') NOT NULL DEFAULT 'RESERVED',
 picked_by INT UNSIGNED NULL,
 packed_by INT UNSIGNED NULL,
 picked_at DATETIME NULL,
 packed_at DATETIME NULL,
 INDEX idx_ooa_order(online_order_id), INDEX idx_ooa_location(location_id), INDEX idx_ooa_status(status), INDEX idx_ooa_serial(serial_id),
 CONSTRAINT fk_ooa_order FOREIGN KEY(online_order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_ooa_item FOREIGN KEY(order_item_id) REFERENCES online_order_items(id) ON DELETE CASCADE,
 CONSTRAINT fk_ooa_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_ooa_batch FOREIGN KEY(batch_id) REFERENCES batches(id),
 CONSTRAINT fk_ooa_location FOREIGN KEY(location_id) REFERENCES locations(id),
 CONSTRAINT fk_ooa_serial FOREIGN KEY(serial_id) REFERENCES product_serials(id) ON DELETE SET NULL,
 CONSTRAINT fk_ooa_picked_by FOREIGN KEY(picked_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_ooa_packed_by FOREIGN KEY(packed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- V1.3 ROLE SYNC / INTER-PANEL COMMUNICATION
CREATE TABLE workflow_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(60) NOT NULL,
 title VARCHAR(160) NOT NULL,
 message VARCHAR(500) NOT NULL,
 entity_type VARCHAR(60) NULL,
 entity_id BIGINT UNSIGNED NULL,
 location_id INT UNSIGNED NULL,
 source_user_id INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_we_created(created_at), INDEX idx_we_location(location_id), INDEX idx_we_key(event_key),
 CONSTRAINT fk_we_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL,
 CONSTRAINT fk_we_user FOREIGN KEY(source_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE workflow_event_roles (
 event_id BIGINT UNSIGNED NOT NULL,
 role VARCHAR(40) NOT NULL,
 PRIMARY KEY(event_id,role), INDEX idx_wer_role(role),
 CONSTRAINT fk_wer_event FOREIGN KEY(event_id) REFERENCES workflow_events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE workflow_event_reads (
 event_id BIGINT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(event_id,user_id),
 CONSTRAINT fk_werr_event FOREIGN KEY(event_id) REFERENCES workflow_events(id) ON DELETE CASCADE,
 CONSTRAINT fk_werr_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE stock_count_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, count_no VARCHAR(60) NOT NULL UNIQUE, location_id INT UNSIGNED NOT NULL,
 status ENUM('OPEN','SUBMITTED','APPROVED','CANCELLED') NOT NULL DEFAULT 'OPEN', notes VARCHAR(500) NULL,
 started_by INT UNSIGNED NOT NULL, submitted_by INT UNSIGNED NULL, approved_by INT UNSIGNED NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, submitted_at DATETIME NULL, approved_at DATETIME NULL,
 INDEX idx_scs_location(location_id), INDEX idx_scs_status(status),
 CONSTRAINT fk_scs_location FOREIGN KEY(location_id) REFERENCES locations(id), CONSTRAINT fk_scs_started FOREIGN KEY(started_by) REFERENCES users(id),
 CONSTRAINT fk_scs_submitted FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL, CONSTRAINT fk_scs_approved FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE stock_count_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id BIGINT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 system_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000, counted_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000, serial_tracking TINYINT(1) NOT NULL DEFAULT 0,
 last_counted_by INT UNSIGNED NULL, last_counted_at DATETIME NULL, UNIQUE KEY uq_scl_session_batch(session_id,product_id,batch_id),
 CONSTRAINT fk_scl_session FOREIGN KEY(session_id) REFERENCES stock_count_sessions(id) ON DELETE CASCADE, CONSTRAINT fk_scl_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_scl_batch FOREIGN KEY(batch_id) REFERENCES batches(id), CONSTRAINT fk_scl_user FOREIGN KEY(last_counted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE stock_count_scans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id BIGINT UNSIGNED NOT NULL, line_id BIGINT UNSIGNED NOT NULL, barcode VARCHAR(120) NOT NULL, serial_id BIGINT UNSIGNED NULL,
 scanned_by INT UNSIGNED NOT NULL, scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_scs_session_barcode(session_id,barcode),
 CONSTRAINT fk_scscan_session FOREIGN KEY(session_id) REFERENCES stock_count_sessions(id) ON DELETE CASCADE, CONSTRAINT fk_scscan_line FOREIGN KEY(line_id) REFERENCES stock_count_lines(id) ON DELETE CASCADE,
 CONSTRAINT fk_scscan_serial FOREIGN KEY(serial_id) REFERENCES product_serials(id) ON DELETE SET NULL, CONSTRAINT fk_scscan_user FOREIGN KEY(scanned_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- PRATYANGI V1.5 LICENSE CLIENT TABLES (required by app/license_client.php)
CREATE TABLE IF NOT EXISTS license_client_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  installation_id VARCHAR(100) NOT NULL,
  signed_token MEDIUMTEXT NULL,
  payload_json JSON NULL,
  last_check_at DATETIME NULL,
  last_ok_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS license_client_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_license_client_log_created (created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO license_client_state(id,installation_id)
VALUES(1, CONCAT('INS-', UPPER(SUBSTRING(REPLACE(UUID(),'-',''),1,24))));
