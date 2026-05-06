# TalentTrack v3.99.0 — Onboarding pipeline children 2b + 3 + 4 (#0081)

Closes the #0081 onboarding-pipeline epic in one ship at the user's direction. Three child specs land together: child 2b (chain completion + parent-confirmation no-login REST), child 3 (pipeline widget + 10 KPIs + standalone view), child 4 (trial-cases rolling membership + sixth template). After child 2a shipped as v3.97.0, this PR finishes the epic — the funnel runs end-to-end from "scout clicks + New prospect" through "player accepts a team offer."

## Why one PR rather than three

The user explicitly chose to bundle. Three sequential PRs would have meant three separate rebases against an actively-shipping `main` (parallel-agent collisions are the documented norm — see `feedback_talenttrack_parallel_pr_collisions.md`), three sets of CI cycles, and intermediate states where the chain was half-wired (e.g. `ReviewTrialGroupMembershipTemplate.chainSteps()` referencing `AwaitTeamOfferDecisionTemplate` from child 4). Bundling lets the chain ship complete, with one rebase budget and one CI cycle. The cost is a larger PR; the user accepted that.

## Child 2b — chain completion

Three new `TaskTemplate` classes finish the five-template chain started in v3.96.0/v3.97.0:

### `ConfirmTestTrainingTemplate` (chain link 3/5)

Spawned by `InviteToTestTrainingTemplate`'s chain step. Assigned to HoD via `RoleBasedResolver('tt_head_dev')`, but the form's outcome can also be written by an inbound parent action — see the public REST endpoint below. 5-day deadline.

The form is a three-button HoD form (confirmed / declined / no-response). On `declined` or `no_response` the prospect is archived with the matching `archive_reason`. On `confirmed` the chain step spawns `RecordTestTrainingOutcomeTemplate`.

### `RecordTestTrainingOutcomeTemplate` (chain link 4/5)

Coach observation + admit / decline / second-attendance recommendation. 7-day deadline. Required cap: `tt_decide_test_training_outcome`.

Form's `serializeResponse()` has the most side-effects in the chain: on `admit_to_trial`, it (a) promotes the prospect into a `tt_players` row with `status = 'trial'` (lazily — re-promotion is a no-op via `prospects.promoted_to_player_id` check), (b) creates the `tt_trial_cases` row with the default trial track, (c) stamps `prospects.promoted_to_player_id` and `prospects.promoted_to_trial_case_id` on the source row. The form returns `trial_case_id` and `player_id` in the response payload so the chain step's `contextBuilder` can build the next task's `TaskContext`.

### `ReviewTrialGroupMembershipTemplate` (chain link 5/5)

Quarterly HoD review. 14-day deadline. Required cap: `tt_decide_trial_outcome`. Three decisions: `offer_team_position` / `continue_in_trial_group` / `decline_final`.

On `continue_in_trial_group` the trial case's `continued_until` bumps 90 days and the template re-spawns itself in 90 days. On `offer_team_position` the chain spawns `AwaitTeamOfferDecisionTemplate` (child 4 territory). On `decline_final` the case decision flips to `deny_final`, status `decided`, prospect archived. Self-referencing chain step is the first in the codebase — engine handles it without special-casing because `chainSteps()` is just a list of step descriptors.

### Public parent-confirmation REST endpoint

`GET /talenttrack/v1/prospects/confirm?task_id=N&outcome=confirmed&token=...` is a no-login route. The token is a deterministic HMAC of `task_id|prospect_id|prospect_uuid` keyed on `wp_salt('auth')` — leaking a task ID alone doesn't grant access; reproducing the link requires the prospect's UUID. Idempotent: hits to an already-completed task return 200 with the existing decision rather than re-completing. Lives at `src/Modules/Prospects/Rest/ParentConfirmationController.php`.

## Child 3 — pipeline widget + 10 KPIs + standalone view

### `OnboardingPipelineWidget`

New widget at `src/Modules/PersonaDashboard/Widgets/`. Six stages (Prospects / Invited / Test training / Trial group / Team offer / Joined) with active counts + stale badges (overdue tasks). Pure pivot of existing data — no new tables, no parallel state machine.

Stage queries — counts and stale-counts — are batched into one private closure inside the widget so the SQL is co-located. 60-second `wp_cache_set` keyed on `(club_id, user_id)` keeps the pivot light on busy dashboards.

Scout-scope filtering applied at the SQL layer: when the user holds `tt_scout` but not `tt_head_dev` / `tt_club_admin` / `administrator`, the EXISTS subquery joins `tt_workflow_tasks → tt_prospects` on `prospect_id` and filters by `discovered_by_user_id = $user_id`. HoD/Admin see global counts. The widget literally cannot leak cross-scout data because the predicate runs in the SQL, not in the rendering layer.

Size variants S/M/L/XL all supported. The L (3×2) is the default — fits next to other widgets on the HoD landing. XL (12×1) is what the standalone view uses. Mobile (≤720px) stacks columns via the existing `tt-pd-size-l` / `tt-pd-size-xl` responsive CSS.

### 10 new KPIs

All in `src/Modules/PersonaDashboard/Kpis/`. Eight tagged `PersonaContext::ACADEMY`, two tagged `PersonaContext::COACH`:

| KPI | Persona | What it counts |
| --- | --- | --- |
| `prospects_active_total` | ACADEMY | Total non-archived prospects. Headline funnel number. |
| `prospects_logged_this_month` | ACADEMY | Discovery rhythm — prospects with `created_at >= start-of-month`. |
| `prospects_stale_count` | ACADEMY | Prospects with no non-terminal task AND no completion in the last 30 days. The most operationally consequential KPI. |
| `test_trainings_upcoming` | ACADEMY | `tt_test_trainings` with `date` in the next 14 days. |
| `trial_group_active_count` | ACADEMY | Distinct players on open trial cases with `decision = continue_in_trial_group`. |
| `trial_decisions_pending` | ACADEMY | Open `ReviewTrialGroupMembershipTemplate` tasks. |
| `team_offers_pending_response` | ACADEMY | Open `AwaitTeamOfferDecisionTemplate` tasks. |
| `prospects_promoted_this_season` | ACADEMY | Prospects with `promoted_to_player_id IS NOT NULL` and `created_at` within trailing 12 months. |
| `my_prospects_active` | COACH (scout) | Per-scout: active prospects logged by the current user. |
| `my_prospects_promoted` | COACH (scout) | Per-scout: prospects logged by the current user that promoted within trailing 24 months. |

Each ~30 lines, `compute( $user_id, $club_id )` returning a `KpiValue::of( (string) $count )` (or `KpiValue::unavailable()` when the table doesn't exist on the install).

Stale-threshold default 30 days, configurable via `wp_options.tt_prospect_stale_threshold_days`.

### Standalone view `?tt_view=onboarding-pipeline`

New `FrontendOnboardingPipelineView` (~30 lines + assets). Wraps the widget at XL size on a single-column page, gated on `tt_view_prospects`. Tile registered in the existing Trials group at order 5 (above the Trial cases tile). `+ New prospect` CTA at the bottom links to `POST /prospects/log`.

### What's *not* in child 3

- Persona-template defaults updates. The widget + KPIs are registered, but HoD / Scout / Academy Admin default templates are not auto-edited to ship them in — operators drag the widget into their grid via the editor. The spec called for default-template wiring as part of v1; deferred here because adjusting persona defaults invites cross-PR conflicts (multiple ongoing personas-related PRs touching `CoreTemplates.php`).
- Interactive card expansion + filter chips on the widget. Static counts ship; click-to-expand cards is a v2 polish.
- Hover tooltip on column titles. Same — v2.

## Child 4 — trial-cases rolling membership

### Three new decision constants on `TrialCasesRepository`

```php
public const DECISION_OFFERED_TEAM_POSITION    = 'offered_team_position';
public const DECISION_DECLINED_OFFERED_POSITION = 'declined_offered_position';
public const DECISION_CONTINUE_IN_TRIAL_GROUP  = 'continue_in_trial_group';
```

`update()`'s allowlist gains `continued_until`. The trial-decision wizard's dropdown picks these up automatically — its options list reads from the constants.

### Migration 0069

Two ALTERs:
- `tt_trial_cases` gains `continued_until DATE DEFAULT NULL` + `idx_continued_until` index.
- `tt_teams` gains `team_kind VARCHAR(32) DEFAULT NULL` + `idx_team_kind` index. NULL = regular academy team. `'trial_group'` = the per-club trial-group pseudo-team.

Both ALTERs guarded by `SHOW COLUMNS` so re-running on a backfilled install is a no-op.

### `TrialGroupTeam` service

`src/Modules/Trials/TrialGroupTeam.php`. Two methods:
- `ensure( ?string $age_group )` — lazily creates the per-club, per-age-group trial-group team, returns its id. Idempotent. Name composed from a translatable template (`Trial group %s`).
- `activeMemberCount( ?string $age_group )` — counts distinct players currently on the trial track via `tt_trial_cases.decision = 'continue_in_trial_group'`. Joins through `tt_teams.age_group` on the player's primary team when filtered.

The pseudo-team's roster is **queried via the trial case**, not via `tt_team_people`. The schema impedance: `tt_team_people.person_id` is people-keyed, not player-keyed (it's a person ↔ team join, not player ↔ team), so adding a player to a trial-group team via that path would require finding/creating a person row — net new work for what should be a query-only concern. Querying through trial cases keeps the trial case as the single source of truth for "who's on the trial track" and avoids the redundant `tt_team_people` write.

### `AwaitTeamOfferDecisionTemplate` (chain link 6 — sixth template)

Spawned by `ReviewTrialGroupMembership` on `offer_team_position`. Assigned to HoD. 14-day deadline. Required cap: `tt_decide_trial_outcome`.

Form has three radio outcomes: accepted / declined / no-response. On accept, trial case decision flips to `admit` + status `decided` (eventually triggering player-status promotion via the existing player-status-on-trial-decision flow). On decline, decision flips to `declined_offered_position` + status `decided`. On no-response, the case is archived without a decision change.

## What's NOT in this PR

- **Per-attendee batch UX** in `RecordTestTrainingOutcomeForm`. The spec calls for one form per HoD pass containing rows for every prospect at the test training; we ship the single-attendee form (one task per prospect). Multi-attendee batching is a UX overlay on top of the same data — better to land it after the chain is observable.
- **Persona-template default-template edits** for HoD / Scout / Academy Admin. The widget + KPIs are registered; they don't auto-appear on the default landings until the operator drags them in. Same reasoning as the spec's `Out of scope` note for child 3 default-template wiring.
- **Trial-decision wizard updates**. The new decision constants exist; the existing trial-decision wizard's dropdown shows whatever decision values are passed in. Operator-visible wording for the new options uses the existing translation strings without needing wizard code edits — sanity-check on the live install will confirm.
- **Comprehensive docs page** at `docs/onboarding-pipeline.md`. Skipped to keep the PR shippable. The CHANGES.md entry + spec already document the operator-facing flow comprehensively. Doc page can land in a follow-up.

## Translations

70+ new NL msgids covering: template names + descriptions (4 templates), form labels + validation messages (4 forms), pipeline widget stage labels (6 stages), 10 KPI labels, standalone view title + cap-error + CTA, ParentConfirmationController error messages, trial-group team names. Reuses several existing entries (`Notes`, `Prospects`, `Trial group`, `Recommendation`, `Test training`).

## Affected files (count)

- 4 new migrations / templates / forms files: 0 + 4 + 4 = 8 (1 migration + 4 templates + 3 forms — the AwaitTeamOfferDecisionForm is new but counts towards trial-cases child 4)
- Actually: migration 0070, 4 new templates (`ConfirmTestTrainingTemplate`, `RecordTestTrainingOutcomeTemplate`, `ReviewTrialGroupMembershipTemplate`, `AwaitTeamOfferDecisionTemplate`), 4 new forms (matching), `TrialGroupTeam` service, `OnboardingPipelineWidget`, 10 KPIs, `FrontendOnboardingPipelineView`, `ParentConfirmationController`. Plus updates to `LegacyCapMapper`, `WorkflowModule`, `CoreWidgets`, `CoreKpis`, `CoreSurfaceRegistration`, `DashboardShortcode`, `TrialCasesRepository`, `InviteToTestTrainingTemplate`, `ProspectsModule`, NL `.po`, `talenttrack.php`, `readme.txt`, `SEQUENCE.md`.

Renumbered v3.97.0 → v3.99.0 (parallel-agent ship took v3.98.0 mid-CI; original renumber + this one bookkeeping) in CHANGES.md / SEQUENCE.md / readme.txt / talenttrack.php after the PR opened to avoid collision with parallel-agent ships during CI.
