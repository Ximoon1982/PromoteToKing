-- Post-v2.9.7 staging migration: separate browser-observed freshness from server-verified freshness.
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS player_matches_observed_at DATETIME NULL AFTER player_matches_checked_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS player_matches_unverified_since DATETIME NULL AFTER player_matches_observed_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS stats_observed_at DATETIME NULL AFTER stats_checked_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS stats_unverified_since DATETIME NULL AFTER stats_observed_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_daily_rating INT UNSIGNED NULL AFTER stats_unverified_since;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_chess960_rating INT UNSIGNED NULL AFTER observed_daily_rating;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_rating_source VARCHAR(32) NULL AFTER observed_chess960_rating;
ALTER TABLE p2k_tp_members ADD INDEX IF NOT EXISTS idx_tp_members_observed_refresh (club_slug,current_member,player_matches_observed_at,stats_observed_at,member_id);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(11);
