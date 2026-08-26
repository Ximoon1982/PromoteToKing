-- Promote to King v2.8.8 Core migration: cached opponent Chess.com club icons.
ALTER TABLE p2k_tp_opponents
  ADD COLUMN IF NOT EXISTS icon_url VARCHAR(500) NULL AFTER last_checked_at,
  ADD COLUMN IF NOT EXISTS icon_checked_at DATETIME NULL AFTER icon_url,
  ADD COLUMN IF NOT EXISTS profile_updated_at DATETIME NULL AFTER icon_checked_at;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(6);
