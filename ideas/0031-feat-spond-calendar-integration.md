<!-- type: feat -->

# Spond integration — pull club training/match calendar into TalentTrack sessions

Origin: 26 April 2026 conversation. Spond is the dominant team-management app for grassroots/youth football in NL/NO/DE/UK — a lot of clubs already run their training schedule, RSVPs, and chat in Spond and don't want to re-enter sessions in TalentTrack. If we can ingest the Spond calendar, the coach gets evaluations + goals + sessions all in one place without losing the existing Spond workflow.

## Why this is interesting

- **Removes a real adoption blocker.** "Do I have to enter every training twice?" is a question every club using Spond will ask. Today the answer is yes; with this feature it's no.
- **One-way is enough for v1.** Spond stays the source of truth for scheduling + RSVPs; TalentTrack reads the schedule and uses it to anchor evaluations + attendance.
- **Cheap to ship.** If Spond exposes an iCal feed (which they do for personal calendars and — per their help docs — for team/group calendars too), v1 is "fetch a URL on a schedule, parse, upsert sessions". No OAuth dance, no partner-API approval, no per-event API calls.

## Working assumption (needs verification during shaping)

Spond exposes a private iCal/.ics URL per **group** (= team in TalentTrack vocabulary). Anyone with the URL can subscribe; the URL is the auth token. Each event in the feed has at least: UID, start/end datetime, summary (title), location, description, organizer, last-modified.

If that holds, v1 is:

1. **One-time setup per team**: paste the Spond group's iCal URL into the team edit form. Stored in `tt_teams.spond_ical_url` (encrypted at rest, since the URL acts as a credential).
2. **Periodic poll (WP-cron, every N minutes)**: fetch each team's iCal feed, parse with the same `Sabre\VObject` library WordPress core already includes (or a tiny custom parser), iterate VEVENTs.
3. **Upsert sessions** by `external_id = "spond:<UID>"`:
   - First time we see a UID → create a `tt_sessions` row with type=Training (or auto-detected from title — "match" / "wedstrijd" → Match; "training" → Training; default Training).
   - Subsequent times with same UID → update title/date/location if changed.
   - UIDs that disappear from the feed → soft-archive the local session (don't hard-delete; coach may have evaluations against it).

## What needs a shaping conversation

Before this becomes a spec, answer:

1. **Direction**: read-only (Spond → TalentTrack) for v1, or also write-back (TalentTrack → Spond)? Write-back almost certainly needs the partner API — out of scope for v1. **Recommendation: read-only.**
2. **Granularity of the URL**: per-team (each TalentTrack team gets its own Spond feed URL) or one club-wide URL with team-detection by event title/group? Per-team is cleaner; club-wide is faster to set up but requires title parsing. **Recommendation: per-team, one URL field on the team edit form.**
3. **Auth posture**: the iCal URL is a bearer credential — anyone with it can read the feed. Storage requires encryption at rest (`tt_config` already does this for API keys via `wp_encrypt`-style helper, or use the same pattern Backup uses for SMTP passwords). Confirm we're OK with that posture vs. requiring OAuth (which would block v1).
4. **Poll frequency**: hourly (gentle, ~24 fetches/team/day)? Every 15 min (snappier, ~96/day)? Manual-only (refresh button on team page)? **Recommendation: hourly default, admin-overridable, plus a "Refresh now" button.**
5. **Match vs Training detection**: regex on title? Add a free-text "match keywords" config? Or add a per-event tag in Spond and rely on that? **Recommendation: simple keyword match in title (case-insensitive: "match", "wedstrijd", "game", "kamp"), default to Training otherwise.**
6. **Conflict handling**: if a coach manually edits a Spond-imported session in TalentTrack and then the Spond event changes, what wins? **Recommendation: Spond wins on date/title/location (the schedule fields); TalentTrack-only fields (notes, attendance, evaluations linked) are preserved.**
7. **Attendance sync**: Spond tracks RSVPs; do we want them as TalentTrack attendance hints? Probably not via iCal (not exposed in standard fields) — would need the REST API. **Recommendation: defer to v2 / Spond-API-partner version.**
8. **Removal handling**: if a Spond event is deleted, do we delete or soft-archive the TalentTrack session? Coaches may have written evaluations against it. **Recommendation: soft-archive (set `archived_at`); only hard-delete if coach confirms.**
9. **Discovery / setup UX**: how does the admin find their Spond iCal URL? It's buried in Spond's settings. **Recommendation: paste-only field with a help link to Spond's "export calendar" docs.**
10. **Multilingual**: TalentTrack is NL-primary, Spond is internationalised. Match-keyword detection must be locale-aware.
11. **Failure mode**: feed unreachable, parse fails, URL revoked. **Recommendation: log to audit (#0021 when it ships) + show a small inline notice on the team edit form ("Last sync failed at … — check the URL").**

## Rough scope (before shaping)

New:
- `database/migrations/<NN>-add-spond-fields.sql` — add `tt_teams.spond_ical_url` (TEXT, encrypted) + `tt_teams.spond_last_sync_at` + `tt_teams.spond_last_sync_status`. Add `tt_sessions.external_id` (VARCHAR(64), nullable, indexed) + `tt_sessions.external_source` (VARCHAR(16), e.g. `spond`).
- `src/Modules/Spond/SpondModule.php` — module shell + WP-cron schedule registration.
- `src/Modules/Spond/SpondClient.php` — fetch + parse iCal feed.
- `src/Modules/Spond/SpondSync.php` — upsert + soft-archive logic.
- `src/Modules/Spond/Admin/SpondHealthNotice.php` — inline notice on team edit form when last sync failed.
- A small WP-CLI command `wp tt spond sync [--team=<id>]` so admins can trigger manually for debugging.
- Docs: `docs/spond-integration.md` + nl_NL counterpart.
- nl_NL.po strings for setup form labels + status messages.

Existing:
- `src/Modules/Teams/Admin/TeamsPage.php` (and the frontend `FrontendTeamsManageView.php` edit form) — add the Spond URL field.
- `src/Shared/Frontend/FrontendSessionsManageView.php` — show a small "from Spond" badge on imported sessions (so coaches know not to edit the date in TalentTrack).

## Out of scope (for v1)

- Two-way write-back (TalentTrack → Spond).
- RSVP / attendance sync (needs the REST API; partner-only).
- Multi-club Spond environments (one Spond org → multiple TalentTrack installs).
- Spond chat / messages integration.
- Auto-discovery of teams (admin pastes one URL per team manually).
- A Spond-branded oauth flow.

## Sequence position (proposed)

Phase 1 follow-on. Modest size (~12-18h estimated). Slot-able after #0026 (guest player attendance) since both touch session UX. Independent of #0027 methodology, #0025 multilingual auto-translate, and the rest of the Ready queue.

## Notes / unknowns to research before shaping

- Confirm Spond's iCal feed exists at the **group/team** level (not just personal). If it's personal-only, the model collapses to "each coach pastes their personal feed and we filter to events they're admin of" — uglier UX.
- Confirm event UIDs are stable across edits (some calendar systems regenerate UIDs on edit, breaking upsert).
- Confirm rate limits / poll-frequency etiquette. Spond's terms may forbid certain auto-poll frequencies.
- Verify Spond's iCal URLs are revokable/regenerable (so a club can rotate the credential if leaked).

## Estimated effort

- v1 (read-only iCal sync, per-team URL): **~12-18 hours**.
  - Schema + migration: 1.5h
  - Client + parser: 3h
  - Sync logic + conflict rules: 4h
  - Team edit form integration: 1.5h
  - Sessions render badge: 1h
  - WP-cron + WP-CLI command: 2h
  - Docs + nl_NL.po: 1.5h
  - Testing against a real Spond feed: 2-4h (most variable; depends on what the feed actually contains)

- v2 (REST API integration with RSVP sync): **~30-45h**, blocked on Spond partner-API access.
