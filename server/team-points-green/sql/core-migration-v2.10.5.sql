-- P2K v2.10.5 Green Core additive migration.
-- Runtime updater executes GreenRepository::initializeSchemas(), which applies
-- these changes idempotently after checking information_schema.
-- No rows are reset or reseeded.

ALTER TABLE p2k_g_state
  ADD COLUMN IF NOT EXISTS public_read_target ENUM('blue','green') NOT NULL DEFAULT 'blue' AFTER force_mode,
  ADD COLUMN IF NOT EXISTS migration_phase ENUM('blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary') NOT NULL DEFAULT 'blue_primary' AFTER public_read_target;

ALTER TABLE p2k_g_quick_board_cycle_items
  MODIFY claim_count INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS p2k_g_member_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  identity_id BIGINT UNSIGNED NULL,
  event_key VARCHAR(255) NOT NULL,
  event_type ENUM('discovered','joined','left','name_changed','rejoined') NOT NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  effective_at DATETIME NULL,
  username VARCHAR(120) NULL,
  previous_username VARCHAR(120) NULL,
  new_username VARCHAR(120) NULL,
  profile_status ENUM('pending','active','closed','unknown') NULL,
  profile_checked_at DATETIME NULL,
  cycle_no BIGINT UNSIGNED NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'green_roster',
  metadata_json MEDIUMTEXT NULL,
  UNIQUE KEY uq_g_member_event_key (event_key),
  KEY idx_g_member_events_time (detected_at,event_id),
  KEY idx_g_member_events_identity (identity_id,event_id),
  KEY idx_g_member_events_profile (profile_status,event_type,detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
