# Migration to v2.10.6.21

No database migration or reset is required. This release changes only the
authenticated Admin shell markup/CSS and release cache generation.

After deployment, hard-refresh `ui-v2.html` once so the v2.10.6.21 dashboard
CSS and JavaScript are loaded. All Green runtime state and queues continue
without restart.
