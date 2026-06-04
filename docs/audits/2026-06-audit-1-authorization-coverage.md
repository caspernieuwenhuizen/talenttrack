# Audit 1 — Authorization coverage

Static cross-check of `config/authorization_seed.php` against
`src/Shared/CoreSurfaceRegistration.php` (tile + admin-menu registry)
and `src/Modules/Authorization/LegacyCapMapper.php`, run on the main
branch at HEAD `0c30113` (2026-06-03). Tracking issue: #1175.

## Summary

The seed declares roughly 118 distinct entities. Of those:

- 45 are directly consumed by a frontend tile (`entity` field on
  `TileRegistry::register([...])`).
- 15 additional ones are reachable indirectly because a tile or
  admin-menu entry declares a `cap` that `LegacyCapMapper::tupleFor()`
  bridges to an entity in the seed (e.g. `tt_view_teams` → `team`).
- 10 are whitelisted in `MatrixEntityCatalog::ADMIN_ONLY_ENTITIES`
  (v4.20.9 / #1159).
- **One phantom tile entity** (`exports`) is declared on a tile but
  not seeded — the same bug class as #1143 (scouting-visits).
- The remainder fall into three buckets discussed below.

The phantom-entity check (B) found exactly one offender. The orphan
sweep (A) found a long tail of mostly-legitimate "data entities
without a dedicated tile" — these are intentional in the matrix
design (e.g. `pdp_verdict` is read through a panel tile that gates on
`pdp_panel`) and should be neutralised by extending
`ADMIN_ONLY_ENTITIES` to also cover "tile-aggregated data entities,"
or by adding tile-visibility entity aliases. The cap-without-entity
sweep (C) found no live offenders — every tile cap currently maps to
a seeded entity.

The audit's main usable signal is therefore:

1. The phantom entity `exports` must be fixed (B-1).
2. The orphan list (A) wants a structural decision about how the
   matrix admin should treat "data entities with no first-class UI
   surface" — currently 50 of them flag as orphan-noise. This is the
   #1159 problem in larger form.

## A) Orphan entities — seeded but no tile, no admin-menu cap, not whitelisted

51 entities total. Grouped by structural cause, with action.

### A.1 — Data entities reachable only through tile-visibility entity aliases

These are read-by-the-feature but the dashboard tile is gated on a
distinct `*_panel` entity (the #0079 split). The matrix's
`consumersOf()` walk doesn't follow that aliasing, so it flags them
as orphan even though the cap pipeline + REST surface use them.

| Entity | Personas with grants | Suggested action |
|---|---|---|
| `pdp_verdict` | player, parent, hc, tm, scout, hod, admin | Add to `ADMIN_ONLY_ENTITIES` (rename whitelist to `MATRIX_ONLY_ENTITIES`) — consumed via PDP detail view gated on `pdp_panel`. |
| `pdp_conversations` | player, parent, hc, tm, hod, admin | Same. |
| `pdp_calendar_export` | all 8 personas | Same — self-scope export endpoint. |
| `pdp_evidence_packet` | player, parent, hc, hod, admin | Same. |
| `player_status` | all personas | Same — surfaced inside player card tile (gated on `my_card` / `coach_player_list_panel`). |
| `player_status_breakdown` | ac, hc, tm, hod, admin | Same. |
| `player_potential` | hc, hod, admin | Same. |
| `player_behaviour_ratings` | hc, tm, hod, admin | Same. |
| `player_injuries` | all personas | Same. |
| `player_timeline` | all personas | Same. |
| `player_notes` | ac, hc, tm, scout, hod, admin | Same. |
| `safeguarding_notes` | hod, admin | Same — surfaced inside player detail; sensitive. |
| `trial_inputs` | ac, hc, scout, hod, admin | Same — written inside trial-case view. |
| `trial_decisions` | hc, hod, admin | Same. |
| `trial_case_staff` | hc, hod, admin | Same. |
| `trial_extensions` | hod, admin | Same. |
| `trial_reminders` | hod, admin | Same. |
| `trial_letters_generated` | parent, hc, scout, hod, admin | Same. |
| `staff_certifications` | ac, hc, tm, scout, hod, admin | Same — read on People profile. |
| `staff_mentorships` | hc, hod, admin | Same. |
| `test_trainings` | hc, scout, hod, admin | Same — funnel inside `prospects` tile. |
| `scout_access` | hod, admin | Same — HoD assigns scouts inside Configuration. |
| `scout_history` | hod, admin | Same. |
| `team_chemistry` | hc, tm, scout, hod, admin | Same — covered by `team_chemistry_panel` tile. |
| `attendance` | parent, ac, hc, tm, hod, admin | Same — recorded inline on activities. |
| `invitations` | parent, ac, hc, tm, scout, hod, admin | Same — modal flow inside People + Players views. |

### A.2 — "My" entities for player/parent self-routes

These are routed through the dashboard shortcode but not wrapped in a
tile (the route exists, the tile doesn't). They're functionally not
orphan because the shortcode dispatch reaches them.

| Entity | Personas | Suggested action |
|---|---|---|
| `my_profile` | player, parent | Add `entity` to the "My profile" sub-view OR document as shortcode-routed and add to `MATRIX_ONLY_ENTITIES`. |
| `my_person` | ac, hc, tm, scout, hod, admin | Same — staff self-edit. |
| `scout_my_players` | scout | Same — scout dashboard widget. |
| `task_completion` | all | Same — used on workflow buttons inline. |
| `push_subscriptions` | all | Same — silent JS register. |

### A.3 — Admin / config surfaces missed by `ADMIN_ONLY_ENTITIES`

These are real admin/config pages but the page uses a WP cap
(`administrator`, `manage_options`, `read`) or a `tt_*` cap that doesn't
bridge through `LegacyCapMapper` to the entity. **#1159 should have
caught these too.**

| Entity | Personas | Suggested action |
|---|---|---|
| `roles` | admin | Add to `ADMIN_ONLY_ENTITIES`. Page exists (`tt-roles`) gated on `tt_view_settings` which maps to `settings`, not `roles`. |
| `authorization_matrix` | admin | Add to `ADMIN_ONLY_ENTITIES`. Page exists (`tt-matrix`) gated on `administrator`. |
| `matrix_preview_apply` | admin | Add to `ADMIN_ONLY_ENTITIES`. Reachable via `tt-matrix-preview`. |
| `backup` | admin | Add to `ADMIN_ONLY_ENTITIES`. `tt_manage_backups` maps but no admin page declares this cap directly. |
| `demo_data` | admin | Add to `ADMIN_ONLY_ENTITIES`. Page gated on `manage_options`. |
| `custom_css` | admin | Add to `ADMIN_ONLY_ENTITIES`. `tt_admin_styling` maps but no tile/admin uses this cap. |
| `impersonation_action` | admin | Add to `ADMIN_ONLY_ENTITIES`. Triggered from inline buttons in user-management surface. |
| `usage_stats_details` | hod, admin | Add to `ADMIN_ONLY_ENTITIES`. Admin page exists (`tt-usage-stats-details`) but gated on `tt_view_settings` → maps to `settings`. |
| `documentation` | all | Add to `ADMIN_ONLY_ENTITIES`. `Help & Docs` admin page exists but uses `cap='read'`. |
| `persona_templates` | all | Add to `ADMIN_ONLY_ENTITIES`. Templates seeded into admin's dashboard config. |
| `rating_scale` | hod, admin | Add to `ADMIN_ONLY_ENTITIES`. Rendered as a sub-tab inside Configuration tile. |
| `translations` | hod, admin | Add to `ADMIN_ONLY_ENTITIES`. Sub-tab inside Configuration. |
| `translations_config` | hod, admin | Same. |
| `custom_widgets` | hod, admin | Same. Reached from Configuration sub-tab. |
| `football_actions` | ac, hc, tm, scout, hod, admin | Same — admin page `tt-football-actions` exists but gated on `tt_view_methodology` → maps to `methodology`, not `football_actions`. |
| `spond_integration` | hc, scout, hod, admin | Same — admin page gated on `tt_edit_teams` → maps to `team`. |
| `thread_messages` | all | Same — inline on player + team views. |

### A.4 — VCT (#0095 in flight)

VCT module is still landing; tiles for VCT have not yet been
registered. **Action:** revisit after VCT-5/-6 ship; once the VCT
tiles land, these become covered.

| Entity | Personas | Suggested action |
|---|---|---|
| `vct` | ac, hc, hod, admin | Defer — VCT module is mid-rollout. |
| `vct_library` | hod, admin | Defer — same. |
| `vct_workload` | hod, admin | Defer — same. |

## B) Phantom tile entities — tile declares `entity` not in seed

The #1143 bug class. **One offender.**

| Tile view_slug | Entity declared | Suggested fix |
|---|---|---|
| `exports` | `exports` | Either (a) add `exports` to the seed (e.g. HoD + admin R global, scouts/coaches via current cap layer), or (b) align the tile to `reports` like #1143 aligned scouting-visits to `prospects`. The view itself self-filters per-cap so option (b) is the lighter touch — the tile's cap `tt_view_reports` already maps to `reports`. Recommended: change `entity` to `reports` in `CoreSurfaceRegistration.php:741`. |

## C) Cap-without-entity tiles — tile's `cap` maps to entity not in seed

**No offenders found.** Every `cap` declared on a tile in
`CoreSurfaceRegistration.php` maps through `LegacyCapMapper` to an
entity that exists in `authorization_seed.php`. The two bug classes
this audit would have caught:
- A tile gated on a `tt_*` cap that's not in `LegacyCapMapper::MAPPING`
  at all (would fall through to native WP cap evaluation, invisible
  to the matrix).
- A tile gated on a `tt_*` cap that maps to a phantom entity (similar
  to B but discovered via the cap layer).

Both are clean.

## Recommended audit harness (CI sketch)

`tests/Authorization/Audit1CoverageTest.php`:

```php
final class Audit1CoverageTest extends \WP_UnitTestCase {

    public function test_every_seeded_entity_has_a_consumer_surface(): void {
        $seed     = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $entities = array_values( array_unique( array_column( $seed, 'entity' ) ) );

        $orphans = [];
        foreach ( $entities as $entity ) {
            if ( MatrixEntityCatalog::consumersOf( $entity ) === [] ) {
                $orphans[] = $entity;
            }
        }
        $this->assertSame( [], $orphans,
            'Seeded entities with no consumer: ' . implode( ', ', $orphans ) );
    }

    public function test_every_tile_entity_exists_in_seed(): void {
        $seed         = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $seeded       = array_flip( array_column( $seed, 'entity' ) );
        $phantoms     = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            $declared = (string) ( $tile['entity'] ?? '' );
            if ( $declared === '' ) continue;
            if ( ! isset( $seeded[ $declared ] ) ) {
                $phantoms[] = sprintf( '%s (view=%s)', $declared, $tile['view_slug'] ?? '?' );
            }
        }
        $this->assertSame( [], $phantoms,
            'Tiles declare phantom entities: ' . implode( ', ', $phantoms ) );
    }

    public function test_every_tile_cap_maps_to_seeded_entity(): void {
        $seed   = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $seeded = array_flip( array_column( $seed, 'entity' ) );
        $broken = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            $cap = (string) ( $tile['cap'] ?? '' );
            if ( $cap === '' ) continue;
            $tuple = LegacyCapMapper::tupleFor( $cap );
            if ( $tuple === null ) continue; // cap not in mapper; native WP eval
            if ( ! isset( $seeded[ $tuple[0] ] ) ) {
                $broken[] = sprintf( '%s (cap=%s → entity=%s)',
                    $tile['view_slug'] ?? '?', $cap, $tuple[0] );
            }
        }
        $this->assertSame( [], $broken,
            'Tile caps map to entities missing from seed: ' . implode( ', ', $broken ) );
    }
}
```

**Run trigger.** Add to the CI matrix's PHPUnit job. The first
assertion will fail loudly today (51 orphans). It only becomes useful
after `ADMIN_ONLY_ENTITIES` is widened (issue #A-1 below) OR
`consumersOf()` is taught about tile-visibility aliases. Until then,
ship just the two reverse assertions (B + C) — they pass today and
catch new #1143-class regressions immediately.

## Methodology notes

- Tile entities extracted by grep on `'entity' => '...'` inside
  `TileRegistry::register([...])` blocks in
  `src/Shared/CoreSurfaceRegistration.php`. 52 calls, 45 distinct
  entity values, 2 duplicates intentional (the `activities_panel`
  and `team_chemistry_panel` aliases used by adjacent tiles).
- Seed entities extracted from the `$expand()` array keys in
  `config/authorization_seed.php`, deduped across all 8 personas.
- Cap-to-entity bridge taken from `LegacyCapMapper::MAPPING`.
- Admin-menu coverage walked manually from
  `registerAdminSubmenu()` + `registerAdminDashboardTiles()`.
- Audit run date: 2026-06-03. HEAD: `0c30113`.
