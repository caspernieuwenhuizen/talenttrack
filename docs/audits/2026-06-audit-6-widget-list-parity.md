<!-- audience: dev -->

# Audit 6 — Widget → list deep-link filter parity

Date: 2026-06-03. Spec: #1180. Performed against the v4.17.x working tree.

## Summary

The KPI catalogue under `src/Modules/PersonaDashboard/Kpis/` has 36 data
sources. About a third of them have a non-stub `compute()` that applies
non-trivial WHERE clauses (date windows, status enums, archived filters,
demo-scope). Two widgets render those KPIs as clickable cards:

- `KpiCardWidget` — the dominant placement (every persona dashboard grid
  uses individual cards via `Defaults\CoreTemplates::add('kpi_card', …)`).
- `KpiStripWidget` — only used in the HoD top hero strip (one slot in
  `CoreTemplates`, line 246).

The audit found **one structural defect** and **four real per-KPI
drifts**. The structural defect dominates: `KpiCardWidget::render()`
(lines 66–69) wires the click-through using `linkView()` only — it never
calls `linkUrl()`. That means three KPIs that overrode `linkUrl()` to
deep-link with filters (`MyTeamAttendancePct`, `MyTeamAvgRating`,
`PdpVerdictsPending`) deliver their filters only when placed in a
`kpi_strip`, not when placed in a `kpi_card`. The fix moves the
`linkUrl()` resolution into `KpiCardWidget` (and into `AbstractWidget` as
a shared helper) so every clickable KPI honours the override.

The four per-KPI drifts that remain after that structural fix:

| # | Widget / KPI | Drift | Operator impact |
| - | - | - | - |
| A | `ActivePlayersTotal` | compute filters `status='active'`; destination defaults `filter[archived]=active` (archived only). Trial / released / inactive players with `archived_at IS NULL` show in list but not in count. | KPI says "12 active", list says "17 players". Pilot already filed once as #478. |
| B | `RecentAcademyEvents` | compute counts rows `created_at >= -30d`; destination `audit-log` has no date filter applied by default and shows ALL rows. | KPI "12 recent events" → list of 8,400 audit rows since install. |
| C | `MyEvaluationsThisWeek` | compute filters `created_at >= -7d`; destination `my-evaluations` shows trailing 30 days via `EvaluationsRepository::recentForCoach( $coach_user_id, 30 )`. | KPI "3 this week", list shows 11 evals back to last month. |
| D | `MyTeamAvgRating::linkUrl()` deep-links to `evaluations?filter[date_from]=...` for the rolling 90-day window — but `compute()` also filters `e.archived_at IS NULL`, while the destination list defaults to non-archived already, so that part is fine. Issue: `linkUrl()` doesn't pass `filter[team_id]` even though `compute()` filters `pl.team_id IN coach_teams`. A coach's "team avg" 7.4 lands on an evaluations list that includes evaluations across every team they can see (`(pl.team_id IN coach_teams OR e.coach_id = uid)` — could include other-team evals the coach personally wrote). | Coach sees "team avg 7.4", clicks, sees a list mixing the team's evals with the coach's own out-of-team evaluations. |

A handful of KPIs (`ProspectsActiveTotal`, `TestTrainingsUpcoming`,
`TrialGroupActiveCount`, etc.) only filter by `club_id` and
`archived_at IS NULL` — their destinations default to the same scope, so
no drift. Documented at the bottom under "intentional broadening / no
drift" so the audit doesn't re-flag them next quarter.

## KPI ↔ destination parity table

Filter notation: `D` = date range, `T` = team_id IN, `S` = status enum,
`A` = archived_at IS NULL, `X` = demo-scope, `C` = club_id, `U` =
user_id-as-author / discovered_by. `linkUrl args`: what's actually
appended to the destination URL.

### Academy persona

| KPI class | KPI key | compute filters | linkUrl args | Parity | Drift |
| - | - | - | - | - | - |
| `ActivePlayersTotal` | `active_players_total` | C, A, `status='active'`, X | (default `viewUrl('players')`, no args) | NO | Destination list does NOT filter `status='active'`. See finding A. |
| `EvaluationsThisMonth` | `evaluations_this_month` | C, `created_at >= 1st of month`, X | (default, no args) | NO | Destination shows all evaluations; no date prefilter. **Operator confusion: KPI "23 this month" → list of 312 evaluations.** Same pattern as MyTeamAttendancePct fix in #771 but never applied here. |
| `NewEvaluationsThisWeek` | `new_evaluations_this_week` | `created_at >= -7d`, X | (default, no args) | NO | Same pattern as above on a 7-day window. KPI "5 this week" → unfiltered list. |
| `AttendancePctRolling` | `attendance_pct_rolling` | C, `session_date in -28d..today`, `plan_state='completed'`, `record_type='actual'`, X | (default `viewUrl('activities')`, no args) | NO | Destination activities list has no date filter; shows past + upcoming all-time. KPI "78%" rolls over 28 days; click lands on far broader list. |
| `OpenTrialCases` | `open_trial_cases` | C, A, `status IN ('open','extended')` | (default `viewUrl('trials')`, no args) | NO | Trials list defaults to no status filter (`f['status']` only if `$_GET['status']` set) — shows decided + archived too. Pilot already burned by #481-adjacent confusion. |
| `RecentAcademyEvents` | `recent_academy_events` | `created_at >= -30d` (no `club_id`!) | (default `viewUrl('audit-log')`, no args) | NO | See finding B. Plus: compute is missing `club_id` filter (cross-tenant leak on multi-tenant installs). |
| `GoalCompletionPct` | `goal_completion_pct` | C, X | (default, no args) | YES (intentional broadening) | KPI is a `%`; destination is a list. No date filter on either side. |
| `GoalsByPrincipleKpi` | `goals_by_principle_pct` | C, `created_at >= -90d`, X | (default, no args) | NO | KPI rolls 90 days; destination list defaults to all-time. Less severe (the KPI is "tagged %" not a count) but operator inspecting "which untagged goals are dragging the %" sees old data too. |
| `PdpVerdictsPending` | `pdp_verdicts_pending` | C, A, verdict IS NULL OR NOT signed_off | `viewUrl('pdp') + filter[status]=open` | YES (and only because KpiStripWidget honours linkUrl) | But fired only in the strip — see structural defect for kpi_card placements. |
| `TrialGroupActiveCount` | `trial_group_active_count` | C, A, `decision=continue_in_trial_group` | linkView empty → no link | n/a | Card is inert; safe. |
| `TestTrainingsUpcoming` | `test_trainings_upcoming` | C, A, `date in [now, now+14d]` | linkView empty | n/a | Inert. |
| `TrialDecisionsPending` | `trial_decisions_pending` | C, `template_key='review_trial_group_membership'`, status IN actionable | linkView empty | n/a | Inert. |
| `TeamOffersPendingResponse` | `team_offers_pending_response` | C, `template_key='await_team_offer_decision'`, status IN actionable | linkView empty | n/a | Inert. |
| `ProspectsActiveTotal` | `prospects_active_total` | C, A | linkView empty | n/a | Inert. Destination `FrontendProspectsOverviewView` defaults to `filter[status]=active` (archived excluded) — would be parity-safe if linked. |
| `ProspectsLoggedThisMonth` | `prospects_logged_this_month` | C, `created_at >= 1st of month` | linkView empty | n/a | Inert. |
| `ProspectsPromotedThisSeason` | `prospects_promoted_this_season` | C, `promoted_to_player_id IS NOT NULL`, `created_at >= -365d` | linkView empty | n/a | Inert. |
| `ProspectsStaleCount` | `prospects_stale_count` | C, A, `promoted_to_player_id IS NULL`, no recent completed/open task | linkView empty | n/a | Inert. Highest-impact KPI on HoD board if it gets a click target — explicit follow-up. |

### Coach persona

| KPI class | KPI key | compute filters | linkUrl args | Parity | Drift |
| - | - | - | - | - | - |
| `MyEvaluationsThisWeek` | `my_evaluations_this_week` | `created_by=uid OR coach_id=uid`, `created_at >= -7d`, X | (default `viewUrl('my-evaluations')`, no args) | NO | See finding C. Destination renders 30-day window. |
| `MyTeamAttendancePct` | `my_team_attendance_pct` | C, T (coach teams), `session_date in -28d..today`, `plan_state IN ('completed','in_progress')`, `record_type='actual'`, X | `viewUrl('activities') + filter[date_from]= + filter[date_to]= + filter[plan_state]=completed,in_progress` | YES IN STRIP / NO IN CARD | Structural defect — `linkUrl()` is dead in kpi_card. After the structural fix: still missing `filter[team_id]` for multi-team coaches (compute filters T, link doesn't). |
| `MyTeamAvgRating` | `my_team_avg_rating` | C, A, T (coach teams), `eval_date >= -90d`, X | `viewUrl('evaluations') + filter[date_from]=` | YES IN STRIP / NO IN CARD | Structural defect. Plus: missing `filter[team_id]` — see finding D. |
| `MyOpenWorkflowTasks` | `my_open_workflow_tasks` | C, `assignee_user_id=uid`, status IN actionable, `snoozed_until IS NULL OR <= now()` | (default `viewUrl('my-tasks')`, no args) | YES | `FrontendMyTasksView::openCountForUser()` uses the SAME WHERE clause — destination defaults match. Documented as parity-safe in the source. |
| `MyProspectsActive` | `my_prospects_active` | C, A, `discovered_by_user_id=uid` | linkView empty | n/a | Inert. Worth linking → follow-up. |
| `MyProspectsPromoted` | `my_prospects_promoted` | C, `discovered_by_user_id=uid`, `promoted_to_player_id IS NOT NULL`, `created_at >= -730d` | linkView empty | n/a | Inert. |

### Player / parent persona

All five (`MyRatingTrend`, `MyTeamPodiumPosition`, `MyGoalsCompletedSeason`,
`MyActivitiesAttendedPct`, `MyEvaluationsReceived`, `MyPdpConversationsDone`,
`MyNextMilestone`) return `KpiValue::unavailable()` — out of scope for
parity until the backing epics ship.

### Stubs (no parity check possible)

`AvgEvaluationRating`, `PlayersAtRisk`, `PlayersTopQuartile`,
`MyPlayersEvaluatedSeason`, `PdpPlannedVsConductedBlock`,
`CohortDistribution`. Mention here so a future audit knows they were
scanned, not skipped.

### Non-KPI widget audit

The 22 widget classes under `src/Modules/PersonaDashboard/Widgets/`
include eight that have their own non-KPI "click through" affordance
(`OnboardingPipelineWidget`, `MatchesNeedingReviewWidget`,
`TaskListPanelWidget`, `MiniPlayerListWidget`, `ScoutingPlanWidget`,
`AssignedPlayersGridWidget`, `TeamRosterTableWidget`, `DataTableWidget`).
Spot-checked the two highest-traffic:

- `OnboardingPipelineWidget` — fragment-anchored links (`#stage-prospects`)
  to a `?tt_view=onboarding-pipeline` destination that filters server-side
  by the same `ProspectStageClassifier`. Parity-safe by construction
  (`computeStageCounts` is shared between widget and destination).
- `MatchesNeedingReviewWidget` — links to
  `?tt_view=match-executions&state=pending_review`. The destination view
  reads `$_GET['state']` and filters identically. Parity-safe.

The other six wrap a query inside the widget body itself; they ARE the
list, not a count + drill-down, so the parity question doesn't apply.

## Pattern recommendation

The root cause for findings A–D is the same: KPI `compute()` and KPI
`linkUrl()` are TWO authorities for the same filter set. The filter set
is asserted in SQL once and in PHP query-string args a second time, and
the two drift independently as code evolves (the audit history shows
#771, #775, #781 each ran this exact bug fix on different KPIs).

Recommended structural fix in addition to the per-KPI specs below:

1. Hoist the filter set onto a single named method, e.g.
   `KpiDataSource::filterSpec(): array` returning a normalised filter
   array (`['team_id' => [...], 'date_from' => '...', 'status' => [...]
   ]`). Both `compute()` and `linkUrl()` consume it. Drift becomes
   impossible — they read from the same struct.
2. Have `AbstractKpiDataSource::linkUrl()` auto-derive the query-string
   from `filterSpec()` so KPIs that don't override get filters for free.
3. Move the `linkUrl()` resolution out of `KpiStripWidget` and
   `KpiCardWidget` into a shared helper on `AbstractWidget`
   (`kpiHrefFor(KpiDataSource)`) so the structural defect can't recur on
   future widgets that surface KPIs.

The per-KPI specs below assume the structural fix lands first.

## Follow-up issue specs

### Issue A — `KpiCardWidget` never honours `linkUrl()` overrides

**Spec**:

`KpiCardWidget::render()` (lines 41–74 in
`src/Modules/PersonaDashboard/Widgets/KpiCardWidget.php`) builds the
click-through URL as:

```php
$link_view = ( $source !== null && method_exists( $source, 'linkView' ) ) ? $source->linkView() : '';
if ( $link_view !== '' ) {
    $url = $ctx->viewUrl( $link_view );
    ...
}
```

It never calls `linkUrl( $ctx )`. Result: every per-KPI override that was
added to deep-link with prefilters (`MyTeamAttendancePct`,
`MyTeamAvgRating`, `PdpVerdictsPending`) is dead code in the dominant
placement (kpi_card slot — used by `CoreTemplates::addKpiCard()` for every
persona). The override only fires when the same KPI is placed in a
`kpi_strip`, which is the HoD top-hero exception.

Fix: mirror `KpiStripWidget::render()` lines 60–68 — prefer `linkUrl()`,
fall back to `linkView()`:

```php
$url = '';
if ( $source !== null ) {
    if ( method_exists( $source, 'linkUrl' ) ) {
        $url = $source->linkUrl( $ctx );
    } elseif ( method_exists( $source, 'linkView' ) ) {
        $v = $source->linkView();
        if ( $v !== '' ) $url = $ctx->viewUrl( $v );
    }
}
```

Better: extract `kpiHrefFor( KpiDataSource $s, RenderContext $ctx ): string`
on `AbstractWidget` and call it from both widgets so the next time a third
widget surfaces KPIs (the `RateCardHeroWidget` already does), it can't
forget.

**Acceptance**: a coach with the default coach-template dashboard who
clicks the "My team attendance %" kpi_card lands on
`?tt_view=activities&filter[date_from]=…&filter[date_to]=…&filter[plan_state]=completed,in_progress`,
not bare `?tt_view=activities`.

**Wizard plan**: Exemption — bugfix.

Ref `#1180`.

### Issue B — `ActivePlayersTotal` deep-link doesn't carry `filter[status]`

**Spec**:

`ActivePlayersTotal::compute()` filters `p.status = 'active'`
(`src/Modules/PersonaDashboard/Kpis/ActivePlayersTotal.php` line 41).
The destination `FrontendPlayersManageView` defaults to
`filter[archived]=active` (archived/not-archived) but does NOT filter by
`status`. A trial / released / inactive player with `archived_at IS NULL`
shows in the list but not in the count.

The pilot already flagged this once as #478 — the symptom was 30 vs 29 in
demo mode. The fix at the time was to thread demo-scope through the KPI;
the deeper parity gap on `status` was missed.

Fix: override `linkUrl()` to append `filter[status]=active` (the
`PlayersRestController` already accepts `filter[status]` — verify the
exact param name; if not, extend the controller alongside).

**Acceptance**: KPI "12 active players" clicks through to a list of 12
rows (modulo pagination), not 17.

**Wizard plan**: Exemption — bugfix.

Ref `#1180`.

### Issue C — Academy KPIs miss the date-window deep-link pattern that `#771` introduced for coach KPIs

**Spec** (consolidated — same fix applies to 5 KPIs):

`#771` shipped the "date-window in the deep link" pattern on the two
coach KPIs (`MyTeamAttendancePct`, `MyTeamAvgRating`). The same pattern
needs to ship on the academy KPIs that have a date window in
`compute()`:

- `EvaluationsThisMonth` — append `filter[date_from]=<1st of month>` to
  `viewUrl('evaluations')`.
- `NewEvaluationsThisWeek` — append `filter[date_from]=<today-7d>`.
- `AttendancePctRolling` — append `filter[date_from]=<-28d>` +
  `filter[date_to]=<today>` + `filter[plan_state]=completed` to
  `viewUrl('activities')`.
- `RecentAcademyEvents` — append `f_date_from=<-30d>` (note: audit-log
  uses `f_date_from`, not `filter[date_from]`); also add the missing
  `club_id` scope to compute() at the same time.
- `GoalsByPrincipleKpi` — append `filter[date_from]=<-90d>` to
  `viewUrl('goals')` (lower priority — the KPI's % framing makes the
  drift less visible than a count drift).

Same defensive pattern as `MyTeamAttendancePct`: pull the window into a
shared constant + private static helper, consume it in both `compute()`
and `linkUrl()` so drift is structurally impossible.

**Acceptance** (per KPI): clicking the card lands on a destination list
whose row count equals the KPI's headline number (±1 for cross-day
rollover during the click).

**Wizard plan**: Exemption — bugfix batch (5 KPIs, same shape, same
fix).

Ref `#1180`. Depends on issue A landing first so the override actually
fires from kpi_card placements.

### Issue D — `OpenTrialCases` deep-link doesn't carry `status=open,extended`

**Spec**:

`OpenTrialCases::compute()` filters `status IN ('open','extended')`
(`OpenTrialCases.php` line 37). The `FrontendTrialsManageView` reads
`?status=<one of: open|extended|decided|archived>` (line 153) and shows
ALL trial cases when no status is set.

Fix: override `linkUrl()` to append `?status=open` (the picker has no
"open + extended" composite option, so a single value is the closest
honest match — pair with a follow-up in #481 if the operator needs both
in one click).

**Acceptance**: HoD with 3 open + 2 extended cases sees KPI "5 open trial
cases", clicks, lands on a filtered list that doesn't include decided
ones.

**Wizard plan**: Exemption — bugfix.

Ref `#1180`. (Adjacent to historical fixes #481 / #475 on the same
KPI — should be cited in the commit.)

### Issue E — Coach KPIs that filter on `team_id IN (coach teams)` should pass the team filter to the destination

**Spec**:

`MyTeamAttendancePct` and `MyTeamAvgRating` both call
`QueryHelpers::get_teams_for_coach( $user_id )` and filter
`pl.team_id IN (...)`. Their `linkUrl()` overrides pass `date_from` /
`date_to` but NOT `filter[team_id]`. For a coach who only head-coaches
one team this is a no-op. For an assistant coach who's linked to three
teams it's a real drift — the destination evaluations / activities list
will include the union, not the per-team breakdown, while the KPI is
already a club-side filter.

Worse for `MyTeamAvgRating`: the evaluations list since v3.110.126 uses
`pl.team_id IN coach_teams OR e.coach_id = uid` — adding evaluations the
coach personally wrote on players outside their team. The KPI's compute
uses `pl.team_id IN coach_teams` only. Operator sees "team avg 7.4",
clicks, list includes the coach's out-of-team evals.

Fix: have both KPIs' `linkUrl()` pass `filter[team_id]=<csv of team
ids>` (the existing `FrontendListTable` filter accepts a single value;
either extend it to accept CSV or pick the first team — the latter is a
lossy fix and should be the second choice). The shared `filterSpec()`
struct recommended in "Pattern recommendation" obviates this entirely.

**Acceptance**: assistant coach linked to U15-A and U17-B sees a "team
avg 7.4" KPI, clicks, lands on an evaluations list scoped to those two
teams' players — not their own evaluations on other teams' players.

**Wizard plan**: Exemption — bugfix.

Ref `#1180`. Adjacent to historical #857.

### Issue F — `MyEvaluationsThisWeek` KPI and destination disagree on window (7d vs 30d)

**Spec**:

`MyEvaluationsThisWeek::compute()` filters `e.created_at >= -7d`.
`FrontendMyEvaluationsView::renderForCoach()` calls
`EvaluationsRepository::recentForCoach( $coach_user_id, 30 )` — a
30-day window — and renders that.

The destination's existing docblock explicitly comments on this drift
("Widens to a 30-day trailing window — wider than the KPI's
strictly-this-week cut so coaches always see some recent context even
on a quiet week"). So it's intentional broadening — except the headline
mismatch breeds mistrust ("KPI says 3 but page shows 11"). Two clean
fixes:

- (a) `linkUrl()` appends `?days=7` and the destination view honours it
  (capping the recentForCoach window).
- (b) The destination's empty state explains "showing 30 days for
  context; KPI cards count this week (Mon-now)."

Option (a) is the parity fix; option (b) is the UX fix. Suggest (a)
because (b) leaves the rendered row count mismatched.

**Acceptance**: KPI "3 this week" clicks through to a list of 3 rows
(or 3 + a "previous 30 days" expandable section, if the UX broadening
is preserved deliberately).

**Wizard plan**: Exemption — bugfix.

Ref `#1180`.

## Out of scope / no drift

Documented so the next pass of this audit doesn't re-flag them as bugs:

- `MyOpenWorkflowTasks` — same SQL between compute and destination
  (already cited in the KPI's source comments).
- `OnboardingPipelineWidget` and `MatchesNeedingReviewWidget` — shared
  computation between widget and destination by construction.
- `ProspectsActiveTotal`, `TrialGroupActiveCount`, `TestTrainingsUpcoming`,
  `TrialDecisionsPending`, `TeamOffersPendingResponse`,
  `MyProspectsActive`, `MyProspectsPromoted`, `ProspectsLoggedThisMonth`,
  `ProspectsPromotedThisSeason`, `ProspectsStaleCount` — `linkView()`
  returns `''`, the card is inert, no click-through to drift against.
  Three of these have obvious destinations (`prospects-overview`) and
  would be valuable as clickable cards; that's an enhancement, not a
  drift, so out of scope here.
