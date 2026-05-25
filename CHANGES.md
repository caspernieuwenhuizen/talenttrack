# TalentTrack v4.3.1 — VCT module ship 2: capabilities + matrix seed + LegacyCapMapper bridges (closes #907, partial epic #905)

## Context

Ship 2 of the VCT epic. VCT-1 (v4.3.0) landed the schema; this ship adds the cap layer + matrix seed + LegacyCapMapper bridges. No REST endpoints, no UI — pure authorization foundation that VCT-3 through VCT-7 build on.

## What changed

### Three new caps (matrix-only)

Registered in `src/Infrastructure/Security/RolesService.php` as a new `VCT_CAPS` constant + included in the `ensureCapabilities()` merge so `administrator` gets them on next `admin_init`:

| Cap | Purpose |
|---|---|
| `tt_vct_plan` | Plan / generate / edit / publish VCT sessions; manage own team's schedule + PHV flags |
| `tt_vct_admin_library` | CRUD on shared exercise catalogue + age profiles + macro-blocks |
| `tt_vct_view_load` | Read workload aggregates |

**Matrix-only by design** — NOT added to any role definition's `caps` array (`roleDefinitions()`). Coverage flows exclusively through the authorization matrix bridge. This honours the scout/prospects #824 lesson: caps that need per-install scope mutability must not freeze at role-baseline time.

### LegacyCapMapper bridges

Three new entries in `src/Modules/Authorization/LegacyCapMapper.php::MAPPING`:

- `tt_vct_plan` → `(vct, read)`
- `tt_vct_admin_library` → `(vct_library, read)`
- `tt_vct_view_load` → `(vct_workload, read)`

**Activity letter is `read` for all three**, intentionally — the coarsest "does this user participate in this entity at all?" check. The LegacyCapMapper schema is one cap → one (entity, activity) tuple, so the granular activity letters (`r/c/d/p`) live in the matrix seed instead. Per-endpoint scope checks shipping in VCT-6 enforce the specific activity required by each route as the second layer (cap + scope per the spec's two-layer `permission_callback`).

### Matrix seed — four personas

Appended to `config/authorization_seed.php`:

| Persona | Entity | Activities | Scope |
|---|---|---|---|
| `head_coach` | `vct` | `rcd` | `team` |
| `assistant_coach` | `vct` | `rcd` | `team` |
| `head_of_development` | `vct` | `rcd` | `global` |
| `head_of_development` | `vct_library` | `rcd` | `global` |
| `head_of_development` | `vct_workload` | `r` | `global` |
| `academy_admin` | `vct` | `rcd` | `global` |
| `academy_admin` | `vct_library` | `rcd` | `global` |
| `academy_admin` | `vct_workload` | `r` | `global` |

### Spec letter → codebase activity mapping

The spec's design-review-locked activity letters are `r`=read, `c`=create, `d`=delete, `p`=publish. The codebase's activity vocabulary is `read`, `change`, `create_delete` (three verbs). The mapping:

- spec `r` → codebase `read`
- spec `c` (create) + spec `d` (delete) → codebase `create_delete` (one verb covering both)
- spec `p` (publish) → codebase `change` (publishing IS a state mutation)

So the spec's `rcdp` for the `vct` entity expands to codebase activities {read, change, create_delete} = codebase shorthand `rcd`. The spec's `rcd` for `vct_library` similarly expands to codebase `rcd`. The spec's `r` for `vct_workload` is `r` unchanged.

### Top-up migration for existing installs

`database/migrations/0123_vct_authorization_seed_topup.php` walks the seed file and `INSERT IGNORE`s every row whose entity is `vct`, `vct_library`, or `vct_workload`. Migration 0026's `seedIfEmpty()` only seeds on fresh install or via the operator's "Reset to defaults" button, so without this top-up the new matrix grants would never reach existing installs. Same pattern as migration 0069 for player_notes. Matrix cache cleared at the end so grants take effect on the next request.

### Matrix admin labels

`MatrixEntityCatalog::labelMap()` gets three new human labels so the matrix admin UI doesn't display raw slugs: "VCT sessions", "VCT exercise library", "VCT workload aggregates".

### Module class fallback

`config/authorization_seed.php` adds `$mod_vct` with a `class_exists()` fallback to `$mod_authorization` — the `VctModule` class ships in VCT-5 (#910) onwards; until then the seed gracefully falls back, matching the existing pattern used for `$mod_trials` / `$mod_journey` / `$mod_persona_dash`.

## Validation

- `grep "tt_vct_" src/Infrastructure/Security/RolesService.php` shows the three caps only in the `VCT_CAPS` constant and the `ensureCapabilities()` merge — NOT in any `roleDefinitions()` entry.
- `LegacyCapMapper::isKnown('tt_vct_plan')` returns `true`; `::tupleFor('tt_vct_plan')` returns `['vct', 'read']`.
- After the top-up migration runs, `SELECT COUNT(*) FROM wp_tt_authorization_matrix WHERE entity LIKE 'vct%'` returns 8 (1 head_coach + 1 assistant_coach + 3 HoD + 3 admin).
- `AuthChainDebugPage` (`?page=tt-auth-chain-debug&user_id=N`) shows `tt_vct_plan` resolving green via the matrix path for a head_coach assigned to one team within that team's scope; red outside scope.
- Migration is idempotent: re-running it leaves the 8-row count unchanged (UNIQUE key suppresses duplicates).

## Why this is `patch`, not `minor`

Small enhancement within the current 4.3 minor that opened with VCT-1's schema foundation. No new feature epic; just the cap layer that VCT-1's tables need. Patch bump per `DEVOPS.md` § "When to bump what" — same pattern as ship-2/3 of an epic following its ship-1 minor.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.0` → `4.3.1`.
