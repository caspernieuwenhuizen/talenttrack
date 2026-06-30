<!-- audience: admin -->

# Data Browser

The Data Browser is a **read-only** window onto the raw data behind TalentTrack. It lets an administrator browse the contents of the plugin's database tables with friendly column names, see how tables connect, and inspect the actual stored values — without touching wp-admin or SQL.

It is admin transparency / data-audit tooling. It never edits data or definitions, and it is scoped tightly to two capabilities so it never widens who can see player data.

## Who can use it

The Data Browser tile appears under **Administration** for users holding the dedicated `tt_view_data_browser` capability. By default that is:

- **Administrator** (the matrix admin / superuser), and
- **Club Admin** (the academy admin).

No other role — coaches, Head of Development, scouts, parents — sees the tile or the data. The capability is deliberately *not* part of the `tt_view_settings` umbrella, so granting general settings access does not grant the Data Browser.

## What it shows

### Table index (`?tt_view=data-browser`)

A searchable list of every `tt_*` table, split into two groups:

- **Core tables** — the player-centric tables (players, teams, activities, evaluations, goals, attendance, …) that carry hand-written friendly labels and descriptions.
- **Other tables** — everything else, with labels derived automatically from the table name.

Each row shows the friendly label, the real table name, a one-line description, and an approximate row count. Tables holding sensitive data carry a **Sensitive** badge.

The search box matches a table's name, label and description **and its column names**. Typing a column fragment like `minutes`, `club_id` or `uuid` lists every table that has a matching column; when a table surfaces because of a column, the row shows a **matched column** hint naming it, so the result is actionable.

### Table page (`?tt_view=data-browser&table=tt_…`)

- **Semantic column headers** — each column shows a friendly label, the real `column · type`, and (for curated columns) a short description on the `?` marker.
- **Raw rows** — the values exactly as stored, paginated. A search box filters rows across the table's text columns.
- **Connected tables** — a chip row showing which tables this one links to (outgoing, e.g. `team_id → Teams`) and which link back to it (incoming).
- **Clickable foreign keys** — a value like a `team_id` of `3` links straight to that row in the Teams table.

## Sensitive tables

Tables holding medical, safeguarding, or family data about minors (e.g. injuries, parent/guardian links, player notes) are flagged. Opening one shows a warning and writes a `data_browser.view` entry to the audit log recording who looked and which table. The data is still shown — the flag is about accountability, not restriction.

## Tenancy

Every row read is scoped to the active club when the table carries a `club_id` column, so on a future multi-tenant install one academy can never see another's rows.

## REST API

The same data is available read-only through the REST API (the canonical contract; the rendered view is one consumer of it):

| Endpoint | Returns |
|---|---|
| `GET /talenttrack/v1/data-browser/tables` | All browsable tables with labels, descriptions, sensitivity, row counts. |
| `GET /talenttrack/v1/data-browser/tables/{table}/schema` | Columns (with semantic labels) + relationships for one table. |
| `GET /talenttrack/v1/data-browser/tables/{table}/rows?page=&per_page=&q=&pk=` | A page of raw rows. |

Every endpoint requires `tt_view_data_browser` and validates the table name against the live schema before any query runs.

## Limits (v1)

- Read-only — there is no editing of data or schema.
- Relationships are inferred from `*_id` column names (this schema has no SQL foreign keys), so an unusually named link may not be detected.
- The friendly layer covers the core tables in full; other tables fall back to humanised column names. Adding a table to the curated set is a one-block change in `SemanticRegistry` — no migration.
