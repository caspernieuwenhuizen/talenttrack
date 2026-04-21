# TalentTrack v2.21.0 — Tile-Based Frontend + Read-Only Observer Role

## Summary

Two items, both preparatory for the broader front-end admin arc:

1. **Tile-based frontend landing page** — the frontend shortcode now opens onto a role-gated tile grid instead of dropping straight into a tab-heavy dashboard view. Same visual language as the admin dashboard (2.18.0) carried through to the public-facing experience.
2. **`tt_readonly_observer` role** — new narrow WordPress role with `read` + `tt_view_reports` only. Gates the new tile grid for users who should see reports and analytics without getting any management or evaluation capabilities.

No schema changes. No migrations. No breaking changes to existing tab navigation — it still works when `?tt_view` is set.

## Item 1 — Tile-based frontend

### Before

The frontend `[talenttrack_dashboard]` shortcode auto-dispatched to one of two heavy dashboard classes based on role:

- `PlayerDashboardView::render()` for pure players — a 6-tab interface (Overview, Mijn team, Evaluaties, Sessies, Doelen, Profiel)
- `CoachDashboardView::render()` for coaches + admins — a 7+ tab interface (Roster, Player Detail, Add evaluation, Attendance, etc.)

Two problems:

- On mobile, 7 tabs don't fit horizontally. Tabs were already scroll-shimmed in 2.16.0 mobile pass but remain cramped.
- There was no landing page — users dropped straight into whatever tab happened to be first, with no overview of "what can I do here?"

### After

When the shortcode page is visited **without** `?tt_view`, it now renders a **tile landing page** with the same visual style as the 2.18.0 admin dashboard:

- Personalized greeting header ("Welcome, {Name}")
- Section labels with fading-line accents
- Tile grid with colored icon + label + one-line description
- Hover lift + shadow elevation
- Mobile: 1-column grid, tiles shrink appropriately

Tapping a tile appends `?tt_view=<slug>` and reloads. The existing Player/Coach dashboard classes pick up from there — they already handle the sub-sections via their tabs, so nothing inside them needed to change.

### Tile groups

The tile grid is composed of 4 groups, each cap-gated so users only see what they can access:

**Me** (visible when user is linked to a player record):
- My card
- My team
- My evaluations
- My sessions
- My goals
- My profile

**Coaching** (visible with `tt_evaluate_players` or `tt_manage_settings`):
- My teams
- Players
- Evaluations
- Sessions
- Goals
- Podium

**Analytics** (visible with `tt_view_reports`):
- Rate cards
- Player comparison

**Administration** (visible with `tt_manage_settings`):
- Go to admin

A user who is a player + coach sees both "Me" and "Coaching" groups. A read-only observer (new role in this release) sees "Analytics" only, with no write capabilities inside. An admin sees everything.

### Frontend back navigation

New `FrontendBackButton` helper renders "← Back to dashboard" at the top of any tile sub-view. Different from the admin `BackButton`:

- Target is always the shortcode page sans query params rather than the HTTP referer
- Frontend referers are less reliable (caching, CDN, theme quirks)
- Fixed destination fits the tile-landing pattern: every sub-view has one clear home

### Behavior matrix

| `?tt_view` | Result |
|---|---|
| not set | Tile landing page |
| set, user is pure player | `PlayerDashboardView::render()` with back button |
| set, user is coach/admin | `CoachDashboardView::render()` with back button |
| set, user has `tt_view_reports` only (observer) | `CoachDashboardView::render()` with `is_admin=false`, back button |
| set, user has none of above | "No player profile linked" notice |

Existing bookmarks to specific `?tt_view=X` URLs continue to work and skip the tile landing — transparent upgrade.

## Item 2 — `tt_readonly_observer` role

### Motivation

The previous authorization model had these roles and their caps:

| Role | `read` | manage_players | evaluate_players | manage_settings | view_reports |
|---|---|---|---|---|---|
| Head of Development | ✓ | ✓ | ✓ | ✓ | ✓ |
| Club Admin | ✓ | ✓ | — | ✓ | ✓ |
| Coach | ✓ | — | ✓ | — | ✓ |
| Scout | ✓ | — | ✓ | — | — |
| Staff | ✓ | ✓ | — | — | — |
| Player | ✓ | — | — | — | — |
| Parent | ✓ | — | — | — | — |

There was no role for someone who should **see reports and dashboards but never save anything**. Closest options:
- Player/Parent — sees only their own data; can't see reports
- Coach — sees reports but also has full evaluation write access
- Scout — can evaluate players, so also a write role

### The new role

`tt_readonly_observer` — "Read-Only Observer" — with exactly `read` + `tt_view_reports`.

- Can log into the frontend dashboard
- Sees the Analytics tile group (Rate cards, Player comparison) on the frontend tile landing
- Can view the rate card page, usage statistics, team ratings, coach activity reports
- **Cannot** save evaluations, edit players, create sessions, set goals, or change configuration — every write surface is gated behind caps this role lacks

### Use cases

- Assistant coach in training — observing how evaluations work before being granted `tt_evaluate_players`
- Board member or club president who should see team performance without ever editing data
- External reviewer or auditor (e.g. federation/league assessment) brought in for a period
- Parent-liaison with extra viewing rights vs. regular parents

### Scope boundary (important)

**This is a lightweight role, not a cap refactor.** The existing TalentTrack caps are binary "view + manage" pairs — e.g. `tt_evaluate_players` means "can see the evaluation UI AND can save/edit/delete evaluations." A proper "read-only coach" experience would split each cap into `tt_view_*` + `tt_edit_*` pairs, but that's a plugin-wide audit sprint of its own. The observer role works today using only the narrow `tt_view_reports` cap.

Deep cap refactor is queued as a **v2.22.0 candidate item**. Flagged in this release's design notes.

## Files in this release

### New
- `src/Shared/Frontend/FrontendTileGrid.php` — role-gated tile landing page
- `src/Shared/Frontend/FrontendBackButton.php` — frontend back navigation helper

### Modified
- `talenttrack.php` — version 2.21.0
- `src/Shared/Frontend/DashboardShortcode.php` — route to tile landing when `?tt_view` is absent; render back button otherwise; add observer-role fallback path
- `src/Infrastructure/Security/RolesService.php` — add `tt_readonly_observer` role definition
- `languages/talenttrack-nl_NL.po` + `.mo` — ~31 new strings

### Deleted
(none)

## Install

Extract `talenttrack-v2_21_0.zip`. Move contents into your `talenttrack/` folder. Deactivate + reactivate.

**Activation installs the new role.** `RolesService::installRoles()` picks it up automatically — `add_role` is a no-op if the role already exists, so the migration is idempotent.

**No schema migration.** No data changes.

## Verify

### Tile landing
1. Log out of WordPress. Navigate to the page with the `[talenttrack_dashboard]` shortcode.
2. Log in as a player-only user. You see: greeting header, "Me" section with 6 tiles, no Coaching/Analytics/Administration sections.
3. Tap "My evaluations" — you go into the existing Evaluaties tab with "← Back to dashboard" at the top. Click back — you return to the tile grid.
4. Log in as a coach — now you see "Me" (if they're also a player) + "Coaching" (6 tiles) + "Analytics" (2 tiles). No Administration.
5. Log in as an admin — all 4 sections visible.
6. Existing `?tt_view=overview` bookmarks continue to work and skip the tile landing.

### Read-only observer role
7. In WP Users admin, create a new user and assign the "Read-Only Observer" role.
8. Log in as that user. Go to the frontend dashboard.
9. See: greeting + "Analytics" section only (Rate cards + Player comparison). No Me / Coaching / Administration.
10. Tap "Rate cards" — can view the page but any edit/save buttons are absent (they're gated by `tt_evaluate_players` or `tt_manage_players`, which the observer lacks).
11. Try a URL like `?tt_view=evaluations&action=new` — the controller blocks the action because the observer has no `tt_evaluate_players` cap.

### Regression checks
12. Player-only user without the observer role sees their normal tile set, unchanged.
13. Coach sees all coaching functions normally, no permission regressions.
14. Admin sees all tiles + Go to admin.

## Known caveats

- **Observer role sees analytics pages with edit buttons hidden, but the underlying render methods still include some UI chrome.** The write-block is at the cap-check level — the ReportsPage's "Run report" button works fine for the observer; the rate card's print button works; what's blocked is the `tt_manage_players` / `tt_evaluate_players` save actions. If you discover a write action that leaks through for an observer, the fix is adding a `current_user_can( 'tt_evaluate_players' )` guard around the render — report it.
- **The tile grid is static for now.** No favoriting, no "recently used," no personalization. These are parked for future polish sprints.
- **FrontendBackButton does not use HTTP referer.** Deliberate — frontend referers are unreliable (see design notes). Fixed destination matches the tile-landing pattern and is simpler to reason about.
- **Greeting is plain user display name.** Could expand to team info, age group, etc. — future enhancement.

## Design notes

- **Why ship the tile frontend before the cap refactor.** The tile grid IS the new navigation; it should land first so there's a place where cap-granular visibility matters. Gating tiles today uses existing caps — fine. When cap refactor happens in 2.22.0, the tile grid gets more granular per-tile visibility without visual rework.
- **Why observer role is so narrow (`read` + `tt_view_reports` only).** Broader observer caps would contradict "read-only" — anything that grants view-access to a surface where write UI exists creates a footgun. Narrow = correct.
- **Why tile landing doesn't replace the tabs entirely.** Huge cost, zero functional gain. The tabs inside existing dashboard views work well once you're inside a section; the tile landing addresses "where should I go first?" which the tabs never answered.
- **Why a new FrontendBackButton vs reusing the admin one.** Admin BackButton uses `wp_get_referer()`, which on frontend is unreliable due to caching, CDN, and theme variance. A frontend-specific helper with a known-safe target (shortcode page sans query params) is more predictable. Same visual pattern, different underlying logic.

## v2.22.0 preview

- **Help wiki** (carryover from 2.19 + 2.20 backlog) — markdown-based, 18 topics, contextual links across admin pages, per-release update discipline
- **Capability refactor** (Option B from the sprint brief) — split `tt_manage_*` and `tt_evaluate_*` into `tt_view_*` + `tt_edit_*` pairs so the Read-Only Observer experience covers all sections, not just analytics
- Additional report types (carryover)
- Any other items accumulated
