<?php
/**
 * Migration: 0283_authorization_seed_topup_player_tournaments
 *
 * #3560 (epic #3558) — a player's own tournament history had nobody to
 * read it. The only tournament entity was `tournaments`, the planner,
 * granted to staff and academy admin; widening that would have handed
 * every family the whole rotation board rather than one child's record.
 *
 * `player_tournaments` is the player-scoped read: `read` only, seeded to
 * `player` at self scope, `parent` at player scope, `assistant_coach` and
 * `head_coach` at team scope, and `head_of_development` and
 * `academy_admin` globally. The planner is untouched.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install has no row for the
 * new entity at all, and every reader is refused.
 *
 * Every exit path reports (#3854): a top-up that returns on a guard and
 * says nothing is indistinguishable afterwards from one that worked, which
 * is how a grant disappears without anybody being able to tell.
 *
 * Scoped to the exact tuples this change adds, INSERT IGNORE on the unique
 * key, so an operator-edited row is never touched and a second run adds
 * nothing.
 *
 * The parent-visibility half needs no migration: a player with no row for
 * a section is treated as sharing it, so `tournaments` defaults to visible
 * for every existing family the moment the key exists.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * The exact (persona, entity) pairs this migration may write.
     * Activity is always `read`.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const GRANTS = [
        [ 'player',              'player_tournaments' ],
        [ 'parent',              'player_tournaments' ],
        [ 'assistant_coach',     'player_tournaments' ],
        [ 'head_coach',          'player_tournaments' ],
        [ 'head_of_development', 'player_tournaments' ],
        [ 'academy_admin',       'player_tournaments' ],
    ];

    public function getName(): string {
        return '0283_authorization_seed_topup_player_tournaments';
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
            $persona = (string) ( $row['persona'] ?? '' );
            $entity  = (string) ( $row['entity'] ?? '' );
            if ( (string) ( $row['activity'] ?? '' ) !== 'read' ) continue;
            if ( ! in_array( [ $persona, $entity ], self::GRANTS, true ) ) continue;

            $seen++;
            $written += (int) $wpdb->query( $wpdb->prepare(
                $sql,
                $persona,
                $entity,
                'read',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // INSERT IGNORE reports 0 for a row already present, so 0 written
        // with all six grants seen is the healthy repeat run. 0 seen means
        // the seed no longer carries them — the case worth knowing about,
        // and the one that leaves the tab dark for everybody.
        $this->report( $written, sprintf( '%d of %d grant(s) found in the seed', $seen, count( self::GRANTS ) ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
