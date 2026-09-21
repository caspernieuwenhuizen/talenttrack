<?php
/**
 * Migration: 0287_authorization_seed_topup_head_coach_potential
 *
 * #3967 — head coaches set potential for their own squads. The seed now
 * gives `head_coach` `player_potential: change` at team scope (it held
 * `read` only), but the seed file is read at install time and every gate
 * reads the live matrix table. Without this top-up an existing install
 * keeps refusing the head coach, and the Set potential button never
 * appears for the people the change is for.
 *
 * Scoped to the one tuple this change adds, INSERT IGNORE on the unique
 * key, so an operator-edited row is never touched and a second run adds
 * nothing. Every exit path reports (#3854).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const PERSONA  = 'head_coach';
    private const ENTITY   = 'player_potential';
    private const ACTIVITY = 'change';

    public function getName(): string {
        return '0287_authorization_seed_topup_head_coach_potential';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            $this->report( 0, 'authorization_seed.php is not readable' );
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            $this->report( 0, 'authorization_seed.php did not return an array' );
            return;
        }

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        $seen    = 0;
        foreach ( $rows as $row ) {
            if ( (string) ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            if ( (string) ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            if ( (string) ( $row['activity'] ?? '' ) !== self::ACTIVITY ) continue;

            $seen++;
            $written += (int) $wpdb->query( $wpdb->prepare(
                $sql,
                self::PERSONA,
                self::ENTITY,
                self::ACTIVITY,
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // 0 written with the grant seen is the healthy repeat run. 0 seen
        // means the seed no longer carries it, which is the case worth
        // knowing about.
        $this->report( $written, sprintf( '%d of 1 grant(s) found in the seed', $seen ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
