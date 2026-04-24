<!-- type: bug -->

# Fix FrontendMyProfileView fatal error for rostered players

## Problem

Every player who is rostered on a team sees a fatal PHP error when they navigate to their "My profile" view. Only unrostered players see the page render correctly. This has been in production since the frontend rebuild and is assumed to have gone unnoticed because early testing used unrostered demo accounts.

The fatal crashes the page rather than degrading gracefully, so affected players cannot access their own profile at all.

Who feels it: every rostered player with `wp_user_id` linked, on every visit to My profile. That's most real users once a club is set up.

## Proposal

Two-change, one-file fix in `src/Shared/Frontend/FrontendMyProfileView.php`:

1. **Replace the call to the non-existent `QueryHelpers::get_team()`** with either (a) a direct `$wpdb->get_row()` against `tt_teams` by team ID, or (b) a new `QueryHelpers::get_team( int $team_id )` helper if any other caller in the codebase needs the same lookup. Before picking, grep for other `tt_teams` lookups in the codebase — if there's duplication, consolidate into `QueryHelpers`. If it's a one-off, keep it local to avoid expanding the helper API for a single site.

2. **Move the age-group display block inside the existing `if ( $team )` wrapper.** Currently it sits outside the wrapper and relies on `??` null-coalesce to avoid a fatal on PHP 8, but still emits a warning when `$team` is null and `WP_DEBUG_DISPLAY` is on.

## Scope

- `src/Shared/Frontend/FrontendMyProfileView.php` — the broken call on line 28 and the orphaned age-group block on line 51.
- Optionally, `src/Infrastructure/Query/QueryHelpers.php` if consolidation is the right call.

## Out of scope

- Any broader rebuild of the My profile view. That's #0014 Part A, a separate epic.
- Visual polish, mobile-responsiveness, new fields. Those are #0014's concerns too.
- Adding test coverage — the plugin has no test suite today, and this isn't the right bug to introduce one on.

## Acceptance criteria

- A player user rostered on a team can load the My profile page without PHP errors (no fatal, no warnings rendered into the page).
- A player user with no team still loads the My profile page without errors (no regression).
- The team name and age group both display correctly when the player has a team.
- When the player has no team, the team-related blocks are cleanly hidden.
- No PHP warnings or notices in `debug.log` on either path.

## Notes

- Reproduction steps are concrete: create a player user, assign to a team, log in as that player, visit My profile tile. With the bug, page errors. After fix, page renders.
- This is scoped intentionally narrow. Do not drift into rebuild territory — that's what #0014 is for.
- Before production release, verify on both PHP 8.1 and 8.2 (two versions the plugin currently supports based on `composer.json`).
- If a `get_team()` helper is added to `QueryHelpers`, keep the return type consistent with how `player_display_name()` handles missing records (null vs empty string vs WP_Error).
- Estimated effort: ~1–2 hours including review.
