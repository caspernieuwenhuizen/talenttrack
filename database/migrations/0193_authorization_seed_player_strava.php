<?php
/**
 * Migration 0193 — grant the PLAYER persona a self-scoped Strava grant (#2153).
 *
 * Strava is personal activity data: a player should be able to connect their
 * own Strava from their profile. #2127 (migration 0191) seeded the
 * `strava_integration` matrix entity for the operator personas only
 * (head_coach + academy_admin), so on the matrix the PLAYER persona holds NO
 * `strava_integration` row. With matrix gating active, the capability checks
 * behind the player Strava connect flow deny the athlete on their own record.
 *
 * #2153 adds `strava_integration => [ 'rc', 'self', $mod_players ]` to the
 * PLAYER block of `config/authorization_seed.php` (mirroring `my_profile`).
 * The matrix reseed is a manual, destructive TRUNCATE+reinsert that never runs
 * on upgrade, so already-installed sites would never gain the new PLAYER rows.
 * This top-up adds them, mirroring 0190_measurements / 0191_strava.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited rows
 * untouched and only adds the missing tuples. Scoped to the single
 * `strava_integration` entity AND the `player` persona so it never touches
 * the operator grants 0191 already seeded, nor any row an operator removed
 * for another entity. Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const ENTITIES = [ 'strava_integration' ];
    private const PERSONAS = [ 'player' ];

    public function getName(): string {
        return '0193_authorization_seed_player_strava';
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
            if ( ! in_array( $row['persona'] ?? '', self::PERSONAS, true ) ) {
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
