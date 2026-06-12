<!-- audience: admin, dev -->

# Error log

When something fails inside TalentTrack — a save that doesn't stick, an import that stops halfway — the plugin logs the technical reason. Historically those entries only went to the server's PHP error log, which most operators can't reach without hosting-panel or SSH access.

From v4.20.119 the plugin also keeps its own bounded error log inside the database, with a viewer in wp-admin.

## Where to find it

**TalentTrack → Error Log** in wp-admin (in the Configuration group, next to Migrations).

Access requires the *audit log* read permission (`tt_view_audit_log`) — the same operator group that can read the audit log. Administrators always have access.

## What it shows

Every `error` and `warning` the plugin logs at runtime, newest first:

- **Date** — when it happened (site timezone).
- **Level** — `error` (something failed) or `warning` (something degraded but continued).
- **Message** — the technical event key, e.g. `admin.activity.save.failed`.
- **Context** — expandable details: the database error text, the record id involved, and similar diagnostic values.

Filter by level and date range at the top. The viewer shows the newest 100 matching entries; narrow the date range to see older ones.

## Retention

The log is a rolling buffer: only the **newest 500 entries** are kept. Older rows are pruned automatically on every write — no cron job, no manual cleanup, no unbounded table growth.

The log is diagnostic, not an audit trail. For "who changed what", use the audit log; for schema state, use the Migrations page.

## If the page says the table is missing

The error log table ships with database migration `0155_error_log`. Run pending migrations from **TalentTrack → Migrations** and reload.

## For developers

- Entries are written by `Logger::error()` / `Logger::warning()` — no call-site changes needed; every existing Logger call is captured automatically.
- Persistence can never break the request: a missing table, a down database, or an encoding failure degrades silently to the existing `error_log()` write.
- The same data is available over REST at `GET /wp-json/talenttrack/v1/system/errors` (same capability gate) — see [rest-api.md](rest-api.md).
