<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamSpondGroups (#2399) — the domain answers behind a head coach picking
 * their team's Spond group.
 *
 * Three questions, all kept out of the view (CLAUDE.md §4) so the REST
 * endpoint and the rendered panel give identical answers:
 *
 *  - which groups can this team's effective Spond account see?
 *  - is a group already linked to a DIFFERENT team, and which one?
 *  - persist the choice.
 *
 * Group listing needs an authenticated call to Spond, so the result is
 * cached briefly per team: the connect panel would otherwise pay a live
 * round-trip on every render.
 */
final class TeamSpondGroups {

    /** Short cache — long enough to keep the panel snappy, short enough that a new Spond group shows up the same session. */
    private const CACHE_TTL = 300;

    /**
     * Groups visible to the team's effective account (its own override when
     * set, else the club account). The shape mirrors SpondClient::fetchGroups()
     * so callers handle one contract.
     *
     * @return array{ok:bool, groups:array<int,array{id:string,name:string}>, error_code?:string, error_message?:string}
     */
    public static function forTeam( int $team_id, bool $use_cache = true ): array {
        if ( $team_id <= 0 ) {
            return [ 'ok' => false, 'groups' => [], 'error_code' => 'bad_team_id', 'error_message' => __( 'Team id is required.', 'talenttrack' ) ];
        }

        $key = 'tt_spond_groups_' . CurrentClub::id() . '_' . $team_id;
        if ( $use_cache ) {
            $hit = get_transient( $key );
            if ( is_array( $hit ) ) return $hit;
        }

        $account = new TeamSpondAccount( $team_id );
        // A team without its own credentials falls back to the club account,
        // exactly as the sync does — so the picker lists what the sync would
        // actually be able to read.
        $result = $account->hasCredentials()
            ? SpondClient::fetchGroups( $account )
            : SpondClient::fetchGroups();

        $out = [
            'ok'     => ! empty( $result['ok'] ),
            'groups' => [],
        ];
        foreach ( (array) ( $result['groups'] ?? [] ) as $g ) {
            $gid = (string) ( $g['id'] ?? '' );
            if ( $gid === '' ) continue;
            $out['groups'][] = [
                'id'   => $gid,
                'name' => (string) ( $g['name'] ?? $gid ),
            ];
        }
        if ( ! $out['ok'] ) {
            $out['error_code']    = (string) ( $result['error_code'] ?? 'fetch_failed' );
            $out['error_message'] = (string) ( $result['error_message'] ?? __( 'Could not load Spond groups.', 'talenttrack' ) );
        }

        // Only a successful listing is worth caching; a failure should be
        // retried as soon as the coach fixes the login.
        if ( $out['ok'] ) set_transient( $key, $out, self::CACHE_TTL );

        return $out;
    }

    /** Drop the cached listing for a team (after credentials change). */
    public static function forgetCache( int $team_id ): void {
        delete_transient( 'tt_spond_groups_' . CurrentClub::id() . '_' . $team_id );
    }

    /**
     * Which OTHER teams already point at these group ids?
     *
     * Sharing a group is legitimate — clubs do run a combined age-group
     * calendar — so this exists to warn, never to block. Returns
     * `group_id => "Team name"` for groups linked elsewhere; groups linked
     * to no one, or only to $team_id itself, are absent.
     *
     * @param array<int,string> $group_ids
     * @return array<string,string>
     */
    public static function otherTeamsUsing( array $group_ids, int $team_id ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'strval', $group_ids ), static fn( $v ) => $v !== '' ) ) );
        if ( ! $ids ) return [];

        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $sql  = "SELECT spond_group_id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d
                    AND id <> %d
                    AND spond_group_id IN ($ph)";
        $args = array_merge( [ CurrentClub::id(), $team_id ], $ids );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

        $map = [];
        foreach ( (array) $rows as $r ) {
            $gid = (string) ( $r->spond_group_id ?? '' );
            if ( $gid === '' || isset( $map[ $gid ] ) ) continue;
            $map[ $gid ] = (string) ( $r->name ?? '' );
        }
        return $map;
    }

    /**
     * Persist the team's group choice. '' clears it. Returns false when the
     * team doesn't exist in this club — callers surface a 404.
     */
    public static function setGroup( int $team_id, string $group_id ): bool {
        if ( $team_id <= 0 ) return false;
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'tt_teams',
            [ 'spond_group_id' => $group_id ],
            [ 'id' => $team_id, 'club_id' => CurrentClub::id() ]
        );
        return $updated !== false;
    }
}
