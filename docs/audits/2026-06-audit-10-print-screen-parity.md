# Audit 10 — Print/PDF router vs on-screen view content composition parity

Date: 2026-06-03 · Issue: #1184

## Summary

Catalogued 11 print/PDF surfaces: 5 print routers in `src/Modules/**/Print/`,
1 print router in `src/Modules/Stats/`, 1 sub-view print renderer in
`src/Modules/Vct/Frontend/`, and 7 PDF exporters in `src/Modules/Export/Exporters/`.

The #1059 shared-renderer pattern (`MatchPrepPrintableRenderer::bodyHtml()`
called by both print router and PDF exporter) is established for one
domain (match prep). The PDP pair (`PdpPrintRouter` / `PdpPdfExporter`)
and the Player Evaluation pair (`PrintRouter` / `PlayerEvaluationPdfExporter`)
also share their renderers — those three are best-in-class.

Four exporters carry the same **`club_id`-strict vs on-screen-loose**
data-source forks that produced #1149. All three of the player-centric
PDFs (`PlayerOnePagerPdfExporter`, `PlayerEvaluationPdfExporter`,
`ScoutingReportPdfExporter`) go through `QueryHelpers::get_player()`
which filters `p.club_id = CurrentClub::id()`, while the on-screen
`FrontendPlayersManageView::loadPlayer()` doesn't — so any player whose
stored `club_id` differs from `CurrentClub::id()` opens fine on screen
and then 404s on PDF generation. This is the same defect family #1149
fixed in `PlayerGoalIntakePrintRouter::emit()`. None of these three
have been hit in pilot yet, but the latent bug is structurally
identical.

Two exporters render content the on-screen view does NOT yet have:
`ActivityBriefPdfExporter` (attendance roster — exists in the view via
inline-edit, OK) and `MatchDayTeamSheetPdfExporter` (Starting XI / Bench
partition — the on-screen activity view does not surface this split at
all). The team-sheet print is structurally a "print-only" feature whose
on-screen mirror is the **match-prep view**, not the activity detail
view. That's a documentation gap, not necessarily a refactor.

Two routers (`MethodologyReferencePrintRouter`,
`PlayerGoalIntakePrintRouter`) are intentionally print-only artefacts
(laminated reference card, paper intake form) — no on-screen mirror
exists by design. Both already query repositories cleanly via `$wpdb`
on shipped methodology + player tables. Not a parity gap.

## Inventory

| Exporter / Router | Mirrors on-screen view | Data source: same/forked | Scope filters: match/drift | Composition: parity / divergent | Impact |
| --- | --- | --- | --- | --- | --- |
| `Stats\PrintRouter` | `PlayerReportView::render()` | **Same** — delegates to `PlayerReportView::render()` | Match (same `frontendCanAccess` cap chain) | Parity (renders view's HTML; adds print-only toolbar + PDF button) | Best-in-class |
| `MatchPrep\Print\MatchPrepPrintRouter` | `FrontendMatchPrepView` | **Same** — both call `MatchPrepRepository::findByActivity / listAvailability / listLineup / listPlayerGoals` and `FrontendMatchPrepView::defaultSlotLayouts()` via `MatchPrepPrintableRenderer::bodyHtml()` | Match (both filter `a.club_id = $club_id`) | Parity (#1059 reference fix) | Best-in-class |
| `Pdp\Print\PdpPrintRouter` | PDP file detail (`?tt_view=pdp&id=N`) | **Same** — calls `PdpFilesRepository`, `PdpConversationsRepository`, `PdpVerdictsRepository`, `SeasonsRepository`; only the goals/evals/activities sub-queries are inline `$wpdb` | Match (`canAccess()` shared with `PdpPdfExporter`) | Parity (single A4 default; optional evidence page is documented divergence) | Best-in-class |
| `Methodology\Print\MethodologyReferencePrintRouter` | None — print-only laminated card | Forked-by-design — direct `$wpdb` on `tt_principles`, `tt_football_actions`, `tt_methodology_learning_goals` | n/a — methodology tables are global, not per-club | Print-only (no on-screen mirror) | OK by design |
| `Goals\Print\PlayerGoalIntakePrintRouter` | None — paper intake form | Forked-by-design — direct `$wpdb` for player + last-season stats + lookback | **Drift fixed in #1149** for player lookup; sub-queries still use `pl.club_id` | Print-only (no on-screen mirror) | OK after #1149 |
| `Vct\Frontend\FrontendVctSessionPrintView` | `FrontendVctSessionView` | **Same** — both use `VctSessionsRepository`, `VctSessionBlocksRepository`, `VctExercisesRepository`, `VctCoachingPointsRepository` | Match (`canPlanForTeam` cap shared) | Parity (sub-render of the session view) | Best-in-class |
| `Export\Exporters\PlayerEvaluationPdfExporter` | `PlayerReportView` (eval report) | **Same** — uses `PlayerReportRenderer::renderStandard()` (the renderer the on-screen report also uses) | **Drift** — gates via `QueryHelpers::get_player()` (filters `p.club_id = %d`); `FrontendPlayersManageView::loadPlayer()` does not | Parity (renders same HTML; strips Chart.js `<script>` block) | **Medium — latent #1149-family bug** |
| `Export\Exporters\PdpPdfExporter` | `PdpPrintRouter` (which mirrors PDP detail view) | **Same** — calls `PdpPrintRouter::renderHtml()`; strips toolbar before handoff to DomPDF | Match (calls `PdpPrintRouter::canAccess()`) | Parity (#0063 use case 2 reference fix) | Best-in-class |
| `Export\Exporters\ScoutingReportPdfExporter` | Scout-shared report URL (`ScoutDelivery::emailLink()`) | **Same** — uses `PlayerReportRenderer::render()` with SCOUT `ReportConfig` | **Drift** — gates via `QueryHelpers::get_player()` (`club_id`-strict) vs on-screen scout-share view | Parity (SCOUT audience defaults: `[profile, ratings]`, formal tone) | **Medium — latent #1149-family bug** |
| `Export\Exporters\PlayerOnePagerPdfExporter` | `FrontendPlayersManageView::render()` (player profile) | **Forked** — gates via `QueryHelpers::get_player()` + own `teamName()` `$wpdb` query | **Drift** — `club_id`-strict vs on-screen player profile's demo-scope-only | Documented divergence (A5 trial card; profile view shows more) | **Medium — latent #1149-family bug** |
| `Export\Exporters\ActivityBriefPdfExporter` | `FrontendActivitiesManageView::renderDetail()` | **Forked** — inline `$wpdb` on `tt_activities` + `tt_attendance`; on-screen view also queries inline via `loadSession() / loadAttendance()` | Drift — exporter filters `a.club_id = $request->clubId` AND `pl.club_id = $request->clubId`; on-screen view uses demo-scope only on `tt_activities` (no `club_id` filter on `tt_attendance` join) | Mostly parity — includes notes block, attendance roster (no guests) | **Low — same #1149-family pattern; no `ActivitiesRepository` exists yet** |
| `Export\Exporters\MatchDayTeamSheetPdfExporter` | None — Starting XI / Bench partition is print-only | **Forked** — inline `$wpdb` on `tt_activities` + `tt_attendance`; reads `lineup_role` + `position_played` columns that no on-screen view surfaces | Drift — `club_id`-strict on both `a.club_id` and `pl.club_id` | Print-only — Starting XI / Bench partition + signature lines | **Low — print-only by design, but no spec doc** |

## Migration priorities

1. **`PlayerEvaluationPdfExporter` + `ScoutingReportPdfExporter` + `PlayerOnePagerPdfExporter` — the `club_id`-strict player lookup**.
   All three call `QueryHelpers::get_player()`. The on-screen player
   profile (`FrontendPlayersManageView::loadPlayer()`) does not. Same
   #1149 defect mechanism; will hit pilot the moment a player row's
   stored `club_id` doesn't match `CurrentClub::id()`. Either pivot
   these exporters off `QueryHelpers::get_player()` (mirror
   `FrontendPlayersManageView::loadPlayer()`'s demo-scope-only check)
   or fix `QueryHelpers::get_player()` to read the player's own
   `club_id` like the #1149 patch did for the goal-intake router.
   Pick the second — it fixes 3 exporters + any future caller in one
   place. *(Follow-up spec below.)*

2. **`ActivityBriefPdfExporter` — extract a shared `ActivitiesRepository` and call it from both surfaces**.
   The view + exporter both inline `$wpdb` on `tt_activities` +
   `tt_attendance` with subtly different filter sets. Today they
   roughly agree; tomorrow either side adds an `archived_at IS NULL`
   filter the other doesn't and the divergence becomes a bug. Same
   structural risk #1059 fixed for match prep. *(Follow-up spec below.)*

3. **`MatchDayTeamSheetPdfExporter` — document the print-only status OR build the on-screen pre-match team-sheet view**.
   The Starting XI / Bench partition + the `position_played` /
   `lineup_role` columns are real domain data with no on-screen
   surface. Coaches printing the team sheet for the dugout have no
   way to verify what they'll print before they print it. Either add
   a `?tt_view=team-sheet&activity_id=N` view that shares a renderer
   with the exporter (preferred — same #1059 pattern), or write a
   docblock + `docs/exports.md` note that this is a deliberate
   print-only artefact and the operator edits the source data via
   match-prep + REST PATCH. *(Follow-up spec below.)*

Lower-priority — best-in-class today, no migration needed:
`Stats\PrintRouter`, `MatchPrepPrintRouter`, `PdpPrintRouter`,
`PdpPdfExporter`, `FrontendVctSessionPrintView`,
`MethodologyReferencePrintRouter`, `PlayerGoalIntakePrintRouter`
(post-#1149).
