<!-- type: feat -->

# 0051 — Module surface gating: hide disabled modules from frontend tiles, wp-admin menu, and view dispatch

## Problem

The `Modules` admin page (Authorization → Modules) lets an administrator toggle non-core modules off. Today the toggle correctly persists state in `tt_module_state` and stops the module's `register()` / `boot()` from running — its REST routes, hooks, and capabilities go dark.

But three user-facing surfaces never consult `ModuleRegistry::isEnabled()`:

1. **Frontend dashboard tiles** — `src/Shared/Frontend/FrontendTileGrid.php::buildGroups()` is a fully static array literal. Every tile is gated by capabilities only.
2. **wp-admin sidebar menu** — `src/Shared/Admin/Menu.php::register()` is a wall of hardcoded `add_submenu_page()` calls. None check module-enabled state.
3. **wp-admin dashboard tiles** — `src/Shared/Admin/Menu.php::dashboard()` has its own static tile groups, also cap-only.

Net effect: a user toggles "Evaluations" off, but the Evaluations tile still shows on the frontend dashboard, the Evaluations menu item still appears in wp-admin, and clicking either still routes to a working view (because `DashboardShortcode::dispatchCoachingView()` and the wp-admin page registration are also unconditional).

This is the long-standing follow-up flagged inside `specs/0033-epic-authorization-and-module-management.md` (Sprint 4: "*Migration of existing tile arrays … No tile literals remain in the rendering code*"). It was carried out as #0033 Sprint 5 for `ConfigTabRegistry` (Configuration tabs hide cleanly), but never extended to tiles or menus.

## Decision (Casper, 2026-04-27)

Implement Option B from the diagnosis turn: gate all three surfaces by a single source of truth, rather than rewriting every module's `boot()` to push into `TileRegistry::register()`. The full registry migration stays as a future cleanup; that lift isn't required to fix the bug.

Accepted trade-off: tile/menu literals stay where they are. They become **declaratively owned** by a module via one central `ModuleSurfaceMap`. When the module is disabled, all three rendering surfaces consult the map and skip / refuse the matching surface.

## Surfaces

### Frontend dashboard

- `FrontendTileGrid::renderGroups()` filters out tiles whose owning module is disabled.
- `DashboardShortcode::render()` short-circuits each `tt_view=<slug>` dispatcher: if the slug's owning module is disabled, render a friendly notice ("This section is currently unavailable.") with a back button. No 404, no fatal.

### wp-admin sidebar

- `Menu::register()` skips every `add_submenu_page()` call whose page-slug owner is disabled. The page is not registered, so its URL also stops resolving — same behaviour as if the module had never loaded.
- The "Modules" toggle UI itself is owned by Authorization (always-on) so the user can always reach it to turn things back on.

### wp-admin dashboard tiles

- `Menu::dashboard()` filters its grouped tiles by module-enabled state, in addition to the existing capability check. Stat cards (Players / Teams / Evaluations / …) follow the same rule.

## ModuleSurfaceMap contract

`src/Core/ModuleSurfaceMap.php` is one declarative data file. Two pure-static lookups:

```php
ModuleSurfaceMap::moduleForViewSlug( string $tt_view_slug ): ?string;
ModuleSurfaceMap::moduleForAdminSlug( string $admin_page_slug ): ?string;
```

Both return a fully-qualified module class name, or `null` for slugs that aren't gated by any single module (the personal / always-on / cross-cutting surfaces).

A surface returning `null` is **never** filtered — that's the explicit "I belong to no single module" signal, not an oversight. Examples:

- `tt_view=overview` (player landing) → `null` (no module owns "your own profile")
- `tt_view=docs` → `DocumentationModule`
- `tt_view=audit-log` → `null` (audit is infrastructure, not a module)
- `?page=tt-config` → `ConfigurationModule` (always-on, so the filter never bites)

## Always-on modules and surfaces

Always-on modules already cannot be toggled off via the admin UI (`ModuleRegistry::isAlwaysOn`). The map can still tag their surfaces with the always-on module class — `isEnabled` returns true unconditionally, so the filter is a no-op. This keeps the ownership data consistent without exception cases at the call site.

## Friendly notice for disabled-module slugs

When a user navigates directly to a disabled module's `tt_view=` URL (bookmark, stale link), they see the back-button + notice pattern that `DashboardShortcode` already uses for the "unknown section" / "no permission" cases:

> *Title:* This section is currently unavailable.
> *Body:* The administrator has temporarily turned off this part of TalentTrack. Please check back later, or ask your administrator if you need access.

Pure server-side render, no JS, translatable.

## Acceptance criteria

- [ ] Toggling any non-core module off makes its frontend tile disappear from the dashboard.
- [ ] Toggling any non-core module off removes its wp-admin sidebar entries.
- [ ] Toggling any non-core module off removes its wp-admin dashboard tile + stat card.
- [ ] Direct URL to a disabled module's `tt_view=` slug shows the friendly notice, not the view.
- [ ] Always-on modules (Auth, Configuration, Authorization) cannot be disabled — their surfaces always render.
- [ ] No PHPStan regressions; PHP syntax lint clean across changed files.
- [ ] Translations updated in `languages/talenttrack-nl_NL.po` for the new notice strings.
- [ ] `docs/configuration-modules.md` + `docs/nl_NL/configuration-modules.md` mention the gating behaviour.
- [ ] SEQUENCE.md updated with the release row.

## Out of scope

- Full TileRegistry / AdminMenuRegistry migration of every literal into per-module `boot()` calls. Tracked as follow-up. The map approach buys us correctness now without paying the migration tax.
- Module dependency graph (e.g. "disabling Players also disables Teams") — `ModulesPage` already warns that dependencies aren't enforced; that's a separate task.
- Hiding individual REST routes — already handled because `bootAll()` skips the module entirely.

## Files

**New**

- `src/Core/ModuleSurfaceMap.php` — single declarative map.

**Modified**

- `src/Shared/Frontend/FrontendTileGrid.php` — filter step in `renderGroups()`.
- `src/Shared/Frontend/DashboardShortcode.php` — gate every dispatcher; new `renderModuleDisabledNotice()`.
- `src/Shared/Admin/Menu.php` — gate every `add_submenu_page` call + the `dashboard()` tile filter.
- `languages/talenttrack-nl_NL.po` — notice translations.
- `docs/configuration-modules.md` + `docs/nl_NL/configuration-modules.md` — behaviour documented.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — release.

## Estimate

~4-6h end-to-end with translations and docs. Empirically the project compresses ~1/2.5 vs estimate; realistic actual likely ~2-3h.
