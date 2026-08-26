-- Promote to King v2.8.0 Core schema (fresh installation only)
-- Canonical chess facts + short-lived operational state. No HTTP payload cache,
-- seed staging, Insights, Live Ranks, or historical analytics are stored here.

CREATE TABLE IF NOT EXISTS p2k_core_schema_version (
  version INT NOT NULL PRIMARY KEY,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_core_clubs (
  club_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_core_club_slug (club_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p2k_core_clubs(club_slug,display_name)
VALUES ('promote-to-king','Promote to King')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

-- Member strings live once here; high-volume board/game tables reference member_id.
CREATE TABLE IF NOT EXISTS p2k_tp_members (
  member_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  player_id BIGINT UNSIGNED NULL,
  current_member TINYINT(1) NOT NULL DEFAULT 1,
  joined_at DATETIME NULL,
  daily_rating INT UNSIGNED NULL,
  chess960_rating INT UNSIGNED NULL,
  rating_updated_at DATETIME NULL,
  player_matches_checked_at DATETIME NULL,
  player_matches_observed_at DATETIME NULL,
  player_matches_passive_observed_at DATETIME NULL,
  player_matches_unverified_since DATETIME NULL,
  stats_checked_at DATETIME NULL,
  stats_observed_at DATETIME NULL,
  stats_passive_observed_at DATETIME NULL,
  stats_unverified_since DATETIME NULL,
  observed_daily_rating INT UNSIGNED NULL,
  observed_chess960_rating INT UNSIGNED NULL,
  observed_rating_source VARCHAR(32) NULL,
  profile_observed_at DATETIME NULL,
  observed_avatar_url VARCHAR(500) NULL,
  observed_profile_url VARCHAR(500) NULL,
  observed_country_code VARCHAR(16) NULL,
  observed_profile_status VARCHAR(40) NULL,
  avatar_url VARCHAR(500) NULL,
  profile_url VARCHAR(500) NULL,
  country_code VARCHAR(16) NULL,
  profile_status VARCHAR(40) NULL,
  avatar_checked_at DATETIME NULL,
  profile_updated_at DATETIME NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_tp_member (club_slug,username_key),
  KEY idx_tp_members_current (club_slug,current_member,username_key),
  KEY idx_tp_members_player_id (club_slug,player_id),
  KEY idx_tp_members_refresh_due (club_slug,current_member,player_matches_checked_at,stats_checked_at,member_id),
  KEY idx_tp_members_observed_refresh (club_slug,current_member,player_matches_observed_at,stats_observed_at,member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  core_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  members_last_observed_at DATETIME NULL,
  members_last_verified_at DATETIME NULL,
  members_last_observed_count INT UNSIGNED NULL,
  members_count_observed_at DATETIME NULL,
  club_index_last_observed_at DATETIME NULL,
  club_index_last_verified_at DATETIME NULL,
  club_index_registered_observed INT UNSIGNED NULL,
  club_index_in_progress_observed INT UNSIGNED NULL,
  club_index_finished_observed INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p2k_tp_state(club_slug,core_generation)
VALUES ('promote-to-king',1)
ON DUPLICATE KEY UPDATE club_slug=VALUES(club_slug);

CREATE TABLE IF NOT EXISTS p2k_tp_opponents (
  opponent_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  opponent_slug VARCHAR(160) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  club_url VARCHAR(255) NULL,
  country_code VARCHAR(16) NULL,
  disabled TINYINT(1) NOT NULL DEFAULT 0,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_checked_at DATETIME NULL,
  icon_url VARCHAR(500) NULL,
  icon_checked_at DATETIME NULL,
  profile_updated_at DATETIME NULL,
  last_error TEXT NULL,
  UNIQUE KEY uq_tp_opponent (club_slug,opponent_slug),
  KEY idx_tp_opponents_name (club_slug,display_name),
  KEY idx_tp_opponents_disabled (club_slug,disabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_opponent_aliases (
  club_slug VARCHAR(120) NOT NULL,
  alias_slug VARCHAR(160) NOT NULL,
  canonical_slug VARCHAR(160) NOT NULL,
  alias_name VARCHAR(255) NULL,
  detected_at DATETIME NOT NULL,
  PRIMARY KEY (club_slug,alias_slug),
  KEY idx_tp_opponent_alias_canonical (club_slug,canonical_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per Chess.com match. Result/competition points are canonical final facts,
-- eliminating a second permanent match-summary copy in Core.
CREATE TABLE IF NOT EXISTS p2k_tp_match_metadata (
  club_slug VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  match_name VARCHAR(255) NOT NULL DEFAULT '',
  match_url VARCHAR(255) NULL,
  status ENUM('unknown','registered','in_progress','finished') NOT NULL DEFAULT 'unknown',
  observed_status ENUM('unknown','registered','in_progress','finished') NULL,
  rules VARCHAR(32) NULL,
  time_control VARCHAR(32) NULL,
  is_league TINYINT(1) NOT NULL DEFAULT 0,
  start_time DATETIME NULL,
  end_time DATETIME NULL,
  board_count INT UNSIGNED NOT NULL DEFAULT 0,
  p2k_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  opponent_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  p2k_avg_rating SMALLINT UNSIGNED NULL,
  opponent_avg_rating SMALLINT UNSIGNED NULL,
  rated_board_count INT UNSIGNED NULL,
  max_rating SMALLINT UNSIGNED NULL,
  first_discovered_at DATETIME NULL,
  result ENUM('unknown','win','draw','loss') NOT NULL DEFAULT 'unknown',
  competition_points INT UNSIGNED NOT NULL DEFAULT 0,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  opponent_slug VARCHAR(160) NULL,
  opponent_name VARCHAR(255) NULL,
  opponent_url VARCHAR(255) NULL,
  discovery_source VARCHAR(40) NOT NULL DEFAULT 'unknown',
  last_verified_at DATETIME NULL,
  last_observed_at DATETIME NULL,
  last_index_seen_at DATETIME NULL,
  next_detail_check_at DATETIME NULL,
  finalized_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (club_slug,match_id),
  KEY idx_tp_match_metadata_opponent (club_slug,opponent_slug,status),
  KEY idx_tp_match_metadata_status (club_slug,status,match_id),
  KEY idx_tp_match_detail_due (club_slug,status,next_detail_check_at),
  KEY idx_tp_match_time (club_slug,start_time,end_time),
  KEY idx_tp_match_result (club_slug,result,end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Participation and board state are one physical row. board_no replaces a repeated
-- ~50-byte API URL in the normal case; board_url_override is only for unusual URLs.
CREATE TABLE IF NOT EXISTS p2k_tp_boards (
  board_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL,
  p2k_rating SMALLINT UNSIGNED NULL,
  opponent_rating SMALLINT UNSIGNED NULL,
  opponent_username VARCHAR(80) NULL,
  rating_source VARCHAR(24) NULL,
  rating_captured_at DATETIME NULL,
  board_url_override VARCHAR(255) NULL,
  white_result VARCHAR(40) NULL,
  black_result VARCHAR(40) NULL,
  source_bucket ENUM('unknown','registered','in_progress','finished','rediscovered') NOT NULL DEFAULT 'unknown',
  state ENUM('newly_discovered','recent_in_progress','potentially_incomplete','complete_immutable','failed_malformed') NOT NULL DEFAULT 'newly_discovered',
  finished_game_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_discovered_at DATETIME NOT NULL,
  last_discovered_at DATETIME NOT NULL,
  last_checked_at DATETIME NULL,
  next_check_at DATETIME NULL,
  completed_at DATETIME NULL,
  failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  UNIQUE KEY uq_tp_board_member_match (member_id,match_id),
  UNIQUE KEY uq_tp_board_match_number (match_id,board_no),
  KEY idx_tp_board_due (state,next_check_at),
  KEY idx_tp_board_member_due (member_id,state,next_check_at),
  KEY idx_tp_board_opponent_username (opponent_username,match_id),
  CONSTRAINT fk_tp_board_member FOREIGN KEY (member_id) REFERENCES p2k_tp_members(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One compact row per finished game. Board -> member/match relations are not repeated.
-- points_x2 stores 0/1/2 rather than DECIMAL and source_hash uses BINARY(32).
CREATE TABLE IF NOT EXISTS p2k_tp_games (
  game_row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  board_id BIGINT UNSIGNED NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NULL,
  game_url_override VARCHAR(255) NULL,
  game_end_utc DATETIME NOT NULL,
  result_code VARCHAR(40) NOT NULL,
  points_x2 TINYINT UNSIGNED NOT NULL,
  source_hash BINARY(32) NULL,
  verified_at DATETIME NOT NULL,
  is_seed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tp_game_board_sequence (board_id,sequence_no),
  UNIQUE KEY uq_tp_game_chess_id (game_id),
  KEY idx_tp_game_end (game_end_utc),
  KEY idx_tp_game_board_end (board_id,game_end_utc),
  CONSTRAINT fk_tp_game_board FOREIGN KEY (board_id) REFERENCES p2k_tp_boards(board_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility read views keep the existing service-layer payload contracts while
-- the physical representation remains compact.
DROP VIEW IF EXISTS p2k_tp_participations;
CREATE VIEW p2k_tp_participations AS
SELECT m.club_slug, u.username_key, u.username, b.match_id, m.match_url,
       COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no)) AS board_url,
       b.white_result,b.black_result,b.first_discovered_at AS first_seen_at,b.last_discovered_at AS last_seen_at
FROM p2k_tp_boards b
JOIN p2k_tp_members u ON u.member_id=b.member_id
JOIN p2k_tp_match_metadata m ON m.match_id=b.match_id AND m.club_slug=u.club_slug;

DROP VIEW IF EXISTS p2k_tp_board_states;
CREATE VIEW p2k_tp_board_states AS
SELECT u.club_slug,u.username_key,u.username,b.match_id,
       COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no)) AS board_url,
       b.source_bucket,b.state,b.finished_game_count,b.first_discovered_at,b.last_discovered_at,
       b.last_checked_at,b.next_check_at,b.completed_at,b.failure_count,b.last_error
FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id;

DROP VIEW IF EXISTS p2k_tp_point_events;
CREATE VIEW p2k_tp_point_events AS
SELECT u.club_slug,u.username_key,u.username,b.match_id,
       COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no)) AS board_url,
       COALESCE(g.game_url_override,
                CASE WHEN g.game_id IS NOT NULL THEN CONCAT('https://www.chess.com/game/daily/',g.game_id)
                     ELSE CONCAT(COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no)),'#seed-game-',g.sequence_no) END) AS game_url,
       g.game_end_utc,CAST(DATE_FORMAT(g.game_end_utc,'%Y-%m-01') AS DATE) AS utc_month,
       g.result_code,(g.points_x2/2.0) AS points,
       CASE WHEN g.source_hash IS NULL THEN NULL ELSE LOWER(HEX(g.source_hash)) END AS source_hash,
       g.verified_at
FROM p2k_tp_games g
JOIN p2k_tp_boards b ON b.board_id=g.board_id
JOIN p2k_tp_members u ON u.member_id=b.member_id;

DROP VIEW IF EXISTS p2k_tp_match_summaries;
CREATE VIEW p2k_tp_match_summaries AS
SELECT club_slug,match_id,board_count,(board_count*2) AS game_count,p2k_score AS team_score,
       CASE WHEN p2k_score>opponent_score THEN 'win' WHEN p2k_score<opponent_score THEN 'loss' ELSE 'draw' END AS result,
       CASE WHEN p2k_score>opponent_score THEN 5*board_count WHEN p2k_score=opponent_score THEN 2*board_count ELSE 0 END AS competition_points,
       COALESCE(finalized_at,end_time,last_verified_at) AS finalized_at,updated_at
FROM p2k_tp_match_metadata
WHERE status='finished' AND is_void=0 AND board_count>0 AND (p2k_score<>0 OR opponent_score<>0);

DROP VIEW IF EXISTS p2k_tp_void_matches;
CREATE VIEW p2k_tp_void_matches AS
SELECT club_slug,match_id,board_count,'finished_0_0_draw' AS reason,'core' AS source,
       COALESCE(finalized_at,last_verified_at) AS imported_at
FROM p2k_tp_match_metadata WHERE status='finished' AND is_void=1;

-- Operational tables: bounded by housekeeping rather than retained forever.
CREATE TABLE IF NOT EXISTS p2k_tp_jobs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  job_type VARCHAR(40) NOT NULL,
  status ENUM('new','running','paused','completed','failed','cancelled') NOT NULL DEFAULT 'new',
  stop_requested TINYINT(1) NOT NULL DEFAULT 0,
  processed_items INT UNSIGNED NOT NULL DEFAULT 0,
  total_items INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,started_at DATETIME NULL,updated_at DATETIME NOT NULL,finished_at DATETIME NULL,last_error TEXT NULL,
  KEY idx_tp_jobs_status (club_slug,status,updated_at), KEY idx_tp_jobs_finished (status,finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_job_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id CHAR(36) NOT NULL,item_type VARCHAR(40) NOT NULL,item_key VARCHAR(255) NOT NULL,
  canonical_scope VARCHAR(120) NOT NULL DEFAULT '',canonical_key VARCHAR(190) NOT NULL DEFAULT '',priority_rank SMALLINT NOT NULL DEFAULT 100,
  generation INT UNSIGNED NOT NULL DEFAULT 1,requested_generation INT UNSIGNED NOT NULL DEFAULT 1,
  requested_item_type VARCHAR(40) NULL,requested_item_key VARCHAR(255) NULL,requested_payload_json MEDIUMTEXT NULL,
  coalesced_count INT UNSIGNED NOT NULL DEFAULT 0,last_requested_at DATETIME NULL,
  active_dedupe_key VARCHAR(320) GENERATED ALWAYS AS (CASE WHEN status IN ('pending','running','retry') AND canonical_scope<>'' AND canonical_key<>'' THEN CONCAT(canonical_scope,'|',canonical_key) ELSE NULL END) STORED,
  payload_json MEDIUMTEXT NULL,
  status ENUM('pending','running','done','retry','failed','skipped') NOT NULL DEFAULT 'pending',attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,locked_at DATETIME NULL,updated_at DATETIME NOT NULL,last_error TEXT NULL,
  KEY idx_tp_job_item_legacy(job_id,item_type,item_key),KEY idx_tp_job_canonical(canonical_scope,canonical_key,status),
  KEY idx_tp_job_priority(job_id,status,priority_rank,available_at,id),KEY idx_tp_job_queue (job_id,status,available_at,id),KEY idx_tp_job_items_retention(status,updated_at),
  UNIQUE KEY uq_tp_active_canonical(active_dedupe_key),
  CONSTRAINT fk_tp_job_item_job FOREIGN KEY(job_id) REFERENCES p2k_tp_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_worker_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,job_id CHAR(36) NULL,trigger_type VARCHAR(20) NOT NULL,
  started_at DATETIME NOT NULL,finished_at DATETIME NULL,processed_items INT UNSIGNED NOT NULL DEFAULT 0,result_status VARCHAR(30) NOT NULL,message TEXT NULL,
  KEY idx_tp_worker_runs_time(started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_job_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,job_id CHAR(36) NULL,worker_run_id BIGINT UNSIGNED NULL,level VARCHAR(12) NOT NULL DEFAULT 'info',
  task_type VARCHAR(40) NOT NULL,item_key VARCHAR(255) NULL,message TEXT NOT NULL,context_json MEDIUMTEXT NULL,created_at DATETIME NOT NULL,
  KEY idx_tp_job_logs_job_time(job_id,created_at,id),KEY idx_tp_job_logs_run(worker_run_id,id),KEY idx_tp_job_logs_retention(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_cron_state (
  task_key VARCHAR(64) NOT NULL PRIMARY KEY,chain_id CHAR(36) NOT NULL,lease_until DATETIME NOT NULL,last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,next_run_at DATETIME NULL,last_status VARCHAR(30) NULL,last_message TEXT NULL,updated_at DATETIME NOT NULL,
  KEY idx_tp_cron_next_run(next_run_at),KEY idx_tp_cron_lease(lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_match_discovery_state (
  club_slug VARCHAR(120) NOT NULL,source_key VARCHAR(64) NOT NULL,cursor_match_id BIGINT UNSIGNED NULL,lower_bound_match_id BIGINT UNSIGNED NULL,
  upper_bound_match_id BIGINT UNSIGNED NULL,last_success_match_id BIGINT UNSIGNED NULL,scanned_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  matched_count BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL,PRIMARY KEY(club_slug,source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_audit_jobs (
  id CHAR(36) NOT NULL PRIMARY KEY,club_slug VARCHAR(120) NOT NULL,mode ENUM('arithmetic','former','full') NOT NULL,
  status ENUM('running','paused','completed','cancelled') NOT NULL DEFAULT 'paused',cursor_match_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_candidates BIGINT UNSIGNED NOT NULL DEFAULT 0,checked_count BIGINT UNSIGNED NOT NULL DEFAULT 0,issue_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,completed_at DATETIME NULL,last_error TEXT NULL,
  KEY idx_tp_audit_jobs(club_slug,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_audit_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,job_id CHAR(36) NOT NULL,club_slug VARCHAR(120) NOT NULL,match_id BIGINT UNSIGNED NOT NULL,
  issue_type VARCHAR(48) NOT NULL,proposed_action ENUM('remove','review') NOT NULL DEFAULT 'remove',reason TEXT NOT NULL,details_json MEDIUMTEXT NULL,
  status ENUM('pending','repaired','ignored') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_tp_audit_issue(job_id,match_id,issue_type),KEY idx_tp_audit_pending(job_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_consistency_repairs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,repair_run_id CHAR(36) NOT NULL,club_slug VARCHAR(120) NOT NULL,match_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(24) NOT NULL,reason TEXT NULL,before_json MEDIUMTEXT NULL,after_json MEDIUMTEXT NULL,created_at DATETIME NOT NULL,
  KEY idx_tp_consistency_run(repair_run_id,id),KEY idx_tp_consistency_match(club_slug,match_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_control_tasks (
  task_key VARCHAR(80) NOT NULL PRIMARY KEY,label VARCHAR(160) NOT NULL,expected_interval_seconds INT UNSIGNED NOT NULL,
  legacy_endpoint VARCHAR(500) NULL,maintenance_url VARCHAR(500) NULL,status ENUM('idle','queued','running','paused','failed') NOT NULL DEFAULT 'idle',
  pause_requested TINYINT(1) NOT NULL DEFAULT 0,last_started_at DATETIME NULL,last_completed_at DATETIME NULL,last_success_at DATETIME NULL,
  next_due_at DATETIME NULL,last_message VARCHAR(500) NULL,details_json MEDIUMTEXT NULL,updated_at DATETIME NOT NULL,
  KEY idx_control_due(status,next_due_at),KEY idx_control_success(last_success_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_control_task_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,run_id VARCHAR(120) NOT NULL,task_key VARCHAR(80) NOT NULL,
  source ENUM('cron','manual','scheduler','legacy') NOT NULL DEFAULT 'scheduler',status ENUM('running','success','partial','failed','paused','skipped') NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL,ended_at DATETIME NULL,processed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,message VARCHAR(500) NULL,details_json MEDIUMTEXT NULL,
  UNIQUE KEY uq_control_run(run_id),KEY idx_control_runs_task(task_key,started_at),KEY idx_control_runs_status(status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_control_task_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,task_key VARCHAR(80) NOT NULL,run_id VARCHAR(120) NULL,
  level ENUM('debug','info','success','warning','error') NOT NULL DEFAULT 'info',component VARCHAR(80) NOT NULL DEFAULT 'controller',
  message VARCHAR(500) NOT NULL,context_json MEDIUMTEXT NULL,created_at DATETIME NOT NULL,
  KEY idx_control_logs_task(task_key,created_at),KEY idx_control_logs_level(level,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p2k_control_tasks(task_key,label,expected_interval_seconds,legacy_endpoint,maintenance_url,status,next_due_at,updated_at)
VALUES
 ('team-points-club','Club Points',300,'server/team-points/public/cron-club.php','TaskControl.html?task=team-points-club#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('team-points-player','Member Points',600,'server/team-points/public/cron-player.php','TaskControl.html?task=team-points-player#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('team-points','Team Points (legacy)',300,'server/team-points/public/cron.php','TaskControl.html?task=team-points-club#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('match-tracking','Match monitoring',3600,'api/track-upcoming-league-matches/','TaskControl.html?task=match-tracking#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('tournaments','Tournaments',600,'server/tournaments/public/cron.php','TaskControl.html?task=tournaments#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE label=VALUES(label),expected_interval_seconds=VALUES(expected_interval_seconds),legacy_endpoint=VALUES(legacy_endpoint),maintenance_url=VALUES(maintenance_url);

CREATE TABLE IF NOT EXISTS p2k_core_initialization (
  initialization_id CHAR(36) NOT NULL PRIMARY KEY,snapshot_epoch BIGINT UNSIGNED NOT NULL,manifest_sha256 CHAR(64) NOT NULL,
  members BIGINT UNSIGNED NOT NULL,matches BIGINT UNSIGNED NOT NULL,boards BIGINT UNSIGNED NOT NULL,games BIGINT UNSIGNED NOT NULL,
  club_points BIGINT UNSIGNED NOT NULL,initialized_at DATETIME NOT NULL,tool_version VARCHAR(30) NOT NULL,
  KEY idx_core_init_time(initialized_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  seed_format VARCHAR(40) NULL,
  seed_archive_sha256 CHAR(64) NULL,
  seed_imported_at DATETIME NULL,
  map_rebuilt_at DATETIME NULL,
  last_change_reason VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_names (
  name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  player_id BIGINT UNSIGNED NULL,
  joined_epoch BIGINT UNSIGNED NULL,
  current_member TINYINT(1) NOT NULL DEFAULT 0,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  source_flags VARCHAR(255) NULL,
  player_id_checked_at DATETIME NULL,
  hard_conflict TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miac_name(club_slug,username_key),
  KEY idx_miac_player_id(club_slug,player_id),
  KEY idx_miac_current(club_slug,current_member,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_edges (
  edge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  old_username_key VARCHAR(80) NOT NULL,
  new_username_key VARCHAR(80) NOT NULL,
  confidence VARCHAR(24) NOT NULL DEFAULT 'Possible',
  shared_boards INT UNSIGNED NOT NULL DEFAULT 0,
  same_joined TINYINT(1) NOT NULL DEFAULT 0,
  roster_handover TINYINT(1) NOT NULL DEFAULT 0,
  coexists TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('candidate','confirmed','rejected','conflict') NOT NULL DEFAULT 'candidate',
  evidence_source VARCHAR(40) NOT NULL DEFAULT 'seed',
  evidence_json MEDIUMTEXT NULL,
  reviewed_by VARCHAR(80) NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miac_edge(club_slug,old_username_key,new_username_key),
  KEY idx_miac_edge_status(club_slug,status,confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_canonical_map (
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  canonical_username_key VARCHAR(80) NOT NULL,
  canonical_username VARCHAR(80) NOT NULL,
  resolution_reason ENUM('self','player_id','admin_confirmed','hard_conflict') NOT NULL DEFAULT 'self',
  identity_map_generation BIGINT UNSIGNED NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key),
  KEY idx_miac_canonical(club_slug,canonical_username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_pir_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  cursor_match_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at DATETIME NULL,
  last_completed_cycle_at DATETIME NULL,
  checked_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  issues_found BIGINT UNSIGNED NOT NULL DEFAULT 0,
  repairs_queued BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_pir_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  issue_type VARCHAR(64) NOT NULL,
  severity ENUM('warning','error','critical') NOT NULL DEFAULT 'error',
  details_json MEDIUMTEXT NULL,
  status ENUM('open','queued','resolved','ignored') NOT NULL DEFAULT 'open',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_pir_issue(club_slug,match_id,board_id,issue_type),
  KEY idx_pir_open(club_slug,status,last_seen_at),
  KEY idx_pir_match(club_slug,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(1);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(2);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(4);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(5);

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(8);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(9);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(10);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(11);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(12);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(13);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(14);


-- v2.9.22 Fresh Points Reconstruction staging
CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_runs (
  run_id CHAR(36) NOT NULL PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  include_club TINYINT(1) NOT NULL DEFAULT 1,
  include_player TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('running','paused','ready','applying','applied','cancelled','failed') NOT NULL DEFAULT 'running',
  phase VARCHAR(64) NOT NULL DEFAULT 'created',
  phase_label VARCHAR(160) NOT NULL DEFAULT 'Created',
  overall_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  club_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  player_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  metrics_json MEDIUMTEXT NULL,
  opening_roster_count INT UNSIGNED NOT NULL DEFAULT 0,
  closing_roster_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  club_applied_at DATETIME NULL,
  player_applied_at DATETIME NULL,
  last_error TEXT NULL,
  KEY idx_tp_reconstruction_latest (club_slug,created_at),
  KEY idx_tp_reconstruction_status (club_slug,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_matches (
  run_id CHAR(36) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  stage_state ENUM('pending','resolved','unresolved','failed') NOT NULL DEFAULT 'pending',
  source_flags VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'unknown',
  board_count INT UNSIGNED NOT NULL DEFAULT 0,
  p2k_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  opponent_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  excluded_zero_zero TINYINT(1) NOT NULL DEFAULT 0,
  payload_json MEDIUMTEXT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,match_id),
  KEY idx_tp_reconstruction_match_state (run_id,stage_state,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_members (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  joined_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
  opening_member TINYINT(1) NOT NULL DEFAULT 0,
  closing_member TINYINT(1) NOT NULL DEFAULT 0,
  stage_state ENUM('pending','matches_done','archive_fallback','boards_done','complete','unresolved','failed') NOT NULL DEFAULT 'pending',
  points_x2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  metrics_json MEDIUMTEXT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,username_key),
  KEY idx_tp_reconstruction_member_state (run_id,stage_state,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_boards (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL DEFAULT 0,
  board_url VARCHAR(255) NULL,
  source_bucket VARCHAR(24) NOT NULL DEFAULT 'unknown',
  stage_state ENUM('discovered','pending','resolved','unresolved','failed') NOT NULL DEFAULT 'discovered',
  white_result VARCHAR(40) NULL,
  black_result VARCHAR(40) NULL,
  p2k_rating SMALLINT UNSIGNED NULL,
  opponent_rating SMALLINT UNSIGNED NULL,
  points_x2 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  finished_game_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,username_key,match_id),
  KEY idx_tp_reconstruction_board_state (run_id,stage_state,match_id),
  KEY idx_tp_reconstruction_board_match (run_id,match_id,board_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_games (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NULL,
  game_url VARCHAR(255) NULL,
  game_end_utc DATETIME NOT NULL,
  result_code VARCHAR(40) NOT NULL,
  points_x2 TINYINT UNSIGNED NOT NULL,
  source_hash CHAR(64) NULL,
  PRIMARY KEY (run_id,username_key,match_id,sequence_no),
  KEY idx_tp_reconstruction_game_match (run_id,match_id),
  KEY idx_tp_reconstruction_game_end (run_id,game_end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(15);

-- v2.9.22.6 incremental reconciliation audit
CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_actions (
  action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id CHAR(36) NOT NULL,
  scope ENUM('club','player') NOT NULL,
  entity_key VARCHAR(120) NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  queue_superseded INT UNSIGNED NOT NULL DEFAULT 0,
  applied_by VARCHAR(80) NULL,
  applied_at DATETIME NOT NULL,
  KEY idx_tp_reconstruction_action_entity (run_id,scope,entity_key,applied_at),
  KEY idx_tp_reconstruction_action_scope (run_id,scope,applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(16);

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(17);
