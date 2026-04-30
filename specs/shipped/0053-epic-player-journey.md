<!-- type: epic -->

# #0053 — Player journey

## Problem

A player's time at the academy is a *journey* — trial → signed → first season at U13 → position change → injury → return → promotion to U14 → end-of-season verdict → graduation or release. Today the data exists across Evaluations, Goals, PDP verdicts, and (since v3.42.0) Trials. The *journey* doesn't. There's no place a coach, parent, or head of academy can see the chronological story of one player.

`CLAUDE.md` § 1 makes "the player is the center of the system" a hard architectural constraint. The principle without this epic is aspirational — every record asks "which player does this serve?" but the player can't yet ask back "what's happened to me?". Three concrete things stay impossible without it:

1. **The end-of-season parent meeting** is "the coach builds a slide deck from scratch" instead of "open the player's journey, filter to this season, that's the conversation."
2. **The "where does this player stand?" question** a new coach inheriting a team always asks requires reading several modules' worth of records. Today they form a mental model. Tomorrow they read a tab.
3. **Cohort queries** like "every player promoted to U15 this year" or "all injuries longer than 4 weeks last season" require manual spreadsheet work today. The HoD's job has a structural ceiling that this epic lifts.

## Proposal

A first-class **journey** entity. New `tt_player_events` table acting as the unifying spine. New `tt_player_injuries` table covering the one major data source the codebase doesn't have today. Repository hooks on every existing module that produces journey-relevant data so events emit as a side effect of normal operations. New REST endpoints + a journey tab on the player profile. Reports that exploit the new data shape.

This is more "weave together what exists" than "build from scratch." The audit in the idea file shows ~70% of the source data already exists across Evaluations, Goals, PDP verdicts, and Trials; the missing 30% is the unifying event table, the injury data, and the surfaces that show it.

Single PR per the recent compression pattern (~6-9h actual estimated against ~4-5 sprints idea-file estimate).

## Scope

### Schema

Migration `0037_player_journey.php` creates two tables.

```sql
CREATE TABLE tt_player_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,                   -- key from `journey_event_type` lookup
    event_date DATETIME NOT NULL,                      -- when it happened (not when we recorded it)
    effective_from DATE DEFAULT NULL,                  -- range start (e.g. injury start)
    effective_to DATE DEFAULT NULL,                    -- range end (e.g. injury end / NULL = ongoing)
    summary VARCHAR(500) NOT NULL,                     -- short human-readable label rendered on the timeline
    payload LONGTEXT,                                  -- JSON, type-specific detail
    payload_valid TINYINT(1) NOT NULL DEFAULT 1,       -- 0 if EventTypeRegistry rejected the payload shape
    visibility VARCHAR(20) NOT NULL DEFAULT 'public',  -- 'public' | 'coaching_staff' | 'medical' | 'safeguarding'
    source_module VARCHAR(64) NOT NULL,                -- e.g. 'Evaluations', 'Pdp', 'Trials', 'Players'
    source_entity_type VARCHAR(64) NOT NULL,           -- e.g. 'evaluation', 'pdp_verdict', 'trial_case'
    source_entity_id BIGINT UNSIGNED DEFAULT NULL,     -- FK target in the source module
    superseded_by_event_id BIGINT UNSIGNED DEFAULT NULL,  -- soft-correct: points at the replacement event
    superseded_at DATETIME DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uk_uuid (uuid),
    UNIQUE KEY uk_natural (source_module, source_entity_type, source_entity_id, event_type),
    KEY idx_player_date (player_id, event_date DESC, id DESC),
    KEY idx_player_type_date (player_id, event_type, event_date DESC),
    KEY idx_source (source_module, source_entity_type, source_entity_id),
    KEY idx_visibility (visibility),
    KEY idx_club (club_id)
);

CREATE TABLE tt_player_injuries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    started_on DATE NOT NULL,
    expected_return DATE DEFAULT NULL,
    actual_return DATE DEFAULT NULL,
    injury_type_lookup_id BIGINT UNSIGNED DEFAULT NULL,    -- FK tt_lookups[lookup_type='injury_type']
    body_part_lookup_id BIGINT UNSIGNED DEFAULT NULL,      -- FK tt_lookups[lookup_type='body_part']
    severity_lookup_id BIGINT UNSIGNED DEFAULT NULL,       -- FK tt_lookups[lookup_type='injury_severity']
    notes TEXT,
    is_recovery_logged TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_player (player_id),
    KEY idx_open (player_id, actual_return),
    KEY idx_started (started_on DESC),
    KEY idx_club (club_id)
);
```

Both carry `club_id INT UNSIGNED NOT NULL DEFAULT 1` per CLAUDE.md § 3. `tt_player_events` carries `uuid` per the same standard (root entity in the journey domain).

### Lookups

Three new lookup types seeded by the migration:

- **`journey_event_type`** — 14 v1 types, each with `meta` carrying `icon` (lucide-style slug), `color` (hex), `severity` (`info` / `warning` / `milestone`), and `default_visibility` (`public` / `coaching_staff` / `medical` / `safeguarding`):

  | Key | Severity | Default visibility | Group |
  | --- | --- | --- | --- |
  | `joined_academy` | milestone | public | Lifecycle |
  | `trial_started` | info | public | Lifecycle |
  | `trial_ended` | info | public | Lifecycle |
  | `signed` | milestone | public | Lifecycle |
  | `released` | milestone | coaching_staff | Lifecycle |
  | `graduated` | milestone | public | Lifecycle |
  | `team_changed` | info | public | Roster |
  | `age_group_promoted` | milestone | public | Roster |
  | `position_changed` | info | public | Roster |
  | `injury_started` | warning | medical | Health |
  | `injury_ended` | info | medical | Health |
  | `evaluation_completed` | info | public | Development |
  | `pdp_verdict_recorded` | milestone | public | Development |
  | `note_added` | info | coaching_staff | Development |

  Per-club editable. New types added by clubs render with sensible defaults (`info` severity, `public` visibility, generic icon).

- **`injury_type`** — Sprain, Strain, Fracture, Concussion, Overuse, Illness, Other. Per-club editable.

- **`body_part`** — Ankle, Knee, Hamstring, Groin, Hip, Lower back, Upper back, Shoulder, Wrist, Hand, Foot, Head, Other. Per-club editable.

A fourth seed using the existing `tt_lookups` schema:

- **`injury_severity`** — Minor (≤2 weeks), Moderate (2-6 weeks), Serious (6+ weeks), Season-ending. Per-club editable.

### Capabilities

Two new caps:

- `tt_view_player_medical` — required to see `visibility='medical'` events. Granted to: `tt_head_dev`, `tt_club_admin`, `administrator`. Coaches do *not* get it by default — academy can grant per-coach via the matrix admin UI.
- `tt_view_player_safeguarding` — required to see `visibility='safeguarding'` events. Granted only to `tt_head_dev` + `administrator` by default. Reserved for future safeguarding workflow.

Existing caps cover the rest:

- `tt_view_player` (via `AuthorizationService::canViewPlayer`) gates the journey tab itself.
- Coaches see `visibility='public'` and `visibility='coaching_staff'` events for players on their teams. Players see `public` events on their own record. Parents see `public` events on their child's record.

Auth-matrix entries seeded for the new entity row `player_event` per persona.

### REST endpoints

Module-located under `src/Infrastructure/REST/PlayerJourneyRestController.php` (the journey is cross-module so it lives in Infrastructure rather than a single module). All gated by `AuthorizationService` capability checks — never role-string compares.

```
GET  /players/{id}/timeline                     — chronological feed
GET  /players/{id}/transitions                  — only milestone-severity events
POST /players/{id}/events                       — manual event (e.g. note_added)
PUT  /player-events/{id}                        — supersede (creates corrected event, marks original)
GET  /journey/event-types                       — taxonomy + meta for the timeline render

GET  /players/{id}/injuries                     — current + historical injuries
POST /players/{id}/injuries                     — record an injury
PUT  /player-injuries/{id}                      — update (e.g. set actual_return)
DELETE /player-injuries/{id}                    — soft-delete via archived_at

GET  /journey/cohort-transitions                — HoD cohort queries (e.g. ?event_type=age_group_promoted&from=…&to=…)
```

`GET /players/{id}/timeline` accepts:
- `from=YYYY-MM-DD` / `to=YYYY-MM-DD` (default: today minus 12 months → today)
- `event_type=<key>` (filter by type, repeatable)
- `season_id=<int>` (filter by season's date range)
- `cursor=<event_id>&limit=50` (cursor pagination, hard cap 200)
- `include_superseded=1` (default off — show retracted events for audit)

Visibility filtering happens server-side based on the requester's capabilities. The response includes a `hidden_count` so the UI can render "1 entry hidden" placeholders without the consumer having to ask the API a second time.

### Auto-write hooks (event emission from existing modules)

A new `EventEmitter` service exposed via the `Infrastructure\Journey` namespace. Existing repositories tap in:

- `EvaluationsRestController::create_eval()` → emit `evaluation_completed` after `tt_evaluation_saved`. The action already exists (#0022 added it).
- `GoalsRestController::create_goal()` → emit `goal_set`. **Skipped per Q2 lock**: `goal_completed` events drop into the timeline noise. The goals tab on the player profile remains the place to see goal history; only goal *creation* lands as an event.
- `PdpVerdictsRepository::upsertForFile()` → emit `pdp_verdict_recorded` on first sign-off (not on amend).
- `PlayersRestController::update_player()` → on `team_id` change, emit `team_changed`; if destination team's age_group differs, also emit `age_group_promoted` with the from/to in payload.
- `PlayersRestController::update_player()` → on `preferred_positions` change, emit `position_changed` with from/to in payload.
- `PlayersRestController::update_player()` → on `status` flip from `trial`→`active`, emit `signed`; on `status`→`released`, emit `released`; on `status`→`graduated`, emit `graduated`.
- `TrialsModule` (#0017) — three hook points:
  - `tt_trial_started` action → emit `trial_started`
  - `tt_trial_decision_recorded` action → emit `trial_ended` (always); on decision `admit`, also emit `signed`; on decision `deny_final`, also emit `released` with `payload.context='post_trial'`.
- `tt_player_injuries` insert → emit `injury_started`. Setting `actual_return` → emit `injury_ended`.

Idempotency is enforced by the unique key `uk_natural (source_module, source_entity_type, source_entity_id, event_type)`. Re-running a backfill or re-saving an entity does NOT multiply events; the upsert behaviour is "ignore on duplicate".

`do_action( 'tt_player_event_emitted', $event_id, $event_type, $player_id )` fires after every successful emit so future modules (audit log, notification systems) can subscribe.

### Backfill (one-shot, in-migration)

The migration walks existing data and emits initial events:

| Source | Event type | Date used |
| --- | --- | --- |
| `tt_evaluations` (archived_at IS NULL) | `evaluation_completed` | `eval_date` |
| `tt_pdp_verdicts` (signed_off_at IS NOT NULL) | `pdp_verdict_recorded` | `signed_off_at` |
| `tt_goals` (archived_at IS NULL) | `goal_set` | `created_at` |
| `tt_players.date_joined` | `joined_academy` | `date_joined` |
| `tt_trial_cases` (status NOT IN draft) | `trial_started` + (where decision is set) `trial_ended` | `started_on` / `decided_at` |

**Skipped backfills (per Q3 lock)**:
- Team changes — `tt_players.team_id` is current-state only; audit log isn't a reliable history source.
- Position changes — same reason.
- Injuries — no source data exists.
- `signed` events for players who joined before this migration — no historical trial→active transition record. Documented as a known gap.

### EventTypeRegistry + payload shape

PHP class `Infrastructure\Journey\EventTypeRegistry` exposes:

```php
EventTypeRegistry::register( $type_key, EventTypeDefinition $def );
EventTypeRegistry::find( $type_key ): ?EventTypeDefinition;
EventTypeRegistry::all(): array<string, EventTypeDefinition>;
EventTypeRegistry::validatePayload( $type_key, array $payload ): bool;
```

Each `EventTypeDefinition` carries `payload_schema` (associative array of `field => type`). On emit, the engine validates the payload against the schema; bad payloads log a warning and persist with `payload_valid=0` rather than rejecting (defensive — never lose an event because the schema drifted).

V1 schemas:

```php
'team_changed' => [
    'from_team_id' => 'int',
    'to_team_id'   => 'int',
    'from_team_name' => 'string',
    'to_team_name' => 'string',
],
'position_changed' => [
    'from' => 'string',
    'to'   => 'string',
],
'age_group_promoted' => [
    'from_team_id' => 'int',
    'to_team_id'   => 'int',
    'from_age_group' => 'string',
    'to_age_group' => 'string',
],
'injury_started' => [
    'injury_id'           => 'int',
    'expected_weeks_out'  => 'int',
    'severity_key'        => 'string',
    'body_part'           => 'string',
],
'injury_ended' => [
    'injury_id'      => 'int',
    'days_out'       => 'int',
    'expected_days'  => 'int',
],
'evaluation_completed' => [
    'evaluation_id' => 'int',
    'overall'       => 'float',
],
'pdp_verdict_recorded' => [
    'pdp_file_id' => 'int',
    'decision'    => 'string',
],
'trial_ended' => [
    'trial_case_id' => 'int',
    'decision'      => 'string',
    'context'       => 'string',
],
'released' => [
    'context' => 'string',  // 'post_trial' | 'mid_season' | 'end_of_season'
],
```

Other types (`note_added`, `joined_academy`, `signed`, `graduated`, `trial_started`) accept an empty payload schema — the summary field carries the meaning.

### Frontend surfaces

**New journey tab on the player profile.** Modifies the existing `FrontendMyProfileView` (player-side) and `FrontendPlayersManageView` detail (coach-side) to add a Journey tab.

- **Timeline mode** (default): chronological list, newest first by default with a "show oldest first" toggle.
  - Each event renders as a card: icon (from lookup `meta.icon`), color-coded by severity, date, summary, source link.
  - Filter chips above the list: by event type (multi-select), by date range, by season.
  - Cards for `medical` / `safeguarding` events that the viewer can't see render as discreet "1 entry hidden" placeholders.
  - Pagination via the cursor pattern; default load is 50 events from the last 12 months.

- **Transitions mode**: condensed view of `severity='milestone'` events only. Useful for parent meetings and coach onboarding to a new team.

- **Mobile-first**: 360px base, no horizontal scroll, 48px tap targets, all filter controls keyboard-accessible.

**New cohort transitions surface for HoD** at `?tt_view=cohort-transitions` (gated by `tt_view_settings`). Form-driven query:
- Pick event type (e.g. `age_group_promoted`)
- Pick date range
- Optional team filter
- Result: list of (player, date, summary) rows. Click a row to drill into that player's journey.

### Reporting

Reuses the report renderer from #0014. New `JourneyReportConfig` audience-template:

- **Season summary** — events filtered to a season's date range, grouped by category. Powers the end-of-season parent meeting.
- **Career summary** — full journey, used when a player leaves the academy (graduation packet, scout pitch).

Renderer call site lands in `PlayerReportRenderer::renderJourneySection()`. Print-friendly mode follows the existing report renderer's pattern.

### Module wiring

```
src/Infrastructure/Journey/
├── EventEmitter.php                 — public `emit()` API
├── EventTypeRegistry.php            — type definitions + payload validators
├── PlayerEventsRepository.php       — read API: forPlayer / transitionsForPlayer / cohortByType
├── InjuryRepository.php             — CRUD on tt_player_injuries
└── Backfill/
    ├── EvaluationsBackfill.php
    ├── PdpVerdictsBackfill.php
    ├── GoalsBackfill.php
    ├── PlayersJoinedBackfill.php
    └── TrialsBackfill.php

src/Infrastructure/REST/
└── PlayerJourneyRestController.php

src/Modules/Players/Frontend/
├── PlayerJourneyTab.php             — composable tab block
└── (no new dispatcher slug — tab integrates with existing player profile views)

src/Shared/Frontend/
└── FrontendCohortTransitionsView.php  — HoD surface
```

Module registration via `CoreSurfaceRegistration` (the registry pattern from #0033-finalisation): tile + cap + auth-matrix + REST + workflow.

Hooks subscribed (existing actions, no new module needed):
- `tt_evaluation_saved` (#0022) → emit `evaluation_completed`
- `tt_after_player_save` → diff old vs new and emit `team_changed` / `position_changed` / `signed` / `released` / `graduated` / `age_group_promoted`
- `tt_trial_started` (#0017) → emit `trial_started`
- `tt_trial_decision_recorded` (#0017) → emit `trial_ended` + downstream
- `tt_pdp_verdict_signed_off` (#0044, hook to be added if it doesn't exist) → emit `pdp_verdict_recorded`

If `#0044` doesn't currently fire `tt_pdp_verdict_signed_off`, this epic adds it (one-line `do_action` in `PdpVerdictsRepository::upsertForFile` after the upsert).

### Translations + docs

- NL `.po` updated in the same PR. ~80 new msgids estimated (event-type labels + UI copy + workflow strings).
- New doc `docs/player-journey.md` (EN) + `docs/nl_NL/player-journey.md` (NL), audience marker `<!-- audience: user -->` for the player/coach perspective. Plus a separate admin-tier doc passage explaining event emission, the EventTypeRegistry, and how to add a new event type.
- `docs/architecture.md` gets a new "Journey events" section explaining the contract + the boundary with workflow tasks (per Q8 lock — workflow tasks are reminders, journey events are records of things that happened).
- `docs/rest-api.md` gets the new endpoint definitions.

### Workflow integration

Two new templates registered against the engine (#0022) on module boot:

1. **`injury_recovery_due`** — on `tt_player_injuries` insert with non-NULL `expected_return`, schedule a task on the player's head coach for `expected_return - 3 days`: "Confirm [player] is on track for recovery." Completing the task asks for `actual_return` (today / extend / unsure).
2. **`pdp_verdict_due_for_journey`** — when a PDP cycle's last conversation is signed off, schedule a verdict task for the head of academy. The journey event fires when the verdict is recorded; the workflow task gets the HoD there. (This template MAY already exist via #0044 — check on build; if so, no new template, just a doc note.)

No `chainSteps()` needed in v1.

## Out of scope

- **Replacing any existing module.** Evaluations, Goals, PDP, Trials all keep their own UIs; the journey is a *read-side* aggregate, not a rewrite.
- **Psychometric or emotional-state tracking.** Real product question for another time; this epic stays about observable academy events.
- **Mobile push notifications** for journey events — the workflow engine handles task notifications; the journey is a read surface.
- **Calendar exports** of journey events. Possible follow-up if the journey lands well.
- **Scout-facing public profiles** that include journey. The #0014 scout flow (v3.40.0) already handles scout report access; journey integration there is a follow-up.
- **Safeguarding workflow.** Touched only enough to enforce visibility on `safeguarding` events; the deeper workflow is its own future epic.
- **Backfill of team changes, position changes, injuries.** No reliable source data; from-now-on tracking only. Documented as a known gap.
- **`goal_completed` events on the timeline.** Excluded per Q2 lock to avoid signal-to-noise on prolific goal lifecycles. The goals tab on the player profile remains the surface for goal history.
- **Audit log relationship.** `tt_audit_log` continues to track operational telemetry separately. The journey is the player-development story, not the system-action log.

## Acceptance criteria

The epic is done when:

- [ ] Migration `0037_player_journey.php` creates `tt_player_events` and `tt_player_injuries` on a fresh install. Idempotent on re-run.
- [ ] Activator schema mirror covers both new tables for the fresh-install path.
- [ ] `journey_event_type` lookup seeded with all 14 v1 types + their `meta` (icon / color / severity / default_visibility). Per-club editable.
- [ ] `injury_type`, `body_part`, `injury_severity` lookups seeded with the values listed above. Per-club editable.
- [ ] Two new caps (`tt_view_player_medical`, `tt_view_player_safeguarding`) seeded with the role grants above. Auth matrix has the new `player_event` entity row for every persona.
- [ ] `EventEmitter` service available; existing repositories (Evaluations, Goals, PDP, Players, Trials) emit events on lifecycle changes via the documented hooks.
- [ ] `EventTypeRegistry` validates payloads against the per-type schemas; bad payloads persist with `payload_valid=0` and a logged warning, never reject.
- [ ] Backfill on migration writes events for: every `tt_evaluations` row → `evaluation_completed`, every signed-off `tt_pdp_verdicts` → `pdp_verdict_recorded`, every `tt_goals` → `goal_set`, every `tt_players.date_joined` → `joined_academy`, every `tt_trial_cases` row → `trial_started` + (where decided) `trial_ended`. Idempotent — re-running the migration does not multiply events.
- [ ] REST endpoints exist at the paths above. All declare `permission_callback` against `AuthorizationService`. A coach without `tt_view_player_medical` who calls `GET /players/{id}/timeline` gets `medical` events filtered out plus a `hidden_count`.
- [ ] Journey tab renders on both the player-facing profile and the coach-facing player detail. Two view modes (Timeline / Transitions). Filter chips for type / date / season.
- [ ] Cards for events the viewer can't see render as "1 entry hidden" placeholders, not omitted silently.
- [ ] Pagination cursor works; default 12-month window honored; "Show full history" toggle drops the date filter.
- [ ] Mobile-first surfaces render at 360px width with no horizontal scroll, all touch targets ≥ 48×48 CSS px, no hover-only interactions.
- [ ] Cohort transitions surface lists every player whose journey contains the queried event type within the date range; clicking a row navigates to that player's journey.
- [ ] Injury record CRUD surface (admin or physio role) creates a `tt_player_injuries` row, which auto-emits `injury_started` and (on `actual_return` set) `injury_ended`.
- [ ] Soft-correct path works: amending an evaluation that has already emitted `evaluation_completed` results in a new event with `superseded_by_event_id` populated on the original. Default timeline view filters out superseded-only events; "Show retracted" toggle reveals them.
- [ ] Visibility column populates on emit from the event type's `meta.default_visibility`. Authors can override per-row when calling `EventEmitter::emit( … 'visibility' => 'medical' … )`.
- [ ] Two new workflow templates (`injury_recovery_due`, plus the optional verdict template if #0044 doesn't already cover it) register on module boot.
- [ ] `docs/player-journey.md` (EN + NL) shipped with audience markers + standards-quality copy. `docs/architecture.md` has a "Journey events" section. `docs/rest-api.md` documents the new endpoints.
- [ ] PHP lint clean, msgfmt validates the .po, docs-audience CI green.
- [ ] NL `.po` updated.
- [ ] SEQUENCE.md updated with the Done row + version bump.

## Notes

### Decisions locked during shaping (the eight from the idea + three extras)

1. **Naming** — `journey` for product, `events` for technical (`tt_player_events`, `Infrastructure\Journey`). REST URL is `/players/{id}/timeline` because that's the natural REST shorthand and doesn't conflict.
2. **Taxonomy** — 14 v1 types stored in `tt_lookups[lookup_type='journey_event_type']`, admin-extensible. Excluded `goal_completed`, `goal_set` collapses to creation only. Each type carries `meta.icon` + `meta.color` + `meta.severity` + `meta.default_visibility`.
3. **Backfill** — evaluations + PDP verdicts + goals (created_at only) + `joined_academy` + trial_started/ended. Skipped: team changes, position changes, injuries, pre-migration `signed`. Documented as known gaps.
4. **Soft-correct** — never delete events. Two columns (`superseded_by_event_id` + `superseded_at`); retraction emits a new event linking back. Default UI filters superseded-only events; "Show retracted" toggle reveals them.
5. **Privacy** — per-event `visibility` column with four levels (`public` / `coaching_staff` / `medical` / `safeguarding`). Default per type from the lookup `meta`. Authors override per-row. Hidden events render as "1 entry hidden" placeholders.
6. **Performance** — server-side filter, default 12-month window, cursor pagination with hard-cap of 200 per page. Index `(player_id, event_date DESC, id DESC)` covers the common queries.
7. **Order vs #0052** — build #0053 with the SaaS scaffold inline (`club_id` + `uuid` from day one). Don't gate on #0052.
8. **Workflow boundary** — workflow tasks are reminders; journey events are records of things that happened. Document the boundary in `docs/architecture.md`. Shared triggers (an evaluation save fires both) are coincidental, not architectural.
9. **Sprint plan** — single PR per the recent compression pattern. ~6-9h actual estimated.
10. **Injury heaviness** — minimal `tt_player_injuries`. No treatment plans, doctors, scans. Three lookups (type / body_part / severity) cover 90% of cases; `notes` field handles the rest.
11. **Payload schema** — free-form JSON validated by `EventTypeRegistry`. Bad payloads persist with `payload_valid=0`; never reject on emit (defensive — schema drift can't lose events).

### Player-centricity check

Per CLAUDE.md § 1, every feature must answer "which player(s) does this serve?". The journey is the *direct* answer to the player-centricity principle. The player-question this feature helps answer is the entire question: *"What's happened to this player, where are they now, where are they going?"* — which is the spine of the principle.

The journey turns the principle from aspirational to enforceable. Without it, every record asks "which player?" but the player can't ask back.

### SaaS-readiness check

- Both new tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1`. Repositories filter by `club_id` even though it's a no-op today.
- `tt_player_events` carries `uuid CHAR(36) UNIQUE` (root entity). `tt_player_injuries` is a leaf and doesn't.
- All new surfaces consume the REST API; the PHP-rendered front end is one consumer, not the canonical interface. A future SaaS web app can render the journey from `/players/{id}/timeline` without any plugin code on the client.
- Auth via `AuthorizationService` capability checks, not role-string compares.
- Background work (the `injury_recovery_due` workflow template) rides on the existing engine (#0022), not ad-hoc `wp_cron`.
- `document_url` field is not present here, but if it were (future scout-facing journey export), it would be a URL not a server-relative path. Asset-portability per CLAUDE.md § 3.

### Cross-references

- **#0014** — player profile + report generator. Journey tab integrates with `FrontendMyProfileView` (Sprint 2 rebuild). `JourneyReportConfig` uses the renderer (`PlayerReportRenderer` from sprints 3-5).
- **#0017** — trial player module (shipped v3.42.0). Journey hooks subscribe to `tt_trial_started` and `tt_trial_decision_recorded` actions for `trial_started` / `trial_ended` / `signed` / `released` events.
- **#0021** — audit log viewer. Audit log stays separate from journey; documented in `docs/architecture.md` as the operational-telemetry vs player-development-story boundary.
- **#0022** — workflow & tasks engine. Two journey-related templates ride on the existing engine. Boundary documented: tasks are reminders, events are records.
- **#0033** — authorization matrix. New `player_event` entity row + two new visibility caps (`tt_view_player_medical` / `tt_view_player_safeguarding`) seeded into the existing matrix.
- **#0044** — PDP cycle. Adds `tt_pdp_verdict_signed_off` action if not already firing; subscribes to it for `pdp_verdict_recorded` events.
- **#0052** — SaaS-readiness baseline. This epic ships ahead of #0052 with the scaffold inline (`club_id` + `uuid`). When #0052 lands, these tables already comply.

### Estimated effort

Idea file estimate: ~4-5 sprints. Compression pattern across the last 8 epics (#0044, #0018, #0022, #0014, #0017): 5–14× actual. Realistic actual: **~6-9h bundled**. Ship as next available minor at PR-creation time (likely v3.43.0 or v3.44.0 depending on parallel work).

### What ships in the PR

- Migration `0037_player_journey.php` with backfill logic.
- `Infrastructure\Journey` namespace: `EventEmitter`, `EventTypeRegistry`, `PlayerEventsRepository`, `InjuryRepository`, five `Backfill\*` classes.
- New `Infrastructure\REST\PlayerJourneyRestController.php` with all endpoints.
- New `PlayerJourneyTab` block + integration into `FrontendMyProfileView` and `FrontendPlayersManageView`.
- New `FrontendCohortTransitionsView` (HoD surface).
- Hooks wired in `EvaluationsRestController`, `GoalsRestController`, `PlayersRestController`, `TrialsModule` (action subscribers, not direct edits to those modules), `PdpVerdictsRepository`.
- `tt_pdp_verdict_signed_off` action added to `PdpVerdictsRepository::upsertForFile()` if not already firing.
- Two new workflow templates (`injury_recovery_due`, plus the verdict one if needed).
- Activator schema mirror for the two new tables.
- Tile + capability + auth-matrix + lookup seeding via `CoreSurfaceRegistration`.
- `docs/player-journey.md` + `docs/nl_NL/player-journey.md` + audience markers + standards-quality rewrite.
- `docs/architecture.md` "Journey events" section.
- `docs/rest-api.md` endpoint additions.
- NL `.po` updates.
- `SEQUENCE.md` Done row + version bump.
