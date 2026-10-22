-- Select the correct BigRock database in phpMyAdmin before importing.
-- No hard-coded USE statement is included.

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
