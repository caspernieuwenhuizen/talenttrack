<?php
/**
 * Migration: 0278_authorization_seed_topup_tournaments_personas
 *
 * #3703 — the tournament module shipped admin-only, which locked the
 * people who run the squad and the playing time out of the planner on
 * the day it is played. The seed now grants `tournaments rcd` at team
 * scope to both coach personas and the team manager, and at global scope
 * to head of development. Academy admin is unchanged.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install keeps the 403.
 * Same shape as 0179, which backfilled the entity in the first place:
 * scoped to the `tournaments` entity and to the four personas this
 * change adds, INSERT IGNORE on the unique key, so an operator-edited
 * row is never touched and a second run adds nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** Personas this migration is allowed to write. Academy admin already has its rows. */
    private const PERSONAS = [ 'assistant_coach', 'head_coach', 'team_manager', 'head_of_development' ];

    public function getName(): string {
        return '0278_authorization_seed_topup_tournaments_personas';
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
            if ( (string) ( $row['entity'] ?? '' ) !== 'tournaments' ) continue;
            if ( ! in_array( (string) ( $row['persona'] ?? '' ), self::PERSONAS, true ) ) continue;

            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
