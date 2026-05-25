# TalentTrack v4.2.5 — Prospects + Scouting matrix-bypass cluster (closes #914)

## Pilot context

Scouts whose access is granted via the authorization matrix (rather than via a WP role that bakes `tt_view_prospects` / `tt_edit_prospects` into its baseline caps) hit "Not authorized" when they tried to:

- Open the scouting-visit detail page (`?tt_view=scouting-visit&id=N`).
- Create / update / archive a scouting visit via REST.
- See the right "scope-clamped to my prospects" subset on the kanban (`?tt_view=onboarding-pipeline`).

CLAUDE.md §4 ("The cap layer is portable; the cookie layer is not") locks the codebase into checking caps via `current_user_can()` + the `AuthorizationService` matrix bridge — but four call sites in `Prospects` were on the bare cap path, and three sites used `in_array( 'tt_scout', $roles )` role-string compares, both of which bypass the matrix layer entirely.

## The mismatch in one sentence

`tt_scout` doesn't carry the prospects caps in its WP role baseline; they come exclusively through `LegacyCapMapper` + the `user_has_cap` filter. `current_user_can( 'tt_view_prospects' )` returns `false` for a matrix-only scout. `AuthorizationService::userCanOrMatrix( $uid, 'tt_view_prospects' )` returns `true` for the same user because it falls back to `LegacyCapMapper::evaluate()` when the WP cap layer says no. The fix is mechanical: route every Prospects-module cap decision through the canonical helper.

## What changed

### Matrix bypass — bare `current_user_can` → `userCanOrMatrix`

- **`src/Modules/Prospects/Rest/ScoutingVisitsRestController.php:52-56`** — `can_edit()` (POST `/scouting-visits`, POST/DELETE `/scouting-visits/{id}`). Three cap checks all migrated.
- **`src/Modules/Prospects/Rest/ScoutingVisitsRestController.php:178-184`** — `canEditRow()` (per-row scope check inside update / archive handlers). Two cap checks migrated.
- **`src/Modules/Prospects/Frontend/FrontendScoutingVisitDetailView.php:30`** — entry cap on the detail render. Picks up matrix-only scouts.
- **`src/Modules/Prospects/Frontend/FrontendScoutingVisitDetailView.php:54`** — row-level scope check (`tt_manage_prospects`).
- **`src/Modules/Prospects/Frontend/FrontendScoutingVisitDetailView.php:84`** — "Log scouting find" page-action cap gate.
- **`src/Modules/Prospects/Frontend/FrontendScoutingPlanView.php:165-178`** — `canEdit()` row-level scope + `renderList()` filter clamp + empty-list copy decision.
- **`src/Modules/Prospects/Rest/TestTrainingsRestController.php:45-48`** — `can_edit()` for the HoD `+ New test training` REST POST.

Acceptance grep:

```
grep -rn "current_user_can( 'tt_" src/Modules/Prospects/
```

Zero hits after this ship.

### Role-string compare → capability-based

- **`src/Modules/Prospects/Frontend/FrontendOnboardingPipelineView.php:367-376`** — `isScoutOnly()` rewritten. Old shape: `in_array( 'tt_scout', $roles ) && ! in_array( 'tt_head_dev'/'tt_club_admin'/'administrator', $roles )`. New shape: `userCanOrMatrix( $uid, 'tt_view_prospects' ) && ! userCanOrMatrix( $uid, 'tt_manage_prospects' )`. Captures the same intent — "user has prospect access but not the admin tier" — and works for matrix-only scouts.

### Dead code removed

- **`src/Modules/Prospects/Rest/ProspectsRestController.php:188-197`** — `isScoutOnly()` was `private static`, no internal call sites since the v3.110.154 scout-scope clamp removal. The class-level comment at line 116 saying "retained for any other call site" was incorrect — a `private static` method can't be reached from outside. Removed. The stale comment is rewritten to drop the dead-helper reference.

Acceptance grep:

```
grep -rn "in_array.*tt_scout\|in_array.*tt_coach\|in_array.*tt_head" src/Modules/Prospects/
```

Zero hits after this ship.

## What's deliberately not in this PR

- **`Modules/PersonaDashboard/Widgets/OnboardingPipelineWidget::isScoutOnly()` (line 239)** has the identical role-string-compare bug pattern. It's outside this issue's Prospects-only scope. Flagged as a follow-up — same mechanical migration to `userCanOrMatrix()`. Filing a separate issue.

## Validation

- A scout granted via the matrix (`tt_edit_prospects = self`, `tt_view_prospects = global`, no `tt_scout` WP role) can:
  - Open `?tt_view=scouting-visit&id=N` (was blocked → now allowed).
  - POST `/wp-json/talenttrack/v1/scouting-visits` (was 403 → now 200).
  - Update / archive their own visit (was 403 → now 200).
  - See the kanban scope-clamped to their own prospects.
- A scout whose grant is revoked still gets "Not authorized" cleanly — both layers say no.
- No regression for scouts who hold caps via WP role baseline — `userCanOrMatrix` tries `user_can()` first, only falls through to the matrix when WP says no, so legacy users take the same path they always did.

## Why this is `patch`, not `major`

Bug-fix cluster. No cap matrix change (the caps were already in the matrix). No REST contract change (the endpoints, request/response shapes, and authorization semantics intended all along are now what's actually enforced). No schema change. The user-visible diff is "the access the matrix already grants now actually works." Patch bump per the SemVer table in `DEVOPS.md`. CLAUDE.md §9 DoD: auth is checked via capabilities, not role-string compare ✓.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.2.4` → `4.2.5`.
