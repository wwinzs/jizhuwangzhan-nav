-- v1.9 additive migration 002: one-use owner password reset grants (NOT publicly exposed).
-- Safe only after migration 001; back up the dedicated nav database first.
-- No plaintext grant/token/email/IP/UA is persisted.
CREATE TABLE IF NOT EXISTS nav_password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) DEFAULT NULL,
  CONSTRAINT fk_nav_password_reset_owner FOREIGN KEY (user_id) REFERENCES nav_users(id) ON DELETE CASCADE,
  INDEX idx_nav_reset_user_active (user_id, consumed_at, expires_at),
  INDEX idx_nav_reset_cleanup (expires_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit events keep only a minimal action name and timestamp.
ALTER TABLE nav_security_events MODIFY COLUMN event_type
  ENUM('login_success','logout','password_changed','other_sessions_revoked',
       'owner_recovery','password_reset') NOT NULL;
