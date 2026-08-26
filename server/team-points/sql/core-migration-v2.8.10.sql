-- v2.8.10: canonical finished-match outcomes are derived from authoritative team scores.
-- Defensive normalization: authoritative finished 0-0 matches are traceable voids.
UPDATE p2k_tp_match_metadata
SET is_void=1, result='draw', competition_points=0
WHERE status='finished' AND board_count>0 AND p2k_score=0 AND opponent_score=0;

UPDATE p2k_tp_match_metadata
SET result = CASE
      WHEN p2k_score > opponent_score THEN 'win'
      WHEN p2k_score < opponent_score THEN 'loss'
      ELSE 'draw'
    END,
    competition_points = CASE
      WHEN p2k_score > opponent_score THEN 5 * board_count
      WHEN p2k_score = opponent_score THEN 2 * board_count
      ELSE 0
    END,
    updated_at = updated_at
WHERE status='finished' AND is_void=0 AND board_count>0 AND (p2k_score<>0 OR opponent_score<>0);

DROP VIEW IF EXISTS p2k_tp_match_summaries;
CREATE VIEW p2k_tp_match_summaries AS
SELECT club_slug,match_id,board_count,(board_count*2) AS game_count,p2k_score AS team_score,
       CASE
         WHEN p2k_score > opponent_score THEN 'win'
         WHEN p2k_score < opponent_score THEN 'loss'
         ELSE 'draw'
       END AS result,
       CASE
         WHEN p2k_score > opponent_score THEN 5 * board_count
         WHEN p2k_score = opponent_score THEN 2 * board_count
         ELSE 0
       END AS competition_points,
       COALESCE(finalized_at,end_time,last_verified_at) AS finalized_at,updated_at
FROM p2k_tp_match_metadata
WHERE status='finished' AND is_void=0 AND board_count>0 AND (p2k_score<>0 OR opponent_score<>0);

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(7);


UPDATE p2k_control_tasks SET expected_interval_seconds=600,legacy_endpoint='server/tournaments/public/cron.php',maintenance_url='TaskControl.html?task=tournaments#task-detail' WHERE task_key='tournaments';
INSERT INTO p2k_control_tasks(task_key,label,expected_interval_seconds,legacy_endpoint,maintenance_url,status,next_due_at,updated_at)
VALUES
 ('team-points-club','Club Points',300,'server/team-points/public/cron-club.php','TaskControl.html?task=team-points-club#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('team-points-player','Player Points',1800,'server/team-points/public/cron-player.php','TaskControl.html?task=team-points-player#task-detail','idle',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE label=VALUES(label),expected_interval_seconds=VALUES(expected_interval_seconds),legacy_endpoint=VALUES(legacy_endpoint),maintenance_url=VALUES(maintenance_url);
