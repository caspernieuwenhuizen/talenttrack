# TalentTrack v3.110.171 — Tournaments tile routing fix (closes #764)

## Pilot report

Chat 2026-05-20:

> Ok so we need to ensure the tournament functionality. At the moment, when as admin I click on the tile I get a "unknown part" message/. That is not correct

## Root cause

`src/Shared/Frontend/DashboardShortcode.php`:

- **Line 665** — the `tournaments` case in `dispatchCoachingView()` is correctly wired:
  ```php
  case 'tournaments':
      FrontendTournamentsManageView::render( $user_id, $is_admin );
      break;
  ```
- **Line 195** — the `$coaching_slugs` allowlist that the top-level router consults at **line 350** does NOT contain `'tournaments'`:
  ```php
  $coaching_slugs = [ 'teams', 'players', 'players-import', 'people',
      'functional-roles', 'evaluations', 'activities', 'goals',
      'pdp', 'pdp-planning', /* … */, 'mail-compose' ];
      //                              ^^^^^ no 'tournaments' here
  ```
- **Line 350** — the router:
  ```php
  } elseif ( in_array( $view, $coaching_slugs, true ) ) {
      self::dispatchCoachingView( $view, $user_id, $is_admin );
  } elseif ( /* … other dispatch branches … */ ) {
      // …
  } else {
      echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
  }
  ```

So when the admin clicked the Tournaments tile and landed on `?tt_view=tournaments`:

1. None of the slug allowlists matched (`tournaments` is in zero of `$me_slugs`, `$account_slugs`, `$coaching_slugs`, `$analytics_slugs`, `$admin_slugs`, `$workflow_slugs`, `$dev_slugs`, `$invitation_slugs`, `$report_slugs`, `$trial_slugs`, `$staff_dev_slugs`, `$wizard_slugs`, `$mfa_slugs`, `$mobile_slugs`).
2. The router fell through to the final else branch.
3. `Unknown section.` rendered. Dutch users saw *"Onbekend onderdeel."*

The `FrontendTournamentsManageView::render()` call site that *would* serve the page was never reached. The view itself was perfectly functional — it just had no inbound route from the tile.

## Why this slipped through

The tournament feature shipped in two staggered ships:

- **#0093 chunk 1 (v3.110.132 / v3.110.133)** — schema + REST + view + wizard. Reachable only by direct URL (`?tt_view=tournaments`), and direct URLs *also* hit the same slug-allowlist gate, so this should have caught the bug then. But the only person clicking direct URLs at the time was a developer who'd added `?tt_view=tournaments` manually — and that path goes through `dispatchCoachingView` only IF the slug is in the allowlist. The direct URL path was equally broken; nobody noticed because there was no tile to expose it.

- **v3.110.152 — tile registration** (`TournamentsModule::boot()` → `TileRegistry::register` with `view_slug: 'tournaments'`). This is what exposed the bug — the academy admin now had a one-click entry from the dashboard tile grid, and clicking it surfaced the routing miss immediately.

This is the **third repeat of the exact same class of bug** in this file. The block comment above `$coaching_slugs` already references the previous two:

- v3.110.10 — `team-planner` had the same miss.
- A later ship — `onboarding-pipeline` had the same miss.

Both fixes were one-line allowlist additions. This fix is the same shape.

## Fix

One line: add `'tournaments'` to the `$coaching_slugs` array, between `'goals'` and `'pdp'` (alphabetical ordering of the Performance-group surfaces).

```php
$coaching_slugs  = [ 'teams', 'players', 'players-import', 'people',
    'functional-roles', 'evaluations', 'activities', 'goals',
    'tournaments',  // <-- added
    'pdp', 'pdp-planning', /* … */ ];
```

Plus a comment block above the array explaining the miss (matching the style of the prior two comments for team-planner and onboarding-pipeline) so the next coder reading the file knows this is a recurring pattern.

## Suggested follow-up (not in this ship)

The recurring nature of this miss suggests a structural fix is worth considering for a future ship:

- **Option A** — auto-derive the allowlist from the dispatcher's `switch` cases. Reflection or a static parse on plugin boot, then cached in `wp_options` like the tile registry. A new case in `dispatchCoachingView()` would auto-update the router with no separate edit.
- **Option B** — fold the per-group dispatchers into a single registry that takes ownership of both the allowlist AND the render callback. New view = one registration entry.
- **Option C** — add a unit test that asserts every `case '<slug>':` in each dispatcher method appears in at least one allowlist. CI catches the next miss before it ships.

Option C is the cheapest insurance and would have caught this bug at v3.110.132 push time. Worth a separate idea file. Not done in this ship — the user wants the tile to work today.

## Files touched

- `src/Shared/Frontend/DashboardShortcode.php` — one line added to `$coaching_slugs`, comment block above it.
- `talenttrack.php` — version 3.110.170 → 3.110.171.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.

No DB migration, no REST shape change, no new i18n strings, no auth change.

## Test plan

On the pilot install after the release ZIP rebuilds:

- [ ] Log in as admin (or any user holding `tt_view_tournaments`, which in v1 means `administrator` + `tt_club_admin`).
- [ ] On the dashboard, locate the Tournaments tile (Performance group, between Team planner and Goals).
- [ ] Click the tile.
- [ ] Lands on the tournaments list view (`FrontendTournamentsManageView::renderList`), not "Unknown section."
- [ ] Whole-row click navigates to tournament detail (the row-link standard shipped in v3.110.170 still applies — Tournaments was wired up there).
- [ ] Coach / HoD / scout / player / parent personas (without `tt_view_tournaments`): the tile is auto-hidden by `TileRegistry`'s `cap` gate, so they never see the entry point. Direct URL `?tt_view=tournaments` still routes correctly but `FrontendTournamentsManageView::render` shows the "Not authorized" notice inside the view's own permission re-check.
