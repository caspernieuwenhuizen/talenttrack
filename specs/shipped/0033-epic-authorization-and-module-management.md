<!-- type: epic -->

# #0033 — Authorization matrix + module toggles + per-module configuration + dashboard tile split

## Problem

Four pain points cluster in the same surface area and resolve in the same architectural pass:

1. **Authorization is hand-rolled and scattered.** Every dispatcher has its own `current_user_can( 'tt_edit_evaluations' )`-style call. There's no single answer to "what can a Head of Development do, on what, where?" Multi-persona users (parent-and-coach, HoD-and-staff, coach-and-academy-admin) get an approximation: WordPress assigns one role at a time, the right capability set is inferred. Adding a new role (#0032's `tt_parent`, #0017's scout refinement, the missing `tt_team_manager`) requires touching dozens of files.
2. **Tiles and menus are hardcoded.** [Menu.php](../src/Shared/Admin/Menu.php) and [FrontendTileGrid.php](../src/Shared/Frontend/FrontendTileGrid.php) declare tile arrays per role-class, with `cap` checks per row. Re-labeling a tile per persona ("Players" → "My child" for parents) means editing both files; rendering a different group order per persona is impractical without a registry.
3. **Modules can't be turned off.** [config/modules.php](../config/modules.php) is a static `=> true` per module. Disabling a module for a fresh install / dev session / demo means editing source. Critical when monetization (#0011) ships and an admin demoing the product needs to hide the License surface.
4. **The Configuration page has 14 hardcoded tabs.** Every new module that needs config either edits [ConfigurationPage.php](../src/Modules/Configuration/Admin/ConfigurationPage.php) (Branding, Toggles, Backups, Wizard, Translations, Audit, …) or piggybacks on the `tt_config_tabs` filter added in #0025. Result: the page is owned by no one. Per-module ownership scales; the current chrome doesn't.

A fifth observation about the dashboard layout makes the same pass natural to bundle:

5. **Daily-work tiles compete with admin clutter.** The frontend tile groups (Me / People / Performance / Analytics / Administration) sit one above the other in a continuous scroll. Admins see admin tiles right next to coaching tiles. The visual weight of "Migrations" and "Custom fields" tiles drowns the "Players" tile that the user actually clicks every day. Splitting daily-work tiles from setup/configuration tiles is a small change with disproportionate UX value.

## Proposal

A single coherent persona-matrix model plus a runtime module-management layer, delivered across nine sprints. Sprint 1 ships the schema and read-only matrix gate; Sprint 9 ships docs, i18n, and cross-persona testing. Each sprint is independently mergeable.

The architecture has four cooperating components:

- **`MatrixGate`** — single read API for "can persona P perform activity A on entity E in scope S?". Replaces ad-hoc `current_user_can` calls.
- **`PersonaResolver`** — given a `user_id`, returns the set of personas that user holds (multi-persona-aware).
- **`TileRegistry`** — single source of truth for what tile/menu items exist, who renders them, and how they're labeled per persona. Replaces the hardcoded arrays in `Menu.php` and `FrontendTileGrid.php`.
- **`ModuleRegistry::isEnabled()`** — runtime module toggles backed by a new `tt_module_state` table. `MatrixGate` short-circuits to `false` when the entity's owning module is disabled, so "module off" and "no persona has access" share one code path.

Decisions locked during shaping (2026-04-26):

1. **Matrix storage.** New `tt_authorization_matrix` table (one row per persona × activity × entity × scope-kind), seeded from a shipped PHP file (`config/authorization_seed.php`), with a "reset to defaults" button in the admin UI.
2. **Scope model.** `tt_user_role_scopes` (existing — global / team / player) stays as-is for *runtime* scope ("which teams does this coach actually coach?"). The matrix row carries the scope *capability* ("a coach can act at team level"). Two questions, two stores.
3. **Multi-persona resolution.** Union (most permissive wins per entity/activity/scope) by default. A small **persona switcher** appears in the user menu only for users with 2+ personas; switching is a temporary lens (resets on browser close — stored in `sessionStorage`, not `user_meta`). Default view = union.
4. **Tile/menu rendering.** Centralised into `TileRegistry`. Tiles declare an `entity` and a `kind` (`work` | `setup`); the registry asks `MatrixGate` once per tile per request whether to render it, and looks up the persona-aware label. Replaces per-dispatcher cap checks.
5. **WordPress role compatibility.** WP roles (`tt_player`, `tt_coach`, `tt_head_dev`, `tt_readonly_observer`, `tt_staff`, `tt_scout`, `tt_parent`, new `tt_team_manager`) continue to exist; the matrix layer sits **on top**, computing capabilities at runtime via the `user_has_cap` filter. Existing third-party / module code that calls `current_user_can( 'tt_edit_evaluations' )` keeps working without modification.
6. **Functional Role + persona interaction.** A person assigned as Head Coach via Functional Role gets the Head Coach persona's profile, scoped to that team — assignment auto-elevates within scope. Removing the assignment removes the persona within that scope. The `tt_team_manager` role works the same way (Team Manager Functional Role assignment grants the persona).
7. **Matrix admin UI.** Under a new top-level menu group "Authorization" (sibling to Configuration, since per-module config means Configuration is no longer the single home for everything). Admin-only — gated by `administrator` (WP), not `tt_edit_settings` (which isn't strict enough for "redefine what every role can do"). All matrix changes write to the audit log (#0021 when it ships; until then, simple option-table append).
8. **Migration.** A migration walks every existing user's WP role + `tt_user_role_scopes` rows and derives their persona set. The new matrix takes effect on next request. Sprint 8 ships the **migration preview report** ("what changes for each existing user, gained vs revoked") so admins verify before applying.
9. **Module enable/disable.** New `tt_module_state` table. Default state for every module = `enabled` (matches today's `=> true`). Admin → Authorization → Modules tab toggles per-module. Disabled modules don't run `register()` or `boot()`. The License module is **toggleable in the admin UI** (no `TT_DEV_MODE` constant gate) for now — pre-launch, easy iteration matters more than billing safety. The toggle row carries an inline warning: "Disabling License removes all monetization gates. Before going live, replace this toggle with a hard-coded enable, or implement a `TT_DEV_MODE` guard." Once monetization is live this becomes a real gate (deferred to post-launch).
10. **Per-module configuration ownership.** Each module owns its config tab. The existing `tt_config_tabs` filter + `tt_config_tab_<key>` action (added in #0025) generalize: Sprint 6 migrates all 14 hardcoded ConfigurationPage tabs to module-owned registrations. The Configuration page becomes pure chrome that lists registered tabs grouped by owning module.
11. **Dashboard tile split.** `TileRegistry` registers each tile with a `kind`: `work` (daily-use surfaces — players, evaluations, sessions, goals, podium, methodology, my-* tiles) or `setup` (admin/configuration — modules, configuration, roles, custom fields, audit, migrations). Frontend dashboard renders two top-level sections: **"Today's work"** (work tiles) and **"Setup & administration"** (setup tiles, collapsed by default for non-admin personas). Admin dashboard ([Menu.php](../src/Shared/Admin/Menu.php)) gets the same split.
12. **Module-off architectural link.** `MatrixGate::can()` returns `false` short-circuit when the entity's owning module is disabled. One auth check, no parallel "is the module on?" branch in callers. Each entity in the seed matrix carries a `module_class` field for this lookup.
13. **Print/PDF authority.** Same as `read` activity for v1. v2 candidates list adds a separate `print` activity for clubs with stricter export controls.
14. **Multi-tenant scope.** Per-install for v1 (matches today's single-club tenancy posture). Per-tenant matrix is a v2 extension that slots into `tt_authorization_matrix` via an optional `tenant_id` column added when #0011 brings multi-site licensing.
15. **Per-team customization of personas.** Out of scope for v1. Matrix is club-wide; per-team flavour ("our HoD restricts what Head Coach means for our team") deferred.
16. **Active personas.** Switcher visible in the user menu only for users with 2+ personas. Default = union view. Switching is a temporary lens reset on browser close. UI shows a small banner when a non-default persona is active: "You are viewing as Parent. [Switch back to all personas]".

## Scope

Nine sprints. Each independently mergeable; sprint specs (one per sprint) authored at implementation time per project convention.

### Sprint 1 — Schema + matrix repository + read API

**Schema.**

```sql
CREATE TABLE tt_authorization_matrix (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    persona VARCHAR(40) NOT NULL,           -- 'parent' | 'player' | 'assistant_coach' | ...
    entity VARCHAR(64) NOT NULL,            -- 'player' | 'evaluation' | 'session' | ...
    activity ENUM('read', 'change', 'create_delete') NOT NULL,
    scope_kind ENUM('global', 'team', 'player', 'self') NOT NULL,
    module_class VARCHAR(128) NOT NULL,     -- enables Sprint 5 module-off short-circuit
    is_default BOOLEAN NOT NULL DEFAULT 1,  -- 0 once admin edits this row
    PRIMARY KEY (id),
    UNIQUE KEY uk_lookup (persona, entity, activity, scope_kind),
    KEY idx_persona (persona),
    KEY idx_module (module_class)
);
```

**Seed file.** `config/authorization_seed.php` — returns the full default matrix as a PHP array. Sprint 1 also ships the seed *content* (see Notes → Seed matrix below). Migration `0022_authorization_matrix.php` creates the table and seeds it.

**Repository.**

```php
namespace TT\Modules\Authorization\Matrix;

class MatrixRepository {
    public function lookup( string $persona, string $entity, string $activity, string $scope_kind ): bool;
    public function lookupAny( array $personas, string $entity, string $activity, string $scope_kind ): bool;
    public function entitiesFor( string $persona, string $activity, string $scope_kind ): array;
    public function reseed(): void;  // truncate + reload from config/authorization_seed.php
}
```

**`MatrixGate` — the single read API.**

```php
namespace TT\Modules\Authorization;

class MatrixGate {
    public static function can(
        int $user_id,
        string $entity,
        string $activity,         // 'read' | 'change' | 'create_delete'
        string $scope_kind = 'global',
        ?int $scope_target_id = null   // team_id when scope='team', etc.
    ): bool;
}
```

Algorithm:
1. Resolve user → personas via `PersonaResolver::personasFor( $user_id )`.
2. For each persona, look up matrix row for `(persona, entity, activity, scope_kind)`. If any row exists → continue; otherwise next persona.
3. If `scope_kind !== 'global'`, verify the user actually has that scope assignment via `tt_user_role_scopes` (e.g., for `scope_kind='team'`, check the user is assigned to `$scope_target_id`).
4. Sprint 5 hook: lookup `module_class` from the matrix row, return `false` if `ModuleRegistry::isEnabled( $module_class )` is `false`.
5. Return `true` on first persona that satisfies all checks; `false` otherwise.

**`PersonaResolver`.**

```php
namespace TT\Modules\Authorization;

class PersonaResolver {
    /** @return string[] e.g. ['head_coach', 'parent'] */
    public static function personasFor( int $user_id ): array;

    /** @return string[] persona keys the user could pick from in the switcher */
    public static function availablePersonas( int $user_id ): array;

    /** Active persona from sessionStorage cookie; null = union view */
    public static function activePersona( int $user_id ): ?string;
}
```

Tests: `MatrixRepository` round-trip; `MatrixGate::can` for every (persona, entity, activity, scope) combination in the seed against synthetic users.

**Sizing: ~10h.** Schema 1h, seed file authoring 4h, repository 1h, gate 2h, persona resolver 1h, tests 1h.

### Sprint 2 — Migration + WordPress capability compatibility

**Migration of existing users.** Walk every user with a `tt_*` WP role and:

1. Map WP role → primary persona (e.g., `tt_coach` + Head Coach Functional Role assignment → `head_coach`; same WP role + Assistant Coach FR → `assistant_coach`).
2. Verify `tt_user_role_scopes` rows exist and match. If a user has `tt_coach` but no scope rows, log + insert default-scope rows derived from team assignments.
3. No matrix-table writes — the matrix is club-wide static; user state lives in `tt_user_role_scopes` and is unchanged.

**`user_has_cap` filter.** A new filter callback routes legacy capability checks through `MatrixGate`:

```php
add_filter( 'user_has_cap', function ( array $allcaps, array $caps, array $args, WP_User $user ) {
    foreach ( $caps as $cap ) {
        if ( strpos( $cap, 'tt_' ) !== 0 ) continue;
        $allcaps[ $cap ] = LegacyCapMapper::mapToMatrix( $cap, $user, $args );
    }
    return $allcaps;
}, 10, 4 );
```

`LegacyCapMapper` translates `tt_view_evaluations` → `MatrixGate::can( $user_id, 'evaluation', 'read' )`, etc. Lookup table covers the ~40 existing `tt_*` capabilities.

**Backwards compat verified.** Every existing dispatcher's `current_user_can` calls keep working without modification. Sprint 2 ships only the legacy bridge; the dispatcher refactor (calling `MatrixGate::can()` directly) is later, opportunistic, per-module.

Tests: every existing dispatcher's auth path still returns the same result for a synthetic user with the same WP role.

**Sizing: ~8h.** Migration walker 3h, `user_has_cap` filter 2h, `LegacyCapMapper` table 2h, regression tests 1h.

### Sprint 3 — Admin matrix UI

New top-level menu group **"Authorization"** with three submenu pages (the existing Roles / Functional Roles / Permission Debug pages move under it):

- **Authorization → Matrix** — the persona × entity grid editor (new this sprint).
- **Authorization → Roles** — moved from Configuration group (existing page).
- **Authorization → Functional Roles** — moved from Configuration group (existing page).
- **Authorization → Permission Check** — moved (renamed from "Permission Debug"; existing page).
- (Sprint 5 adds **Authorization → Modules**.)

**Matrix editor page.**

UI: a grid with personas as rows and entities as columns; each cell holds three pill toggles (`read` / `change` / `create_delete`) plus a scope dropdown (`global` / `team` / `player` / `self`). Default seed values are displayed dimmed; admin-edited values are bold. A "Reset to defaults" button re-runs `MatrixRepository::reseed()`.

Capability gate: `administrator` (WP role). Not `tt_edit_settings` — redefining what every role can do is too sharp for a delegated capability.

Audit: every save writes a row to `tt_audit_log` (#0021 schema; until that ships, write to a simple `tt_authorization_changelog` table in this sprint).

**Sizing: ~14h.** Page scaffold 2h, grid renderer 4h, save handler + validation 3h, reset button + confirmation 1h, audit wiring 2h, mobile-responsive checks 2h.

### Sprint 4 — TileRegistry + persona-aware labels + dashboard work/setup split

**`TileRegistry`.**

```php
namespace TT\Shared\Tiles;

class TileRegistry {
    /**
     * @param array{
     *   slug: string,           // 'players', 'evaluations', etc.
     *   entity: string,         // matrix entity for cap check
     *   kind: 'work'|'setup',
     *   labels: array<string, string>,  // ['*' => 'Players', 'parent' => 'My child', 'player' => '__hidden__']
     *   icon: string,
     *   color: string,
     *   url: string|callable,
     *   description: string,
     *   group: string,          // 'roster' | 'performance' | 'methodology' | ...
     *   order: int
     * } $tile
     */
    public static function register( array $tile ): void;

    /** Returns visible+labeled tiles for a user, split by kind. */
    public static function tilesForUser( int $user_id ): array;
        // ['work' => [...], 'setup' => [...]]
}
```

**Per-persona labels.** Each tile's `labels` map keys by persona; `'*'` is the fallback. Special value `'__hidden__'` removes the tile for that persona. Example for `players`:

```php
'labels' => [
    '*'             => __( 'Players', 'talenttrack' ),
    'player'        => '__hidden__',
    'parent'        => __( 'My child(ren)', 'talenttrack' ),
    'head_coach'    => __( "My team's players", 'talenttrack' ),
    'assistant_coach' => __( "My team's players", 'talenttrack' ),
],
```

`TileRegistry::tilesForUser()` resolves the active persona (or union default) and picks the matching label.

**Frontend dashboard split.** `FrontendTileGrid` becomes a renderer:

```
[Today's work]
  [Roster group]    Players  Teams  People
  [Performance]     Evaluations  Sessions  Goals  Podium
  [Methodology]     Methodology
  [Analytics]       Reports  Rate cards  Comparison

[Setup & administration]   [collapsed by default for non-admin personas]
  [Authorization]   Roles  Functional roles  Matrix  Permission check
  [Modules]         Module toggles
  [Configuration]   Branding  Lookups  Wizard  Audit  Translations
```

Two top-level `<details>` sections. `Today's work` is open by default. `Setup & administration` is open by default for `administrator`-personas only (collapsed for everyone else). State persisted in `localStorage` per user.

**Admin dashboard ([Menu.php](../src/Shared/Admin/Menu.php))** gets the same split — work tiles in the top group, setup tiles below in a collapsible section.

**Migration of existing tile arrays.** Every `[ 'label' => ..., 'icon' => ..., 'url' => ..., 'cap' => ... ]` literal in Menu.php and FrontendTileGrid.php is migrated to a `TileRegistry::register()` call inside the owning module's `boot()` method. No tile literals remain in the rendering code.

**Sizing: ~14h.** TileRegistry 3h, label resolution 1h, work/setup split rendering 3h, migration of existing tiles to module-owned registrations 5h, persona-switcher chip on the user menu 2h.

### Sprint 5 — Module registry runtime-toggleable + admin Modules tab

**Schema.**

```sql
CREATE TABLE tt_module_state (
    module_class VARCHAR(128) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (module_class)
);
```

Migration `0023_module_state.php` creates the table and seeds it from the current [config/modules.php](../config/modules.php) defaults (every existing module → `enabled = 1`).

**`ModuleRegistry::isEnabled()`.**

```php
namespace TT\Core;

class ModuleRegistry {
    public static function isEnabled( string $module_class ): bool;
    public static function setEnabled( string $module_class, bool $enabled ): void;
    public static function allWithState(): array;  // [ ['class'=>..., 'name'=>..., 'enabled'=>bool], ... ]
}
```

Reads from `tt_module_state` once per request, cached in a static array.

**Bootstrap respects the toggle.** `ModuleRegistry::load()` skips classes whose `tt_module_state.enabled = 0` — they don't `register()` or `boot()`. Their config tabs, tiles, admin pages, REST routes, and capability declarations all silently disappear.

**`MatrixGate::can()` short-circuit.** First check after persona resolution: if the matrix row's `module_class` is disabled, return `false`. Single source of truth for "is this entity reachable at all?"

**Admin → Authorization → Modules tab.** Lists every registered module with: name, description (from a new `getDescription()` method on `ModuleInterface`), enabled toggle, `module_class`. Always-on modules (`AuthModule`, `ConfigurationModule`, `AuthorizationModule` itself) are listed but the toggle is disabled with the tooltip "Core module — cannot be disabled."

**Special-cased: License module row.** Toggleable like any other, **with an inline warning banner** in red:

> ⚠️ **Don't forget to implement the gate before going live.** Disabling License removes all monetization checks (`LicenseGate::*`). Pre-launch this is fine for demos and dev. Before public launch, either hardcode `LicenseModule` enabled or implement a `TT_DEV_MODE` constant that disables this toggle in production.

The warning is a static admin notice rendered above the Modules tab whenever License is the most recently toggled module OR the toggle is currently `false`. Persistent until launch-readiness work.

**Sizing: ~10h.** Schema + migration 1h, `ModuleRegistry::isEnabled()` 1h, bootstrap respect 2h, MatrixGate short-circuit 1h, Modules tab UI 3h, License warning 1h, tests (verify a disabled module truly leaves no trace) 1h.

### Sprint 6 — `ConfigTabRegistry` + per-module config split

**`ConfigTabRegistry`.**

```php
namespace TT\Modules\Configuration\Admin;

class ConfigTabRegistry {
    /**
     * @param array{
     *   slug: string,
     *   label: string,             // i18n-ready
     *   render: callable,
     *   capability: string,
     *   module_class: string,      // for grouping; enables Sprint 5 short-circuit
     *   group_label: string,       // 'Lookups' | 'System' | 'Modules' | ...
     *   order: int
     * } $tab
     */
    public static function register( array $tab ): void;
    public static function tabsFor( int $user_id ): array;  // grouped by group_label
}
```

**Migration of the 14 hardcoded tabs.** Each tab moves to the owning module's `boot()`:

| Current tab | Owning module | Group |
| --- | --- | --- |
| `eval_types`, `positions`, `foot_options`, `age_groups`, `goal_statuses`, `goal_priorities`, `att_statuses`, `rating` | (shared `LookupsModule` — new, extracted from ConfigurationModule) | Lookups |
| `branding` | `ConfigurationModule` | Branding |
| `toggles` | `ConfigurationModule` (FeatureToggleService) | Branding |
| `backups` | `BackupModule` (already separate; now moves to its own tab registration) | System |
| `wizard` | `OnboardingModule` | System |
| `translations` | `TranslationsModule` (already does this via the `tt_config_tabs` filter — formalizes the pattern) | System |
| `audit` | (to-be-built `AuditModule`; until then keep on `ConfigurationModule`) | System |

**`ConfigurationPage` becomes pure chrome.** The `switch ( $tab )` block is replaced with `call_user_func( ConfigTabRegistry::renderCallback( $tab ) )`. Tab navigation lists tabs grouped by `group_label`; each group renders as a `<details>` block in the sidebar.

**Capability per tab.** Each tab declares its required capability via `MatrixGate::can()` on a dedicated `module_config` entity per module (e.g., `branding_config`, `backup_config`). The matrix seeds these as `administrator`-only by default. Non-admins who somehow reach `?page=tt-config&tab=backups` get a 403.

**Sizing: ~12h.** Registry 2h, migration of 14 tabs 6h (mostly mechanical, but the lookup tabs share machinery that needs untangling), chrome refactor 2h, capability enforcement 1h, tests 1h.

### Sprint 7 — New / refined roles

**`tt_team_manager` WP role.** Created via `add_role()` in a new migration step. Capability set:

- Read: team, players (their team), people (their team), sessions, evaluations (read), goals (read), attendance.
- Change: sessions (schedule + reschedule), attendance (record + edit), invitations (per #0032 — invite parents and players to their team).
- Create/delete: sessions only.
- No edit on evaluations or goals (that's coaching).

**Refined `tt_scout`.** Read across all teams + players (cross-team). Write on `trial_case` entities only when assigned (#0017). Until #0017 ships, the matrix declares the entity but no implementation surface exists; no-op gracefully.

**Head/assistant Functional Role flag.** New column on `tt_functional_role_assignments`: `is_head_coach BOOLEAN`. Drives `PersonaResolver`'s output: same `tt_coach` WP role + `is_head_coach=1` → `head_coach` persona; same role + `is_head_coach=0` → `assistant_coach` persona. Migration sets `is_head_coach=1` on all existing coach assignments per team's first-coach-assigned heuristic; admins review and adjust as needed.

Matrix profiles for the three new personas (team_manager, refined_scout, assistant_coach) added to the seed file.

**Sizing: ~6h.** Role creation 1h, capability declarations 1h, FR flag column + migration 2h, matrix seed updates 1h, tests 1h.

### Sprint 8 — Migration preview report

Before applying the matrix to live users, an admin can run a **preview report** that compares each user's old (cap-based) and new (matrix-based) effective permissions. Output is a downloadable CSV plus an in-page table:

| user | persona(s) | gained capabilities | revoked capabilities | scope-only changes |
| --- | --- | --- | --- | --- |

"Gained" = matrix grants something the old caps didn't. "Revoked" = the opposite (the dangerous category). Admin sees the diff before clicking "Apply." Until "Apply" is clicked, the `user_has_cap` filter from Sprint 2 routes through `LegacyCapMapper` (old behavior); after, through `MatrixGate` (new behavior).

The Apply step writes a flag (`tt_authorization_active = 1`) to `tt_config`; the filter callback consults this flag and routes accordingly. Provides a one-click rollback (set flag to 0) if a regression surfaces in the wild.

**Sizing: ~6h.** Diff computer 3h, report UI 2h, apply/rollback toggle 1h.

### Sprint 9 — Cross-persona testing + docs + i18n

**Manual test matrix.** Walk each of the 8 personas through every entity surface they should access; confirm tile labels match per persona; confirm gated tiles are hidden; confirm the persona switcher works; confirm a multi-persona user gets the union view by default and the lensed view after switching.

**Documentation updates.**

- Full rewrite of `docs/access-control.md` + `docs/nl_NL/access-control.md` — explain the matrix model, where to edit it, what each persona means.
- New `docs/authorization-matrix.md` + nl_NL — admin guide for the matrix editor, with screenshots.
- New `docs/modules.md` + nl_NL — module toggle UX, the License warning, what disabling each module actually does.
- Update `docs/configuration.md` + nl_NL — note the per-module tab ownership; how to find a tab if it has moved.
- Per-persona quickstart pages (`docs/persona-{slug}.md` + nl_NL) — what each role sees and can do. Eight pages × 2 languages = 16 small files.

**i18n.** All new admin strings (matrix editor, module toggles, persona switcher, warning banners, tab labels) translated to nl_NL in `languages/talenttrack-nl_NL.po`.

**Sizing: ~10h.** Manual testing 3h, doc rewrites 5h, persona quickstarts 1h, .po updates 1h.

### Total sizing

**~90 hours.** Original idea sized at ~46-70h; +20h covers the module-toggle infrastructure, per-module config split, and dashboard tile split added during shaping.

| Sprint | Hours | Cumulative |
| --- | --- | --- |
| 1. Schema + matrix gate read API | 10 | 10 |
| 2. Migration + WP cap compat | 8 | 18 |
| 3. Admin matrix UI | 14 | 32 |
| 4. TileRegistry + persona labels + work/setup split | 14 | 46 |
| 5. Module registry runtime-toggleable + Modules tab | 10 | 56 |
| 6. ConfigTabRegistry + per-module config | 12 | 68 |
| 7. New/refined roles | 6 | 74 |
| 8. Migration preview report | 6 | 80 |
| 9. Cross-persona testing + docs + i18n | 10 | 90 |

## Out of scope (v1)

- **Per-team customization of personas** — each team can override the club-wide matrix.
- **Time-bounded permissions** — "this user has Head Coach access until end of season."
- **Approval workflows** — HoD approves coach's evaluation before publishing. Use case lives in #0022 (workflow engine), not here.
- **Bulk activity** — separate auth verb for bulk archive / bulk delete; treat as `create_delete` for v1.
- **Print/PDF as a separate activity** — currently identical to `read`. v2 candidate.
- **Comment/discuss activity** — for #0028 conversational goals; v2.
- **Approve/sign-off activity** — for #0017 trial decisions, #0022 review chain; v2.
- **`cohort` / `season` / `age-group` scope kinds** beyond global / team / player / self.
- **Guardian-of-multiple-children roll-up views** — one parent → many children; needs UX work outside the matrix.
- **External-auditor temporary access** — board reviewer gets read-only for 7 days. Rides on time-bounded permissions when that v2 lands.
- **Federated identity** (Google / Apple SSO).
- **Multi-tenant matrix** — per-site customization in a multi-tenant license deployment. Slot for `tenant_id` column reserved; logic deferred until #0011 brings multi-site.
- **Production-safe License-module toggle** — `TT_DEV_MODE` constant gating, removal of the inline warning. Pre-launch easy mode is fine; post-launch becomes a hard requirement.
- **Per-module dependency graph** — disabling a module that another module depends on is currently silent (the dependent module breaks). v2: `ModuleInterface::dependencies()` + Modules tab shows "depends on" + warns / blocks the toggle.

## Acceptance criteria

Per-sprint, the items below; release of the epic requires all to pass.

**Sprint 1.**
- [ ] `tt_authorization_matrix` table created via migration; seeded from `config/authorization_seed.php`.
- [ ] `MatrixGate::can()` returns correct results for every (persona, entity, activity, scope_kind) tuple in the seed against synthetic test users.
- [ ] `PersonaResolver::personasFor()` correctly returns multi-persona arrays for users with overlapping role assignments.
- [ ] Unit tests cover repository round-trip and gate algorithm.

**Sprint 2.**
- [ ] `user_has_cap` filter routes every `tt_*` capability through `LegacyCapMapper` → `MatrixGate`.
- [ ] Every existing dispatcher's auth path returns the same boolean as pre-Sprint-2 for the same synthetic user. (Regression suite passes.)
- [ ] Migration walker logs every user it processed (count + per-user one-line summary).

**Sprint 3.**
- [ ] Authorization → Matrix admin page loads under `administrator`-only gate.
- [ ] Matrix editor saves changes; default-vs-edited values visually distinguished.
- [ ] "Reset to defaults" requires confirmation; reseeds the table.
- [ ] Every save writes an audit log entry.

**Sprint 4.**
- [ ] Every tile rendered on admin + frontend comes from `TileRegistry::tilesForUser()`. No tile literals remain in `Menu.php` or `FrontendTileGrid.php`.
- [ ] Per-persona label resolution: parent-only user sees "My child(ren)" instead of "Players" on the players tile.
- [ ] Dashboard renders two top-level sections: "Today's work" (open) and "Setup & administration" (collapsed for non-admin personas).
- [ ] Persona switcher appears in user menu only for users with 2+ personas.
- [ ] Active persona persists across navigation within a browser session; resets on browser close.

**Sprint 5.**
- [ ] `tt_module_state` table created + seeded with all current modules → `enabled=1`.
- [ ] Modules tab toggles; disabled modules don't `register()` or `boot()` (verified by inspecting `ModuleRegistry::all()` output).
- [ ] `MatrixGate::can()` returns `false` for any entity whose owning module is disabled.
- [ ] License module row carries the inline warning when disabled or recently toggled.
- [ ] Core modules (Auth, Configuration, Authorization) cannot be disabled (toggle disabled with tooltip).

**Sprint 6.**
- [ ] All 14 hardcoded ConfigurationPage tabs registered via `ConfigTabRegistry` from their owning module's `boot()`.
- [ ] ConfigurationPage chrome lists tabs grouped by `group_label`.
- [ ] Disabling a module via Sprint 5's toggle removes its config tabs from the navigation.
- [ ] Each tab's render is gated by `MatrixGate::can( $user_id, '{slug}_config', 'read' )`; non-admin users get 403 on direct URLs.

**Sprint 7.**
- [ ] `tt_team_manager` WP role exists with the declared capability set.
- [ ] `tt_functional_role_assignments.is_head_coach` column added; migration sets initial values.
- [ ] `PersonaResolver` correctly distinguishes `head_coach` vs `assistant_coach` based on the FR flag.
- [ ] Refined `tt_scout` has matrix entries for read-across-all-teams; trial_case entries declared but no surface required (deferred to #0017).

**Sprint 8.**
- [ ] Migration preview report renders for any admin user; CSV downloadable.
- [ ] "Apply" sets `tt_authorization_active = 1`; from then, `user_has_cap` routes through `MatrixGate`.
- [ ] "Rollback" sets the flag to 0; routes back through `LegacyCapMapper`.

**Sprint 9.**
- [ ] All 8 personas walked through their expected surfaces (manual test checklist completed and recorded).
- [ ] `docs/access-control.md` + nl_NL rewritten.
- [ ] `docs/authorization-matrix.md`, `docs/modules.md` (+ nl_NL counterparts) created.
- [ ] 8 persona-quickstart pages × 2 languages = 16 doc files created.
- [ ] Every new admin string translated in `languages/talenttrack-nl_NL.po`.

## Notes

### Seed matrix (the actual default permission profile per persona)

Authored as a strawman — admin reviews via the matrix editor (Sprint 3) and tweaks before applying via the preview report (Sprint 8). Format below is a compressed reading aid; the real seed lives in `config/authorization_seed.php` as a structured PHP array.

Notation: `entity:R/C/D` means `read` / `change` / `create_delete`; `[scope]` qualifies; `*` = global.

**`player`** (a player viewing their own data)
- `my_card:R[self]`, `my_evaluations:R[self]`, `my_sessions:R[self]`, `my_goals:R[self]`, `my_team:R[self]`, `my_profile:R/C[self]`
- `methodology:R[*]` (read-only, public to all members)
- `documentation:R[*]`
- Everything else → no access.

**`parent`** (a parent of one or more players, via #0032)
- All `my_*` entities scoped to `[player]` (their child) instead of `[self]`.
- `players:R[player]`, `evaluations:R[player]`, `goals:R[player]`, `sessions:R[player]`, `attendance:R[player]`
- `team:R[player]` (their child's team)
- `methodology:R[*]`, `documentation:R[*]`
- `invitations:C[player]` (can re-invite the other parent / co-guardian within their child's record)
- Everything else → no access.

**`assistant_coach`** (per-team, FR `is_head_coach=0`)
- `team:R[team]`, `players:R[team]`, `people:R[team]`
- `evaluations:R/C[team]` — record + edit own; cannot delete.
- `sessions:R/C[team]` — schedule + edit; cannot delete a session that already has attendance recorded.
- `goals:R/C[team]` — set + edit; cannot delete.
- `attendance:R/C[team]`
- `methodology:R[*]`
- `reports:R[team]`, `rate_cards:R[team]`, `compare:R[team]`
- `documentation:R[*]`

**`head_coach`** (per-team, FR `is_head_coach=1`)
- All assistant_coach permissions, plus:
- `evaluations:C/D[team]`, `sessions:C/D[team]`, `goals:C/D[team]` — full create + delete on team-scoped surfaces.
- `invitations:C[team]` — invite players to the team via #0032.
- `team:C[team]` — edit team metadata (name, age group, notes).
- `attendance:D[team]`

**`head_of_development`**
- `team:R/C/D[*]`, `players:R/C/D[*]`, `people:R/C/D[*]`
- `evaluations:R/C/D[*]`, `sessions:R/C/D[*]`, `goals:R/C/D[*]`, `attendance:R/C/D[*]`
- `methodology:R/C/D[*]`
- `reports:R/C/D[*]`, `rate_cards:R[*]`, `compare:R[*]`, `usage_stats:R[*]`
- `bulk_import:C[*]`
- `custom_field_values:R/C[*]` (not definitions — those are `academy_admin`)
- `functional_role_assignments:R/C[*]` — assign coaches/managers to teams.
- `invitations:C[*]`
- `documentation:R/C[*]` — edit help docs (when #0029 ships its admin-side workflow).

**`scout`** (refined; #0017 ramps up usage)
- `players:R[*]`, `team:R[*]`, `evaluations:R[*]`, `sessions:R[*]`, `goals:R[*]`
- `trial_cases:R/C[player]` — full edit on assigned trial cases (when #0017 ships).
- `reports:R[*]`, `rate_cards:R[*]`, `compare:R[*]`
- `methodology:R[*]`, `documentation:R[*]`

**`team_manager`** (per-team, no coaching surface)
- `team:R[team]`, `players:R[team]`, `people:R[team]`
- `sessions:R/C/D[team]` — schedule + reschedule + delete.
- `attendance:R/C[team]`
- `goals:R[team]`, `evaluations:R[team]` — read-only on coaching outputs.
- `invitations:C[team]` — invite players + parents to the team.
- `documentation:R[*]`

**`academy_admin`** (the WP `administrator` user)
- Everything `R/C/D` at `[*]` scope.
- All `*_config` entities for the per-module config tabs.
- `module_state:R/C[*]` — toggle modules on/off.
- `authorization_matrix:R/C[*]` — edit the matrix itself.
- `migrations:R/C[*]`, `backup:R/C/D[*]`, `license:R/C[*]`, `setup_wizard:R/C[*]`, `branding:R/C[*]`, `lookups:R/C/D[*]`, `feature_toggles:R/C[*]`, `audit_log:R[*]`.

### Why the matrix is the right shape

A matrix has been considered (and resisted) on similar projects because it reads as "complex enterprise auth on top of a simple plugin." The reasons it earns its complexity here:

1. **Eight personas, real ones.** Not five hypothetical ones; the existing role sprawl is already at six WP roles plus Functional Roles plus scope rows. Pretending it's simpler doesn't make it simpler.
2. **Multi-persona is the norm.** A parent who's also the assistant coach of their kid's team is the modal user. Today they get the more permissive role's caps and the UI lies about what they're doing.
3. **One source of truth.** `MatrixGate::can()` is greppable, documentable, and (crucially) editable without a release. The current `current_user_can` calls scattered across 50+ files are not.
4. **Module toggles need it.** Per the locked decisions, module-off is implemented as "no persona has access to entities owned by this module." If we're already splitting auth and module-availability at the `MatrixGate` layer, we get module toggling almost for free.

### Why the dashboard work/setup split

The five dashboard tile groups today (Me / People / Performance / Analytics / Administration) treat all surfaces as equal and present them as one continuous scroll. This works when there are 12 tiles. We're at 24 and growing — every new module adds a tile, and admins add admin-tier tiles (Custom fields, Migrations, Configuration, Audit) that compete visually with the daily-use tiles a coach actually clicks 30 times a week.

The work/setup split is a one-pass UX win that costs almost nothing once `TileRegistry` exists (Sprint 4 work it depends on anyway). The default-collapsed setup section means non-admin personas don't see the admin clutter at all; admins get the choice to expand or stay collapsed (state remembered per user). Net effect: the daily-work area visible above the fold contains only daily-work tiles.

### Why the License warning instead of a hard guard

A `TT_DEV_MODE` constant is the right *production* answer. Pre-launch, none of that matters; what matters is that an admin in a fresh install / demo can disable monetization without editing source. The inline warning gives the right cognitive friction without blocking the flow. The post-launch hardening (constant gating + warning removal) is a small follow-up tracked in the v2 list above.

### Sequence position

**Strict: ships AFTER #0032 lands.** #0032 (invitation flow) introduces the `tt_parent` WP role and modifies the same files (`Menu.php`, `FrontendTileGrid.php`, `ConfigurationPage.php`) that #0033 rewrites structurally. Implementing #0033 in parallel guarantees three-file merge conflicts and a duplicated parent-role bring-up.

**Probably ships before #0017 trial player module** — #0017 needs the new scout role definition. If #0017 is urgent, the `tt_scout` matrix profile can be backported as a one-row addition to the seed file.

**Independent of #0011 monetization.** `LicenseGate` becomes a clean caller of `MatrixGate::can( $user_id, 'license', 'read' )` — Sprint 5's module toggle is in fact a stepping stone to a cleaner LicenseGate.

### Touches

New (per sprint):
- `database/migrations/0022_authorization_matrix.php` — Sprint 1.
- `database/migrations/0023_module_state.php` — Sprint 5.
- `database/migrations/0024_functional_role_is_head_coach.php` — Sprint 7.
- `config/authorization_seed.php` — Sprint 1.
- `src/Modules/Authorization/MatrixGate.php` — Sprint 1.
- `src/Modules/Authorization/PersonaResolver.php` — Sprint 1.
- `src/Modules/Authorization/Matrix/MatrixRepository.php` — Sprint 1.
- `src/Modules/Authorization/LegacyCapMapper.php` — Sprint 2.
- `src/Modules/Authorization/Admin/MatrixEditorPage.php` — Sprint 3.
- `src/Modules/Authorization/Admin/ModulesPage.php` — Sprint 5.
- `src/Modules/Authorization/Admin/MigrationPreviewPage.php` — Sprint 8.
- `src/Shared/Tiles/TileRegistry.php` — Sprint 4.
- `src/Modules/Configuration/Admin/ConfigTabRegistry.php` — Sprint 6.

Modified (substantial):
- `src/Core/ModuleRegistry.php` — Sprint 5: `isEnabled()`, `setEnabled()`, `allWithState()`, bootstrap respect.
- `src/Core/ModuleInterface.php` — Sprint 5: optional `getDescription()`.
- `src/Shared/Admin/Menu.php` — Sprint 4: tile literals migrated to `TileRegistry::register()`; menu groups reorganized into work/setup; Authorization group added.
- `src/Shared/Frontend/FrontendTileGrid.php` — Sprint 4: tile literals migrated; rendering split into work/setup sections.
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — Sprint 6: switch block replaced with registry lookup.
- Every existing module's `boot()` — Sprint 4 (own tiles), Sprint 6 (own config tabs), Sprint 5 (`getDescription()`).
- `config/modules.php` — Sprint 5: comment notes that this file is now a *defaults seed*; runtime state lives in `tt_module_state`.

Documentation (Sprint 9):
- `docs/access-control.md` + nl_NL — full rewrite.
- `docs/authorization-matrix.md` + nl_NL — new.
- `docs/modules.md` + nl_NL — new.
- `docs/configuration.md` + nl_NL — note the per-module ownership.
- `docs/persona-{slug}.md` × 8 personas × 2 languages — 16 small files.

### Cross-references

- Idea origin: previous content of [ideas/0033-feat-authorization-review-persona-matrix.md](../ideas/0033-feat-authorization-review-persona-matrix.md) — removed in this commit per the idea→spec discipline.
- Adjacent specs:
  - **#0011 monetization** — `LicenseGate` becomes a `MatrixGate` caller after Sprint 5.
  - **#0017 trial player module** — needs the refined scout matrix profile from Sprint 7.
  - **#0022 workflow engine** — approval workflows live there, not here.
  - **#0028 conversational goals** — comment activity is v2 of this matrix.
  - **#0029 documentation split** — docs writes live here when the admin-side editor ships.
  - **#0032 invitation flow** — introduces `tt_parent`; this spec assumes #0032 has merged.

### Risk register

- **Migration walker reads scope rows incorrectly.** Mitigation: Sprint 8 preview report. If the diff shows unexpected revocations, fix the walker before Apply.
- **Hidden cap checks survive Sprint 2 because `current_user_can` is also called via shortcodes / external code.** Mitigation: keep `LegacyCapMapper` indefinitely; never plan a deprecation.
- **Module-off short-circuit cascades.** Disabling `EvaluationsModule` makes `evaluations` return false everywhere; the rate-card view that *displays* evaluations breaks because its renderer expected the data to exist. Mitigation: Sprint 5 acceptance test specifically walks the module-disable path on every dependent surface.
- **Persona switcher confuses users.** Mitigation: Sprint 4 ships with a small "What's this?" inline help link next to the switcher; copy is part of Sprint 9 docs.
- **License toggle accidentally gets shipped to production.** Mitigation: the inline warning + the v2-list entry tracking the `TT_DEV_MODE` follow-up.

### Per-sprint independence

Each sprint is independently mergeable. Sprint 1 ships a usable read API even with no callers. Sprint 2 ships compat without changing behavior. Sprint 3 ships an admin page that's harmless until the `tt_authorization_active = 1` flag flips in Sprint 8. Sprint 5 + 6 are admin-tooling; safe to ship before Sprint 8. Sprint 7's new roles can sit in the matrix without users assigned.

The only ordering constraint within the epic: **Sprint 8's "Apply" toggle must land before any production install relies on matrix-driven permissions.** Until then, every install sees the legacy behavior.
