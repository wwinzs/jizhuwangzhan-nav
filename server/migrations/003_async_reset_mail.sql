-- v1.9.1 additive migration 003. Encrypted email addresses for asynchronous password recovery.
-- Apply only after migrations 001 and 002, with a tested backup.
CREATE TABLE IF NOT EXISTS nav_reset_mail_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recipient_cipher VARBINARY(512) NOT NULL,
  nonce BINARY(12) NOT NULL,
  auth_tag BINARY(16) NOT NULL,
  state ENUM('pending','processing') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  lease_until DATETIME(6) DEFAULT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_nav_mailqueue_claim (state,available_at,id),
  INDEX idx_nav_mailqueue_lease (state,lease_until,id),
  INDEX idx_nav_mailqueue_recipient (recipient_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
