<?php
/**
 * Migration 0234 — resolve the four tile-gate divergences found by #2008's
 * audit gate (#2788).
 *
 * Each was a persona that saw a dashboard tile and was refused by the surface
 * behind it. Three resolutions, because they are three different problems:
 *
 *   holidays            — coaches hold `holidays:read` because the team
 *                         planner needs it for the holiday banners (#1480), so
 *                         the read could not be dropped. Tile visibility moves
 *                         to `holidays_panel` (#0079 pattern), seeded to the
 *                         personas who hold `holidays:rcd`.
 *   workflow-config     — `workflow_templates:read` removed from
 *   invitations-config    head_of_development, and `invitations_config:read`
 *                         likewise. Neither entity is read anywhere but its
 *                         own configuration surface, and neither has a
 *                         read-only cap, so the grant only ever produced a
 *                         tile the persona was refused at.
 *   pdp-planning        — no matrix change; the tile and view now gate on
 *                         `tt_view_pdp_planning`, which team_manager already
 *                         holds. Code-only, nothing to migrate.
 *
 * This migration therefore both INSERTs and DELETEs, which is unusual here —
 * the seed top-ups are normally INSERT IGNORE only. The deletes are scoped to
 * exactly two (persona, entity) pairs and only to rows still marked
 * `is_default = 1`, so an operator who has deliberately granted either of them
 * on the matrix admin page keeps their edit. Silently reverting a considered
 * operator decision would be worse than leaving a stale grant behind.
 *
 * Idempotent / re-runnable. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Tile-visibility entity to seed from config/authorization_seed.php. */
    private const ADD_ENTITIES = [ 'holidays_panel' ];

    /** (persona, entity) reads that only ever produced a 403. */
    private const DROP = [
        [ 'head_of_development', 'workflow_templates' ],
        [ 'head_of_development', 'invitations_config' ],
    ];

    public function getName(): string {
        return '0234_authorization_tile_gate_divergences';
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

        $insert = "INSERT IGNORE INTO {$table}
                     (persona, entity, activity, scope_kind, module_class, is_default)
                   VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ! in_array( $row['entity'] ?? '', self::ADD_ENTITIES, true ) ) {
                continue;
            }
            $this->exec( $wpdb->prepare(
                $insert,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // `is_default = 1` is the guard: it marks a row this seeding process
        // put there. A row an operator created or kept deliberately is not
        // ours to remove.
        foreach ( self::DROP as [ $persona, $entity ] ) {
            $this->exec( $wpdb->prepare(
                "DELETE FROM {$table}
                  WHERE persona = %s AND entity = %s AND activity = %s AND is_default = 1",
                $persona,
                $entity,
                'read'
            ) );
        }
    }
};
