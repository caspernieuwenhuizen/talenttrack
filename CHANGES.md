# TalentTrack v4.12.0 — Lookup canonical-language drift audit + admin review tool (closes #987)

Follow-up data fix to v4.11.0's lookup admin rework. The new 5-locale translation grid that shipped in #985 only renders correctly when `tt_lookups.name` carries the stable English internal key and `tt_translations` carries the per-locale display label. On pilot installs the `name` column has drifted into a mixed-language vocabulary (some rows in Dutch, some in lowercase English, some canonical), because earlier operator workflows let admins type anything into `name` and never populated `tt_translations`. This ship is the data fix: the architecture is fine, the data needs to be normalised.

## Pilot symptom

Opening any lookup category in the Configuration admin shows a vocabulary that is half Dutch, half English: some `name` values were entered as `Aanwezig` / `Wedstrijd` / `K`, others as `present` / `match` / `GK`. Looks unprofessional and inconsistent. The new 5-locale grid from v4.11.0 needs the column to be canonical English for the display layer to work as designed.

## Why this is a data fix, not a schema fix

The architecture already supports the right contract:

- `tt_lookups.name` is the stable English internal key.
- `tt_translations` carries per-row, per-locale display labels.
- `LookupTranslator::name($row)` resolves through translations -> gettext -> raw name.

No schema change is needed. The data drifted; v4.12.0 normalises it under operator control, then v4.11.0's display layer is consistent everywhere.

## What ships

**PHP** - `src/Modules/Configuration/LookupCanonicalSeeds.php` (new)

Single source of truth for the canonical English values per `lookup_type`. The map is assembled from every seed migration that has shipped (0001, 0027, 0033, 0037, 0042, 0047, 0048, 0051, 0058, 0060, 0091, 0093, 0098, 0110-0117, 0124). Three callable surfaces:

- `canonicalFor( $lookup_type )` returns the allowed values list — used by the migration to decide if a row is canonical and by the REST controller to defensively reject typos at accept time.
- `suggestCanonicalFor( $lookup_type, $current_name )` runs the heuristic chain: direct hit on the Dutch -> English reverse map (built from migration 0060's seed pairs), then case-insensitive match against the canonical list, then whitespace / punctuation forgiveness for drifts like `gk` -> `GK`.
- `detectLocaleForValue( $lookup_type, $current_name )` picks a plausible source locale for the drifted value: nl_NL for a hit in the reverse map, the site locale for a value containing non-ASCII letters in the Latin Extended-A range, en_US for lowercase-only ASCII drifts.

**Schema audit** - `database/migrations/0132_lookup_canonical_normalisation.php` (new)

Walks every row in `tt_lookups`, cross-checks `name` against the canonical map. For every drifted row, writes an entry to `tt_audit_log` with `action = lookup.needs_review`, `entity_type = lookup`, `entity_id = <row id>` and a JSON payload carrying the lookup_type, the current value, the suggested canonical, the detected source locale, and the full canonical option list for the operator to choose from. Idempotent: re-running the migration on an already-audited install skips rows whose entity_id already has an open review entry (no double-logging). The migration never auto-renames anything — every accepted rewrite goes through the human-in-the-loop admin tool. Rows whose `lookup_type` is not in the canonical map are intentionally not flagged (we would rather under-flag than spam operators with rows we cannot suggest a fix for; future migrations can extend the seed map).

**Frontend view** - `src/Shared/Frontend/FrontendLookupNormalisationView.php` (new)

Reachable via `?tt_view=lookup-normalisation`. Cap gate `tt_access_frontend_admin` — mirrors `FrontendConfigurationView`'s own gate. Server-rendered queue of pending review rows (filtered via a NOT EXISTS join so resolved rows do not surface). Each card shows the current value, a dropdown of canonical options pre-selected to the migration's suggestion, and a locale dropdown defaulting to the heuristic-detected source. Footer carries Skip + Accept buttons. Mobile-first per CLAUDE.md §2: 360px base, 640px breakpoint, all interactive targets >= 48px tall.

The view's vanilla-JS handler is inline (small enough to inline; the view is admin-rare). On click, posts to the REST controller, swaps the row's class to `is-applied` / `is-skipped` / `is-error`, surfaces the message in a `role="status" aria-live="polite"` slot for screen readers. No JS framework dependency.

**REST** - `src/Infrastructure/REST/LookupNormalisationRestController.php` (new)

Two endpoints under the existing `talenttrack/v1` namespace:

- `POST /lookup-normalisation/{audit_id}/accept` — rewrites `tt_lookups.name` to the chosen canonical (defaults to the migration's suggestion; operator may override via `canonical` body param), and upserts the drifted value as a `tt_translations` entry for the detected or operator-chosen locale (`locale` body param). Defensively rejects canonical values that are not in the per-`lookup_type` allowlist — operator cannot typo a fresh drift back into the column. Writes a follow-up `lookup.normalisation.applied` audit entry.
- `POST /lookup-normalisation/{audit_id}/skip` — leaves the row as-is. Writes a follow-up `lookup.normalisation.skipped` audit entry.

Both endpoints check `current_user_can( 'tt_access_frontend_admin' )` and refuse to act on rows that have already been resolved (idempotent under double-click / replay).

**Configuration tile** - `src/Shared/Frontend/FrontendConfigurationView.php`

New "Lookup canonical-language review" tile in the Configuration grid, gated on `tt_access_frontend_admin` AND conditionally rendered only while the pending-count is > 0. Description carries the count via `_n()` so it reads `1 lookup row drifted...` or `12 lookup rows drifted...`. Tile disappears the moment the queue empties.

New private helper `pendingLookupDriftCount()` runs the same NOT EXISTS query the view uses, scoped to `CurrentClub::id()`.

**Wiring**

- `src/Shared/Frontend/DashboardShortcode.php` — `dispatchAdminView()` learns the `lookup-normalisation` slug.
- `src/Modules/Configuration/ConfigurationModule.php` — registers the new REST controller alongside the existing lookup + audit-log controllers.

## What is not in scope

- Hardcoded string literals across the codebase (e.g. `WHERE status = 'pending'`) — that is issue B, filed separately.
- Renaming integer FK columns — the architecture is fine.
- Retrofitting v3.x record-creation flows to the wizard pattern (separate forward-only policy per CLAUDE.md §3).

## Version + i18n

- `talenttrack.php`: TT_VERSION 4.7.0 -> 4.12.0; plugin header Version: 4.12.0. (Reconciles the on-disk version constant with the actual ship cadence — v4.11.0's PR landed without bumping the constant.)
- `readme.txt`: Stable tag 4.7.0 -> 4.12.0; Changelog stanza prepended.
- `languages/talenttrack-nl_NL.po`: 8 new msgids covering the view's labels + the tile description. No duplicate msgids.

## Definition of done

- Migration runs cleanly on a fresh install (no `tt_lookups` rows -> no-op) and on the pilot install (writes audit rows for every drifted value).
- Cleanup tool surfaces every flagged row; operator can review + accept or skip.
- After every accept, the production `tt_lookups.name` is canonical English; the drifted value lives in `tt_translations` for the chosen locale; dashboards render the locale label via the unchanged translator chain.
- Cap gate honoured: non-admin users get the "not authorized" early-return.
- Mobile-first: view renders at 360px without horizontal scroll; all interactive targets >= 48px.
- No schema change beyond audit-log writes.
