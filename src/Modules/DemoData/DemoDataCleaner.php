<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoDataCleaner — deletes demo-tagged content.
 *
 * Two flavours:
 *
 *   wipeData()  — removes every demo-tagged row EXCEPT users marked
 *                 persistent:true (the Rich set of 36). Walks the tag
 *                 table in dependency order so FKs / orphan checks stay
 *                 happy. Also re-binds the 5 player-slot WP users by
 *                 setting tt_players.wp_user_id = 0 before deleting
 *                 the player rows. Users themselves remain, as does
 *                 the player<N> slot tag.
 *
 *   wipeUsers() — removes the persistent demo users too. Three safety
 *                 rails per spec: email domain matches the configured
 *                 demo domain, user is not the current logged-in user,
 *                 user is not the last administrator on the site.
 *
 * Never touches a non-demo record. Every DELETE is gated by an id
 * membership check against tt_demo_tags.
 */
class DemoDataCleaner {

    /** Data-level entity types, in dependency-safe delete order. */
    private const DATA_ORDER = [
        'eval_rating',
        'evaluation',
        'attendance',
        'activity',
        'goal',
        'player',
        'team_person',
        'team',
        'person',
    ];

    /** Map entity_type => (table, id_column). */
    private const TABLE_MAP = [
        'eval_rating' => [ 'tt_eval_ratings', 'id' ],
        'evaluation'  => [ 'tt_evaluations',  'id' ],
        'attendance'  => [ 'tt_attendance',   'id' ],
        'activity'     => [ 'tt_activities',     'id' ],
        'goal'        => [ 'tt_goals',        'id' ],
        'player'      => [ 'tt_players',      'id' ],
        'team_person' => [ 'tt_team_people',  'id' ],
        'team'        => [ 'tt_teams',        'id' ],
        'person'      => [ 'tt_people',       'id' ],
    ];

    /**
     * Operator-facing categories the wipe form exposes. Each key maps to
     * a list of entity types `tt_demo_tags` rows use; checking the
     * category in the wipe form expands to the full list (cascade).
     *
     * Cascades reflect FK-driven dependency: if you wipe `evaluation`,
     * you must also wipe `eval_rating` (rows that reference it). The
     * operator-preference cascades (e.g. wiping a team also wipes
     * `team_person` associations and `activity` rows tied to that team)
     * are also captured here so the operator picks one box and gets
     * the consistent fan-out.
     *
     * Order within each cascade list is dependency-safe (children first).
     */
    public const CATEGORIES = [
        'goals'       => [ 'goal' ],
        'evaluations' => [ 'eval_rating', 'evaluation' ],
        'activities'  => [ 'attendance', 'activity' ],
        'players'     => [ 'eval_rating', 'evaluation', 'attendance', 'goal', 'player' ],
        'teams'       => [ 'eval_rating', 'evaluation', 'attendance', 'activity', 'team_person', 'team' ],
        'people'      => [ 'team_person', 'person' ],
    ];

    /**
     * @param string[]|null $categories Operator-picked categories from
     *   `CATEGORIES` keys (`['teams','activities',…]`). `null` falls back
     *   to all categories — the v3.85.0 "wipe everything" behaviour, kept
     *   for back-compat callers. Each category expands to its dependency
     *   cascade; the union is then deleted in `DATA_ORDER`.
     * @param string|null $batch_id #0080 Wave B2 — optional batch
     *   filter. When set, the wipe is scoped to entities tagged with
     *   that `batch_id` only; the matching `tt_demo_tags` rows for
     *   that batch are also dropped. Other batches' demo rows survive.
     *   `null` / empty preserves the all-batches behaviour.
     * @return array<string,int> Rows deleted per entity type.
     */
    public static function wipeData( ?array $categories = null, ?string $batch_id = null ): array {
        global $wpdb;
        $deleted = [];
        $batch_id = ( $batch_id !== null && $batch_id !== '' ) ? $batch_id : null;

        $types_to_wipe = self::resolveTypes( $categories );

        // Unbind any player<N> WP users that currently point at demo
        // players, so the persistent users remain in a clean state.
        // Only fires when player rows are actually being wiped.
        if ( in_array( 'player', $types_to_wipe, true ) ) {
            $player_ids = DemoBatchRegistry::allEntityIds( 'player', $batch_id );
            if ( $player_ids ) {
                $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = 0 WHERE id IN ({$placeholders}) AND club_id = %d",
                    ...array_merge( $player_ids, [ CurrentClub::id() ] )
                ) );
            }
        }

        foreach ( self::DATA_ORDER as $type ) {
            if ( ! in_array( $type, $types_to_wipe, true ) ) continue;
            $ids = DemoBatchRegistry::allEntityIds( $type, $batch_id );
            if ( ! $ids ) {
                $deleted[ $type ] = 0;
                continue;
            }
            [ $table, $id_col ] = self::TABLE_MAP[ $type ];
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$table} WHERE {$id_col} IN ({$placeholders}) AND club_id = %d",
                ...array_merge( $ids, [ CurrentClub::id() ] )
            ) );
            $deleted[ $type ] = (int) $n;

            // Drop the tags for the same type. Scoped to the batch
            // when one was passed, so other batches' tags survive.
            if ( $batch_id !== null ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = %s AND club_id = %d AND batch_id = %s",
                    $type, CurrentClub::id(), $batch_id
                ) );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = %s AND club_id = %d",
                    $type, CurrentClub::id()
                ) );
            }
        }
        return $deleted;
    }

    /**
     * Expand the operator-picked categories to the underlying entity-type
     * set, deduplicated. Null falls back to every entity type the
     * v3.85.0 cleaner used to walk (preserving the "wipe everything
     * except people" default).
     *
     * @param string[]|null $categories
     * @return string[] entity types
     */
    private static function resolveTypes( ?array $categories ): array {
        if ( $categories === null ) {
            // v3.85.0 default: walk the legacy DATA_ORDER except `person`,
            // because `person` rows were left for the separate
            // `wipeUsers()` flow.
            return array_values( array_filter(
                self::DATA_ORDER,
                static function ( string $t ): bool { return $t !== 'person'; }
            ) );
        }
        $types = [];
        foreach ( $categories as $cat ) {
            if ( ! isset( self::CATEGORIES[ $cat ] ) ) continue;
            foreach ( self::CATEGORIES[ $cat ] as $t ) {
                if ( ! in_array( $t, $types, true ) ) $types[] = $t;
            }
        }
        return $types;
    }

    /**
     * Live count per category given the current `tt_demo_tags` state.
     * Counts the tagged-row total once per type then sums across the
     * category's cascade. Used by the wipe-form preview so the operator
     * sees what each checkbox will actually delete.
     *
     * @return array<string,int> category key => total tagged rows that
     *   would be deleted if that category alone were checked
     */
    public static function categoryCounts( ?string $batch_id = null ): array {
        $per_type = [];
        foreach ( array_keys( self::TABLE_MAP ) as $type ) {
            $per_type[ $type ] = count( DemoBatchRegistry::allEntityIds( $type, $batch_id ) );
        }
        $out = [];
        foreach ( self::CATEGORIES as $cat => $types ) {
            $total = 0;
            foreach ( $types as $t ) $total += (int) ( $per_type[ $t ] ?? 0 );
            $out[ $cat ] = $total;
        }
        return $out;
    }

    /**
     * Remove the persistent demo users. Requires:
     *   - every user's email domain matches $expected_domain
     *   - no user is the currently logged-in user
     *   - no user is the only remaining administrator
     *
     * @return array{deleted:int, refused:array<int,string>}
     */
    public static function wipeUsers( string $expected_domain ): array {
        $expected_domain = strtolower( ltrim( $expected_domain, '@' ) );
        $refused = [];
        $deleted = 0;

        $persistent_ids = DemoBatchRegistry::persistentEntityIds( 'wp_user' );
        if ( ! $persistent_ids ) {
            return [ 'deleted' => 0, 'refused' => [] ];
        }

        // Persistent persons are tied to the user set — remove them
        // before deleting the users so we don't leave orphan rows with
        // wp_user_id pointing at nonexistent accounts.
        global $wpdb;
        $person_ids = DemoBatchRegistry::persistentEntityIds( 'person' );
        if ( $person_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $person_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tt_people WHERE id IN ({$placeholders}) AND club_id = %d",
                ...array_merge( $person_ids, [ CurrentClub::id() ] )
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = 'person' AND club_id = %d",
                CurrentClub::id()
            ) );
        }

        $current_user_id = (int) get_current_user_id();
        $admin_count = self::countAdministrators();

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        foreach ( $persistent_ids as $user_id ) {
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                // Orphan tag — safe to drop.
                $wpdb->delete( $wpdb->prefix . 'tt_demo_tags', [
                    'entity_type' => 'wp_user',
                    'entity_id'   => $user_id,
                    'club_id'     => CurrentClub::id(),
                ] );
                continue;
            }

            $email_domain = strtolower( (string) substr( strrchr( $user->user_email, '@' ) ?: '@', 1 ) );
            if ( $email_domain !== $expected_domain ) {
                $refused[ $user_id ] = 'domain-mismatch';
                continue;
            }
            if ( $user_id === $current_user_id ) {
                $refused[ $user_id ] = 'is-current-user';
                continue;
            }
            $is_admin_user = \TT\Infrastructure\Security\RoleResolver::userHasRole( $user_id, 'administrator' );
            if ( $is_admin_user && $admin_count <= 1 ) {
                $refused[ $user_id ] = 'last-admin';
                continue;
            }

            $ok = wp_delete_user( $user_id );
            if ( $ok ) {
                $wpdb->delete( $wpdb->prefix . 'tt_demo_tags', [
                    'entity_type' => 'wp_user',
                    'entity_id'   => $user_id,
                    'club_id'     => CurrentClub::id(),
                ] );
                $deleted++;
                if ( $is_admin_user ) {
                    $admin_count--;
                }
            } else {
                $refused[ $user_id ] = 'wp_delete_user-failed';
            }
        }

        return [ 'deleted' => $deleted, 'refused' => $refused ];
    }

    private static function countAdministrators(): int {
        $users = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        return count( $users );
    }
}
