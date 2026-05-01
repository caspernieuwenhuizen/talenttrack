<!-- type: feat -->

# #0072 — New Evaluation wizard (activity-first, player-fallback)

> Originally drafted as #0070 in the user's intake (uploaded as a PDF in commit `81646b0`). Renumbered on intake — #0070 was already taken by the v3.70.0 list-linkification ship.

## Problem

Today's `NewEvaluationWizard` (`src/Modules/Wizards/Evaluation/NewEvaluationWizard.php`) is a two-step pre-flight: pick a player, pick an evaluation type, then redirect to the heavyweight evaluation form. It works, but it gets coaching backwards. A coach almost always thinks "I just finished training with U14; let me rate the players who were there" — activity-first, attendance-aware, multi-player in one sitting. The current wizard makes them pick one player at a time and never references the activity that prompted the evaluation, so they skip the wizard and jump to the flat form, which is itself optimised for editing a single existing evaluation rather than creating a batch.

Three concrete consequences:

1. **Evaluations under-cover real activities.** A coach who just ran a training rates two or three players when they meant to rate the whole squad, because the per-player flow makes batching painful.
2. **The activity-evaluation join (`tt_evaluations.activity_id`) is sparsely populated** even though the data model supports it, which weakens reports and the player journey.
3. **Ad-hoc evaluations have no first-class entry point** — they're funnelled through the same per-player flow as activity-attached evaluations, with the same metadata fields shown but disconnected from any activity context.

The redesign keeps the wizard registry, draft mechanics, analytics, and entry-point patterns the codebase already has. It replaces only the steps and the per-step UX, plus introduces one piece of supporting data: a `rateable` attribute on activity types so the wizard's activity picker doesn't need a hardcoded list of which types qualify.

## Proposal

A redesigned `NewEvaluationWizard` with **two entry paths, smart-default landing, and per-step UX shaped around how a coach actually creates evaluations**. The wizard registers under the same slug (`new-evaluation`) so existing entry points (`WizardEntryPoint::urlFor('new-evaluation', ...)` from manage views and the persona dashboard) continue to resolve. The required cap stays `tt_edit_evaluations` (Assistant Coach RC team, Head Coach RCD team, HoD RCD global, Academy Admin RCD global per the matrix). Team Manager has only R team on evaluations — the wizard is correctly inaccessible to them via the cap gate.

The two paths:

- **Activity-first.** Coach picks a recently-completed rateable activity, the wizard surfaces the team's roster grouped by attendance, and the coach rates each present player with a quick-rate row plus an expandable deep-rate panel. One submit button creates N evaluations (one per rated player) with `activity_id` set. **This is the primary flow** because it's the daily reality.
- **Player-first.** Coach picks one player and fills a hybrid form that combines the deep-rate fields with the activity-context fields the activity flow would normally inject (date, setting, free-text reason). Used for ad-hoc observations — a tournament moment, something noticed in passing, anything not anchored to an activity row. Submits one evaluation with `activity_id = NULL`.

**Smart default**: the wizard's first-step resolver looks at the coach's recent rateable activities (last 30 days, on a team they coach, type marked `rateable`, where they haven't already evaluated every present player). If at least one such activity exists, land on the activity picker. Otherwise, land on the player picker. Both landings show a visible "or rate a player directly" / "or rate from an activity" link to the other path so neither is a dead end.

## Scope

### 1. The `rateable` attribute on activity types

Activity types live in `tt_lookups` with `lookup_type = 'activity_type'`. The table already has a `meta TEXT` column for per-type attributes — no schema migration needed. The wizard reads the attribute via a small helper.

A new method `LookupsRepository::isActivityTypeRateable( string $type_name ): bool`. Implementation: load the lookup row, JSON-decode `meta`, return `meta['rateable'] ?? true`. Default `true` so unmarked existing data stays rateable on upgrade.

The Configuration → Lookups admin tab gains a "Rateable" checkbox per row when the active `lookup_type` is `activity_type`. The save handler reads/writes the meta JSON. Other lookup tabs (positions, age groups, etc.) don't get the checkbox — it's specific to activity types. Label: "Rateable — coaches can create player evaluations on activities of this type."

A migration `0054_seed_activity_type_rateable_meta` writes explicit `meta.rateable = false` for the well-known non-rateable types: `clinic`, `methodology` (lectures, not player performance), `team_meeting`. All other existing rows are left unmodified — the `?? true` default in the read helper preserves their behaviour.

The same attribute is consumed by future Reports/Stats work that needs the same filter (out of scope here, but the helper exists for them).

### 2. Wizard structure

Replace `src/Modules/Wizards/Evaluation/NewEvaluationWizard.php` with the new step graph. The wizard interface contract (`WizardInterface`) doesn't change — `slug()`, `label()`, `requiredCap()`, `firstStepSlug()`, `steps()` all keep their signatures. What changes is the step list and the dynamic first-step resolution.

Steps (each is a class implementing `WizardStepInterface`, following the convention of the existing Wizards module):

```
src/Modules/Wizards/Evaluation/
├── NewEvaluationWizard.php         // updated: returns the new step list
├── ActivityPickerStep.php          // NEW
├── AttendanceStep.php              // NEW
├── RateActorsStep.php              // NEW
├── PlayerPickerStep.php            // NEW (replaces today's PlayerStep)
├── HybridDeepRateStep.php          // NEW
├── ReviewStep.php                  // NEW
└── (deleted) PlayerStep.php, TypeStep.php
```

`firstStepSlug()` becomes dynamic. The current contract says it returns a `string`, so we keep the signature but compute the value inside the framework. Two options considered:

- **Option A** — extend `WizardInterface` with an optional `firstStepFor( int $user_id ): string` method that the framework calls when present, falling back to `firstStepSlug()`. Backwards-compatible.
- **Option B** — keep `firstStepSlug()` returning `'activity-picker'` (the default for the most common case) and let `ActivityPickerStep::shouldRunFor( int $user_id )` return `false` when the coach has zero recent rateable activities, redirecting to `player-picker`. Step-skip is already a framework-supported pattern (per `_skipped_steps` in `WizardState`).

**Use Option B** — step-level conditional skip is already in there.

#### Step graph

```
(activity-first, default)
   ↓
ActivityPickerStep [skip-if-empty] → pick activity →
   ↓
AttendanceStep [skip-if-recorded] →
   ↓
RateActorsStep →
   ↓
ReviewStep ← HybridDeepRateStep
            ↑
   (player-first, fallback)
            ↑
   PlayerPickerStep
            ↑
   (entered from "rate a player directly" link
    or because the smart-default resolver skipped
    ActivityPickerStep)
```

### 3. ActivityPickerStep

Lists the coach's recent rateable activities. Source query: activities whose `team_id` is in the coach's `tt_team_people` assignments, `start_at < NOW()`, `start_at >= NOW() - INTERVAL 30 DAY`, and whose `activity_type` lookup row has `meta.rateable = true` (or null/missing — defaults to true). Excludes activities where the coach has already created evaluations for every present player; surfaces partials with a "you've evaluated 4 of 12, continue?" affordance.

Grouped by date: "This week", "Last week", "Earlier" (the last 30 days, capped — older than 30 days is hidden behind a "Show older" link that re-queries with a 90-day cutoff).

Three visual states per row:

- **Unevaluated** — primary visual, the main action target. Click → next step (Attendance or RateActors).
- **Editable** — a coach-by-this-coach evaluation younger than 24 hours exists. Secondary visual; badged with countdown ("Editable for 6h 22m"). Click → enters the wizard at the RateActors step with the existing values pre-loaded; the deep-rate sections start expanded; the submit button reads "Save changes" instead of "Submit". Same wizard, different mode flag in `WizardState`.
- **Locked** — older than 24h, OR the coach is not the original author and lacks RCD team scope. Tertiary visual, badged "Evaluated [date]". Click navigates away from the wizard to the read-only evaluation detail view (`?tt_view=evaluations&id=N&action=view`).

The 24-hour edit window is enforced by `EvaluationsRestController::update()`; the wizard reflects the same rule visually but does not enforce it (the REST layer is the authority).

If a coach holds RCD global (HoD, Academy Admin) — they can edit any evaluation regardless of age. The wizard surfaces this: locked rows for HoD show as "Edit (post-window)" instead of "Locked".

A small "Rate a player directly" link appears at the top-right of the picker — the escape hatch to the player path.

### 4. AttendanceStep

Skipped silently if `tt_attendance` already has rows for the activity. The wizard's framework already supports per-step `shouldRunFor()` logic.

If shown: lists the team's full roster (all members per `tt_team_people` where `functional_role_definition.maps_to_player = true`), each with the four attendance states from the existing `attendance_statuses` lookup (typically `present`, `late`, `absent`, `excused` — driven by lookup, not hardcoded).

Default state for each player is `present`. Coach taps to override. Save persists to `tt_attendance` with `activity_id` set; only `present` and `late` flow forward to RateActors. (`absent` and `excused` are recorded for reports but skipped from rating.)

The attendance step is itself a real attendance update — if the coach later visits the activity, the attendance is set. Not a wizard-only side store.

### 5. RateActorsStep

The heart of the wizard. Renders the rateable players (present/late from AttendanceStep, or all-roster fallback if the coach skipped attendance because it was already recorded — only present/late from there).

**Mobile (≤720px width)**: one player at a time. Player card at top (name, position, age group, photo if any). Quick-rate controls in the middle. Deep-rate panel collapsible at bottom, expanded on tap. Bottom-fixed nav: "Previous", current player N of M, "Next" — swipe gestures on the card area too. The "Skip" affordance is visible: tap and that player gets no evaluation in this batch.

**Desktop (>720px)**: one tall page, all players in a vertical list. Each row = player name + quick-rate controls + collapsed deep-rate accordion. Coach can mix quick and deep ratings freely across players in the same submission. A floating "Saved" indicator stays visible at the bottom-right showing autosave state.

**Quick-rate controls.** Each player gets one row per evaluation category that is marked "quick" in the per-club config (a new `meta.quick_rate = true` flag on `tt_evaluation_categories` rows; same pattern as the activity-type rateable attribute). Migration `0055` seeds the well-known categories — typically Technical, Tactical, Physical, Mental — as quick. Other categories surface only inside the deep-rate panel. The rating control is the standard rating-scale widget already used by the existing eval form (driven by Configuration → Rating Scale).

**Deep-rate panel.** Collapsed by default. When expanded: full sub-criteria for every category (not just quick ones), free-text notes per category, and an attachment slot reusing the existing evaluation-attachment uploader. Coach can deep-rate one player and quick-rate another in the same submission; nothing is enforced.

**Skip.** Each player has a "Skip this player" affordance that flags them as not rated in this submission. Skipped players have no `tt_evaluations` row written; the soft-warn at Review surfaces them.

**Autosave** fires per-player as the coach moves between players (mobile) or as deep-rate fields blur (desktop). `WizardState::merge()` is called with the per-player partial; the visible "Saved 3 sec ago" indicator updates. See draft persistence section below for the cross-device part.

### 6. PlayerPickerStep & HybridDeepRateStep (player-first path)

`PlayerPickerStep` reuses the existing `PlayerSearchPickerComponent::render()` (the search-based picker — clubs with 200+ players need search; the small-club dropdown is a fallback handled by the component itself). Filtered to players the coach has access to (evaluations C team for at least one of the player's teams).

`HybridDeepRateStep` shows: a date-picker (defaults to today), a "setting" dropdown (training / match / tournament / observation / other — driven by a new `evaluation_setting` lookup type), a free-text "reason / context" field (multiline, 500 chars), and the same deep-rate UI as the deep-rate panel from RateActorsStep — full categories, sub-criteria, notes, attachments. No quick-rate row in this path; the player-first flow assumes deep intent.

### 7. ReviewStep

Lists the evaluations about to be created (one row per rated player for the activity path; a single row for the player path). Each row summarises: player name, category-level scores, "see details" link that re-expands the deep-rate panel inline. Edit-in-place: any field can be changed without leaving Review.

If any roster members are present-but-unrated (activity path), a soft-warn appears at the top: "3 players were marked present but not rated. Submit anyway, or go back?" — both buttons available. Submitting writes N evaluations; going back returns to RateActorsStep with the unrated players highlighted.

**Submit semantics**: N × `POST /wp-json/talenttrack/v1/evaluations` (no batch endpoint). The wizard issues them sequentially and shows a per-row progress indicator. If any fail (network blip, validation error), the wizard pauses, surfaces the failed rows with "Retry" / "Skip", and lets the coach decide.

On success: `WizardState::clear()`, `tt_wizards_completed` analytics row written via the existing `WizardAnalytics::recordCompletion()` hook, and the wizard redirects to the evaluations list filtered to the activity (or the player, for player-first), so the coach sees their work.

### 8. Draft persistence — extending WizardState

The current `WizardState` (`src/Shared/Wizards/WizardState.php`) uses a 1-hour transient. The handoff requires "drafts always resume across sessions and devices". A 1-hour transient doesn't satisfy that.

Add a parallel persistent store: `tt_wizard_drafts` table.

```sql
CREATE TABLE {prefix}tt_wizard_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    club_id BIGINT UNSIGNED NOT NULL,
    wizard_slug VARCHAR(64) NOT NULL,
    state_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_wizard (user_id, wizard_slug),
    KEY idx_club (club_id),
    KEY idx_updated (updated_at)
);
```

Migration `0056_wizard_drafts`. Multi-tenant via `club_id`.

`WizardState::save()` writes BOTH the transient (fast path for the same session) AND the table (persistent path for cross-device). `WizardState::load()` checks the transient first, falls back to the table if the transient has expired. `WizardState::clear()` removes both.

**Last-write-wins on cross-device**: the table's `updated_at` is the tiebreaker. No "device switch detected" warnings — the handoff was explicit that we don't want them.

A daily cron `tt_wizard_drafts_cleanup_cron` deletes rows older than 14 days (configurable via the `tt_wizard_draft_ttl_days` filter). 14 days is a reasonable balance between "I started rating at home and want to finish at the club tomorrow" and "the database isn't a graveyard".

### 9. Resume detection

When a coach lands on the wizard entry URL and a draft row exists for `(user_id, 'new-evaluation')`, a banner at the top reads: "You started this 2 days ago — continue or start over?" with both buttons. "Continue" loads the state; "Start over" calls `WizardState::clear()` and starts fresh.

The banner does NOT block. Coach can navigate around it; the existing draft is preserved until they explicitly clear or 14 days elapse.

### 10. Auto-save UI

A small persistent indicator at the bottom-right of the wizard chrome showing one of three states:

- "Saved 3 seconds ago" (fade-in after each save call)
- "Saving..." (during the request)
- "Save failed — retry" (if the autosave POST fails; click retries; surfaces the actual error if it persists for 30s)

Frontend uses `setTimeout` to debounce input by 800ms before posting to a new lightweight endpoint `POST /wp-json/talenttrack/v1/wizard-drafts/{slug}` that writes to the table without going through the heavy `WizardState::merge` (which is hit on real step transitions). This keeps autosave fast.

### 11. Mobile/desktop responsive

Single breakpoint at 720px (the existing `--tt-breakpoint-mobile` CSS variable). Above: desktop layout. At or below: mobile layout. No tablet middle ground per the handoff.

Implementation is CSS-only with `@media (max-width: 720px)` — no JS device sniff. The same component renders both; layout differs. RateActorsStep's "one at a time vs all at once" is a CSS grid switch plus `:focus-within`-driven panel expansion — no separate code path.

### 12. Matrix and seed implications

No new entities. The wizard consumes existing matrix grants:

- `evaluations` — coach RC team (Assistant Coach), RCD team (Head Coach), RCD global (HoD, Admin). Wizard entry gated by `tt_edit_evaluations`.
- `attendance` — coach RC team. Wizard's AttendanceStep writes to it.
- `activities` — coach R team minimum (read for the picker), RCD team (Head Coach) for completeness.

Two existing matrix entities gain implicit user-flow significance but no row change:

- `lookups` — Academy Admin RCD global covers the new "Rateable" checkbox in the lookups admin tab. No new entity.
- `wizard_drafts` — implicit: every persona has self-scoped access to their own drafts. Not a matrix entity because draft persistence is internal infrastructure, not a domain concept exposed to the matrix gate. Documented in `docs/modules.md` under the Wizards module.

## Wizard plan

This IS a wizard spec. The relevant entries from the existing wizard convention:

- **Slug.** `new-evaluation` (unchanged from today).
- **Required cap.** `tt_edit_evaluations` (unchanged).
- **First-step resolver.** Dynamic via Option B (step-level `shouldRunFor()`). `ActivityPickerStep::shouldRunFor()` returns `false` if `recentRateableActivitiesFor(user)->isEmpty()`, in which case the framework auto-skips to `PlayerPickerStep`.
- **Entry points.** All existing call sites continue to work via `WizardEntryPoint::urlFor('new-evaluation', $fallback)`. Specifically: the "+ New evaluation" button on `?tt_view=evaluations`, the "Rate a player" button on player detail views, and the persona-dashboard quick-action tile for coaches.
- **Draft persistence.** Extended via the `tt_wizard_drafts` table — see section 8. Other wizards continue to use the transient-only model unless they opt in to the persistent store via the same `WizardState` API (no per-wizard code change required).
- **Analytics.** `WizardAnalytics::recordStart`, `recordStepCompleted`, `recordSkippedStep`, `recordCompletion`, `recordAbandonment` — all already in place. The new step slugs are added to the analytics dashboard's "step funnel" view automatically because `WizardAnalytics` reads step slugs dynamically from `WizardState`.
- **Success criteria.** A coach completing the activity path produces N `tt_evaluations` rows with `activity_id` set, plus zero or more `tt_attendance` rows if AttendanceStep ran. The player path produces one row with `activity_id = NULL`.

## Out of scope

- **Bulk-rate UX shortcuts** like "rate every player 4 stars on Technical and edit outliers". Useful, but a v2 affordance that needs its own UX design. Add as a follow-up issue if the coach feedback validates demand.
- **Co-rating between coaches.** Two coaches rating the same activity jointly today create two separate `tt_evaluations` rows. Some clubs want a "merge to single rating" model. Out of scope; the data model would need to change, not just the wizard.
- **Voice/video evaluation capture.** A coach speaking notes into a phone instead of typing. Real product opportunity but a separate feature; needs its own spec.
- **Retroactive activity creation from the wizard.** "There was no activity row for the Saturday tournament; create one inline." Out of scope; coach should create the activity first via the activity wizard.
- **Stats/Reports filtering by `meta.rateable`.** The attribute exists and the helper is published, but updating Reports / compare / rate cards to consume it is a separate spec — they currently use their own logic and changing them risks regressions in unrelated views.
- **A new `evaluations/batch` REST endpoint.** Out of scope per the decision to use N × POST. Revisit if the per-row latency becomes a UX problem at the Review submit step (unlikely given typical squad sizes).
- **The "two-coaches-on-same-activity" partial-coverage UX beyond surfacing "you've evaluated X of N".** A richer view (which players each coach rated, gap analysis) is useful but separate.

## Acceptance criteria

- [ ] The redesigned wizard registers under slug `new-evaluation` and `WizardEntryPoint::urlFor('new-evaluation', $fallback)` continues to resolve from existing call sites without any of those call sites being modified.
- [ ] A coach with at least one recent rateable activity is landed on `ActivityPickerStep`. A coach with none is landed on `PlayerPickerStep` via the framework's existing skip mechanism. The escape-hatch link is visible on both landings.
- [ ] The `tt_lookups.meta` JSON field is populated with `{"rateable": false}` for `clinic`, `methodology`, `team_meeting` after migration `0054` runs. All other `activity_type` rows have unchanged `meta`. The read helper `LookupsRepository::isActivityTypeRateable()` returns the meta value or `true` for unmarked rows.
- [ ] `tt_wizard_drafts` table exists after migration `0056` with the documented schema. `WizardState::save()` writes both transient and table; `WizardState::load()` falls back from transient to table; `WizardState::clear()` removes both.
- [ ] A coach who started the wizard on phone, closed the browser, and opens the wizard on desktop the next day sees a "continue or start over" banner and can resume at the step they left.
- [ ] The autosave indicator updates within 1 second of a quick-rate change. A simulated network failure surfaces "Save failed — retry"; clicking retry succeeds when the network returns.
- [ ] The wizard registers its steps such that the `WizardAnalytics` step funnel reports the new step slugs without code changes to the analytics module.
- [ ] The activity picker excludes activities older than 30 days by default and reveals up to 90 days when "Show older" is clicked.
- [ ] Locked evaluations (>24h, not author, not RCD-global holder) are visually distinct in the picker and do not enter the wizard — clicking navigates to the read-only detail view.
- [ ] HoD and Academy Admin (holders of evaluations RCD global) see "Edit (post-window)" instead of "Locked" on >24h rows and can edit them in the wizard.
- [ ] The N × POST submission shows per-row progress at Review submit. A failed row pauses submission and offers Retry / Skip per row. Successful rows are not re-submitted on retry.
- [ ] Mobile view (`max-width: 720px`) shows one player at a time in `RateActorsStep` with bottom-fixed navigation. Desktop view shows the full vertical list. Both are CSS-driven, no JS device detection.
- [ ] The daily `tt_wizard_drafts_cleanup_cron` deletes rows older than 14 days (or the value of the `tt_wizard_draft_ttl_days` filter).
- [ ] Soft-warn at Review fires when one or more present-but-unrated players exist; submitting anyway succeeds and writes only the rated rows.

## Notes

### Documentation updates per CLAUDE.md § 5 / Definition of Done

- `docs/wizards.md` and `docs/nl_NL/wizards.md` — replace the existing two-step new-evaluation walkthrough with the new step graph; document the activity-vs-player branching logic, the smart-default landing, the editable / locked badging, and the cross-device draft resume behaviour. Note the 14-day TTL and the `tt_wizard_draft_ttl_days` filter for clubs that want to override.
- `docs/modules.md` — add a row under Configuration noting the new "Rateable" checkbox on activity types and what disabling it does (the activity type vanishes from the new-evaluation activity picker but remains visible on the activity itself, in stats, and in reports — rateable is a wizard-eligibility flag, not a "hide from product" flag).
- `docs/access-control.md` and Dutch mirror — no rewrite needed for the existing roles, but a one-line addition under Team Manager: "Cannot create evaluations (matrix grants R team only); the new-evaluation wizard is correctly inaccessible to this persona."
- A new doc `docs/new-evaluation-wizard.md` and Dutch mirror — operator-facing reference for coaches: how to choose between activity and player flows, how the editable window works, what happens to drafts. Linked from the persona-dashboard help tile.
- `languages/talenttrack-nl_NL.po` — every new user-facing string. Suggested key strings: "Activiteit kiezen", "Aanwezigheid", "Spelers beoordelen", "Snel beoordelen" / "Diep beoordelen", "Doorgaan of opnieuw beginnen?".
- `SEQUENCE.md` — append the spec row.
- `CHANGES.md` — one entry: "New evaluation wizard rebuilt: activity-first, attendance-aware, multi-player batch flow with cross-device drafts. Player-first ad-hoc flow available as an escape hatch."

### CLAUDE.md guideline updates

None for this spec — it doesn't introduce a new architectural pattern. The persistent-draft extension to `WizardState` is documented in the Wizards module's docblock.

### Test hooks per the existing testing convention

- **Unit**: `LookupsRepository::isActivityTypeRateable()` returns the `meta.rateable` value when set, `true` when missing, `true` when meta isn't valid JSON.
- **Unit**: `WizardState::save` round-trips through the table when the transient is missing.
- **Integration**: a coach starts the wizard via mobile-emulating browser, autosaves, switches to a desktop browser, and resumes at the same step with the same partial state.
- **Integration**: a coach with zero recent rateable activities is landed on `PlayerPickerStep`.
- **Integration**: a coach with one recent activity (rateable) sees one row in the picker; the same coach after marking the activity type's `meta.rateable = false` sees zero rows.
- **Integration**: editing an existing-coach evaluation within 24h enters the wizard at RateActors with deep-rate expanded; submitting writes UPDATE rows (not new INSERTs) on the existing `tt_evaluations` records.
- **Integration**: a Team Manager attempting to load `?tt_view=wizard&slug=new-evaluation` is rejected with the standard "you do not have permission" message; the entry-point links don't render for them on manage views.
- **E2E**: the full activity-first happy path — pick activity, mark attendance, quick-rate three players, deep-rate two more, submit. Five `tt_evaluations` rows are created with `activity_id` set; `tt_attendance` rows are written; the wizard analytics row records the completion.

### Open question deliberately left for implementation

The exact per-category quick-rate threshold (which `tt_evaluation_categories` are marked `meta.quick_rate = true` by default in the seed) is a product call that needs a coach review session, not a spec decision. The migration `0055` pre-fills the four conventional ones (Technical / Tactical / Physical / Mental); the seed comment notes that this is a default and clubs can flip individual categories on/off via the existing eval-categories admin page.

### One small inconsistency to fix while in this area

The existing `WizardEntryPoint::dashboardBaseUrl()` hardcodes the shortcode name as `[talenttrack_dashboard]` in its docblock comment (line 24). The actual shortcode is `[tt_dashboard]`. Trivial doc-only fix; bundle it into this PR rather than spinning up a separate one.
