-- Pratyangi Fashions ERP V1.6 - Stock transfer approval workflow.
-- BACK UP FIRST: phpMyAdmin > your database > Export > Quick > SQL > Go.
-- Safe to run on live data: this only adds columns and adds new allowed
-- ENUM values. No table is dropped or renamed, no column is removed, and
-- every existing row keeps its current value (old COMPLETED transfers and
-- existing product_serials statuses are untouched).
--
-- What this enables: stock_transfer.php's old single-step "scan and post
-- immediately" transfer becomes a 4-step workflow (Draft -> Approved ->
-- Dispatched -> Received) handled by stock_transfer.php (draft+list),
-- stock_transfer_approve.php, stock_transfer_dispatch.php and
-- stock_transfer_receive.php.

ALTER TABLE stock_transfers
  MODIFY COLUMN status ENUM('DRAFT','APPROVED','DISPATCHED','RECEIVED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN approved_at DATETIME NULL AFTER completed_at,
  ADD COLUMN dispatched_by INT UNSIGNED NULL AFTER approved_at,
  ADD COLUMN dispatched_at DATETIME NULL AFTER dispatched_by,
  ADD COLUMN received_by INT UNSIGNED NULL AFTER dispatched_at,
  ADD COLUMN received_at DATETIME NULL AFTER received_by,
  ADD COLUMN has_discrepancy TINYINT(1) NOT NULL DEFAULT 0 AFTER received_at,
  ADD COLUMN notes VARCHAR(255) NULL AFTER has_discrepancy,
  ADD CONSTRAINT fk_st_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
  ADD CONSTRAINT fk_st_dispatched_by FOREIGN KEY (dispatched_by) REFERENCES users(id),
  ADD CONSTRAINT fk_st_received_by FOREIGN KEY (received_by) REFERENCES users(id);

ALTER TABLE stock_transfer_items
  ADD COLUMN serial_id BIGINT UNSIGNED NULL AFTER batch_id,
  ADD COLUMN received_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  ADD CONSTRAINT fk_sti_serial FOREIGN KEY (serial_id) REFERENCES product_serials(id);

ALTER TABLE product_serials
  MODIFY COLUMN status ENUM('AVAILABLE','RESERVED','SOLD','DAMAGED','RETURNED_SUPPLIER','IN_TRANSIT') NOT NULL DEFAULT 'AVAILABLE';
