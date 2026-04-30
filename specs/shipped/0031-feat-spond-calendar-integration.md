<!-- type: feat -->

# #0031 — Spond calendar integration (read-only iCal sync)

## Problem

Spond is the dominant team-management app for grassroots / youth football in NL/NO/DE/UK. A lot of clubs that adopt TalentTrack already run their training schedule, RSVPs, and chat in Spond and don't want to enter every session in TalentTrack manually.

Today the answer to "do I need to re-enter every training in TalentTrack?" is yes, and that's a real adoption blocker — especially for the grassroots clubs that are the bulk of the target market.

Who feels it: head coach (re-typing the schedule), team manager (correcting it when it drifts), HoD (looking at attendance numbers that omit Spond-only sessions and so reading wrong).

## Proposal

A read-only **Spond → TalentTrack** sync built on Spond's existing per-team iCal feed. One-time setup per team (paste the iCal URL into the team edit form), then a periodic poll fetches the feed, parses VEVENTs with the standard `Sabre\VObject` library, and upserts `tt_activities` rows keyed on `external_id = "spond:<UID>"`.

Spond stays the source of truth for schedule + RSVPs; TalentTrack stays the source of truth for evaluations + goals + attendance + everything else. Coaches see one timeline either way.

Write-back (TalentTrack → Spond) needs Spond's partner API and is deliberately out of scope for v1.

## Scope

### Schema

Migration `0037_spond_integration.php` adds:

- `tt_teams.spond_ical_url TEXT DEFAULT NULL` — encrypted at rest using the same helper #0031 / #0042 will share for credential storage (the URL is a bearer token; anyone holding it can read the feed).
- `tt_teams.spond_last_sync_at DATETIME DEFAULT NULL`.
- `tt_teams.spond_last_sync_status VARCHAR(32) DEFAULT NULL` — one of `ok`, `failed`, `disabled`, `never`.
- `tt_teams.spond_last_sync_message TEXT DEFAULT NULL` — last error/info string for the inline notice.
- `tt_activities.external_id VARCHAR(64) DEFAULT NULL` + `tt_activities.external_source VARCHAR(16) DEFAULT NULL` (e.g. `spond`).
- Index on `(external_source, external_id)`.

`tt_activities` already supports `archived_at`, so soft-archive of removed events reuses that column.

### Module

New `src/Modules/Spond/SpondModule.php` — module shell, registers WP-cron schedule + WP-CLI command + admin notice.

New `src/Modules/Spond/SpondClient.php` — fetches the iCal URL with `wp_remote_get` (10s timeout), validates HTTP 200 + `text/calendar` content-type, returns the raw body.

New `src/Modules/Spond/SpondParser.php` — wraps `Sabre\VObject\Reader` (vendor library, ships with WordPress core's bundled deps; if missing, fall back to a minimal in-house parser handling VEVENT only). Returns an array of normalised event objects: `{ uid, summary, dtstart, dtend, location, description, last_modified }`.

New `src/Modules/Spond/SpondSync.php` — the upsert loop:

1. For each team with a non-empty `spond_ical_url`, fetch + parse.
2. For each VEVENT:
   - Look up `tt_activities WHERE external_id = "spond:<UID>"`.
   - If found, update `activity_date`, `start_time`, `end_time`, `notes` (location + description), `name` (summary). Preserve `attendance` rows + linked evaluations + the `activity_type_key` if a coach has changed it.
   - If not found, insert a new `tt_activities` row with `team_id = <this team>`, `external_id = "spond:<UID>"`, `external_source = "spond"`, and `activity_type_key` resolved by `SpondTypeResolver` (see below).
3. After the loop, find `tt_activities` rows for this team where `external_source = "spond"` and `external_id` is NOT in the fetched UID list → set `archived_at = NOW()`, leave the row otherwise intact (evaluations stay linked).
4. Update `tt_teams.spond_last_sync_at / _status / _message`.

New `src/Modules/Spond/SpondTypeResolver.php` — case-insensitive title-keyword classifier. Built-in keyword list covers NL/EN/DE/UK out of the box: `match` / `wedstrijd` / `game` / `kamp` → `game`; `training` / `trainen` → `training`; default → `training`. **Keyword list lives in code (not configurable per club)** — clubs that need a custom rule can override via the `tt_spond_classify_event` filter. v2 can promote this to a lookup if demand emerges.

New `src/Modules/Spond/Admin/SpondHealthNotice.php` — when `spond_last_sync_status = 'failed'`, render an inline notice on the team edit form: "Last Spond sync failed at … — check the URL". Includes a "Refresh now" button that calls `SpondSync::syncTeam( $team_id )` synchronously and reloads.

### Cron

Default frequency: hourly. Custom interval registered via `cron_schedules` filter (`tt_spond_hourly` = 3600s). Admin can override per install via the `tt_spond_sync_interval_minutes` config (60 default; minimum 15 to play nice with Spond's terms; documented but unenforced).

WP-cron is unreliable on low-traffic sites, so two compensations:

1. The sync also runs lazily when an admin opens the team edit form and `spond_last_sync_at` is more than 2× the configured interval old.
2. WP-CLI: `wp tt spond sync [--team=<id>]` for manual + scripted runs.

### REST

`POST /wp-json/talenttrack/v1/teams/{id}/spond/sync` — manager-only (`tt_edit_teams`); triggers a sync of one team, returns `{ status, fetched_count, created_count, updated_count, archived_count, last_message }`. Cap-gated; nonce-required; no public access.

### UI

**Team edit form** (`FrontendTeamsManageView` + wp-admin `TeamsPage`) gains:

- A "Spond integration" section with a single text input for the iCal URL (`type=url`, `inputmode=url`, `autocomplete=off`).
- A help link pointing to `docs/spond-integration.md` which explains where to find the URL in Spond's settings.
- When set: a status line showing `last_sync_at` + status + a "Refresh now" button.
- The inline failure notice when `last_sync_status = 'failed'`.

**Sessions list / activity rows** — Spond-imported activities get a small badge ("from Spond") that:

- Hints to coaches not to edit the date/title/location in TalentTrack (Spond will overwrite on next sync per the conflict rule).
- Links to the source iCal URL (or just labels — no link if URL is sensitive).

### Conflict rule

Spond wins on **schedule fields** (date, start time, end time, title, location). TalentTrack wins on **everything else** (`activity_type_key` once a coach has set it, attendance rows, linked evaluations, notes added in TalentTrack).

If a coach changes a schedule field on a Spond-imported activity, the next sync will silently overwrite it. The "from Spond" badge is the warning.

### Removal

If a Spond UID disappears from the feed, set `archived_at = NOW()` on the activity. **Do not** delete — coaches may have evaluations attached. Archived activities stop appearing in active lists per the existing archive convention. A coach who wants to permanently delete uses the existing manual delete flow.

If the Spond UID re-appears later (Spond admin un-archives), the next sync clears `archived_at` and the activity is back. Idempotent.

### Encryption + privacy

The iCal URL is a bearer credential. Stored encrypted using the existing TT encryption helper (the same one #0011 uses for license keys + #0013 uses for SMTP creds). Decrypt only when the cron / sync handler reads it. Audit log entries (`#0021`) write the action (`spond_sync_team`) but never the URL itself.

### Failure handling

- Network / DNS fail → `status = failed`, `message = "Could not reach Spond (timeout)"`.
- HTTP 401/403 → `status = failed`, `message = "Spond URL was rejected — has it been revoked?"`.
- HTTP 404 → same as 401/403, different message.
- 200 but body is not iCal → `status = failed`, `message = "Spond returned a non-calendar response"`.
- Parse fails on a single VEVENT → log + skip that event; the rest of the feed still imports.
- Total parse fail → `status = failed`, all events skipped, no archive sweep.

Failures don't retry exponentially in v1 — the next scheduled run handles it. If the URL is revoked, the team admin sees the inline notice and pastes a new URL.

## Out of scope

- **Two-way write-back** (TalentTrack → Spond). Needs partner API. Tracked as v2 / deferred.
- **RSVP / attendance sync.** Spond's iCal feed doesn't expose RSVPs — needs the REST API. Defer to v2.
- **Spond chat / messages.** Wildly out of scope.
- **Multi-club Spond environments** (one Spond org → multiple TalentTrack installs). Single-club only.
- **Auto-discovery of Spond groups.** Admin pastes one URL per team manually; no group-list endpoint via iCal.
- **OAuth flow.** iCal URL = bearer token. The complexity of OAuth blocks v1 and isn't justified by the threat model.
- **Per-club configurable keyword list** for game-vs-training classification. Built-in NL/EN/DE/UK list + filter hook is enough for v1.
- **Configurable archive-vs-delete on UID removal.** Always soft-archive in v1.

## Acceptance criteria

### Setup

- [ ] An admin / head coach with `tt_edit_teams` can paste a Spond iCal URL into the team edit form.
- [ ] The URL is stored encrypted; raw value is never logged or exposed in REST responses.
- [ ] Pressing "Refresh now" runs an immediate sync and shows the result.
- [ ] WP-CLI `wp tt spond sync --team=<id>` produces the same result.

### Sync — happy path

- [ ] On first sync, every VEVENT in the feed becomes a `tt_activities` row with `external_source='spond'` and `external_id='spond:<UID>'`.
- [ ] Activity type is correctly classified per the keyword rule (game vs training).
- [ ] Each row's `team_id` matches the team the URL is on.
- [ ] `tt_teams.spond_last_sync_status = 'ok'` after success.

### Sync — updates

- [ ] On a subsequent sync, schedule changes (date / time / location / title) are reflected on the existing activity row.
- [ ] Coach-set `activity_type_key` is preserved across syncs (i.e. if the coach manually changed a Spond-imported activity from `training` to `game`, that wins).
- [ ] Attendance rows + linked evaluations are not touched.
- [ ] `tt_teams.spond_last_sync_at` advances.

### Sync — removal

- [ ] When a UID disappears from the feed, the corresponding activity row is soft-archived (`archived_at` set).
- [ ] When the same UID reappears later, the row is un-archived (`archived_at = NULL`).
- [ ] Soft-archived activities don't appear in active session lists but evaluations linked to them still resolve.

### Sync — failure modes

- [ ] Unreachable URL → status `failed`, inline notice on team edit form, no data destruction.
- [ ] HTTP 401/403/404 → status `failed`, helpful message ("Spond URL was rejected — has it been revoked?").
- [ ] Parse failure on one event → other events still import.
- [ ] Total parse failure → status `failed`, no archive sweep.

### Permissions

- [ ] Only users with `tt_edit_teams` can set / change / clear the Spond URL.
- [ ] The `POST /teams/{id}/spond/sync` REST endpoint requires the same cap.
- [ ] WP-CLI command requires admin context.

### UI

- [ ] Spond-imported activities render a "from Spond" badge.
- [ ] The team edit form shows last-sync status.
- [ ] The "Refresh now" button works without a page-reload spinner that lasts longer than 5s in normal cases.

### No regression

- [ ] Teams without a Spond URL behave exactly as before.
- [ ] Activities created manually (no `external_source`) are unaffected by sync passes.

## Notes

### Sizing

~12-18 hours total:

- Schema + migration: 1.5h
- SpondClient + SpondParser: 3h
- SpondSync (upsert + soft-archive logic): 4h
- SpondTypeResolver + filter hook: 1h
- Team edit form integration: 1.5h
- Activity-row "from Spond" badge: 0.5h
- WP-cron + WP-CLI command: 2h
- Admin health notice + REST endpoint + nonce wiring: 1.5h
- Docs (`docs/spond-integration.md` + nl_NL counterpart) + nl_NL.po strings: 1.5h
- Testing against a real Spond feed: 2-4h (most variable; depends on what the feed actually contains; verify UID stability across edits, rate limits, error responses).

### Hard decisions locked during shaping

1. **Read-only direction** — write-back is v2 / partner-API.
2. **Per-team URL** — one iCal URL per team, stored on `tt_teams`. No club-wide URL with title parsing.
3. **iCal URL is the credential** — encrypted at rest, no OAuth in v1.
4. **Hourly poll default** — 15-minute minimum. Lazy on team-edit-form access if cron drift > 2× interval.
5. **Keyword-based type detection** — built-in NL/EN/DE/UK list, filter hook for clubs that need to override. No per-club lookup table in v1.
6. **Spond wins schedule fields** — coach-set `activity_type_key`, attendance, evaluations, and TalentTrack-only notes are preserved.
7. **Soft-archive on UID removal** — never hard-delete from a sync.
8. **No RSVP / attendance sync in v1** — partner-API only.

### Cross-references

- **#0021** Audit log — sync runs write `spond_sync_team` audit entries (not the URL itself).
- **#0026** Guest-player attendance — orthogonal; both touch the activity flow.
- **#0035** Sessions → activities rename + typed activities — this spec uses `tt_activities` + `activity_type_key` directly.
- **#0042** Youth-aware contact strategy — shares the encryption-at-rest helper for credentials.
- **v2** A future spec covering REST-API integration with RSVP sync — blocked on Spond partner-API access.

### Things to verify in the first 30 minutes of build

- Does Spond's iCal feed actually exist at the **group/team** level (not just personal)? If it's personal-only, the model collapses to "each coach pastes their personal feed and we filter to events they're admin of" — uglier UX. **If this fails, pause and re-shape.**
- Are event UIDs stable across edits in Spond? Some calendar systems regenerate UIDs on edit, breaking upsert. If yes, fall back to a `(team_id, summary, dtstart)` composite key with collision logic.
- Are Spond's iCal URLs revokable / regenerable? Confirm so the "leaked URL" recovery path works.
- Spond's terms of service on auto-poll frequency — confirm hourly is acceptable.
