-- ============================================================
-- monthly_session_control_migration.sql
-- Run ONCE in phpMyAdmin before deploying new features.
-- ============================================================

-- 1. session_completed flag
ALTER TABLE `mentor_requests`
    ADD COLUMN IF NOT EXISTS `session_completed` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = session marked complete by mentor this month';

-- 2. rescheduled_by
ALTER TABLE `mentor_requests`
    ADD COLUMN IF NOT EXISTS `rescheduled_by` VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'mentor | mentee — who last rescheduled';

-- 3. reschedule_note
ALTER TABLE `mentor_requests`
    ADD COLUMN IF NOT EXISTS `reschedule_note` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Note on last reschedule action';

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'mentor_requests'
  AND COLUMN_NAME  IN ('session_completed','rescheduled_by','reschedule_note');
