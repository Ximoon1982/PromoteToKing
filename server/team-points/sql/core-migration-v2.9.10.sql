-- v2.9.10: universal passive observation provenance + CRON authoritative freshness floors.
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS player_matches_passive_observed_at DATETIME NULL AFTER player_matches_observed_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS stats_passive_observed_at DATETIME NULL AFTER stats_observed_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS profile_observed_at DATETIME NULL AFTER observed_rating_source;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_avatar_url VARCHAR(500) NULL AFTER profile_observed_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_profile_url VARCHAR(500) NULL AFTER observed_avatar_url;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_country_code VARCHAR(16) NULL AFTER observed_profile_url;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS observed_profile_status VARCHAR(40) NULL AFTER observed_country_code;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS members_last_verified_at DATETIME NULL AFTER members_last_observed_at;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS members_last_observed_count INT UNSIGNED NULL AFTER members_last_verified_at;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS members_count_observed_at DATETIME NULL AFTER members_last_observed_count;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS club_index_last_verified_at DATETIME NULL AFTER club_index_last_observed_at;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS club_index_registered_observed INT UNSIGNED NULL AFTER club_index_last_verified_at;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS club_index_in_progress_observed INT UNSIGNED NULL AFTER club_index_registered_observed;
ALTER TABLE p2k_tp_state ADD COLUMN IF NOT EXISTS club_index_finished_observed INT UNSIGNED NULL AFTER club_index_in_progress_observed;
ALTER TABLE p2k_tp_match_metadata MODIFY COLUMN last_verified_at DATETIME NULL;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS observed_status ENUM('unknown','registered','in_progress','finished') NULL AFTER status;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS last_observed_at DATETIME NULL AFTER last_verified_at;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(12);
