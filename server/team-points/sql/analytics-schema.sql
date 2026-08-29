-- Promote to King v2.8.0 Analytics schema (fresh installation only)
-- Rebuildable/materialized data. The Core DB remains authoritative.

CREATE TABLE IF NOT EXISTS p2k_analytics_schema_version (
  version INT NOT NULL PRIMARY KEY,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_an_player_totals (
  club_slug VARCHAR(120) NOT NULL,username_key VARCHAR(80) NOT NULL,username VARCHAR(80) NOT NULL,current_member TINYINT(1) NOT NULL DEFAULT 0,
  points DECIMAL(14,1) NOT NULL DEFAULT 0,matches BIGINT UNSIGNED NOT NULL DEFAULT 0,games BIGINT UNSIGNED NOT NULL DEFAULT 0,
  wins BIGINT UNSIGNED NOT NULL DEFAULT 0,draws BIGINT UNSIGNED NOT NULL DEFAULT 0,losses BIGINT UNSIGNED NOT NULL DEFAULT 0,
  daily_rating INT UNSIGNED NULL,chess960_rating INT UNSIGNED NULL,rating_updated_at DATETIME NULL,
  first_game_at DATETIME NULL,last_game_at DATETIME NULL,last_standard_game_at DATETIME NULL,last_chess960_game_at DATETIME NULL,updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key),KEY idx_an_player_points(club_slug,current_member,points,username_key),KEY idx_an_player_last(club_slug,last_game_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Materialized one-row-per-match projection for all aggregate Insights. The copy is
-- intentionally rebuildable; detailed board/game facts remain only in Core.
CREATE TABLE IF NOT EXISTS p2k_an_match_facts (
  club_slug VARCHAR(120) NOT NULL,match_id BIGINT UNSIGNED NOT NULL,match_name VARCHAR(255) NOT NULL DEFAULT '',match_url VARCHAR(255) NULL,
  status ENUM('unknown','registered','in_progress','finished') NOT NULL DEFAULT 'unknown',rules VARCHAR(32) NULL,time_control VARCHAR(32) NULL,is_league TINYINT(1) NOT NULL DEFAULT 0,
  start_time DATETIME NULL,end_time DATETIME NULL,duration_seconds BIGINT UNSIGNED NULL,board_count INT UNSIGNED NOT NULL DEFAULT 0,
  p2k_score DECIMAL(12,1) NOT NULL DEFAULT 0,opponent_score DECIMAL(12,1) NOT NULL DEFAULT 0,p2k_avg_rating SMALLINT UNSIGNED NULL,opponent_avg_rating SMALLINT UNSIGNED NULL,rated_board_count INT UNSIGNED NULL,max_rating SMALLINT UNSIGNED NULL,first_discovered_at DATETIME NULL,result ENUM('unknown','win','draw','loss') NOT NULL DEFAULT 'unknown',
  competition_points INT UNSIGNED NOT NULL DEFAULT 0,is_void TINYINT(1) NOT NULL DEFAULT 0,opponent_slug VARCHAR(160) NULL,opponent_name VARCHAR(255) NULL,opponent_url VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL,PRIMARY KEY(club_slug,match_id),KEY idx_an_match_status(club_slug,status,start_time),KEY idx_an_match_end(club_slug,end_time),
  KEY idx_an_match_dimensions(club_slug,rules,time_control,is_league),KEY idx_an_match_opponent(club_slug,opponent_slug,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compact monthly player facts power member activity/rank charts without repeatedly
-- scanning the Core game table. Points are kept as x2 integers internally.
CREATE TABLE IF NOT EXISTS p2k_an_player_monthly (
  club_slug VARCHAR(120) NOT NULL,username_key VARCHAR(80) NOT NULL,username VARCHAR(80) NOT NULL,month_start DATE NOT NULL,
  points_x2 BIGINT UNSIGNED NOT NULL DEFAULT 0,matches BIGINT UNSIGNED NOT NULL DEFAULT 0,games BIGINT UNSIGNED NOT NULL DEFAULT 0,
  wins BIGINT UNSIGNED NOT NULL DEFAULT 0,draws BIGINT UNSIGNED NOT NULL DEFAULT 0,losses BIGINT UNSIGNED NOT NULL DEFAULT 0,
  first_game_at DATETIME NULL,last_game_at DATETIME NULL,updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key,month_start),KEY idx_an_player_month(club_slug,month_start,username_key),KEY idx_an_month_points(club_slug,month_start,points_x2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_club_totals (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,finished_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,finished_boards BIGINT UNSIGNED NOT NULL DEFAULT 0,
  finished_games BIGINT UNSIGNED NOT NULL DEFAULT 0,club_points BIGINT UNSIGNED NOT NULL DEFAULT 0,won_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  drawn_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,lost_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL,
  KEY idx_tp_club_totals_updated(updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_insight_daily (
  club_slug VARCHAR(120) NOT NULL,activity_date DATE NOT NULL,matches_started INT UNSIGNED NOT NULL DEFAULT 0,matches_finished INT UNSIGNED NOT NULL DEFAULT 0,
  boards_started BIGINT UNSIGNED NOT NULL DEFAULT 0,boards_finished BIGINT UNSIGNED NOT NULL DEFAULT 0,games_finished BIGINT UNSIGNED NOT NULL DEFAULT 0,
  unique_players INT UNSIGNED NOT NULL DEFAULT 0,club_points BIGINT UNSIGNED NOT NULL DEFAULT 0,computed_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,activity_date),KEY idx_tp_insight_daily_date(activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_insight_cache_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,source_updated_at DATETIME NULL,refreshed_at DATETIME NOT NULL,row_count INT UNSIGNED NOT NULL DEFAULT 0,last_error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_an_opponent_stats (
  club_slug VARCHAR(120) NOT NULL,opponent_slug VARCHAR(160) NOT NULL,display_name VARCHAR(255) NOT NULL,club_url VARCHAR(255) NULL,disabled TINYINT(1) NOT NULL DEFAULT 0,
  matches BIGINT UNSIGNED NOT NULL DEFAULT 0,finished BIGINT UNSIGNED NOT NULL DEFAULT 0,wins BIGINT UNSIGNED NOT NULL DEFAULT 0,draws BIGINT UNSIGNED NOT NULL DEFAULT 0,losses BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ongoing BIGINT UNSIGNED NOT NULL DEFAULT 0,registered BIGINT UNSIGNED NOT NULL DEFAULT 0,total_boards BIGINT UNSIGNED NOT NULL DEFAULT 0,
  our_points DECIMAL(16,1) NOT NULL DEFAULT 0,their_points DECIMAL(16,1) NOT NULL DEFAULT 0,
  first_match_at DATETIME NULL,last_match_at DATETIME NULL,updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,opponent_slug),KEY idx_an_opp_matches(club_slug,matches,opponent_slug),KEY idx_an_opp_name(club_slug,display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS p2k_an_achievement_unlocks (
  club_slug VARCHAR(120) NOT NULL,username_key VARCHAR(80) NOT NULL,achievement_key VARCHAR(120) NOT NULL,
  earned_at DATETIME NULL,earned_at_precision VARCHAR(24) NOT NULL DEFAULT 'first-recorded',source_type VARCHAR(32) NULL,source_name VARCHAR(255) NULL,source_url VARCHAR(500) NULL,first_recorded_at DATETIME NOT NULL,last_verified_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key,achievement_key),KEY idx_an_achievement_key(club_slug,achievement_key,earned_at),KEY idx_an_achievement_user(club_slug,username_key,earned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live/MCA rank domain belongs to Analytics because it can be rebuilt from uploaded files.
CREATE TABLE IF NOT EXISTS p2k_lr_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,club_slug VARCHAR(120) NOT NULL,original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(255) NOT NULL,
  sha256 CHAR(64) NOT NULL,size_bytes BIGINT UNSIGNED NOT NULL,uploaded_at DATETIME NOT NULL,replaced_at DATETIME NULL,
  arena_id BIGINT UNSIGNED NULL,arena_slug VARCHAR(255) NULL,event_url VARCHAR(500) NULL,csv_url VARCHAR(500) NULL,source_origin VARCHAR(16) NOT NULL DEFAULT 'manual',source_fetched_at DATETIME NULL,
  actual_event_date DATE NULL,effective_event_date DATE NULL,event_date_precision ENUM('known','interpolated','upload-fallback') NOT NULL DEFAULT 'upload-fallback',event_date_updated_at DATETIME NULL,
  status ENUM('uploaded','processed','error') NOT NULL DEFAULT 'uploaded',row_count INT UNSIGNED NOT NULL DEFAULT 0,p2k_row_count INT UNSIGNED NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,last_error TEXT NULL,UNIQUE KEY uq_lr_file_name(club_slug,original_name),UNIQUE KEY uq_lr_file_arena(club_slug,arena_id),KEY idx_lr_file_hash(club_slug,sha256),KEY idx_lr_files_status(club_slug,status,uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_players (
  club_slug VARCHAR(120) NOT NULL,username_key VARCHAR(80) NOT NULL,username VARCHAR(80) NOT NULL,total_points DECIMAL(14,2) NOT NULL DEFAULT 0,
  arena_count INT UNSIGNED NOT NULL DEFAULT 0,total_games BIGINT UNSIGNED NULL,total_wins BIGINT UNSIGNED NULL,total_draws BIGINT UNSIGNED NULL,total_losses BIGINT UNSIGNED NULL,
  best_streak INT UNSIGNED NULL,max_wins_single_arena INT UNSIGNED NULL,max_games_single_arena INT UNSIGNED NULL,best_rank INT UNSIGNED NULL,
  first_place_count INT UNSIGNED NOT NULL DEFAULT 0,top3_count INT UNSIGNED NOT NULL DEFAULT 0,top10_count INT UNSIGNED NOT NULL DEFAULT 0,best_score DECIMAL(14,2) NULL,current_member TINYINT(1) NOT NULL DEFAULT 0,
  account_state ENUM('current_member','pending_profile','former_member','closed_account','possible_renamed') NOT NULL DEFAULT 'pending_profile',
  profile_checked_at DATETIME NULL,last_error TEXT NULL,source_files_json MEDIUMTEXT NULL,identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,identity_resolution VARCHAR(32) NOT NULL DEFAULT 'self',updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key),KEY idx_lr_players_points(club_slug,total_points,username_key),KEY idx_lr_players_state(club_slug,account_state,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_processing_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,status ENUM('idle','running','completed','failed') NOT NULL DEFAULT 'idle',phase VARCHAR(40) NOT NULL DEFAULT 'idle',
  total_files INT UNSIGNED NOT NULL DEFAULT 0,processed_files INT UNSIGNED NOT NULL DEFAULT 0,total_players INT UNSIGNED NOT NULL DEFAULT 0,checked_players INT UNSIGNED NOT NULL DEFAULT 0,
  possible_renamed INT UNSIGNED NOT NULL DEFAULT 0,closed_accounts INT UNSIGNED NOT NULL DEFAULT 0,identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,identity_stale TINYINT(1) NOT NULL DEFAULT 0,started_at DATETIME NULL,updated_at DATETIME NOT NULL,finished_at DATETIME NULL,last_error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS p2k_lr_sync_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  status ENUM('idle','running','completed','failed') NOT NULL DEFAULT 'idle',
  phase VARCHAR(40) NOT NULL DEFAULT 'idle',
  total_events INT UNSIGNED NOT NULL DEFAULT 0,
  checked_events INT UNSIGNED NOT NULL DEFAULT 0,
  csv_found INT UNSIGNED NOT NULL DEFAULT 0,
  csv_added INT UNSIGNED NOT NULL DEFAULT 0,
  dates_added INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  current_arena_id BIGINT UNSIGNED NULL,
  current_arena_slug VARCHAR(255) NULL,
  current_stage VARCHAR(32) NULL,
  high_water_arena_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_request_at DATETIME(6) NULL,
  last_scan_at DATETIME NULL,
  next_scan_at DATETIME NULL,
  rebuild_required TINYINT(1) NOT NULL DEFAULT 0,
  arena_backfill_seeded_at DATETIME NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  last_error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_sync_queue (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  arena_slug VARCHAR(255) NOT NULL,
  arena_url VARCHAR(500) NOT NULL,
  csv_url VARCHAR(500) NOT NULL,
  stage ENUM('page','csv','done') NOT NULL DEFAULT 'page',
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  needs_csv TINYINT(1) NOT NULL DEFAULT 1,
  needs_date TINYINT(1) NOT NULL DEFAULT 1,
  event_start_at DATETIME NULL,
  event_date DATE NULL,
  discovered_at DATETIME NOT NULL,
  fetched_at DATETIME NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id),
  KEY idx_lr_sync_queue_status(club_slug,status,stage,arena_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_stats (
  club_slug VARCHAR(120) NOT NULL,file_id BIGINT UNSIGNED NOT NULL,original_name VARCHAR(255) NOT NULL,participant_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_points DECIMAL(14,2) NOT NULL DEFAULT 0,first_places INT UNSIGNED NOT NULL DEFAULT 0,second_places INT UNSIGNED NOT NULL DEFAULT 0,third_places INT UNSIGNED NOT NULL DEFAULT 0,
  processed_at DATETIME NOT NULL,PRIMARY KEY(club_slug,file_id),KEY idx_lr_arena_points(club_slug,total_points),KEY idx_lr_arena_participants(club_slug,participant_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_source_rows (
  club_slug VARCHAR(120) NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  source_row_no INT UNSIGNED NOT NULL,
  raw_username_key VARCHAR(80) NOT NULL,
  raw_username VARCHAR(80) NOT NULL,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  games INT UNSIGNED NULL,wins INT UNSIGNED NULL,draws INT UNSIGNED NULL,losses INT UNSIGNED NULL,
  streak INT UNSIGNED NULL,max_wins INT UNSIGNED NULL,max_games INT UNSIGNED NULL,rank_value INT UNSIGNED NULL,
  source_event_key VARCHAR(320) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,file_id,source_row_no),
  KEY idx_lr_source_user(club_slug,raw_username_key),
  KEY idx_lr_source_event(club_slug,source_event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_attributions (
  club_slug VARCHAR(120) NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  source_row_no INT UNSIGNED NOT NULL,
  raw_username_key VARCHAR(80) NOT NULL,
  raw_username VARCHAR(80) NOT NULL,
  canonical_username_key VARCHAR(80) NOT NULL,
  canonical_username VARCHAR(80) NOT NULL,
  resolution_reason VARCHAR(32) NOT NULL,
  identity_map_generation BIGINT UNSIGNED NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  attributed_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,file_id,source_row_no),
  KEY idx_lr_attr_canonical(club_slug,canonical_username_key),
  KEY idx_lr_attr_raw(club_slug,raw_username_key),
  KEY idx_lr_attr_generation(club_slug,identity_map_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Tiny, long-lived capacity history. One daily sample, displayed as month-end values.
CREATE TABLE IF NOT EXISTS p2k_an_storage_samples (
  sample_date DATE NOT NULL PRIMARY KEY,core_bytes BIGINT UNSIGNED NULL,analytics_bytes BIGINT UNSIGNED NULL,cache_bytes BIGINT UNSIGNED NULL,
  logs_bytes BIGINT UNSIGNED NULL,archive_bytes BIGINT UNSIGNED NULL,other_runtime_bytes BIGINT UNSIGNED NULL,measured_at DATETIME NOT NULL,
  core_quota_bytes BIGINT UNSIGNED NULL,analytics_quota_bytes BIGINT UNSIGNED NULL,filesystem_quota_bytes BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_analytics_initialization (
  initialization_id CHAR(36) NOT NULL PRIMARY KEY,snapshot_epoch BIGINT UNSIGNED NOT NULL,manifest_sha256 CHAR(64) NOT NULL,
  source_core_watermark VARCHAR(120) NULL,initialized_at DATETIME NOT NULL,tool_version VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_an_refresh_state (
  club_slug VARCHAR(120) NOT NULL,domain_key VARCHAR(64) NOT NULL,source_watermark VARCHAR(120) NULL,refreshed_at DATETIME NOT NULL,row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,last_error TEXT NULL,
  PRIMARY KEY(club_slug,domain_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v2.10.9 Analytics schema 10: durable MCA arena acquisition + club/player/game facts.
CREATE TABLE IF NOT EXISTS p2k_lr_arena_acquisition (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  arena_slug VARCHAR(255) NOT NULL,
  arena_url VARCHAR(500) NOT NULL,
  csv_url VARCHAR(500) NULL,
  source_kind VARCHAR(20) NOT NULL DEFAULT 'historical',
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  stage VARCHAR(24) NOT NULL DEFAULT 'arena',
  results_source VARCHAR(20) NOT NULL DEFAULT 'unknown',
  needs_clubs TINYINT(1) NOT NULL DEFAULT 1,
  needs_players TINYINT(1) NOT NULL DEFAULT 1,
  arena_page_done TINYINT(1) NOT NULL DEFAULT 0,
  advertised_players INT UNSIGNED NULL,
  max_eligible_scorers INT UNSIGNED NULL,
  arena_rating VARCHAR(80) NULL,
  clubs_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  clubs_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  players_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  players_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pairings_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pairings_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  date_index_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  game_count INT UNSIGNED NOT NULL DEFAULT 0,
  event_start_at DATETIME NULL,
  event_date DATE NULL,
  date_precision VARCHAR(32) NOT NULL DEFAULT 'unknown',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  discovered_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id),
  KEY idx_lr_arena_acq_work(club_slug,status,priority,arena_id),
  KEY idx_lr_arena_acq_stage(club_slug,stage,arena_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_clubs (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  result_key VARCHAR(255) NOT NULL,
  result_club VARCHAR(255) NOT NULL,
  result_club_slug VARCHAR(255) NULL,
  rank_value INT UNSIGNED NULL,
  total_players INT UNSIGNED NOT NULL DEFAULT 0,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  source_kind VARCHAR(12) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,result_key),
  KEY idx_lr_arena_clubs_rank(club_slug,arena_id,rank_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_players (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  rank_value INT UNSIGNED NULL,
  rating INT UNSIGNED NULL,
  result_club VARCHAR(255) NULL,
  result_club_slug VARCHAR(255) NULL,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  wins INT UNSIGNED NULL,
  draws INT UNSIGNED NULL,
  losses INT UNSIGNED NULL,
  byes INT UNSIGNED NULL,
  streak INT UNSIGNED NULL,
  most_wins INT UNSIGNED NULL,
  source_kind VARCHAR(12) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,username_key),
  KEY idx_lr_arena_players_rank(club_slug,arena_id,rank_value),
  KEY idx_lr_arena_players_club(club_slug,arena_id,result_club_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_games (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  game_url VARCHAR(500) NOT NULL,
  white_username_key VARCHAR(80) NOT NULL,
  white_username VARCHAR(80) NOT NULL,
  white_rating INT UNSIGNED NULL,
  black_username_key VARCHAR(80) NOT NULL,
  black_username VARCHAR(80) NOT NULL,
  black_rating INT UNSIGNED NULL,
  result VARCHAR(12) NOT NULL,
  source_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,game_id),
  KEY idx_lr_arena_games_white(club_slug,white_username_key),
  KEY idx_lr_arena_games_black(club_slug,black_username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(1);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(2);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(4);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(5);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(6);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(8);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(9);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(10);
