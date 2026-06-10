<!-- audience: dev -->

# Audit 7 — Per-entity scope-filter consistency across sibling read paths

Date: 2026-06-03
Ref: #1181
Related closed issues (canonical bug shapes referenced below): #1127
(planner missing `archived_at IS NULL`), #1149 (print router vs. detail
view scope mismatch), #1148 (analytics player report — `tt_attendance`
JOIN target mismatch), #1137 (people REST archive silent success),
#1032 (rate-step vs. lineup source mismatch), #970 (wizard "no players"
on full roster), #1054 (blueprint editor silent fail).

## Summary

For each of seven root entities — `tt_players`, `tt_activities`,
`tt_teams`, `tt_attendance`, `tt_evaluations`, `tt_goals`, `tt_people` —
catalogued every PHP `SELECT … FROM <table>` site under `src/` (skipping
worktrees + migrations) and tabulated which scope filters apply.

The canonical filter set for each entity is set by the REST
controller's `list_*` method — those are the contract for non-PHP
consumers and are also the most-reviewed reads. Where a PHP-render
sibling read drifts from that contract, it gets flagged.

The most common drift pattern is **missing `archived_at IS NULL` on a
soft-deleted entity**, immediately followed by **missing `club_id`
tenancy scope** on a single-row load (`loadX(int $id)` helpers
established at v3.x that pre-date the tenancy column landing in #0038).
A third pattern shows up on `tt_attendance` joins where the analytics /
planning surface forgets `is_guest = 0` + `record_type = 'actual'` and
ends up counting expected-attendance rows (#788 ship 2) as if they were
recorded.

Across ~150 read sites, the top 9 material drifts are filed as
follow-ups; the rest are either documented intent (KPI snapshot
"total players" deliberately includes archived) or so-trivial-it-
doesn't-matter (single-tenant install — `club_id` drift is dormant
until SaaS multi-tenant lands). The single-tenant-dormant set is
catalogued in the per-entity tables below with a `dormant-tenancy`
tag — they should be fixed when convenient, but no pilot bug today
hinges on them.

## Canonical filter sets

| Entity | Canonical | Authoritative site |
| --- | --- | --- |
| `tt_players` | `club_id` + `archived_at IS NULL` (toggleable via `?include_archived` / `filter[archived]`) + `apply_demo_scope('player')`; `status='active'` only on **active-roster** queries (not on by-id loads, archive views, or all-cohort exports) | `PlayersRestController::list_players` (lines 145–156) |
| `tt_activities` | `club_id` + `archived_at IS NULL` (toggleable via `?include_archived`) + `apply_demo_scope('activity')` + coach-team scope when persona lacks `activities:r[global]`; `plan_state='completed'` for "what happened" reports only | `ActivitiesRestController::list_sessions` (lines 202–210) |
| `tt_teams` | `club_id` + `archived_at IS NULL` (toggleable via `filter[archived]`) + `apply_demo_scope('team')` | `TeamsRestController::list_teams` |
| `tt_attendance` (real squad) | `club_id` + `is_guest = 0` + `record_type = 'actual'` (joined activity `plan_state='completed'`) | `TeamRosterTableWidget` lines 229–243, `KpiSnapshotXlsxExporter` lines 96–113, `FrontendAttendancePlayerReportView` lines 141–168 |
| `tt_attendance` (guest) | `club_id` + `is_guest = 1` | `FrontendActivitiesManageView::loadGuests` |
| `tt_evaluations` | `club_id` (or `pl.club_id` via join) + `archived_at IS NULL` + `apply_demo_scope('evaluation')` | `EvaluationsRestController::list_evaluations`, `EvaluationsXlsxExporter` lines 117–124 |
| `tt_goals` | `club_id` + `archived_at IS NULL` (toggleable via `?include_archived`) + `apply_demo_scope('goal')` | `GoalsRestController::list_goals` (lines 122–129) |
| `tt_people` | `club_id` + `archived_at IS NULL` (toggleable via `filter[archived]`) + `apply_demo_scope('person')` | `PeopleRestController::list_people` (lines 105–117) |

Note on `QueryHelpers::get_*` helpers: `get_team()`, `get_player()`,
`get_evaluation()` all scope by `club_id` + `apply_demo_scope` but
**do not** add `archived_at IS NULL`. That is deliberate — the helpers
are used by both "active list" callers and "loading by id from a
stale URL" callers; the latter need archived rows to render the
404-style "no longer available" message instead of a misleading
"not found". Callers that explicitly want "active rows only" must add
`archived_at IS NULL` themselves. Where this convention is broken,
it is flagged below.

---

## tt_players

Canonical: `club_id = %d AND archived_at IS NULL AND {apply_demo_scope}` for active list, `club_id` alone for by-id loads.

| Read site | club_id | archived_at | demo_scope | status='active' | Drift? | Impact |
| --- | --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/PlayersRestController.php:199` (list) | ✓ | ✓ (toggle) | ✓ | — (filter[archived] toggle) | canonical | — |
| `Shared/Frontend/FrontendPlayersManageView.php:638` (loadPlayer) | — | ✓ | ✓ | — | **drift** | #1149 family — by-id load missing `club_id`; legacy/migrated row with `club_id != 1` renders on profile but rejected by print router |
| `Shared/Frontend/FrontendComparisonView.php:91` (slot selector) | — | ✓ | ✓ | ✓ | **drift** | Player-comparison "Choose player" selector lists every player in DB across all tenants; today single-tenant so dormant, but the comment "cross-club — observer's scope" is **wrong** post-tenancy. `dormant-tenancy` |
| `Modules/Reports/Admin/ReportsPage.php:187` (player ids) | — | — | — | ✓ | **drift** | Admin player picker, missing `club_id` + `archived_at` — includes archived players in the admin coach-activity report. Reachable by HoD. `dormant-tenancy` for club_id; archived_at material |
| `Modules/Goals/Admin/GoalsPage.php:42` (admin list) | ✓ | ✓ (toggle via view_clause) | ✓ | — | canonical | — |
| `Modules/Goals/Print/PlayerGoalIntakePrintRouter.php:178` (player load) | — | — | ✓ | — | documented intent (post-#1149 fix) — pivots `club_id` from row | — |
| `Modules/Goals/Print/PlayerGoalIntakePrintRouter.php:88` (team-batch ids) | — | ✓ | ✓ | — | **drift** | Team-batch print missing `club_id` — would mis-scope when team_id collides across tenants. `dormant-tenancy` |
| `Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php:83` (id IN list) | ✓ | — | — | — | documented intent — single-activity render, archived players showing in print is OK (print is historical) |
| `Modules/Analytics/Frontend/FrontendAnalyticsView.php:251` (display name) | ✓ | — | — | — | documented intent — display lookup |
| `Modules/Analytics/Frontend/FrontendAnalyticsView.php:291` (minutes query JOIN) | (via `WHERE p.club_id`) ✓ | ✓ | — | — | canonical | — |
| `Modules/Analytics/Reports/MinutesQuery.php:164` | ✓ | ✓ | — | — | canonical | — |
| `Infrastructure/REST/PdpConversationsRestController.php:190` (player ⇔ user check) | — | — | — | — | documented intent — explicit `wp_user_id=%d` check, single-user lookup |
| `Modules/Wizards/Tournaments/Wizard/SquadStep.php:65` (active roster) | needs review | — | — | ✓ | see file | not in audit top-N |
| `Shared/Frontend/CoachDashboardView.php` etc. | — | various | ✓ | — | — | not material to audit's top-9 |
| Other ~30 by-id / by-fk lookups | mixed | mixed | mixed | mixed | mostly canonical or `dormant-tenancy` | — |

### Drift summary
- 1 material — `FrontendPlayersManageView::loadPlayer` (the #1149-family head).
- 1 admin reports — `Reports/Admin/ReportsPage` includes archived in player picker.
- Multiple `dormant-tenancy` — file as one cleanup when SaaS port lands.

---

## tt_activities

Canonical: `club_id = %d AND archived_at IS NULL AND {apply_demo_scope}` + coach-team scope.

| Read site | club_id | archived_at | demo_scope | plan_state | Drift? | Impact |
| --- | --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/ActivitiesRestController.php:373` (list) | ✓ | ✓ (toggle) | ✓ | — (optional) | canonical | — |
| `Shared/Frontend/FrontendActivitiesManageView.php:1183` (list) | ✓ | ✓ | ✓ | — | canonical | — |
| `Shared/Frontend/FrontendActivitiesManageView.php:1772` (loadSession by-id) | — | ✓ | ✓ | — | **drift** | by-id load missing `club_id` — #1149-family on activities; archived legacy row would silently render under wrong tenancy. `dormant-tenancy` + edge-case-leak |
| `Shared/Frontend/FrontendMyActivitiesView.php:93` (renderDetail) | — | — | — | — | **drift** | Player's "view this activity" detail loads ANY activity by id — including archived/cancelled. Player-facing inconsistency: card hidden on list, detail page still renders. Material. |
| `Shared/Frontend/FrontendPlayerStatusCaptureView.php:291` (recent-activities pre-fetch) | — | ✓ | — | — | **drift** | Player attendance dropdown — missing `club_id`. Joined attendance missing `is_guest=0` + `record_type='actual'`. `dormant-tenancy` + status-capture noise |
| `Shared/Frontend/FrontendPlayerDetailView.php:79` (behaviour popover pre-fetch) | — | ✓ | — | — (uses `activity_status_key`) | **drift** | Mirrors the status-capture view; same drift shape, different consumer |
| `Modules/Activities/Admin/ActivitiesPage.php:56` (admin list) | ✓ | ✓ (toggle via view_clause) | ✓ | — | canonical | — |
| `Modules/Planning/Frontend/FrontendTeamPlannerView.php:560` (team planner) | ✓ | ✓ | — | — | canonical (post-#1127) | — |
| `Modules/Workflow/Forms/QuarterlyHoDReviewForm.php:138` (review) | ✓ | ✓ | — | — | canonical | — |
| `Modules/Analytics/Frontend/FrontendAnalyticsView.php:337` (minutes JOIN) | ✓ | ✓ | — | ✓ | canonical | — |
| `Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php:56` | ✓ | — | — | — | documented intent — print is historical, archived rows must still render |
| `Modules/Comms/Cron/CommsScheduledCron.php:124` (attendance flag detection) | — | — | — | ✓ | **drift** | Cron query is cross-tenant; missing `archived_at`. `dormant-tenancy` + counts archived activities into "missed-3-of-5" trigger |
| `Modules/Pdp/Frontend/FrontendPdpManageView.php:773` (PDP context) | — | — | — | — | **drift** | PDP "Activities" timeline panel — missing `club_id`, `archived_at`. Will surface archived activities to coach + parent on PDP detail. Material. |
| `Modules/Wizards/Evaluation/ActivityPickerStep.php:212` (eligible activities) | needs review | — | — | mixed | — | not in audit top-N |
| `Modules/Export/Exporters/KpiSnapshotXlsxExporter.php:85` (count in period) | ✓ | — | — | — | **drift** | KPI snapshot "activities in period" double-counts archived activities. Material for HoD trust in the snapshot. |
| `Modules/Spond/SpondSync.php:125` | (via Spond join semantics) | — | — | — | documented intent — sync references everything to reconcile |
| `Shared/Frontend/FrontendStandardReportsView.php:401` (matches_12m) | ✓ | — | — | — | **drift** | Season-summary KPI — counts archived matches in "Matches (12 mo)". Same `match_count` drift in per-team breakdown line 433. Material. |

### Drift summary
- 4 material — `FrontendMyActivitiesView::renderDetail`, `FrontendPdpManageView`
  activities panel, `KpiSnapshotXlsxExporter`, `FrontendStandardReportsView`
  season summary.
- 2 same-shape `loadX` by-id drifts (myActivities, FrontendActivitiesManageView::loadSession).
- 2 attendance-flag / behaviour-popover drifts on the join.

---

## tt_teams

Canonical: `club_id = %d AND archived_at IS NULL AND {apply_demo_scope}`.

| Read site | club_id | archived_at | demo_scope | Drift? | Impact |
| --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/TeamsRestController.php:209` (list) | ✓ | ✓ | ✓ | canonical | — |
| `Shared/Frontend/FrontendTeamsManageView.php:449` (loadTeam by-id) | — | ✓ | ✓ | **drift** | By-id load missing `club_id`. #1149-family; legacy/migrated team renders on detail but inconsistent vs. list. `dormant-tenancy` |
| `Shared/Frontend/FrontendComparisonView.php:103` (team selector) | — | ✓ | — | **drift** | Comparison's team picker is cross-tenant. `dormant-tenancy` |
| `Modules/Teams/Admin/TeamsPage.php:35` (admin list) | ✓ | ✓ (toggle via view_clause) | ✓ | canonical | — |
| `Modules/Workflow/Resolvers/TeamHeadCoachResolver.php:34` | — | — | — | **drift** | Coach resolver doesn't scope `club_id` — only OK because team ids are globally unique. `dormant-tenancy` |
| `Modules/Wizards/TeamBlueprint/SetupStep.php:24` (picker) | ✓ | — | — | **drift** | Blueprint setup team picker — includes archived teams. Coach drift. |
| `Modules/Stats/Admin/PlayerComparisonPage.php:80` | — | ✓ | — | **drift** | Admin team picker missing `club_id`. `dormant-tenancy` |
| `Modules/Authorization/PersonaResolver.php:144` (head-coach count) | — | ✓ | — | **drift** | Cross-tenant count; affects persona resolution. `dormant-tenancy` |
| `Modules/Analytics/Frontend/FrontendAnalyticsView.php:315` (team list) | needs review | ✓ | — | — | varies by call site |
| Other 12+ name/by-id lookups | mostly ✓ | mixed | mixed | mostly canonical or `dormant-tenancy` | — |

### Drift summary
- 1 material — `WizardTeamBlueprint/SetupStep` picker leaks archived teams.
- Several `dormant-tenancy` — group cleanup later.

---

## tt_attendance

Canonical (real squad): `club_id = %d AND is_guest = 0 AND record_type = 'actual'` joined to `tt_activities` with `plan_state = 'completed'`.

| Read site | club_id | is_guest=0 | record_type | activity.plan_state | Drift? | Impact |
| --- | --- | --- | --- | --- | --- | --- |
| `Modules/PersonaDashboard/Widgets/TeamRosterTableWidget.php:229` | ✓ | (via `att.record_type`) | ✓ | ✓ | canonical | — |
| `Modules/Export/Exporters/KpiSnapshotXlsxExporter.php:96` | ✓ | (via record_type) | ✓ | ✓ | canonical | — |
| `Modules/Analytics/Frontend/FrontendAttendancePlayerReportView.php:154` | ✓ | ✓ | ✓ | ✓ | canonical (post-#1148) | — |
| `Modules/Reports/PlayerReportRenderer.php:665` | — | ✓ | ✓ | ✓ | **drift** | Player printable report missing `club_id`. `dormant-tenancy` |
| `Shared/Frontend/FrontendActivitiesManageView.php:1786` (loadAttendance by activity) | — | ✓ | — | n/a | **drift** | Roster attendance load by activity id missing `club_id` and `record_type='actual'`. Would include expected-attendance rows (#788 ship 2) in the attendance form. Material if ship 2 ever fills these rows. |
| `Modules/Activities/Admin/ActivitiesPage.php:237` (admin attendance) | ✓ | ✓ | — | n/a | **drift** | Admin attendance view includes expected-attendance rows. Same shape as above. `low impact today` (ship 2 not active) |
| `Shared/Frontend/PlayerDashboardView.php:147` (player history) | — | — | — | n/a | **drift** | Player's own attendance tab on dashboard: cross-tenant + counts guest rows + counts expected rows. `dormant-tenancy` + status noise |
| `Shared/Frontend/FrontendPlayerDetailView.php:607` (KPI box) | — | ✓ | — | ✓ | **drift** | Player profile KPI strip "Attendance %": missing `club_id` + `record_type='actual'`. Mixed-shape noise. Material if expected-attendance rows fill in. |
| `Shared/Frontend/FrontendMyActivitiesView.php:108` (my-activity attendance) | — | — | — | n/a | **drift** | Player viewing their own attendance row by-id loaded without scope. `dormant-tenancy` |
| `Modules/Comms/Cron/CommsScheduledCron.php:124` (attendance-flag detection) | — | — | — | ✓ | **drift** | Cross-tenant cron; counts guests + expected. Material — drives nudges. |
| `Modules/Pdp/Frontend/FrontendPdpManageView.php:775` (PDP "Activities" panel) | — | — | — | — | **drift** | PDP timeline panel: missing every attendance scope filter. Material. |
| `Modules/Pdp/EvidencePacket.php:91` | (via join) | — | — | — | — | needs review (not in top-N) |
| `Modules/Goals/Print/PlayerGoalIntakePrintRouter.php:453` | (via row pivot) | — | — | — | — | post-#1149 pivot; ok |
| `Modules/Export/Exporters/GdprSubjectAccessZipExporter.php:103` | (via player_id pivot) | — | — | — | — | documented intent — Article 15 subject access dumps everything |

### Drift summary
- 3 material — `FrontendPlayerDetailView` KPI, `FrontendPdpManageView`,
  `CommsScheduledCron` (cron). Comms is the worst — drives parent nudge
  messages off cross-tenant data.

---

## tt_evaluations

Canonical: `club_id = %d` (or `pl.club_id` via JOIN) `AND archived_at IS NULL AND {apply_demo_scope}`.

| Read site | club_id | archived_at | demo_scope | Drift? | Impact |
| --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/EvaluationsRestController.php:269` (list) | ✓ | ✓ (toggle) | ✓ | canonical | — |
| `Infrastructure/Evaluations/EvaluationsRepository.php:66` (recent for coach) | ✓ | ✓ | — | canonical | — |
| `Infrastructure/Stats/PlayerStatsService.php:69` | needs review | — | — | — | not in top-N |
| `Modules/Evaluations/Admin/EvaluationsPage.php:72` (admin list) | ✓ | ✓ (toggle) | ✓ | canonical | — |
| `Shared/Frontend/PlayerDashboardView.php:92` (player tab) | — | — | — | **drift** | Player's own dashboard eval tab — missing `club_id` + `archived_at`. Shows archived evals to the player. Material (player-facing data integrity). |
| `Shared/Frontend/FrontendMyEvaluationsView.php:103` | — | ✓ | — | **drift** | Missing `club_id`. `dormant-tenancy` |
| `Shared/Frontend/FrontendPlayerDetailView.php:563`, `:581` (KPI avg) | — | ✓ | — | **drift** | Player profile avg-rating KPI missing `club_id`. `dormant-tenancy` |
| `Shared/Frontend/FrontendReportDetailView.php:105`, `:165` (per-team avg) | — | ✓ | — | **drift** | Cross-team eval averages reports — missing `club_id`. `dormant-tenancy` |
| `Modules/Reports/PlayerReportRenderer.php:468` | (via builder) | (via builder) | — | — | per-call; needs review |
| `Modules/Reports/Admin/ReportsPage.php:202`, `:260`, `:305` | mostly ✓ | mixed | — | mixed | mostly canonical |
| `Modules/Pdp/EvidencePacket.php:75` | — | — | — | **drift** | PDP evidence panel: missing scope. `dormant-tenancy` |
| `Modules/Pdp/Frontend/FrontendPdpManageView.php:757` | — | ✓ | — | **drift** | PDP eval-list panel. `dormant-tenancy` |
| `Modules/Pdp/Print/PdpPrintRouter.php:265` | — | — | — | **drift** | PDP print router. `dormant-tenancy` |
| `Modules/Export/Exporters/GdprSubjectAccessZipExporter.php:82` | — | — | — | — | documented intent — Article 15 dump |
| `Modules/Export/Exporters/KpiSnapshotXlsxExporter.php:89` | ✓ (via pl join) | ✓ | — | canonical | — |
| `Modules/Export/Exporters/EvaluationsXlsxExporter.php:118` | ✓ (via pl.club_id) | ✓ | — | canonical | — |

### Drift summary
- 1 material — `PlayerDashboardView` tabs (player sees archived evals).
- Many `dormant-tenancy` — group cleanup.

---

## tt_goals

Canonical: `club_id = %d AND archived_at IS NULL AND {apply_demo_scope}`.

| Read site | club_id | archived_at | demo_scope | Drift? | Impact |
| --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/GoalsRestController.php:184` (list) | ✓ | ✓ (toggle) | ✓ | canonical | — |
| `Shared/Frontend/FrontendGoalsManageView.php:491` (loadGoal by-id) | — | ✓ | ✓ | **drift** | by-id load missing `club_id` — #1149-family. `dormant-tenancy` |
| `Shared/Frontend/PlayerDashboardView.php:125` (player tab) | — | — | ✓ | **drift** | Player dashboard goals tab — missing `club_id` + `archived_at`. Material — shows archived goals to player + parent on the dashboard. |
| `Shared/Frontend/FrontendMyGoalsView.php:43`, `:113` | — | ✓ | — | **drift** | Player "My goals" view — `dormant-tenancy` |
| `Shared/Frontend/FrontendPlayerDetailView.php:632`, `:641` (KPI box) | — | ✓ | — | **drift** | KPI box on profile. `dormant-tenancy` |
| `Modules/Goals/Admin/GoalsPage.php:42` (admin list) | ✓ | ✓ (toggle) | ✓ | canonical | — |
| `Modules/Goals/Admin/GoalsPage.php:105` (admin edit-by-id) | ✓ | — | — | documented intent — admin needs to edit archived goals |
| `Modules/Workflow/Forms/QuarterlyHoDReviewForm.php:171` | ✓ | ✓ | — | canonical | — |
| `Modules/PersonaDashboard/Widgets/RecentCommentsWidget.php:148` | ✓ | — | — | **drift** | Comment thread display includes archived-goal titles. Low impact (display-only). `dormant-tenancy` |
| `Modules/Comms/Cron/CommsScheduledCron.php:81` (goal-nudge cron) | — | ✓ | — | **drift** | Cross-tenant cron query for goal nudges. `dormant-tenancy` but operator-impact (parents nudged across tenants). |
| `Modules/Pdp/Frontend/FrontendPdpManageView.php:794`, `:842` | — | ✓ | — | **drift** | PDP detail. `dormant-tenancy` |
| `Modules/Pdp/Print/PdpPrintRouter.php:110` | — | — | — | **drift** | PDP print. `dormant-tenancy` |
| `Modules/Export/Exporters/GdprSubjectAccessZipExporter.php:97` | — | — | — | — | documented intent — Article 15 |
| `Modules/Export/Exporters/KpiSnapshotXlsxExporter.php:119`, `:123`, `:127` | ✓ | — | — | mixed | "Goals total" intentionally counts archived; "Goals active" status-filter ok; flagged as documented intent. |

### Drift summary
- 1 material — `PlayerDashboardView` goals tab (player sees archived).
- Cron + many `dormant-tenancy`.

---

## tt_people

Canonical: `club_id = %d AND archived_at IS NULL AND {apply_demo_scope}` (REST default).

| Read site | club_id | archived_at | demo_scope | Drift? | Impact |
| --- | --- | --- | --- | --- | --- |
| `Infrastructure/REST/PeopleRestController.php:145` (list) | ✓ | ✓ (toggle) | ✓ | canonical | — |
| `Infrastructure/People/PeopleRepository.php:102` (list) | ✓ | — | ✓ | **drift** | The repo helper that callers (e.g. `ParentSearchPickerComponent`, `FrontendFunctionalRolesView`) use does NOT filter `archived_at IS NULL` even when `filters['status']` isn't passed. Archived people land in pickers. #1137 family — operator archives a person, repo-driven pickers still surface them. Material. |
| `Infrastructure/People/PeopleRepository.php:117` (find by id) | ✓ | — | — | documented intent — by-id needs archived rows for "not found" |
| `Modules/People/Admin/PeoplePage.php:131` (admin list) | — | ✓ (toggle via view_clause) | ✓ | **drift** | Admin people list missing `club_id`. `dormant-tenancy` |
| `Modules/Authorization/PersonaResolver.php:153` (wp_user → person) | — | — | — | documented intent — auth resolver, single-row lookup |
| `Modules/Authorization/MatrixGate.php:186`, `:288`, `:339` | — | — | — | documented intent — auth resolver |
| `Modules/Export/Exporters/StaffDirectoryCsvExporter.php:72` | needs review | — | — | — | not in top-N |
| `Infrastructure/People/PersonDeletionCascade.php:318` | varies | — | — | — | deletion path — not a read for display |
| `Infrastructure/REST/PlayersRestController.php:470` (parent existence check) | needs review | — | — | — | not in top-N |
| `Modules/Evaluations/Admin/EvaluationsPage.php:202` (wp_user → person) | ✓ | — | — | documented intent — auth lookup |
| `Modules/Analytics/Frontend/FrontendAnalyticsView.php:361` (list) | needs review | — | — | — | not in top-N |

### Drift summary
- 1 material — `PeopleRepository::list` missing `archived_at IS NULL`
  default. Caller-allowlist pickers will surface archived people.

---

## Pattern recommendation

Two lightweight controls would prevent this drift class going forward:

1. **`QueryHelpers::scope_*` helpers** that emit the canonical `WHERE`
   fragment for each entity. Today every read site re-builds `'p.club_id
   = %d AND p.archived_at IS NULL'` by hand, plus `apply_demo_scope` as
   a separate call. A helper like
   `QueryHelpers::activeScope('p', 'player', $club_id)` returning
   `['sql' => '... AND ...', 'params' => [...]]` would centralise the
   contract — and a PHPCS sniff banning bare `tt_players` reads outside
   the helper (analogous to the existing translation-helper sniff) would
   enforce it.

2. **Single-row `loadX(int $id)` view helpers** (`loadPlayer`, `loadGoal`,
   `loadTeam`, `loadSession`) all share the same broken
   shape: they add `archived_at IS NULL` (which the canonical helper
   `QueryHelpers::get_*` deliberately omits) but forget `club_id`. The
   right fix is to delete these per-view helpers and call
   `QueryHelpers::get_player($id)` / `get_team($id)` / `get_goal($id)` —
   adding `get_goal($id)` and `get_session($id)` if needed — and let the
   caller decide whether to ALSO check `archived_at IS NULL` post-fetch.

## Follow-up issues filed

The material drifts surfaced by this audit are filed as separate
follow-up issues, grouped by entity. Each is referenced back to #1181.

- #1221 — `loadPlayer` + `loadGoal` + `loadTeam` + `loadSession` per-view
  single-row helpers all share the #1149 shape (missing `club_id`).
  Consolidate via `QueryHelpers::get_*` or add `club_id` to all four.
  (Also addresses the `FrontendActivitiesManageView::loadSession`
  by-id drift.)
- #1222 — `tt_activities`: 4 sites count archived activities into
  HoD-facing reports (KPI snapshot, Season summary KPI strip, PDP
  "Activities" timeline, my-activities detail-by-id). Add
  `archived_at IS NULL`.
- #1224 — `CommsScheduledCron`: cross-tenant + missing
  `is_guest=0`/`record_type='actual'`/`archived_at IS NULL` on the
  attendance-flag detection. Drives parent nudges. High severity.
  Also covers the cross-tenant goal-nudge cron at the same file.
- #1225 — `PlayerDashboardView`: player's own evaluations + goals tabs
  read without `archived_at IS NULL` (+ attendance tab missing
  canonical scope). Player + parent see archived rows.
- #1226 — `PeopleRepository::list`: default `archived_at IS NULL`
  filter missing; archived people surface in
  `ParentSearchPickerComponent` + `FrontendFunctionalRolesView` pickers.
  #1137 family.
- #1227 — `tt_attendance`: player-profile KPI tile + activity-form
  roster load miss `is_guest=0` / `record_type='actual'` /
  `club_id`. Pre-#788 ship 2 = dormant; file ahead of stack-up.
- #1228 — `PdpManageView` panels (activities + attendance + eval list +
  goals list) all miss canonical scope. PDP is HoD-reviewed; integrity
  matters here.
- #1229 — `FrontendStandardReportsView` season-summary "Matches (12 mo)"
  KPI + per-team `match_count` both count archived activities. Also
  flags the "Active players" label-vs-filter mismatch.
- #1230 — `WizardTeamBlueprint/SetupStep` + `PlayerComparisonPage` team
  pickers include archived teams. Coach-facing wizard drift.
- #1232 — `Reports/Admin/ReportsPage` admin player picker + 
  `FrontendComparisonView` slot selector miss canonical
  `club_id` / `archived_at IS NULL`; also corrects a misleading
  in-code "cross-club" comment that pre-dates #0038.

A consolidated `dormant-tenancy` cleanup will be filed separately when
the SaaS port reaches the multi-tenant slice — none of those reads are
incorrect today (single tenant), but they will be when `club_id != 1`
becomes possible.
