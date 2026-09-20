<?php
/**
 * Migration: 0282_authorization_seed_topup_staff_only_notes
 *
 * #3858 — the staff-only flag on a thread message borrowed
 * `tt_edit_evaluations`, so the people who write operational notes about a
 * child and hold no evaluation rights — the team manager, the first aider,
 * the assistant coach — had a note they marked staff-only published to
 * everyone reading the conversation, the child's guardian included. The
 * flag now has an entity that names what it guards, `staff_only_notes`,
 * and the seed grants it `change` at team scope to `assistant_coach`,
 * `head_coach` and `team_manager`, and at global scope to
 * `head_of_development` and `academy_admin`.
 *
 * The seed file is only read at install time; every gate reads the live
 * matrix table. Without this top-up an existing install has no row for the
 * new entity at all, which under a refuse-don't-rewrite write path means
 * every author is refused rather than silently published — safe, but not
 * the product.
 *
 * Every exit path reports (#3854): a top-up that returns on a guard and
 * says nothing is indistinguishable afterwards from one that worked.
 *
 * Scoped to the exact tuples this change adds, INSERT IGNORE on the unique
 * key, so an operator-edited row is never touched and a second run adds
 * nothing.
 *
 * The `physio` and `manager` FUNCTIONAL ROLE halves need no migration:
 * functional-role grants are read from `config/functional_role_grants.php`
 * at runtime, not stored in the matrix table.
 *
 * Nothing is done about notes already written. No row records that a
 * widening happened, so the affected notes cannot be identified — only
 * guessed at from the author's rights at the time — and re-hiding a note a
 * family has already read and relied on would be the worse mistake. The
 * fix is forward-only, deliberately.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * The exact (persona, entity) pairs this migration may write.
     * Activity is always `change`.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const GRANTS = [
        [ 'assistant_coach',     'staff_only_notes' ],
        [ 'head_coach',          'staff_only_notes' ],
        [ 'team_manager',        'staff_only_notes' ],
        [ 'head_of_development', 'staff_only_notes' ],
        [ 'academy_admin',       'staff_only_notes' ],
    ];

    public function getName(): string {
        return '0282_authorization_seed_topup_staff_only_notes';
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
            if ( (string) ( $row['activity'] ?? '' ) !== 'change' ) continue;
            if ( ! in_array( [ $persona, $entity ], self::GRANTS, true ) ) continue;

            $seen++;
            $written += (int) $wpdb->query( $wpdb->prepare(
                $sql,
                $persona,
                $entity,
                'change',
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // INSERT IGNORE reports 0 for a row already present, so 0 written
        // with all five grants seen is the healthy repeat run. 0 seen means
        // the seed no longer carries them, which is the case worth knowing
        // about — and, here, the case where nobody may mark a note
        // staff-only any more.
        $this->report( $written, sprintf( '%d of %d grant(s) found in the seed', $seen, count( self::GRANTS ) ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
