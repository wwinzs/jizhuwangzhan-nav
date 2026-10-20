-- v1.9 additive migration 001: privacy-minimal security event history.
-- Apply to an existing dedicated nav database ONLY after backing it up.
-- No credential, IP, user-agent or bearer-token columns.
CREATE TABLE IF NOT EXISTS nav_security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('login_success','logout','password_changed','other_sessions_revoked','owner_recovery') NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_nav_security_event_user FOREIGN KEY (user_id) REFERENCES nav_users(id) ON DELETE CASCADE,
  INDEX idx_nav_security_user_time (user_id, created_at, id),
  INDEX idx_nav_security_cleanup (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
