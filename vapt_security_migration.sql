-- ============================================================
-- VAPT SECURITY MIGRATION — mydatabase
-- Apply ONCE before deploying updated PHP files.
-- Covers all 11 VAPT points from the audit image.
-- ============================================================

-- ── VAPT #8: Concurrent session control ──────────────────────
-- Stores the active session token per user so only ONE login
-- is valid at a time. A second login invalidates the first.
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `session_token`  VARCHAR(64)  DEFAULT NULL
        COMMENT 'Active session token — null = not logged in',
    ADD COLUMN IF NOT EXISTS `last_login_at`  DATETIME     DEFAULT NULL
        COMMENT 'Timestamp of most recent login',
    ADD COLUMN IF NOT EXISTS `last_login_ip`  VARCHAR(45)  DEFAULT NULL
        COMMENT 'IP address of most recent login';

-- ── VAPT #11: Change audit / activity log ────────────────────
-- Tracks every important action (login, logout, status change,
-- booking, reschedule) for the "log for changes" requirement.
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `user_id`     INT(11)      DEFAULT NULL  COMMENT 'Who performed the action',
    `user_email`  VARCHAR(100) DEFAULT NULL  COMMENT 'Email snapshot at time of action',
    `action`      VARCHAR(100) NOT NULL      COMMENT 'e.g. LOGIN, LOGOUT, BOOK_SESSION',
    `detail`      VARCHAR(500) DEFAULT NULL  COMMENT 'Extra context',
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `user_agent`  VARCHAR(255) DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`   (`user_id`),
    KEY `idx_created_at`(`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='VAPT #11 — audit trail for all user actions';

-- ── VAPT #2: Encrypted / token URL IDs ───────────────────────
-- Stores a short-lived signed token that maps to a real DB id,
-- so URLs show ?token=abc instead of ?request_id=123.
CREATE TABLE IF NOT EXISTS `url_tokens` (
    `token`       VARCHAR(64)  NOT NULL,
    `entity_type` VARCHAR(50)  NOT NULL  COMMENT 'e.g. request, user, session',
    `entity_id`   INT(11)      NOT NULL,
    `created_by`  INT(11)      DEFAULT NULL,
    `expires_at`  DATETIME     NOT NULL,
    PRIMARY KEY (`token`),
    KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='VAPT #2 — maps opaque URL tokens to real IDs';

-- ── Verify ────────────────────────────────────────────────────
SELECT 'Migration complete' AS status;
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('activity_log','url_tokens')
ORDER BY TABLE_NAME;
