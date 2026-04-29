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
     * @return array<string,int> Rows deleted per entity type.
     */
    public static function wipeData(): array {
        global $wpdb;
        $deleted = [];

        // Unbind any player<N> WP users that currently point at demo
        // players, so the persistent users remain in a clean state.
        $player_ids = DemoBatchRegistry::allEntityIds( 'player' );
        if ( $player_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = 0 WHERE id IN ({$placeholders}) AND club_id = %d",
                ...array_merge( $player_ids, [ CurrentClub::id() ] )
            ) );
        }

        foreach ( self::DATA_ORDER as $type ) {
            $ids = DemoBatchRegistry::allEntityIds( $type );
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

            // Drop the tags for the same type.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tt_demo_tags WHERE entity_type = %s AND club_id = %d",
                $type, CurrentClub::id()
            ) );
        }
        return $deleted;
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
            if ( in_array( 'administrator', (array) $user->roles, true ) && $admin_count <= 1 ) {
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
                if ( in_array( 'administrator', (array) $user->roles, true ) ) {
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
