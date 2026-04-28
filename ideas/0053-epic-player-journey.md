<!-- type: epic -->

# Player journey — make every player's path through the academy first-class

Raw idea:

A player's time at the academy is a *journey*: trial → signed → first season at U13 → position change → injury → return → promotion to U14 → end-of-season verdict → … → graduation or release. Today the data exists (across evaluations, goals, PDP verdicts, attendance), but the *journey* doesn't — there's no place where a coach, parent, or head of development can see the chronological story of one player. The plugin's product principle is player-centric (`CLAUDE.md` § 1), and the journey is the spine of that principle. This epic makes the journey a first-class entity: queryable events, a unified timeline view, and a small set of explicit transition types that capture the moments that matter.

This is the work behind the player-centric review item that surfaced during the v3.39.0 codebase audit: *"the journey is not first-class — there's no `player_journey` or `player_timeline` anywhere in the codebase, and the only `timeline` concept is for usage stats."*

## Why this is an epic

Cuts across schema (new events table), every existing module that produces journey-relevant data (Evaluations, Goals, PDP, Sessions, Trials, Attendance, Players), the read model (a unified timeline query), and at least one new prominent UI surface (the journey tab on the player profile). Touches reporting too — a "season summary" or "career summary" report becomes natural once the journey exists.

Estimated 4-5 sprints. Sequential, because most of it stacks on the schema foundation.

## What "journey" means here, precisely

A **journey** is the ordered sequence of dated, meaningful events in a player's academy life. Three properties define it:

1. **Chronological.** Always queryable in date order. The default lens.
2. **Cross-module.** Events come from many sources (evaluations, goals, PDP verdicts, trial admissions, position changes, injuries, age-group moves), but they live in one timeline.
3. **Anchored to the player.** Every event has a `player_id`. A player's journey is the slice of all events where `player_id = X`.

A **transition** is a specific subtype of event that marks a change in the player's status: trialed → signed, signed → released, age group U13 → U14, position CB → RB, healthy → injured → returned, contract season N → season N+1. Transitions are the events most often asked about ("when did this kid move to U15?", "how long was she out for that ankle injury?") and they need to be queryable as a class, not just findable in a long timeline.

What is **not** a journey event:
- Routine session attendance. Too noisy. (Aggregate stats are journey-relevant — "missed 8 sessions in March" is — but each individual attendance row is not.)
- Audit-log rows. Different concern; `tt_audit_log` already tracks "who edited what record when," which is operational telemetry, not the player's story.
- Edits to demographic fields (changing the guardian's phone number). Not a development moment.

The line is: **would a coach reading this player's profile next year find this useful context for understanding where the player is now?** If yes, it's a journey event.

## Audit — what exists today

Concrete from the v3.39.0 source.

### What's already journey-shaped

- **`tt_pdp_verdicts`** (migration `0031_pdp_cycle.php`) is the closest existing thing to a journey event. Columns: `pdp_file_id`, `decision`, `summary`, `coach_id`, `head_of_academy_id`, `signed_off_at`, timestamps. It's a per-cycle decision record — exactly the shape a journey event needs, just specialized to PDP. Pattern reusable for the generic journey table.
- **`tt_seasons`** exists and is keyed off PDP cycles. Seasons are the natural binning unit for a journey ("U14 — 2024/25 season"). Not journey events themselves, but useful context.
- **`tt_evaluations`** + **`tt_eval_ratings`** already store dated assessments. A finalized evaluation **is** a journey event — it just isn't surfaced as one.
- **`tt_goals`** has create/update/archive timestamps. A goal being set, completed, or carried over **is** a journey event.
- **`tt_players.status`** captures one transition dimension (`active` / `trial` / others) but as current-state, not as a history. A player who was on trial in 2023 and is now active has no record of when that flipped.
- **`tt_players.date_joined`** is the only explicit journey date on the player record.
- The **#0017 trial epic** captures one specific transition really well: trial → admit/deny-with-encouragement/deny-final, with letters and audit trail. The pattern is correct; it just stops at admission. There's no equivalent epic for "what happens after admission."

### What's missing

- **No generic journey/event table.** No `tt_player_events` or similar. Searched: zero hits for `journey`, `Journey`, `player_timeline`, `player_journey` outside the usage-stats module.
- **No transition records.** Position changes, age-group promotions, injury periods, return-to-play, release decisions, graduation — none of these are modeled as discrete dated records. Inferable in some cases (compare `team_id` snapshots over time), un-inferable in others (no injury data exists at all).
- **No injury data.** No `tt_player_injuries` table. The plugin tracks attendance status (`present` / `absent` / from migration `0020_attendance_guests.php`) but doesn't record *why* a player wasn't present, or that they were unavailable for matches across a date range.
- **No timeline view on the profile.** `FrontendMyProfileView` (`src/Shared/Frontend/FrontendMyProfileView.php`) has six sections: hero, playing details, recent performance, active goals, upcoming, account. All snapshot views. None chronological. The header docblock literally describes it as a six-section snapshot view.
- **No timeline endpoint.** `docs/rest-api.md` has player CRUD, evaluations CRUD, goals CRUD, etc. No `GET /players/{id}/timeline`.
- **The only thing called `timeline` in the codebase** is `user_timeline` in `src/Modules/Stats/Admin/UsageStatsDetailsPage.php` — that's an audit/usage view of a WP user's plugin-actions, not a player development timeline. Worth knowing because the name `timeline` is already partly taken; consider `journey` as the canonical term to avoid conflation.

### What this means

The data is there for ~70% of the journey already (evaluations, goals, PDP verdicts, trial admissions). What's missing is (a) a unifying event table and read model, (b) the transition types that aren't captured today (position changes, age-group moves, injury periods), and (c) the surfaces that show it. This is more "weave together what exists" than "build from scratch," which keeps the epic tractable.

## Decomposition (rough — for shaping into specs later)

### Sprint 1 — Schema and the generic event spine

The foundation. Everything else depends on it.

- New migration creates `tt_player_events`:
  - `id`, `player_id`, `event_type`, `event_date`, `effective_from`, `effective_to` (nullable, for ranges like injury periods), `summary`, `payload` (LONGTEXT JSON for type-specific detail), `source_module`, `source_entity_type`, `source_entity_id`, `created_by`, `created_at`, `updated_at`. Plus the `club_id` and `uuid` columns from #0052.
  - Indexes: `(player_id, event_date DESC)`, `(player_id, event_type, event_date DESC)`, `(source_module, source_entity_type, source_entity_id)` for back-references.
- Define an initial canonical event-type taxonomy. Stored as a `tt_lookups` lookup so it's translatable and admin-extensible (per the ship-along rules in `DEVOPS.md`):
  - `joined_academy`, `trial_started`, `trial_ended`, `signed`, `team_assigned`, `team_changed`, `position_changed`, `age_group_promoted`, `age_group_demoted`, `injury_started`, `injury_ended`, `evaluation_completed`, `goal_set`, `goal_completed`, `pdp_cycle_started`, `pdp_verdict_recorded`, `released`, `graduated`, `returned`, `note_added`. Probably more — to be confirmed during shaping.
- Backfill from existing data:
  - Every `tt_pdp_verdicts` row → one `pdp_verdict_recorded` event.
  - Every finalized `tt_evaluations` row → one `evaluation_completed` event.
  - Every `tt_goals` row's create date → one `goal_set` event; archived/completed dates → corresponding events.
  - `tt_players.date_joined` → `joined_academy` event.
  - `tt_players.status='trial'` history (where reconstructable from `created_at`) → trial events. Trial cases from the #0017 module, when shipped, integrate cleanly.
- New `Infrastructure\Journey\PlayerEventsRepository` with the read API: `forPlayer( $player_id, $filters )`, `transitionsForPlayer( $player_id )`, `eventsByType( $type, $date_range )`. Tenancy filter (`club_id`) automatic per the SaaS-readiness baseline.

### Sprint 2 — Auto-write events from existing modules

Every module that produces journey-relevant data starts emitting events as a side effect of its normal operations.

- New `tt_player_events_emitted` hook fired by repositories. Existing repositories tap in:
  - `EvaluationsRepository::finalize()` → emit `evaluation_completed`.
  - `GoalsRepository::create()` / `markComplete()` / `archive()` → emit corresponding events.
  - `PdpVerdictsRepository::create()` → emit `pdp_verdict_recorded`.
  - `PlayersRepository::update()` — when `team_id` changes, emit `team_changed`; when `status` flips trial→active, emit `signed`; when `status`→released, emit `released`.
  - When the #0017 trial module ships its decision flow (sprint 4 of that epic), it emits `trial_ended` + `signed`/`released_after_trial`.
- Idempotency: events have a `(source_module, source_entity_type, source_entity_id, event_type)` natural key for upsert, so re-running a backfill or re-saving an entity doesn't multiply events.
- Documentation: `docs/architecture.md` gets a new "Journey events" section explaining the contract — what emits, what doesn't, how to add a new event type.

### Sprint 3 — Transition types not captured today

The two categories with no data source yet: **position changes** (only the *current* `preferred_positions` is stored on the player) and **injury periods** (no injury data anywhere).

- Position changes: when an admin/coach edits `preferred_positions` on a player, emit a `position_changed` event with the old + new values in the payload. Backfill is impossible (no history); from-now-on coverage only. Document this in the migration notes.
- Injuries: new lightweight table `tt_player_injuries` (`id`, `player_id`, `started_on`, `expected_return`, `actual_return`, `injury_type` lookup, `body_part` lookup, `severity` lookup, `notes`, `created_by`). Every injury record emits `injury_started` (on create) and `injury_ended` (on `actual_return` set). Optional but high-value — coaches and physios will use this whether or not the journey timeline exists.
- Age-group promotions / demotions: derive from `team_changed` where the destination team is in a different age group, emit a more specific event so the timeline can label it correctly.
- Decide what to do about graduations vs releases — these are status transitions but there's no current data structure for them. Either reuse trial-style decision records (likely best) or add a lightweight `tt_player_status_changes` table.

This sprint is where the most product judgement is needed; ship it after Sprints 1 and 2 have proven the spine works.

### Sprint 4 — The journey view on the profile

The user-facing payoff.

- New tab/section on `FrontendMyProfileView` (and the equivalent coach-facing view via `CoachDashboardView` → drill into a specific player): **Journey**.
- Two view modes:
  - **Timeline.** Chronological list of events, newest first by default with a "show oldest first" toggle. Each event renders a short card: icon by event type, date, summary, source link (e.g. clicking on an `evaluation_completed` event opens that evaluation). Filter chips: by event type, by date range, by season.
  - **Transitions only.** A condensed view showing just the major status changes — when she joined, when she moved up an age group, when injuries hit, when seasons ended. Useful for the parent meeting and the coach's quick "tell me where this kid is" check.
- Per the mobile-first standards in `CLAUDE.md`: works at 360px wide, 48px tap targets, no hover-only filters, `inputmode` correct on date filters. Vanilla JS + REST.
- New REST endpoint: `GET /players/{id}/timeline` (also `GET /players/{id}/transitions`). Per `CLAUDE.md` § 3 SaaS-readiness, the view consumes the same endpoint a future SaaS front end would — no PHP-only paths.
- Permission gating via `AuthorizationService::canViewPlayer( $user_id, $player_id )` (the existing entity-scoped helper). Players see their own journey; coaches see their team's players' journeys; HoD/admins see all.
- Sensitive event types (e.g. injuries with medical detail, safeguarding-flagged notes) gated by additional cap (`tt_view_player_medical`). Default-deny for anyone without it.

### Sprint 5 — Reporting and exports

Once a journey exists, several reports become trivial.

- **Season summary** report per player: events from `season.start_date` to `season.end_date`, grouped by category, ready for the end-of-season parent meeting. Reuses the report renderer from #0014.
- **Career summary** report per player: full journey, useful when a player leaves (graduation packet, scout pitch).
- **Cohort transition reports** for HoD: "every player promoted to U15 this season," "every player on trial in October," "all injuries longer than 4 weeks last year." These are SQL queries on `tt_player_events` filtered by event type and date range — straightforward once Sprint 1 ships.
- Export formats: PDF (per #0014 renderer), CSV (for HoD's spreadsheet workflows), JSON (for the future SaaS front end).
- Print-friendly mode for the parent meeting (per the #0017 trial pattern).

## Open questions

- **Naming: `journey` vs `timeline`.** `timeline` is partly taken (usage-stats module). `journey` reads more product-aligned. Recommend `journey` for the user-facing concept, `events` for the technical one (`tt_player_events`, `GET /players/{id}/timeline` because the URL convention is `/timeline`). Confirm during shaping.
- **Event taxonomy granularity.** ~20 event types in Sprint 1 is a starting point. Too few = useless filtering; too many = overwhelming. Confirm the list with a real coach before committing.
- **Backfill scope.** Backfilling evaluations + goals + PDP verdicts is straightforward (timestamps exist). Backfilling team changes is harder — `tt_players.team_id` is current-state only. Audit log might have history rows for team changes but it's not guaranteed. Decide whether to backfill or accept that historical team changes simply aren't visible for pre-ship players.
- **Soft-delete / event correction.** What happens when a coach makes an evaluation, it emits an `evaluation_completed` event, then the evaluation is deleted? Options: (a) delete the event too, (b) mark the event `superseded`, (c) emit a `evaluation_retracted` event. Recommend (b) + (c) — never silently delete from the journey, the audit trail matters more than tidiness.
- **Privacy on the journey.** Some events should not be visible to all viewers — e.g. a parent shouldn't see a `safeguarding_flag_raised` event on their own child. Per-event-type visibility rules need a small ACL beyond the player-level cap. Defer the detail to Sprint 4 design but flag it now.
- **Performance.** A senior player at the end of their academy career could have 1000+ events. The view needs pagination, the filter needs to be DB-side, and the default load needs to be capped (e.g. last 12 months) with a "show full history" expand. Not hard, just needs to be designed in from Sprint 1.
- **Should this come before or after #0052 (SaaS-readiness)?** Strong recommendation: **after Sprint 1 of #0052**. The new `tt_player_events` table should ship with `club_id` and `uuid` already on it, not have them backfilled later. If #0052 Sprint 1 is delayed, ship this anyway but include those columns from the start.
- **Relationship to the workflow engine** (`src/Modules/Workflow/`). Some events (an evaluation overdue, a PDP verdict due) already fire workflow tasks. Workflow tasks and journey events are *not* the same thing — a workflow task is "something the system reminds someone to do," a journey event is "something that happened to the player." But they share data sources. Document the boundary in `docs/architecture.md` to avoid conflation.

## Touches

New tables: `tt_player_events`, `tt_player_injuries` (Sprint 3).

New code:
- `src/Infrastructure/Journey/` — new namespace. Contains `PlayerEventsRepository`, `EventTypeResolver`, hooks helpers.
- `src/Infrastructure/REST/PlayerJourneyRestController.php` — new.
- `src/Modules/Players/Frontend/PlayerJourneyView.php` — new.

Modified:
- `src/Modules/Evaluations/Repositories/*` — emit events on finalize.
- `src/Modules/Goals/Repositories/*` — emit events on lifecycle changes.
- `src/Modules/Pdp/Repositories/PdpVerdictsRepository.php` — emit on create.
- `src/Modules/Players/Repositories/*` (or equivalent) — emit on `team_id` / `status` / `preferred_positions` changes.
- `src/Modules/Trials/` (when #0017 ships its decision sprint) — emit trial-end events.
- `src/Shared/Frontend/FrontendMyProfileView.php` — link to journey tab.
- `src/Shared/Frontend/CoachDashboardView.php` — link to player journey from drill-through.
- `docs/architecture.md` — new "Journey events" section, plus boundary with workflow engine.
- `docs/rest-api.md` — new `/players/{id}/timeline` and `/players/{id}/transitions` endpoints.
- `docs/player-dashboard.md` — describe the journey tab.
- `docs/nl_NL/*.md` — Dutch counterparts (per `docs/contributing.md`).
- `languages/talenttrack-nl_NL.po` — new strings (per ship-along rule).
- `tt_lookups` seed migration — event-type taxonomy.

Reporting (Sprint 5) reuses the #0014 renderer; no new infrastructure needed there.

## Why this matters product-wise

Three concrete things become possible that aren't possible today:

1. **The end-of-season parent meeting** stops being "the coach builds a slide deck from scratch" and becomes "open the player's journey, filter to this season, that's the conversation."
2. **The "where does this player stand?" question** that a new coach inheriting a team always asks gets a real answer. Today it requires reading several modules' worth of records and forming a mental model. Tomorrow it's the journey tab.
3. **"All players promoted to U15 this year"** and "all injuries longer than 4 weeks last season" become queries the system can answer. Today they require manual spreadsheet work. The HoD's job gets meaningfully easier.

These are the things the player-centric principle (`CLAUDE.md` § 1) is for. The principle without this epic is aspirational; with it, it's enforced by the data model.

## What this epic explicitly does NOT do

- Replace any existing module. Evaluations, Goals, PDP, Trials all keep their own UIs; the journey is a *read-side* aggregate, not a rewrite.
- Add psychometric or emotional-state tracking. That's a real product question for another time; keep this epic about observable academy events.
- Build mobile push notifications, calendar exports of journey events, or scout-facing public profiles. Each is a follow-up if the journey lands well.
- Solve safeguarding/disclosure UI. Touched only enough to enforce visibility on sensitive events; the deeper safeguarding workflow is its own epic.
