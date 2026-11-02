<?php
/**
 * Navigation groups (#4669) — every group a tile may sit in, in display order.
 *
 * Read by `TT\Shared\Tiles\NavigationGroups`. The dashboard, the shell nav
 * (sidebar, rail, drawer, bottom bar) and the Configuration landing all take
 * their group order from this one list, so a module's load order can no
 * longer move a group.
 *
 * Each entry has:
 *   - `key`     stable identifier a tile names in `TileRegistry::register()`.
 *               Never rename one: it is the contract, the label is not.
 *   - `surface` `dashboard` for daily-work groups, `settings` for the
 *               Configuration sections.
 *   - `label`   English msgid with the `navigation group` context.
 *
 * A key that is not listed here is refused by `TileRegistry::register()`.
 * Adding a group is a release, not a module decision.
 *
 * @return list<array{key:string, surface:string, label:string}>
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    // Dashboard: from the player outward.
    [ 'key' => 'me',              'surface' => 'dashboard', 'label' => _x( 'Me', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'people_teams',    'surface' => 'dashboard', 'label' => _x( 'Players & teams', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'activities',      'surface' => 'dashboard', 'label' => _x( 'Activities & matches', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'development',     'surface' => 'dashboard', 'label' => _x( 'Development', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'tactics',         'surface' => 'dashboard', 'label' => _x( 'Tactics', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'analytics',       'surface' => 'dashboard', 'label' => _x( 'Analytics & reports', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'intake',          'surface' => 'dashboard', 'label' => _x( 'Intake', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'communication',   'surface' => 'dashboard', 'label' => _x( 'Communication', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'product_ideas',   'surface' => 'dashboard', 'label' => _x( 'Product ideas', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'settings',        'surface' => 'dashboard', 'label' => _x( 'Settings', 'navigation group', 'talenttrack' ) ],

    // Configuration sections: grouped by the task an admin has in mind.
    [ 'key' => 'academy',                 'surface' => 'settings', 'label' => _x( 'Academy', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'players_teams',           'surface' => 'settings', 'label' => _x( 'Players & teams', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'development_evaluations', 'surface' => 'settings', 'label' => _x( 'Development & evaluations', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'activities_matches',      'surface' => 'settings', 'label' => _x( 'Activities & matches', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'lookups',                 'surface' => 'settings', 'label' => _x( 'Lookups', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'people_access',           'surface' => 'settings', 'label' => _x( 'People & access', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'messages_tasks',          'surface' => 'settings', 'label' => _x( 'Messages & tasks', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'data',                    'surface' => 'settings', 'label' => _x( 'Data', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'features_plan',           'surface' => 'settings', 'label' => _x( 'Features & plan', 'navigation group', 'talenttrack' ) ],
    [ 'key' => 'system',                  'surface' => 'settings', 'label' => _x( 'System', 'navigation group', 'talenttrack' ) ],
];
