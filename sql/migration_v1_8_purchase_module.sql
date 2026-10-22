-- v1.8: Textile Purchase & Vendor Management module.
-- BACK UP THE DATABASE FIRST. Additive only: no table is dropped, no column removed or renamed,
-- no existing row is deleted. Existing purchases keep working and are marked entry_mode='LEGACY'.
-- Run ONCE (the ALTER statements fail with "Duplicate column" if run again; that is harmless).
-- Works on MySQL 5.7+/8 and MariaDB 10.3+.

-- 1) Vendor Register = existing suppliers table, extended.
ALTER TABLE suppliers
  ADD COLUMN vendor_type VARCHAR(40) NULL,
  ADD COLUMN contact_person VARCHAR(120) NULL,
  ADD COLUMN email VARCHAR(150) NULL,
  ADD COLUMN area VARCHAR(120) NULL,
  ADD COLUMN city VARCHAR(100) NULL,
  ADD COLUMN district VARCHAR(100) NULL,
  ADD COLUMN state VARCHAR(100) NULL,
  ADD COLUMN state_code CHAR(2) NULL,
  ADD COLUMN pincode VARCHAR(10) NULL,
  ADD COLUMN remarks VARCHAR(500) NULL,
  ADD COLUMN updated_at DATETIME NULL;

-- 2) Purchase bill header = existing purchases table, extended.
ALTER TABLE purchases
  ADD COLUMN entry_mode ENUM('LEGACY','MODULE') NOT NULL DEFAULT 'LEGACY',
  ADD COLUMN bill_status ENUM('DRAFT','SUBMITTED','APPROVED','CANCELLED') NOT NULL DEFAULT 'APPROVED',
  ADD COLUMN receipt_status ENUM('PENDING','PARTIAL','RECEIVED') NOT NULL DEFAULT 'RECEIVED',
  ADD COLUMN po_number VARCHAR(60) NULL,
  ADD COLUMN received_date DATE NULL,
  ADD COLUMN vendor_name_snap VARCHAR(160) NULL,
  ADD COLUMN vendor_area_snap VARCHAR(160) NULL,
  ADD COLUMN vendor_gstin_snap VARCHAR(30) NULL,
  ADD COLUMN vendor_state_code_snap CHAR(2) NULL,
  ADD COLUMN gst_type ENUM('CGST_SGST','IGST') NULL,
  ADD COLUMN subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN discount_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN taxable_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN cgst_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN sgst_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN igst_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN other_charges DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN freight DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN packing DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN round_off DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN grand_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN payment_status ENUM('UNPAID','PARTIAL','PAID') NOT NULL DEFAULT 'UNPAID',
  ADD COLUMN entry_pay_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN entry_pay_method VARCHAR(20) NULL,
  ADD COLUMN entry_pay_ref VARCHAR(100) NULL,
  ADD COLUMN attachment_path VARCHAR(255) NULL,
  ADD COLUMN attachment_name VARCHAR(190) NULL,
  ADD COLUMN invoice_key VARCHAR(100) NULL,
  ADD COLUMN duplicate_override_reason VARCHAR(255) NULL,
  ADD COLUMN short_closed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN remarks VARCHAR(500) NULL,
  ADD COLUMN approved_by INT UNSIGNED NULL,
  ADD COLUMN approved_at DATETIME NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancel_reason VARCHAR(255) NULL,
  ADD COLUMN updated_at DATETIME NULL,
  ADD INDEX idx_pu_invoice_date(invoice_date),
  ADD INDEX idx_pu_status(bill_status,receipt_status,payment_status),
  ADD UNIQUE KEY uq_pu_vendor_invoice(supplier_id,invoice_key);

-- Existing purchases: totals mirror the old total_cost; one invoice key per vendor where it is unique.
UPDATE purchases SET subtotal=total_cost, taxable_total=total_cost, grand_total=total_cost WHERE entry_mode='LEGACY';
UPDATE IGNORE purchases SET invoice_key=UPPER(TRIM(invoice_no)) WHERE entry_mode='LEGACY' AND invoice_no IS NOT NULL AND TRIM(invoice_no)<>'';

-- 3) Bill lines (what the vendor billed). Stock is NOT created from these; only a goods receipt creates stock.
CREATE TABLE IF NOT EXISTS purchase_bill_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 purchase_id BIGINT UNSIGNED NOT NULL,
 line_no SMALLINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 sku_snap VARCHAR(60) NOT NULL,
 name_snap VARCHAR(180) NOT NULL,
 fabric_snap VARCHAR(100) NULL,
 hsn_snap VARCHAR(20) NULL,
 description VARCHAR(500) NULL,
 color VARCHAR(80) NULL,
 design VARCHAR(100) NULL,
 pattern VARCHAR(100) NULL,
 size VARCHAR(40) NULL,
 quality VARCHAR(80) NULL,
 lot_no VARCHAR(60) NULL,
 unit VARCHAR(20) NOT NULL DEFAULT 'PCS',
 quantity DECIMAL(12,3) NOT NULL,
 rate DECIMAL(12,2) NOT NULL,
 discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 taxable_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
 cgst_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 sgst_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 igst_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
 received_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 rejected_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 INDEX idx_pbl_purchase(purchase_id), INDEX idx_pbl_product(product_id),
 CONSTRAINT fk_pbl_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
 CONSTRAINT fk_pbl_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- 4) Goods receipts (what physically arrived). One confirmed receipt = one stock posting.
CREATE TABLE IF NOT EXISTS goods_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receipt_no VARCHAR(50) NOT NULL UNIQUE,
 purchase_id BIGINT UNSIGNED NOT NULL,
 location_id INT UNSIGNED NOT NULL,
 received_date DATE NOT NULL,
 remarks VARCHAR(500) NULL,
 short_closed TINYINT(1) NOT NULL DEFAULT 0,
 receipt_token CHAR(32) NOT NULL UNIQUE,
 received_by INT UNSIGNED NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_gr_purchase(purchase_id),
 CONSTRAINT fk_gr_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id),
 CONSTRAINT fk_gr_location FOREIGN KEY(location_id) REFERENCES locations(id),
 CONSTRAINT fk_gr_user FOREIGN KEY(received_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receipt_id BIGINT UNSIGNED NOT NULL,
 bill_line_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NULL,
 accepted_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 rejected_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
 remarks VARCHAR(255) NULL,
 INDEX idx_gri_receipt(receipt_id), INDEX idx_gri_line(bill_line_id),
 CONSTRAINT fk_gri_receipt FOREIGN KEY(receipt_id) REFERENCES goods_receipts(id) ON DELETE CASCADE,
 CONSTRAINT fk_gri_line FOREIGN KEY(bill_line_id) REFERENCES purchase_bill_lines(id),
 CONSTRAINT fk_gri_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_gri_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5) Vendor payments. Never deleted; a wrong payment is REVERSED with a reason.
CREATE TABLE IF NOT EXISTS vendor_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 payment_no VARCHAR(50) NOT NULL UNIQUE,
 supplier_id INT UNSIGNED NOT NULL,
 purchase_id BIGINT UNSIGNED NULL,
 amount DECIMAL(14,2) NOT NULL,
 method ENUM('CASH','UPI','BANK','CHEQUE','CARD','OTHER') NOT NULL DEFAULT 'BANK',
 reference VARCHAR(100) NULL,
 paid_on DATE NOT NULL,
 remarks VARCHAR(255) NULL,
 status ENUM('ACTIVE','REVERSED') NOT NULL DEFAULT 'ACTIVE',
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reversed_by INT UNSIGNED NULL,
 reversed_at DATETIME NULL,
 reverse_reason VARCHAR(255) NULL,
 INDEX idx_vp_supplier(supplier_id,paid_on), INDEX idx_vp_purchase(purchase_id),
 CONSTRAINT fk_vp_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 CONSTRAINT fk_vp_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
 CONSTRAINT fk_vp_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 6) Permissions (Super Admin already has everything) and the configurable GST slab list.
INSERT IGNORE INTO role_permissions(role,permission) VALUES
 ('godown','receive_goods'),
 ('auditor','view_purchases');
INSERT IGNORE INTO settings(setting_key,setting_value) VALUES
 ('purchase_gst_rates','0,5,12,18,28');
