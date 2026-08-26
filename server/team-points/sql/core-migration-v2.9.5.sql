-- Promote to King v2.9.5 additive Core migration.
-- Persist opponent country and paired board ratings required by the new
-- global-opposition and rating-upset achievement families.
ALTER TABLE p2k_tp_opponents
  ADD COLUMN IF NOT EXISTS country_code VARCHAR(16) NULL AFTER club_url;

ALTER TABLE p2k_tp_boards
  ADD COLUMN IF NOT EXISTS p2k_rating SMALLINT UNSIGNED NULL AFTER board_no,
  ADD COLUMN IF NOT EXISTS opponent_rating SMALLINT UNSIGNED NULL AFTER p2k_rating,
  ADD COLUMN IF NOT EXISTS rating_source VARCHAR(24) NULL AFTER opponent_rating,
  ADD COLUMN IF NOT EXISTS rating_captured_at DATETIME NULL AFTER rating_source;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(10);
