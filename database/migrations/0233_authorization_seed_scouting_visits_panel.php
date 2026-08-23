<?php
/**
 * Migration 0233 — seed the `scouting_visits_panel` matrix entity (#2007).
 *
 * The Scouting visits tile authorised on `prospects`, the same entity as the
 * onboarding-pipeline funnel. That made the two inseparable: a head coach
 * holds `prospects:[r,team]` deliberately (#0081, so they can read their own
 * age group's funnel), and removing it to hide the scout's outbound-visits
 * tile would have taken the funnel with it.
 *
 * The tile now declares its own tile-visibility entity, the #0079 pattern
 * already used by `team_roster_panel` and friends — matrix-only, no cap
 * bridge, seeded for the personas whose tile it is.
 *
 * Without this top-up an installed site would render the tile for nobody
 * except WordPress administrators: the matrix reseed is a manual,
 * destructive TRUNCATE+reinsert that never runs on upgrade, so the new
 * entity would have no rows and `matrixDispatchAllows()` would deny every
 * non-admin — the #1143 phantom-entity failure, reintroduced. Mirrors
 * 0220_authorization_seed_media / 0222_authorization_seed_training_exposure.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves operator-edited rows
 * untouched and only adds missing tuples. Scoped to the single new entity,
 * so it can never affect another. Run-alone (no other migration in
 * parallel).
 *
 * Personas seeded, straight from config/authorization_seed.php:
 *   scout / head_of_development / academy_admin   r at global scope
 *
 * Head coach is deliberately absent — that is the entire point of the
 * change. Their `prospects:[r,team]` grant is untouched, so the
 * onboarding-pipeline funnel keeps working; only the visits tile and its
 * route go away for them. WP administrators bypass every tt_* cap
 * unconditionally (LegacyCapMapper), so no administrator depends on these
 * rows.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'scouting_visits_panel' ];

    public function getName(): string {
        return '0233_authorization_seed_scouting_visits_panel';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ! in_array( $row['entity'] ?? '', self::ENTITIES, true ) ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
