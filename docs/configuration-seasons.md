<!-- audience: admin -->

# Configuration — Seasons

**Dashboard → Configuration → Seasons** (`?tt_view=seasons`)

Manage the academy's seasons from the frontend — create, edit, set the current season, and delete unused ones. Previously this lived only in wp-admin; the frontend manager removes that detour. Gated by `tt_edit_settings` (administrator / academy admin by default).

Exactly **one** season is current at a time. PDP files are scoped to a season, and the carryover job runs whenever you change the current season.

## What you can do

| Action | Notes |
| --- | --- |
| **Create** | Name + start date + end date. End must be after start. New seasons are not current until you set them. |
| **Edit** | Fix a name or the dates of any season. The right way to correct a mistake — no need to delete and re-add. |
| **Set current** | Promotes one season to current and demotes the previous one in the same step. Triggers carryover for open PDP files. |
| **Delete** | Only available for a season that is **not current** and has **no linked records**. |

## Why delete is guarded

Seasons are referenced by other records — PDP files and blocks, staff-development goals / evaluations, and VCT schedules. Deleting a season that's in use would orphan those records, so it's blocked:

- The **current** season can't be deleted — set another as current first.
- A season **with linked records** can't be deleted — its row shows **In use** instead of a Delete button. Edit it instead.

Only a genuinely unused season (e.g. one created by mistake) can be removed. The same guard is enforced at the REST layer (`DELETE /wp-json/talenttrack/v1/seasons/{id}` returns `409` with a reason), so a non-WordPress front end gets the same protection.

## REST

The manager is a thin client over the season REST contract:

- `GET /wp-json/talenttrack/v1/seasons` — list + current id (any logged-in user).
- `POST /seasons` — create. `PATCH /seasons/{id}` — edit. `PATCH /seasons/{id}/current` — set current. `DELETE /seasons/{id}` — guarded delete. (All writes require `tt_edit_settings`.)

## See also

- [Configuration — General](configuration-general.md)
- [Configuration and branding](configuration-branding.md)
