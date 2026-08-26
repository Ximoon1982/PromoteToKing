# Migration notes — v2.10.6.12

No database, schema, routing or CRON migration is required.

v2.10.6.12 changes only the authenticated Dashboard Admin shell and its browser release generation. Existing administration tools and server endpoints remain in place and are reused through deep links/live reads.

Installation over **v2.10.6.11** therefore consists only of replacing the validated incremental source files. Existing databases, Green/Blue routing state, scheduled tasks, credentials, local configuration and runtime state are preserved.

After installation, hard-refresh `ui-v2.html` once so the browser loads the v2.10.6.12 Dashboard JS/CSS generation.
