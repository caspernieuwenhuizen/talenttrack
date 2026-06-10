<!-- audience: dev -->

# Audit 8 — Form-picker option-source honours user's matrix scope

Date: 2026-06-03
Ref: #1182
Recently fixed (not re-flagged): `FrontendActivitiesManageView::renderForm`
attendance picker — v4.20.10 (#1154); Goal wizard `PlayerStep` — v4.20.6
(#1156).

## Summary

Audited every `<select>` / `<datalist>` / picker render site in
`src/Modules/Wizards/**/Step.php`, `src/Shared/Frontend/Frontend*View.php`,
and `src/Modules/**/Frontend/*View.php` against the scoped helpers
available in `Infrastructure/Query/QueryHelpers.php` (`get_teams_for_coach`,
`get_players($team_id)`, `user_has_global_entity_read`).

`QueryHelpers` exposes a scoped `_for_coach` variant for teams but not
for players; the canonical scoped pattern for players is
`foreach (get_teams_for_coach($uid) as $t) get_players($t->id)`.

Across ~50 picker render sites, most already match the canonical
`$is_admin ? get_teams() : get_teams_for_coach($user_id)` pattern. Four
material drift sites remain that are both reachable by non-admin
personas (coach / HoD / scout) AND expose records outside the user's
matrix scope. All other unscoped calls live in admin-only views
(VCT config, scout access, persona-dashboard editor, scout reports
launcher tiles intended to be club-wide) and are intentional.

A separate (smaller) defect class shows up in 3 wizard / view sites
that use `tt_edit_settings` as the "is admin" gate. HoDs (per
RolesService v3.84.3, line 247-260) explicitly DO NOT carry
`tt_edit_settings`, so they fall through to the coach-scope branch and
see an empty picker. The right gate is the broader
`tt_access_frontend_admin` cap (already used in `PlayerPickerStep`
post-#809/#810). This is a UX defect, not a leak — flagged for
completeness but not material to this audit's privacy / phantom-submit
focus.

## Findings table

| File:line | Picker type | Current source | Scoped variant available | Persona impact | Severity |
| --- | --- | --- | --- | --- | --- |
| `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php:1002` | Team (cross-team "Other team" tab in blueprint editor) | `QueryHelpers::get_teams()` | Yes — `get_teams_for_coach($user_id)` | Coach editing their team's blueprint sees every team in the academy + every player on those teams in the "Add → Other team" tab dropdown. Reachable by `userCoachesTeam` gate (line 64) — i.e. any non-admin coach with at least one assigned team. | High — leaks full club roster to coach; same shape as #810 cascading defect. |
| `src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php:1005` | Player (per-other-team roster nested under sibling team) | `QueryHelpers::get_players($t->id)` over unscoped team set | (depends on team-level fix above) | Same as above — coach sees minors on teams they don't coach. | High — privacy. CLAUDE.md §1 ("These are minors. Player-centricity includes protecting the player"). |
| `src/Shared/Frontend/FrontendStandardReportsView.php:632` | Team (entity-picker landing for team-scoped curated reports) | `QueryHelpers::get_teams()` | Yes — `user_has_global_entity_read($user_id, 'teams')` then either `get_teams()` or `get_teams_for_coach($user_id)` | Coach hits `?tt_view=standard-report&slug=team-minutes-distribution` (or `team-squad-evaluation-summary`) and the picker lists every academy team — they can then click into a team they don't coach and view its squad minutes / eval averages. `tt_view_reports` is granted to coach in `RolesService` line 303. | High — leaks every team's reports surface to coaches; phantom-pick shape. |
| `src/Shared/Frontend/FrontendStandardReportsView.php:608` | Player (entity-picker landing for player-scoped curated reports) | `QueryHelpers::get_players(0)` | Yes — `get_teams_for_coach` → per-team `get_players($t->id)` union (matches the pattern in `FrontendGoalsManageView` lines 219-231 and `FrontendPdpManageView::eligiblePlayers`) | Coach hits `?tt_view=standard-report&slug=player-minutes-played` and the picker lists every active player academy-wide. | High — same as above for the per-player report family. |

## Drift sites documented as intentional (skipped)

| File:line | Reason for being intentional |
| --- | --- |
| `src/Modules/Vct/Frontend/FrontendVctConfigView.php:216` | Cap-gated on `tt_vct_admin_library` (HoD/admin only) — admin surface, club-wide config by design. |
| `src/Modules/Reports/Frontend/FrontendScoutAccessView.php:50` | Cap-gated on `tt_generate_scout_report` (HoD/admin) — HoD assigning scout access needs the full academy roster. |
| `src/Shared/Frontend/FrontendReportDetailView.php:82` (team_ratings) | The report IS the per-team average across the club — by design club-wide. Reachable by `tt_view_reports`; coach value is club benchmarking, no per-player leak. |
| `src/Modules/Players/Admin/PlayersPage.php:71,219` & `src/Modules/Activities/Admin/ActivitiesPage.php:223,259` & `src/Modules/Goals/Admin/GoalsPage.php:106` & `src/Modules/Reports/Admin/ReportsPage.php:147,213,233` & `src/Modules/Evaluations/Admin/EvaluationsPage.php:258` & `src/Modules/Stats/Admin/PlayerRateCardsPage.php:46` & `src/Modules/Teams/Admin/TeamPlayersPanel.php:28` | wp-admin pages — gated on `tt_view_*` admin caps + admin menu registration. HoD/admin-only by venue. |
| `src/Shared/Frontend/Components/GuestAddModal.php:72` (cross_team=true on PlayerSearchPickerComponent) | #0026 linked-guest picker — coach picking a guest player from another team IS the documented use case. Intentional cross-team. |
| `src/Modules/Vct/Frontend/FrontendVctSessionView.php:129` & `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php:312,571,685` & `src/Modules/Pdp/Frontend/FrontendPdpManageView.php:1059,1060` (team_id-scoped) | `get_players($team_id)` with `$team_id` already access-checked upstream. Per-team scope is exactly the canonical pattern. |
| `src/Shared/Frontend/FrontendTeamsManageView.php:240` | Roster-add pool in team-edit form. Form is reachable via `tt_view_teams` (coach has it) but the `+ Add player` REST POST is gated on `tt_edit_teams` (HoD/admin only). UI shows what server-side accepts; not a leak in the actionable sense, but worth a separate idea-file to consider hiding the add-pool UI when the user can't submit. Not in scope for this audit. |

## Secondary class — drift sites with broken admin gate (UX, not privacy)

These views use `tt_edit_settings` as the "is admin" gate. Per
`RolesService.php` line 254 the HoD persona explicitly does NOT carry
`tt_edit_settings`, so HoDs fall through to the coach-scope branch and
see an empty picker (HoDs are not assigned to teams). The right gate
is `tt_access_frontend_admin` — used correctly in `PlayerPickerStep`
post-#809/#810.

| File:line | Source |
| --- | --- |
| `src/Modules/Wizards/Activity/TeamStep.php:22-25` | `$is_admin = current_user_can('tt_edit_settings')` |
| `src/Modules/Vct/Wizard/WhenStep.php:30-33` | Same |
| `src/Shared/Frontend/FrontendRateCardView.php:60` | Same |
| `src/Shared/Frontend/DashboardShortcode.php:168` (dispatcher-level) | Same — propagates the wrong gate to every view that takes `$is_admin` as a parameter (CoachDashboardView, FrontendPodiumView, FrontendTournamentsManageView, FrontendFunctionalRolesView, etc.). Fixing this single line would normalise the gate across the entire dispatcher surface but risks scope creep — already evidenced by #713 (wizard age-group dropdown empty for HC). Recommended as a separate, larger, controlled refactor. |

These do not leak data; they show an empty picker to a persona that
should see everything. Filed for visibility, not as part of this
audit's privacy-focused triage.

## Pattern recommendation

Two lightweight controls would make this drift class self-evident
going forward:

1. **CodeStandards-style sniff** (PHPCS custom rule) banning bare
   `QueryHelpers::get_teams()` / `QueryHelpers::get_players()` calls
   outside `src/Modules/**/Admin/*Page.php`,
   `src/Infrastructure/REST/`, the `*Component.php` resolvers in
   `Shared/Frontend/Components/`, and explicitly-allowlisted admin
   views (e.g. `FrontendVctConfigView`, `FrontendScoutAccessView`).
   Every other call site must go through the
   `$is_admin ? get_teams() : get_teams_for_coach($user_id)` ternary
   (or the equivalent for players). Equivalent to the existing
   `tt_lookups_translations` sniff family.

2. **`PlayerScopeResolver` helper** in `Infrastructure/Query/` that
   takes `($user_id, $is_admin, ?$team_filter = null)` and returns the
   canonical per-team union for non-admins. Picker render sites then
   call `PlayerScopeResolver::players($user_id, $is_admin)` instead of
   re-deriving the loop in every view (today: `FrontendGoalsManageView`,
   `FrontendEvaluationsView`, `FrontendPdpManageView::eligiblePlayers`,
   `FrontendRateCardView`, `CoachForms::renderEvalForm` /
   `renderGoalsForm` — 6 hand-rolled copies of the same loop).
   Fewer copies = fewer places for drift to land.

## Follow-up issues filed

- #1202 — `FrontendTeamBlueprintsView` blueprint editor "Other team"
  picker leaks academy roster to coach. (lines 1002, 1005)
- #1203 — `FrontendStandardReportsView` entity pickers leak academy
  teams + players to coach. (lines 608, 632)
