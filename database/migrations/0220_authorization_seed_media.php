<?php
/**
 * Migration 0220 — seed the `media` matrix entity (#2591, epic #2589).
 *
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never
 * runs on upgrade, so an already-installed site would never gain the new
 * `media` rows. Every coach would hold `tt_view_media` as a raw role
 * capability and still be refused by `MatrixGate`, which is the failure
 * mode that looks like "the feature is broken" rather than "the matrix is
 * missing a row". This top-up adds the missing grants, mirroring
 * 0214_authorization_seed_training_plan / 0190_measurements / 0191_strava.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `media` entity so it never touches another. WP administrators bypass
 * every tt_* cap unconditionally (LegacyCapMapper), so no administrator
 * depends on these rows. Run-alone (no other migration in parallel).
 *
 * Personas seeded, straight from config/authorization_seed.php:
 *   player                              r   at self scope
 *   parent                              r   at player scope
 *   scout                               r   at player scope
 *   team_manager                        r   at team scope
 *   assistant_coach / head_coach        rcd at team scope
 *   head_of_development / academy_admin rcd at global scope
 *
 * Two of those are worth stating rather than leaving to be inferred:
 *
 * The scout reads at **player** scope, not globally, mirroring the #1378
 * tightening of `evaluations` for the same persona. A photograph of a
 * child is at least as sensitive as a written judgment about them, and
 * academy-wide read was the widest sensitive-data grant in the matrix
 * before #1378 removed it.
 *
 * Coaches hold `create_delete` because the seed vocabulary fuses create
 * and delete into one verb, and a coach who cannot upload cannot use the
 * feature at all. The consequence — a coach can also delete — is the
 * right trade: someone who publishes a photograph to a family in error
 * must be able to withdraw it without waiting for an admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'media' ];

    public function getName(): string {
        return '0220_authorization_seed_media';
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
