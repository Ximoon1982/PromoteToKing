# Runtime logs

Match Assistant usage is stored as one JSONL file per UTC day:

```text
logs/match-assistant/YYYY-MM-DD.jsonl
```

Authorized league-match tracking executions are stored separately:

```text
logs/scheduled-tasks/YYYY-MM-DD.jsonl
```

Each scheduled-task entry records the UTC start and end timestamps, duration, scheduled/manual trigger type, success/partial/error status, and recorded/skipped/failed match counts. Fatal upstream errors are recorded before the endpoint returns an error. Invalid cron-token requests are not task executions and are not logged.

Raw log files are denied as static content. Use the Administration **Logs** and **Scheduled tasks** tabs to explore them.
