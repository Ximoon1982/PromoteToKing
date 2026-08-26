-- Promote to King v2.8.5 Core migration
ALTER TABLE p2k_tp_members
  ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) NULL AFTER rating_updated_at,
  ADD COLUMN IF NOT EXISTS profile_url VARCHAR(500) NULL AFTER avatar_url,
  ADD COLUMN IF NOT EXISTS country_code VARCHAR(16) NULL AFTER profile_url,
  ADD COLUMN IF NOT EXISTS profile_status VARCHAR(40) NULL AFTER country_code,
  ADD COLUMN IF NOT EXISTS avatar_checked_at DATETIME NULL AFTER profile_status,
  ADD COLUMN IF NOT EXISTS profile_updated_at DATETIME NULL AFTER avatar_checked_at;

CREATE TABLE IF NOT EXISTS p2k_tp_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  core_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  members_last_observed_at DATETIME NULL,
  club_index_last_observed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p2k_tp_state(club_slug,core_generation)
SELECT club_slug,1 FROM p2k_core_clubs
ON DUPLICATE KEY UPDATE club_slug=VALUES(club_slug);

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(5);
