# TalentTrack v4.3.4 — HoD-dashboard Onboarding Pipeline widget matrix-bypass completion (closes #932)

## Context

Completes the matrix-bypass cluster opened by #914 / PR #926 (v4.2.5). That ship fixed every role-string-compare site inside `src/Modules/Prospects/` but flagged an identical bug pattern outside that scope:

> "The identical role-string-compare bug pattern exists in `Modules/PersonaDashboard/Widgets/OnboardingPipelineWidget::isScoutOnly()` but is outside this issue's Prospects scope — flagged for a follow-up."

This is that follow-up.

## The bug

`OnboardingPipelineWidget::isScoutOnly()` used:

```php
$roles = (array) ( $user->roles ?? [] );
if ( in_array( 'tt_head_dev', $roles, true ) ) return false;
if ( in_array( 'tt_club_admin', $roles, true ) ) return false;
if ( in_array( 'administrator', $roles, true ) ) return false;
return in_array( 'tt_scout', $roles, true );
```

That fails for any scout whose access is granted via the authorization matrix rather than via the `tt_scout` WP role baking the cap into its baseline. The HoD-dashboard Onboarding Pipeline widget never correctly scope-clamped those scouts' views.

## The fix

Mirrors the cap-based rewrite shipped in #914 for `FrontendOnboardingPipelineView::isScoutOnly()`:

```php
private static function isScoutOnly( int $user_id ): bool {
    if ( $user_id <= 0 ) return false;
    if ( AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' ) ) return false;
    return AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' );
}
```

Same intent ("user has prospect access but not the admin tier"). Works for both matrix-granted and WP-role-granted users — `userCanOrMatrix` falls through to the matrix bridge only when the WP cap check returns false, so legacy users take the same path they always did.

## Acceptance

- `grep -rn 'in_array.*tt_scout\|in_array.*tt_coach\|in_array.*tt_head' src/Modules/PersonaDashboard/` returns zero hits after this ship.
- Matrix-only scout sees the correct scope-clamped subset on the HoD dashboard's Onboarding Pipeline widget.
- No regression for scouts holding caps via WP role baseline.

## Validation

- Matrix-only scout (`tt_view_prospects = global` via matrix bridge, no `tt_scout` WP role): widget now shows them only their own prospects (was: showed everything or nothing depending on the surrounding code path).
- WP-role scout: identical behaviour to v4.3.3 (regression check — `userCanOrMatrix` tries `user_can()` first; same path as before).
- HoD / academy_admin / administrator: still see everything (the negative branch correctly rejects them via `tt_manage_prospects` check).

## Why this is `patch`, not anything bigger

Bug-fix cluster completion. No new caps, no schema change, no contract change. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.3` → `4.3.4`.
