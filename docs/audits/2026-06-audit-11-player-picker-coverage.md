# Audit 11 — Player-picker pattern coverage

Date: 2026-06-09
Trigger: pilot 2026-06-09 raised "the new-activity wizard offers a team dropdown but no player picker".
Tracking issue: #1296.
Sibling enhancement: #1297 (new AttendanceRosterStep on activity wizard).

## Purpose

Document the two **canonical patterns** for selecting a player anywhere in the codebase, the five **drift surfaces** that don't use either, and the three **implicit** surfaces where the absence of a picker is by design — so future PRs adding a player-selection surface have a citation target instead of inventing a third pattern.

## Canonical patterns

Both pattern 1 and pattern 2 scope to the coach's team assignments via `QueryHelpers::get_teams_for_coach()` for non-admins. Both fire `change` events on the underlying form value so wizard-validators re-evaluate Next.

### Pattern 1 — Autocomplete picker

Component: [`PlayerSearchPickerComponent`](../../src/Shared/Frontend/Components/PlayerSearchPickerComponent.php).

Shape: autocomplete text input + optional team-filter dropdown. Fits unbounded picker contexts (any active player in the club).

Reference site: [Evaluation wizard PlayerPickerStep](../../src/Modules/Wizards/Evaluation/PlayerPickerStep.php).

When to use: when there are many teams × many players per team, or the picker needs to span the whole club (guest call-ups, cross-team evaluations).

### Pattern 2 — Team→player cascade

Shape: stacked `<select>` elements wired via [`wizard-cascade-picker.js`](../../assets/js/components/wizard-cascade-picker.js) — `data-tt-cascade-filter` on the team select, `<optgroup data-tt-team-id>` per team on the player select.

Reference site: [Goal wizard PlayerStep](../../src/Modules/Wizards/Goal/PlayerStep.php#L75-L109).

When to use: when the picker fits naturally as a wizard step without the JS-autocomplete overhead, or the operator's mental model is "pick team first, then player".

## Drift inventory

Five surfaces don't use either canonical pattern:

| Surface | File | Current shape | Target |
|---|---|---|---|
| Activity wizard | [src/Modules/Wizards/Activity/TeamStep.php](../../src/Modules/Wizards/Activity/TeamStep.php) | Team picker only — no player step exists | #1297 adds AttendanceRosterStep |
| Admin Goals page (legacy) | [includes/Admin/Goals.php:48](../../includes/Admin/Goals.php#L48) | Raw `<select name="player_id">` over `Helpers::get_players()` — no team filter | Candidate for deletion in legacy `includes/` sweep |
| Admin Evaluations page (legacy) | [includes/Admin/Evaluations.php:78](../../includes/Admin/Evaluations.php#L78) | Same shape | Candidate for deletion |
| Frontend coach eval form (legacy) | [includes/Frontend/App.php:239](../../includes/Frontend/App.php#L239) | Flat scoped `<select>` of all team-assigned players — no UI team filter | Candidate for deletion (modern wizard covers the surface) |
| Frontend coach goals form (legacy) | [includes/Frontend/App.php:330](../../includes/Frontend/App.php#L330) | Same shape | Candidate for deletion (modern wizard covers the surface) |

Three of the five live in `includes/` — the pre-`src/` legacy namespace. They're strong deletion candidates rather than retrofit candidates, because modern Goal + Evaluation wizards cover the surface. Cleanup deferred to the legacy-removal sweep (no separate issue filed; the existing legacy-removal slices will claim them in bulk). File a follow-up if a pilot operator reports landing on the legacy admin pages.

The activity-wizard gap is the only forward-only retrofit worth doing today — tracked as enhancement #1297.

## Implicit / non-picker surfaces

Three surfaces select a player without an explicit picker — by design:

| Surface | File | Why it's fine |
|---|---|---|
| Evaluation wizard AttendanceStep | [src/Modules/Wizards/Evaluation/AttendanceStep.php](../../src/Modules/Wizards/Evaluation/AttendanceStep.php) | Players come from the activity roster; no picker needed |
| Evaluation wizard RateActorsStep | [src/Modules/Wizards/Evaluation/RateActorsStep.php](../../src/Modules/Wizards/Evaluation/RateActorsStep.php) | Players come from attendance rows; no picker needed |
| MatchExecution sideline | [src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php](../../src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php) | Read-only, pre-seeded lineup from match prep |

## Going forward

Any new surface that selects a player MUST pick canonical pattern 1 or pattern 2. This audit is the citation target — link it from the PR description so reviewers can verify the choice.

## Refs

- Issue: #1296.
- Related: #1156 (specced team→player dependent dropdowns), #1157 (fixed PlayerSearchPicker change-event dispatch).
- Enhancement spun out: #1297 (AttendanceRosterStep on activity wizard).
