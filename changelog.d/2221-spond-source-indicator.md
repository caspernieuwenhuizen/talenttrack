# Spond source indicator on the activity list and detail (#2221)

Bump: patch

Activities imported from Spond now show their provenance. On the activities
list, a Spond-sourced card carries a small blue **Spond** chip alongside its
type and status pills; manually-created and generated activities show none.
On the activity detail page, Spond-sourced activities show a
**Team last synced from Spond: <time>** line in the audit footer — the
team's most recent Spond sync (the timestamp is team-level, and the label
says so, keeping the freshness claim honest). No schema change: the source
flag and the team sync time already exist. Both `activity_source_key` and
the team's `team_spond_last_sync_at` are exposed on the activity REST
payload so a future front end can render the same chip and freshness line.
