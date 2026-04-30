<!-- type: feat -->

# 0033 finalisation — Tile + admin-menu registries (the Sprint 4 follow-up)

The original #0033 epic shipped across v3.24.0 → v3.26.0 (sprints 1-9), but one acceptance criterion stayed open:

> *Every tile rendered on admin + frontend comes from `TileRegistry::tilesForUser()`. No tile literals remain in `Menu.php` or `FrontendTileGrid.php`.*

The renderer-side migration was deferred. v3.35.0 (#0051) plugged the user-visible bug — disabled modules now disappear from every surface — via a centralised `ModuleSurfaceMap` lookup. v3.36.0 closes the architectural gap: tile + menu data move into proper registries, the renderers become thin iterators, and `ModuleSurfaceMap` retires.

## Architecture

Two registries replace the literals:

- **`TileRegistry`** (extended, was already shipped in #0033 Sprint 4 but unused). Each tile carries `module_class`, `view_slug`, `group`, `kind` (`work` | `setup`), `order`, `label`, `description`, `icon`, `color`, plus optional callables (`label_callback`, `color_callback`, `cap_callback`, `url_callback`) for the few dynamic cases. New `tilesForUserGrouped()` returns the structure `FrontendTileGrid` expects. New `registerSlugOwnership()` covers tile-less sub-views the dispatcher reaches directly.
- **`AdminMenuRegistry`** (new). Three surfaces in one registry: submenu pages, separator heading rows, and wp-admin dashboard quick-link tiles. Separators auto-hide when their group has no visible children. Stat cards stay literal in `Menu::dashboard()` — they couple to entity-specific COUNT + delta queries that don't fit a generic registry.

One declarative seed file, **`CoreSurfaceRegistration`**, calls both registries with every shipped surface, tagged with `module_class`. Called from `Kernel::boot()` after `bootAll()`.

## Acceptance criteria

- [x] `FrontendTileGrid::buildGroups()` deleted. `render()` reads from `TileRegistry::tilesForUserGrouped()`.
- [x] `Menu::register()` body is the top-level `add_menu_page` call + `AdminMenuRegistry::applyAll()` + the existing `injectMenuCss` action. No `add_submenu_page` literals.
- [x] `Menu::dashboard()` tile groups read from `AdminMenuRegistry::dashboardTilesForUser()`. Stat cards stay literal (out of scope per architecture decision).
- [x] `ModuleSurfaceMap` retired. Every prior call site uses the corresponding registry's lookup helper.
- [x] All v3.35.0 module-disabled gating behaviour preserved: tiles disappear, sidebar items unregister, direct URLs render the friendly "section unavailable" notice.
- [x] Always-on modules (Auth, Configuration, Authorization) always render — `ModuleRegistry::isEnabled` returns true unconditionally for them.
- [x] PHP syntax lint clean across changed files.

## Out of scope

- Persona-aware tile labels (`HIDDEN` marker, per-persona alt labels). Infrastructure was already in `TileRegistry` since #0033 Sprint 4; no shipped tile uses it yet, this release doesn't add the first one either.
- Dashboard stat cards. Their per-entity COUNT + delta queries make a generic registry awkward; they stay literal in `Menu::dashboard()` with the same module-enabled gate (now via `AdminMenuRegistry::isAdminSlugDisabled`).
- Per-module `boot()` registrations (the alternative architecture where each module declares its own tiles). The centralised `CoreSurfaceRegistration` is simpler, audit-friendly, and equally satisfies the spec wording. If a module ever needs to register additional tiles dynamically, it can still call `TileRegistry::register()` from its `boot()` — registries are append-only.

## Files

**New**
- `src/Shared/Admin/AdminMenuRegistry.php`
- `src/Shared/CoreSurfaceRegistration.php`

**Modified**
- `src/Shared/Tiles/TileRegistry.php`
- `src/Shared/Frontend/FrontendTileGrid.php` (~370 lines deleted)
- `src/Shared/Frontend/DashboardShortcode.php`
- `src/Shared/Admin/Menu.php` (~190 lines deleted)
- `src/Shared/Admin/MenuExtension.php`
- `src/Core/Kernel.php`

**Deleted**
- `src/Core/ModuleSurfaceMap.php`

## Estimate

~6-10h end-to-end. Empirical compression on this project runs ~1/2.5 against estimate; realistic actual closer to ~2-3h.
