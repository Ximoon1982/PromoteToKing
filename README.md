# Green profile/stats stage stall hotfix — v2.10.1.2

Root cause:
- `nextProfileDue()` selects `current_member=1 AND profile_checked_at IS NULL`.
- The worker only checkpointed HTTP 200.
- A 404/410 therefore remained NULL and was selected again indefinitely.
- `seed_stats` had the identical defect for `stats_checked_at`.
- Transient responses could also be hammered repeatedly during one worker invocation.

Fix:
- 404/410 is terminal for the current initial seed attempt and advances the checkpoint without inventing profile/rating data.
- transient/network/429/5xx responses remain due but stop the current tight loop so a later invocation retries them.
- browser planner diagnostics now expose real profile/stats `due_unhydrated` / `eligible_now` counts instead of misleading 0/0/0.
- no schema change.
- migration version remains 2.10.1.2.

Deploy from the PromoteToKing root:
```bash
chmod +x update-v2.10.1.2-green-profile-stall-hotfix.sh
./update-v2.10.1.2-green-profile-stall-hotfix.sh
```

Optional read-only confirmation before/after:
```bash
php8.5-cli -r '
require getcwd()."/server/team-points-green/src/bootstrap.php";
$r=\P2K\Green\GreenRepository::open();
$s=$r->state();
$q=$r->core->query("SELECT
  SUM(current_member=1 AND profile_checked_at IS NULL) profiles_pending,
  SUM(current_member=1 AND stats_checked_at IS NULL) stats_pending
  FROM p2k_g_players");
print_r(["stage"=>$s["stage"],"pending"=>$q->fetch(PDO::FETCH_ASSOC)]);
'
```
