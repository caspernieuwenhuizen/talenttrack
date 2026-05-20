# TalentTrack v3.110.174 — Team chemistry: "Try a lineup" sandbox with live recompute (closes #768)

## Why

The team chemistry board (`?tt_view=team-chemistry`) ships with an auto-suggested XI from `ChemistryAggregator`. Until today the board was read-only — to experiment with a different lineup ("what if our right-back plays centre-back?") a coach had to open the separate Team Blueprint editor, drag-drop a sandbox, and watch the page reload after every drop. Chemistry analysis lives on the chemistry board; the experimentation should live there too.

## What

A new in-place "Try a lineup" mode on the chemistry board:

- Toggle above the pitch flips the board into sandbox mode. Mode + overrides live in `sessionStorage` (per-team key) so a refresh keeps the experiment.
- Tap any pitch slot → bottom-sheet picker opens listing the full roster ranked by fit score for that slot (depth chart ∪ roster, deduped). Each row shows the fit score and a *currently in <slot>* badge when the player is already on the pitch.
- Pick a candidate → server recompute via `POST /talenttrack/v1/teams/{id}/chemistry/preview` (new, capability-gated, zero DB writes) → JS patches the slot, the composite score, the four-part breakdown, the link-chemistry headline, every link colour, and the link tooltips in place. No page reload.
- *Reset to suggested XI* discards every override.
- *Save as blueprint* prompts for a name, creates a real Team Blueprint with the sandbox lineup as assignments, and redirects into the blueprint editor.

## How it works

- `ChemistryAggregator::teamChemistry()` gains an optional `array $overrides` parameter (`slot_label` → `player_id|null`). The XI-selection runs in two passes: overrides lock specific slots first; the existing greedy pass fills the rest.
- New REST route `POST /teams/{id}/chemistry/preview` accepts the same body shape as a GET, plus `overrides`, and returns the same payload — pure compute, no writes.
- `PitchSvg` now stamps every slot with `data-slot-label` + `data-player-id` and every link line with `data-link-key` so JS can patch the SVG in place.
- New assets: `assets/js/frontend-team-chemistry.js` + `assets/css/frontend-team-chemistry.css`. Localized config in `TT_TEAM_CHEM`.
- Mobile-first: bottom-sheet picker (not centred modal), 48px touch targets, `safe-area-inset-bottom`, `prefers-reduced-motion` respected, `body.tt-chem-picker-open` scroll-locks the page underneath.

## Player-centricity

Every swap is a player-attached experiment — *if this player plays at this slot, here's what changes around them*. The picker labels are player names, candidates are ranked by per-player fit, and the "Save as blueprint" handoff carries the player-by-slot map straight into the blueprint editor.

## Files

- `src/Modules/TeamDevelopment/ChemistryAggregator.php` — overrides parameter, two-pass selection
- `src/Modules/TeamDevelopment/Rest/TeamDevelopmentRestController.php` — `preview_chemistry` handler + route
- `src/Modules/TeamDevelopment/Frontend/PitchSvg.php` — data-attrs on slots + SVG links
- `src/Modules/TeamDevelopment/Frontend/FrontendTeamChemistryView.php` — sandbox markup + breakdown data-attrs + asset enqueue + localize
- `assets/js/frontend-team-chemistry.js` (new)
- `assets/css/frontend-team-chemistry.css` (new)
- `docs/team-chemistry.md` + `docs/nl_NL/team-chemistry.md` — *Try a lineup* section
- `languages/talenttrack-nl_NL.po` — Dutch translations for new strings

---

# TalentTrack v3.110.173 — Sustainable fix for the recurring "Unknown section" bug class (follow-up to #764)

## Pilot ask

Chat 2026-05-20, after the v3.110.171 one-line fix for tournaments:

> This bug needs to be solved permanently and in a sustainable way; propose a solution

I proposed three options:

1. **Bool-returning dispatchers** — fold the per-group `$xxx_slugs` allowlist into each dispatcher's switch itself
2. **CI test asserting switch ↔ allowlist consistency** — cheaper safety net, doesn't eliminate duplication
3. **Central ViewRegistry** — biggest refactor, follows the existing `TileRegistry`/`WizardRegistry` pattern

Pilot picked Option 1 and asked me to leave a note in the SaaS port repo about the eventual SaaS-side approach.

## Recurring bug history

`src/Shared/Frontend/DashboardShortcode.php` shipped the same class of bug **three times**:

| When | Symptom | Fix |
| --- | --- | --- |
| v3.110.10 | Team planner tile → "Unknown section." | Added `'team-planner'` to `$coaching_slugs` |
| later | Onboarding pipeline tile → "Unknown section." | Added `'onboarding-pipeline'` to `$workflow_slugs` |
| v3.110.171 (#764) | Tournaments tile → "Unknown section." | Added `'tournaments'` to `$coaching_slugs` |

Every fix was a one-line allowlist addition. The fundamental shape — two lists that must stay in sync — was the bug.

## Root architecture before this ship

The router consulted ~14 per-group `$xxx_slugs` arrays via `in_array()` to decide which dispatcher to call. Each dispatcher had a `switch ($view) { case '<slug>': render(); break; }` that also enumerated the same slugs. If a developer added a new `case` to a dispatcher and forgot to add the slug to the corresponding allowlist, the dispatcher was never reached and the slug fell through to the "Unknown section." default.

```php
// Before — two sources of truth:
$coaching_slugs = [ 'teams', 'players', /* … */ ];  // ← slug list 1
// …
private static function dispatchCoachingView( ... ): void {
    switch ( $view ) {
        case 'teams':   FrontendTeamsManageView::render( … ); break;  // ← slug list 2
        case 'players': FrontendPlayersManageView::render( … ); break;
        // …
    }
}
```

## The refactor

### Dispatchers return `bool`

Every `dispatchXxxView` method now declares `: bool`. Switch cases use `return true;` after dispatch. The default returns `false`.

```php
// After — one source of truth: the switch case list itself
private static function dispatchCoachingView( ... ): bool {
    switch ( $view ) {
        case 'teams':
            FrontendTeamsManageView::render( … );
            return true;
        case 'players':
            FrontendPlayersManageView::render( … );
            return true;
        // …
        default:
            return false;  // dispatcher passes; router tries the next
    }
}
```

Nested `return;` inside cap-check denial paths (e.g. `'scout-history'` denying when `tt_generate_scout_report` isn't held) becomes `return true;` — the dispatcher claimed the slug and rendered the denial, so the router should not try the next dispatcher.

### Router collapses to one helper call

The 80-line `if (in_array(…)) elseif (in_array(…)) elseif (…)` ladder collapses into:

```php
} elseif ( ! self::tryDispatch( $view, $user_id, $is_admin, $player ) ) {
    FrontendBreadcrumbs::fromDashboard( __( 'Unknown section', 'talenttrack' ) );
    echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
}
```

Where `tryDispatch` chains every dispatcher via `||` short-circuit:

```php
private static function tryDispatch( …, ?object $player ): bool {
    return self::dispatchMeView( $view, $player )
        || self::dispatchAccountView( $view, $user_id )
        || self::dispatchCoachingView( $view, $user_id, $is_admin )
        || self::dispatchAnalyticsView( $view )
        || self::dispatchAdminView( $view, $user_id, $is_admin )
        || self::dispatchWorkflowView( $view, $user_id, $is_admin )
        || self::dispatchDevView( $view )
        || self::dispatchInvitationView( $view )
        || self::dispatchReportView( $view, $user_id, $is_admin )
        || self::dispatchTrialView( $view, $user_id, $is_admin )
        || self::dispatchStaffDevelopmentView( $view, $user_id, $is_admin )
        || self::dispatchWizardView( $view, $user_id, $is_admin )
        || self::dispatchMfaView( $view, $user_id, $is_admin )
        || self::dispatchMobileView( $view, $user_id, $is_admin )
        || self::dispatchAnalyticsExploreView( $view, $user_id, $is_admin )
        || self::dispatchAnalyticsCentralView( $view, $user_id, $is_admin )
        || self::dispatchAnalyticsReportView( $view, $user_id, $is_admin )
        || self::dispatchAnalyticsScheduleView( $view, $user_id, $is_admin );
}
```

The first dispatcher to claim the slug wins; the rest are short-circuited. Order matters when slugs overlap — `dispatchMeView` runs before `dispatchAccountView` (though their slug sets don't actually overlap in practice; the order is preserved for safety).

### Preconditions move inside dispatchers

Previously the router did:

```php
} elseif ( in_array( $view, $me_slugs, true ) ) {
    if ( $player ) {
        self::dispatchMeView( $view, $player );
    } else {
        echo 'needs player record';
    }
}
```

Now `dispatchMeView` owns its precondition via a shared `requirePlayerOrDeny()` helper:

```php
case 'overview':
    if ( ! self::requirePlayerOrDeny( $player ) ) return true;
    FrontendOverviewView::render( $player );
    return true;
```

Same pattern for `dispatchAccountView` and the sign-in-required check.

### 7 new dispatchers wrap previously-inline single-slug routes

For uniformity, every routable view now goes through a bool dispatcher — no special cases:

- `dispatchWizardView` (`wizard` + `wizards-admin`)
- `dispatchMfaView` (`mfa-prompt`)
- `dispatchMobileView` (`mobile-settings`)
- `dispatchAnalyticsExploreView` (`explore`)
- `dispatchAnalyticsCentralView` (`analytics`)
- `dispatchAnalyticsReportView` (`attendance-report-team` + `attendance-report-player`)
- `dispatchAnalyticsScheduleView` (`scheduled-reports`)

### `$xxx_slugs` arrays — all 14 deleted

A comment block replaces them explaining the refactor and listing the three prior misses for future readers.

## Why this kills the bug class

Adding a new view used to require TWO edits:
1. Add the `case '<slug>':` to a dispatcher's switch.
2. Add the slug to a `$xxx_slugs` allowlist.

Forgetting step 2 was the recurring bug. After this refactor, **step 2 doesn't exist**. The switch case itself is the registration. Adding the case makes the slug routable on the next request. There is no second list to forget.

## Behaviour

No observable change. Same surfaces dispatch to the same views with the same permission checks, the same "Not authorized" / "Sign in required" notices, the same module-disabled fallback, the same matrix gate, the same module-disabled-tile notice.

Two tiny side effects worth noting:
- `?tt_view=teammate` was previously unreachable from the router (not in `$me_slugs`) despite the case existing in `dispatchMeView`. It's now reachable. Strictly an improvement.
- `?tt_view=my-settings` is explicitly claimed only by `dispatchAccountView` (not `dispatchMeView`) — the dead case in the old Me-dispatcher was removed and a comment explains why. Non-player personas (coach/scout/admin) reach `my-settings` correctly, same as v3.92.0 fixed.

## SaaS port — this refactor is WP-plugin-specific

The talenttrack-saas port uses Next.js App Router file-based routes. File existence (`app/<segment>/page.tsx`) is the registration — there's no allowlist to drift, no central dispatcher to forget to update.

When porting a WP `?tt_view=foo` surface to SaaS, the natural shape is `apps/web/app/(authenticated)/foo/page.tsx`, not a `ViewRegistry` class. **Don't port this dispatcher pattern.**

Noted in `talenttrack-saas/docs/decisions.md` under "Open decisions" for the eventual route-segment naming ADR (lands when the second SaaS module ports).

## Files touched

- `src/Shared/Frontend/DashboardShortcode.php` — the entire refactor lives in this one file. 11 multi-case dispatchers refactored to `bool`. 7 new tiny dispatchers wrap previously-inline routes. New `tryDispatch()` chains them all. Router elseif ladder collapses. All 14 `$xxx_slugs` arrays deleted.
- `talenttrack.php` — 3.110.172 → 3.110.173.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.
- *(separately)* `talenttrack-saas/docs/decisions.md` — Open-decision entry on view routing for the SaaS side.

No DB migration, no REST shape change, no new i18n strings, no auth change, no view-class change.

## Test plan

- [ ] Admin clicks the Tournaments tile → lands on tournaments list (regression check for the original #764 trigger)
- [ ] Coach clicks Team planner tile → lands on planner (regression check for the original v3.110.10 miss)
- [ ] Scout clicks Onboarding pipeline tile → lands on pipeline (regression check for the v3.110.x miss)
- [ ] Player clicks any Me-tile (my-team, my-evaluations, my-goals, etc.) → renders correctly
- [ ] Coach/scout/admin without a linked player clicks `?tt_view=my-settings` → still works (account-level slug, not Me-group)
- [ ] Coach without a linked player clicks `?tt_view=my-team` → "This section is only available for users linked to a player record."
- [ ] Genuinely unknown slug like `?tt_view=foobar` → "Unknown section."
- [ ] Module disabled → "This section is currently unavailable."
- [ ] Matrix denies → "You do not have access to this surface."
- [ ] Admin clicks every other tile (players, teams, people, evaluations, goals, activities, PDP, etc.) → renders correctly

## Verifying the bug class is gone

Try this experiment after this ship merges: add a temporary case to `dispatchCoachingView`:

```php
case 'test-route':
    echo 'hello world';
    return true;
```

Visit `?tt_view=test-route`. It renders "hello world" immediately. No allowlist edit needed. Pre-refactor, that same case would have rendered "Unknown section." until the slug was also added to `$coaching_slugs`.

Then remove the test case before committing.
