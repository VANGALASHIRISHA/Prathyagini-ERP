-- v1.7: physical-count exceptions (unexpected / duplicate / wrong-location scans).
-- BACK UP FIRST. Additive only; safe to run on live data; safe to run twice.
CREATE TABLE IF NOT EXISTS stock_count_exceptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL,
 barcode VARCHAR(100) NOT NULL,
 reason VARCHAR(255) NOT NULL,
 scanned_by INT UNSIGNED NOT NULL,
 scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sce_session(session_id),
 CONSTRAINT fk_sce_session FOREIGN KEY(session_id) REFERENCES stock_count_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_sce_user FOREIGN KEY(scanned_by) REFERENCES users(id)
) ENGINE=InnoDB;
