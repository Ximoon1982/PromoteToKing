# Migration to v2.10.6.22

No database migration or reset is required. Existing GABCRF state is preserved.

If production is currently stopped on a GABCRF SQLSTATE `40001` / MariaDB 1213 deadlock, the Green worker automatically recognizes that stored transient error after deployment, restores the lane to `running`, and continues from the preserved cursor/pass/counters when worker budget permits.

Hard-refresh `ui-v2.html` once so the updated Dashboard shell and dynamically loaded Dashboard Insights module are loaded. No Green queue reset, GAB restart, or CRON edit is required.
