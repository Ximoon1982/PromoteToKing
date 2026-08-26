-- v2.8.11: finite Member Points convergence and due-aware ACAMR/player reconciliation.
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS player_matches_checked_at DATETIME NULL AFTER rating_updated_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS stats_checked_at DATETIME NULL AFTER player_matches_checked_at;
ALTER TABLE p2k_tp_members ADD INDEX IF NOT EXISTS idx_tp_members_refresh_due (club_slug,current_member,player_matches_checked_at,stats_checked_at,member_id);
-- Existing rating_updated_at values are authoritative evidence that a Chess.com stats fetch succeeded.
-- Reuse that evidence so deployment does not manufacture a full-roster stats backlog.
UPDATE p2k_tp_members SET stats_checked_at=rating_updated_at WHERE stats_checked_at IS NULL AND rating_updated_at IS NOT NULL;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(8);
UPDATE p2k_control_tasks SET label='Member Points',expected_interval_seconds=600 WHERE task_key='team-points-player';
