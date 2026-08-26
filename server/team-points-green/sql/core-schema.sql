CREATE TABLE IF NOT EXISTS p2k_g_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  mode ENUM('seeding','quick','deep') NOT NULL DEFAULT 'seeding',
  stage VARCHAR(64) NOT NULL DEFAULT 'not_started',
  cycle_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cycle_kind VARCHAR(24) NULL,
  cycle_started_at DATETIME NULL,
  cycle_completed_at DATETIME NULL,
  seed_started_at DATETIME NULL,
  seed_completed_at DATETIME NULL,
  discovery_high_watermark BIGINT UNSIGNED NULL,
  index_anchor_match_id BIGINT UNSIGNED NULL,
  deep_scan_from BIGINT UNSIGNED NULL,
  deep_scan_to BIGINT UNSIGNED NULL,
  deep_scan_cursor BIGINT UNSIGNED NULL,
  last_index_fetch DATETIME NULL,
  last_roster_fetch DATETIME NULL,
  last_worker_start DATETIME NULL,
  last_worker_finish DATETIME NULL,
  last_worker_status VARCHAR(32) NULL,
  last_analytics_rebuild DATETIME NULL,
  last_error TEXT NULL,
  worker_target ENUM('blue','green','both','paused') NOT NULL DEFAULT 'blue',
  client_ingest_target ENUM('blue','green','both','off') NOT NULL DEFAULT 'blue',
  force_mode ENUM('auto','seeding','quick','deep') NOT NULL DEFAULT 'auto',
  public_read_target ENUM('blue','green') NOT NULL DEFAULT 'blue',
  migration_phase ENUM('blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary') NOT NULL DEFAULT 'blue_primary',
  gab_status ENUM('not_started','running','ready','error') NOT NULL DEFAULT 'not_started',
  gab_phase VARCHAR(64) NOT NULL DEFAULT 'not_started',
  gab_started_at DATETIME NULL,
  gab_completed_at DATETIME NULL,
  gab_last_error TEXT NULL,
  gffl_enabled TINYINT(1) NOT NULL DEFAULT 1,
  gffl_target_freshness_seconds INT UNSIGNED NOT NULL DEFAULT 1200,
  compat_analytics_rebuilt_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_gab_lanes (
  lane_key VARCHAR(64) NOT NULL PRIMARY KEY,
  label VARCHAR(160) NOT NULL,
  priority INT NOT NULL,
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  total_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  processed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  changed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cursor_json MEDIUMTEXT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_gab_status (status,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_gab_external_work (
  work_key VARCHAR(190) NOT NULL PRIMARY KEY,
  kind VARCHAR(48) NOT NULL,
  url VARCHAR(500) NOT NULL,
  status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  retry_after DATETIME NULL,
  last_http_status INT NULL,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_gab_external (status,retry_after,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_gffl_match_debt (
  match_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  reasons_json MEDIUMTEXT NULL,
  obligation_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
  coalesced_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_served_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_gffl_due (status,due_at,priority,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_phase_progress (
  cycle_no BIGINT UNSIGNED NOT NULL,
  phase_key VARCHAR(64) NOT NULL,
  label VARCHAR(160) NOT NULL,
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  completed_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_update_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  detail_json MEDIUMTEXT NULL,
  PRIMARY KEY (cycle_no,phase_key),
  KEY idx_g_phase_cycle (cycle_no,status,phase_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_cycles (
  cycle_no BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cycle_kind VARCHAR(24) NOT NULL,
  mode VARCHAR(24) NOT NULL,
  status ENUM('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
  stage VARCHAR(64) NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  request_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  changed_objects BIGINT UNSIGNED NOT NULL DEFAULT 0,
  summary_json MEDIUMTEXT NULL,
  KEY idx_g_cycles_status (status,cycle_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_invocations (
  invocation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cycle_no BIGINT UNSIGNED NULL,
  mode VARCHAR(24) NULL,
  stage_start VARCHAR(64) NULL,
  stage_finish VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  runtime_ms INT UNSIGNED NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary_json MEDIUMTEXT NULL,
  KEY idx_g_inv_cycle (cycle_no,invocation_id),
  KEY idx_g_inv_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_request_metrics (
  cycle_no BIGINT UNSIGNED NOT NULL,
  request_type VARCHAR(40) NOT NULL,
  source VARCHAR(24) NOT NULL,
  outcome VARCHAR(24) NOT NULL,
  request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (cycle_no,request_type,source,outcome),
  KEY idx_g_req_type (request_type,cycle_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_findings (
  match_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  imported_at DATETIME NOT NULL,
  source_sha256 CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_matches (
  match_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  api_url VARCHAR(255) NOT NULL,
  web_url VARCHAR(255) NULL,
  name VARCHAR(255) NULL,
  opponent_name VARCHAR(255) NULL,
  opponent_url VARCHAR(255) NULL,
  status ENUM('unknown','registered','in_progress','finished','cancelled','not_club','unavailable') NOT NULL DEFAULT 'unknown',
  index_bucket VARCHAR(24) NULL,
  index_time_class VARCHAR(32) NULL,
  index_result VARCHAR(24) NULL,
  club_verified TINYINT(1) NOT NULL DEFAULT 0,
  verified_club_slug VARCHAR(120) NULL,
  club_side VARCHAR(32) NULL,
  scoring_eligible TINYINT(1) NOT NULL DEFAULT 0,
  exclusion_reason VARCHAR(64) NULL,
  trusted_legacy TINYINT(1) NOT NULL DEFAULT 0,
  fact_source VARCHAR(32) NOT NULL DEFAULT 'api',
  rules VARCHAR(32) NULL,
  time_class VARCHAR(32) NULL,
  time_control VARCHAR(64) NULL,
  start_epoch BIGINT NULL,
  end_epoch BIGINT NULL,
  board_count INT UNSIGNED NULL,
  p2k_score DECIMAL(10,2) NULL,
  opponent_score DECIMAL(10,2) NULL,
  result ENUM('win','draw','loss','none') NOT NULL DEFAULT 'none',
  competition_points INT NOT NULL DEFAULT 0,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  discovery_findings TINYINT(1) NOT NULL DEFAULT 0,
  discovery_index TINYINT(1) NOT NULL DEFAULT 0,
  discovery_deep TINYINT(1) NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NULL,
  last_http_status INT NULL,
  last_observed_at DATETIME NULL,
  last_verified_at DATETIME NULL,
  retry_after DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_match_status (status,match_id),
  KEY idx_g_match_retry (retry_after,status),
  KEY idx_g_match_current (index_bucket,index_time_class,status,last_verified_at),
  KEY idx_g_match_eligibility (club_verified,time_class,scoring_eligible,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_players (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chess_player_id BIGINT UNSIGNED NULL,
  username VARCHAR(120) NOT NULL,
  username_key VARCHAR(120) NOT NULL,
  current_member TINYINT(1) NOT NULL DEFAULT 0,
  joined_epoch BIGINT NULL,
  left_at DATETIME NULL,
  account_status VARCHAR(40) NULL,
  country_url VARCHAR(255) NULL,
  avatar_url VARCHAR(512) NULL,
  daily_rating INT NULL,
  chess960_rating INT NULL,
  profile_checked_at DATETIME NULL,
  stats_checked_at DATETIME NULL,
  last_seen_roster_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_g_username (username_key),
  UNIQUE KEY uq_g_player_id (chess_player_id),
  KEY idx_g_member (current_member,username_key),
  KEY idx_g_stats_due (stats_checked_at,current_member)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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

CREATE TABLE IF NOT EXISTS p2k_g_player_aliases (
  chess_player_id BIGINT UNSIGNED NOT NULL,
  username_key VARCHAR(120) NOT NULL,
  username VARCHAR(120) NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (chess_player_id,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS p2k_g_identity_map (
  username_key VARCHAR(120) NOT NULL PRIMARY KEY,
  username VARCHAR(120) NOT NULL,
  canonical_username_key VARCHAR(120) NOT NULL,
  canonical_username VARCHAR(120) NOT NULL,
  chess_player_id BIGINT UNSIGNED NULL,
  component_key VARCHAR(190) NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'green_observation',
  trusted TINYINT(1) NOT NULL DEFAULT 0,
  source_ref VARCHAR(190) NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_identity_canonical (canonical_username_key),
  KEY idx_g_identity_player (chess_player_id),
  KEY idx_g_identity_component (component_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_identity_edges (
  old_username_key VARCHAR(120) NOT NULL,
  new_username_key VARCHAR(120) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'trusted',
  source VARCHAR(40) NOT NULL DEFAULT 'blue_miac_trusted',
  source_ref VARCHAR(190) NULL,
  evidence_json MEDIUMTEXT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(old_username_key,new_username_key),
  KEY idx_g_identity_edge_new(new_username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_identity_imports (
  import_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(40) NOT NULL,
  source_table VARCHAR(190) NULL,
  detected_shape VARCHAR(80) NULL,
  mapping_count INT UNSIGNED NOT NULL DEFAULT 0,
  edge_count INT UNSIGNED NOT NULL DEFAULT 0,
  source_hash CHAR(64) NULL,
  details_json MEDIUMTEXT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_g_identity_imported(imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_match_players (
  match_id BIGINT UNSIGNED NOT NULL,
  username_key VARCHAR(120) NOT NULL,
  username VARCHAR(120) NOT NULL,
  is_p2k TINYINT(1) NOT NULL,
  board_no INT UNSIGNED NULL,
  board_url VARCHAR(255) NULL,
  played_as_white VARCHAR(32) NULL,
  played_as_black VARCHAR(32) NULL,
  start_rating INT NULL,
  hint_hash CHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(match_id,username_key),
  KEY idx_g_match_player_board(match_id,board_no),
  KEY idx_g_match_player_user(username_key,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_boards (
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL,
  board_url VARCHAR(255) NOT NULL,
  state ENUM('unknown','in_progress','finished','cancelled','unavailable') NOT NULL DEFAULT 'unknown',
  white_username VARCHAR(120) NULL,
  black_username VARCHAR(120) NULL,
  start_epoch BIGINT NULL,
  end_epoch BIGINT NULL,
  p2k_board_score DECIMAL(6,2) NULL,
  opponent_board_score DECIMAL(6,2) NULL,
  finished_game_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hint_hash CHAR(64) NULL,
  payload_hash CHAR(64) NULL,
  needs_refresh TINYINT(1) NOT NULL DEFAULT 1,
  last_http_status INT NULL,
  last_verified_at DATETIME NULL,
  retry_after DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (match_id,board_no),
  UNIQUE KEY uq_g_board_url (board_url),
  KEY idx_g_board_work (needs_refresh,state,retry_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_games (
  game_url VARCHAR(255) NOT NULL PRIMARY KEY,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL,
  game_index TINYINT UNSIGNED NOT NULL,
  white_username VARCHAR(120) NULL,
  black_username VARCHAR(120) NULL,
  white_rating INT NULL,
  black_rating INT NULL,
  white_result VARCHAR(32) NULL,
  black_result VARCHAR(32) NULL,
  start_epoch BIGINT NULL,
  end_epoch BIGINT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_g_game_match (match_id,board_no),
  KEY idx_g_game_end (end_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_point_events (
  game_url VARCHAR(255) NOT NULL,
  username_key VARCHAR(120) NOT NULL,
  username VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL,
  points DECIMAL(6,2) NOT NULL DEFAULT 0,
  result VARCHAR(32) NULL,
  event_epoch BIGINT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (game_url,username_key),
  KEY idx_g_points_player (username_key,event_epoch),
  KEY idx_g_points_match (match_id,board_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_work_claims (
  object_type VARCHAR(32) NOT NULL,
  object_key VARCHAR(190) NOT NULL,
  claimed_by VARCHAR(80) NULL,
  claim_until DATETIME NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  last_http_status INT NULL,
  last_error VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (object_type,object_key),
  KEY idx_g_claim_due (object_type,next_retry_at,claim_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_quick_board_cycles (
  cycle_no BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  initialized_at DATETIME NOT NULL,
  total_items INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_quick_board_cycle_items (
  cycle_no BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL,
  admitted_hint_hash CHAR(64) NULL,
  status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  claim_count INT NOT NULL DEFAULT 0,
  requeued_for_next TINYINT(1) NOT NULL DEFAULT 0,
  terminal_http_status INT NULL,
  first_claimed_at DATETIME NULL,
  last_claimed_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(cycle_no,match_id,board_no),
  KEY idx_g_qbc_pending(cycle_no,status,match_id,board_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_g_observation_metrics (
  cycle_no BIGINT UNSIGNED NOT NULL,
  source VARCHAR(32) NOT NULL,
  object_type VARCHAR(32) NOT NULL,
  accepted BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ignored_stale BIGINT UNSIGNED NOT NULL DEFAULT 0,
  changed BIGINT UNSIGNED NOT NULL DEFAULT 0,
  worker_requests_avoided BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (cycle_no,source,object_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_g_state(club_slug,mode,stage,worker_target,client_ingest_target)
VALUES('promote-to-king','seeding','not_started','blue','blue');
