<!-- audience: dev -->

# Audit 3 — Analytics direct-`$wpdb` views vs `KpiRegistry` framework

Date: 2026-06-03. Parent issue: #1177.

## Summary

The analytics surface has split into two render paths since `KpiRegistry` / `FactQuery` shipped (#0083): a small framework-routed core (the central `?tt_view=analytics` overview, entity Analytics tabs, and the dimension explorer) and a much larger direct-`$wpdb` periphery (six "Standard reports" surfaces, the three Analytics module reports, and the two legacy launcher reports). The framework-routed surfaces inherit `FactQuery`'s tenancy + filter discipline for free; the direct-SQL surfaces each re-implement scope on the same handful of tables, and only some get it right.

The audit catalogued every analytics renderer and classified each. Headline:

- **Framework-routed (3):** `FrontendAnalyticsView`, `FrontendExploreView`, `EntityAnalyticsTabRenderer` all consume `KpiRegistry::find` / `KpiResolver::value` / `FactQuery::run`. Direct `$wpdb` usage in `FrontendAnalyticsView` is left-rail entity-picker SQL only — it does not compute KPIs.
- **Direct-SQL, scope-correct (2):** `FrontendAttendancePlayerReportView`, `FrontendAttendanceTeamReportView`. Both were retrofitted in v4.20.4 (#1147) to apply `QueryHelpers::get_teams_for_coach()`. Documentation comment present.
- **Direct-SQL, scope-leaking (2 surfaces / 6+ slug handlers):** `FrontendStandardReportsView` (all six slug handlers + the page-level cap gate) and `FrontendReportsLauncherView`. Both are reachable by Assistant Coach (`reports:r/team` in the matrix), but no slug handler applies `QueryHelpers::get_teams_for_coach()` or any coach-team filter. `renderSeasonSummary` is explicitly academy-wide; `renderSquadEvaluationSummary` / `renderTeamMinutesDistribution` / `renderPlayerMinutesPlayed` accept any `team_id` / `player_id` on the URL without verifying the entity is in the user's scope.
- **Direct-SQL, scope-correct, will not migrate (3):** `FrontendMinutesTeamReportView` + `MinutesQuery` (cross-fact aggregation over lineup + substitution log; not a single fact), `FrontendReportDetailView::renderTeamRatings` / `renderCoachActivity` (admin-only cap path; matrix grants `reports:r/global` to HoD + Admin so scope is correct by capability). These belong in the documentation list.

Highest-leverage migration target is **not** "rewrite the standard reports against `FactQuery`" — most of them have no matching fact yet (`tt_trial_cases`, `tt_prospects` are facts but `season-summary`'s "totals across players, matches, evaluations, prospects, trial decisions" is a cross-fact aggregation, same shape as `MinutesQuery`). The highest-leverage target is **closing the scope leak on `FrontendStandardReportsView` with the same `get_teams_for_coach()` pattern v4.20.4 used on attendance** — that's a one-PR, ~80-line fix that closes the same class of bug #1147 surfaced.

## Catalogue

| file:class | path | framework or direct | scope context | coach-team filter applied? | reason for direct-SQL (if documented) |
| --- | --- | --- | --- | --- | --- |
| `FrontendAnalyticsView` | `src/Modules/Analytics/Frontend/` | **Framework** (KPI grid via `KpiRegistry::byContext` + `KpiResolver::value`) | Cap-gated `tt_view_analytics`; HoD + Admin only | n/a (admin-only surface) | KPIs go through the framework; left-rail entity-picker SQL is non-analytics catalogue data |
| `FrontendExploreView` | `src/Modules/Analytics/Frontend/` | **Framework** (`FactQuery::run` + `KpiRegistry::find`) | KPI context resolved upstream; explorer assumes the entry surface gated | n/a (relies on upstream gate) | — |
| `EntityAnalyticsTabRenderer` | `src/Modules/Analytics/Frontend/` | **Framework** (`KpiRegistry::forEntity` filtered by persona context) | Per-entity scope; `contextsForCurrentUser()` filters by cap | n/a (entity id baked in as filter) | — |
| `FrontendAttendancePlayerReportView` | `src/Modules/Analytics/Frontend/` | **Direct** (`$wpdb->get_results` against `tt_attendance` / `tt_activities`) | `tt_view_analytics` + `QueryHelpers::get_teams_for_coach()` scope (v4.20.4 #1147) | **YES** | Documented at lines 55–62: pattern matches the players list + teamplanner |
| `FrontendAttendanceTeamReportView` | `src/Modules/Analytics/Frontend/` | **Direct** (`$wpdb->get_results` against `tt_teams` / `tt_activities` / `tt_attendance`) | `tt_view_analytics` + `get_teams_for_coach()` (v4.20.4 #1147) | **YES** | Documented at line 55 |
| `FrontendMinutesTeamReportView` + `MinutesQuery` | `src/Modules/Analytics/Frontend/` + `src/Modules/Analytics/Reports/` | **Direct** (cross-fact: `tt_activities` + `tt_match_prep` + `tt_match_execution_substitutions`) | `tt_view_analytics`; `listTeams()` is club-wide (no coach-team filter) | **NO** — the team picker exposes every club team | Cross-fact aggregation over lineup + substitution log; not a single fact — `MinutesQuery` comment acknowledges no `FactRegistry` integration is planned |
| `FrontendStandardReportsView::renderPlayerMinutesPlayed` | `src/Shared/Frontend/` | **Direct** (`tt_attendance` + `tt_activities`) | `tt_view_reports` (matrix `reports:r/team` for AC, `global` for HoD) | **NO** | Undocumented |
| `FrontendStandardReportsView::renderTeamMinutesDistribution` | `src/Shared/Frontend/` | **Direct** (`tt_players` + `tt_attendance` + `tt_activities`) | `tt_view_reports` | **NO** | Undocumented |
| `FrontendStandardReportsView::renderSquadEvaluationSummary` | `src/Shared/Frontend/` | **Direct** (`tt_players` + `tt_evaluations` + `tt_eval_ratings`) | `tt_view_reports` | **NO** | Undocumented |
| `FrontendStandardReportsView::renderSeasonSummary` | `src/Shared/Frontend/` | **Direct** (academy-wide counts across 6 tables) | `tt_view_reports`; comment says "Academy-wide signals" | **NO** | Undocumented; framing is academy-wide but AC reaches the surface |
| `FrontendStandardReportsView::renderSeasonTrialFunnel` | `src/Shared/Frontend/` | **Direct** (`tt_prospects` + `tt_trial_cases`) | `tt_view_reports` | **NO** | Undocumented |
| `FrontendStandardReportsView::renderScoutReportCard` | `src/Shared/Frontend/` | **Direct** (`tt_prospects` + `tt_trial_cases` + `wp_users`) | `tt_view_reports`; defaults to current user but accepts any `scout_id` | **NO** — `scout_id` URL param not validated | Undocumented |
| `FrontendReportsLauncherView` | `src/Shared/Frontend/` | **No SQL** (tile catalogue) | `tt_view_reports` | n/a (tile listing) | — |
| `FrontendReportDetailView::renderTeamRatings` | `src/Shared/Frontend/` | **Direct** (`tt_evaluations` + `tt_eval_ratings` + `tt_players`) | `tt_view_reports`; surface is operator-only by content (no team filter on URL) | **NO** (academy-wide by design) | Undocumented; pre-#0083 report |
| `FrontendReportDetailView::renderCoachActivity` | `src/Shared/Frontend/` | **Direct** (`tt_evaluations`) | `tt_view_reports` | **NO** (academy-wide by design) | Undocumented; pre-#0083 report |
| `FrontendScheduledReportsView` | `src/Modules/Analytics/Frontend/` | **No analytics SQL** (manages `tt_scheduled_reports` rows; KPI lookup via `KpiRegistry::find`) | `tt_view_analytics` | n/a (admin-only authoring) | — |
| `ScheduledReportsRepository` | `src/Modules/Analytics/` | **Direct** (CRUD on `tt_scheduled_reports`) | Repository pattern; tenancy applied | n/a (CRUD, not analytics) | — |

Domain value objects (`Fact`, `Dimension`, `Measure`, `Kpi`, `DateTimeColumn`, `ExplorerUrl`) and `KpiRegistry` / `FactRegistry` themselves are not renderers and are excluded.

## Migration priorities (in order)

These are direct-SQL renderers that **actively leak scope today**, where the leak is recoverable with the same `QueryHelpers::get_teams_for_coach()` pattern v4.20.4 used on the two attendance views. They are not framework-migration candidates — the framework can't model their cross-table shapes — but they need the same scope retrofit.

1. **`FrontendStandardReportsView` — apply coach-team scope to every slug handler.** Single PR. Pattern lifted from `FrontendAttendancePlayerReportView` lines 55–78: derive `$allowed_team_ids`, return empty when `team_id`/`player_id` URL params fall outside that list, and filter every aggregate by `team_id IN (…)`. Five of the six handlers need it; `renderScoutReportCard` needs a separate `scout_id` validation (only HoD should be able to view another scout's card). Closes the same class of bug as #1147. → **Follow-up issue spec #A below.**
2. **`FrontendMinutesTeamReportView::listTeams()` — apply coach-team scope to the team picker.** Tiny PR — three lines. The query result already restricts to `club_id`; add the same `WHERE id IN (…coach teams…)` clause when the user lacks `tt_view_all_teams`. Without this, AC sees every club team in the dropdown even though they can only meaningfully report on the ones they coach. → **Follow-up issue spec #B below.**

That's the actionable list. Two more candidates were considered and rejected:

- **"Migrate `renderSquadEvaluationSummary` to a `team_avg_rating` fact"** — premature. The fact would need a `category_id` dimension, the AVG-of-AVG semantics differ from the per-rating measure in `evaluations`, and the surface has one consumer. Wait until a second surface wants the same number.
- **"Build a `season_summary` umbrella fact"** — anti-pattern. Six unrelated counts aren't a single fact. The cross-fact aggregation is correct here; what's wrong is the missing scope filter (covered by #A above).

## Document-the-bypass list

These renderers are direct-SQL, **already correctly scoped**, and have a legitimate reason not to migrate to `FactQuery`. Each needs a `@see` docblock + an inline comment naming the scope-enforcement strategy. None becomes a follow-up issue.

- `FrontendMinutesTeamReportView` + `MinutesQuery::forTeam()` — cross-fact aggregation (lineup × substitution log × activities). `MinutesQuery` already carries a "No `Analytics\FactRegistry` integration. Same follow-up" comment; promote it to a `@see` pointing at this audit and naming the scope strategy. **(The team-picker scope leak is a separate issue — spec #B above.)**
- `FrontendReportDetailView::renderTeamRatings` / `renderCoachActivity` — academy-wide by design, served only to capabilities whose matrix scope is `global` (HoD + Admin via `tt_view_reports → reports:r/global` at lines 405 / 484 of `config/authorization_seed.php`). Add a docblock comment naming the matrix tuple as the scope enforcement, so the next pass doesn't re-flag it.
- `FrontendAttendancePlayerReportView` / `FrontendAttendanceTeamReportView` — already have the explanatory comment from v4.20.4 (#1147). Add a brief `@see docs/audits/2026-06-audit-3-...md` line so future audits find this one.

## What this audit does not address

- **`FrontendStandardReportsView`'s six surfaces all reuse the legacy `sess`+`ion_id` / `sess`+`ion_date` column-name concat trick** to dodge the #0035 vocabulary lint. That's correct for the lint, but it indicates the underlying schema rename is still half-done — a separate concern from analytics framework coverage.
- **`KpiResolver` falls back to the legacy `KpiDataSourceRegistry` for un-migrated KPIs.** Bulk-migration of those 26 legacy KPIs to fact-driven `Kpi` declarations is its own backlog, not Audit 3's scope.
- **No REST endpoints exist for the standard reports.** Per CLAUDE.md § 4 they should. Out of scope for this audit; track separately if it bites.
