<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * ParentAccountService (#1815) — domain logic for linking / unlinking a WP
 * account to a player as a parent / guardian, and reporting parent-account
 * status. Sibling of PlayerAccountService; the PHP view and the REST
 * controller both call this so a future non-WordPress front end gets the
 * same answers (CLAUDE.md §4).
 *
 * The parent ↔ player relationship is the many-to-many pivot
 * `tt_player_parents` (a parent WP user may guard several players; a player
 * may have several parents). The "Parent accounts" admin surface lists one
 * row per parent (a `tt_parent` WP user) with the players they guard —
 * mutations are player-scoped (link/unlink a parent on a given player).
 *
 * Gated by the dedicated `tt_manage_parent_accounts` capability.
 */
final class ParentAccountService {

    /** Parent-account role granted on link. */
    private const PARENT_ROLE = 'tt_parent';

    private PlayerParentsRepository $parents;

    public function __construct( ?PlayerParentsRepository $parents = null ) {
        $this->parents = $parents ?? new PlayerParentsRepository();
    }

    /**
     * One row per parent in this club: the WP account plus the players they
     * guard. Parents with no surviving WP user are still surfaced (so an
     * admin can clean up a stale link).
     *
     * @return list<object{wp_user_id:int,display_name:string,user_email:string,exists:bool,player_ids:int[],player_names:string[]}>
     */
    public function listParents(): array {
        global $wpdb;
        $club = CurrentClub::id();

        $ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT parent_user_id
               FROM {$wpdb->prefix}tt_player_parents
              WHERE club_id = %d AND parent_user_id > 0",
            $club
        ) );

        $out = [];
        foreach ( $ids as $raw_uid ) {
            $uid = (int) $raw_uid;
            if ( $uid <= 0 ) continue;

            $player_ids   = $this->parents->playersForParent( $uid );
            $player_names = $this->playerNames( $player_ids );
            $user         = get_userdata( $uid );

            $out[] = (object) [
                'wp_user_id'   => $uid,
                'display_name' => $user ? (string) $user->display_name : '',
                'user_email'   => $user ? (string) $user->user_email : '',
                'exists'       => (bool) $user,
                'player_ids'   => $player_ids,
                'player_names' => $player_names,
            ];
        }

        // Stable order: by display name, unknown accounts last.
        usort( $out, static function ( $a, $b ): int {
            return strcasecmp( $a->display_name ?: 'zzz', $b->display_name ?: 'zzz' );
        } );

        return $out;
    }

    /**
     * WP users eligible to be linked to a player as a parent: every user,
     * minus those already bound as a PLAYER or a staff PERSON in this club
     * (those identities must not double-bind). A user already guarding
     * another player stays eligible — parents are many-to-many.
     *
     * @return array<int,object> WP_User-lite rows (ID, display_name, user_email).
     */
    public function eligibleUsers(): array {
        global $wpdb;
        $club = CurrentClub::id();

        $bound = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id IS NOT NULL AND wp_user_id > 0 AND club_id = %d
             UNION
             SELECT wp_user_id FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id IS NOT NULL AND wp_user_id > 0 AND club_id = %d",
            $club, $club
        ) );

        return get_users( [
            'fields'  => [ 'ID', 'display_name', 'user_email' ],
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'exclude' => array_map( 'intval', $bound ),
        ] );
    }

    /**
     * Link a WP user to a player as a parent.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function linkToPlayer( int $player_id, int $parent_user_id ): array {
        global $wpdb;
        $club = CurrentClub::id();

        if ( $player_id <= 0 || $parent_user_id <= 0 ) {
            return $this->err( 'bad_request', __( 'A player and a WordPress user are both required.', 'talenttrack' ) );
        }

        $player = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );
        if ( ! $player ) {
            return $this->err( 'not_found', __( 'Player not found.', 'talenttrack' ) );
        }
        if ( ! get_userdata( $parent_user_id ) ) {
            return $this->err( 'no_user', __( 'That WordPress user no longer exists.', 'talenttrack' ) );
        }

        // No double-bind: an account that IS a player or a staff person
        // can't also be a parent.
        $is_player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $parent_user_id, $club
        ) );
        if ( $is_player > 0 ) {
            return $this->err( 'already_player', __( 'That account is a player account and can\'t also be a parent.', 'talenttrack' ) );
        }
        $is_person = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $parent_user_id, $club
        ) );
        if ( $is_person > 0 ) {
            return $this->err( 'already_person', __( 'That account is a staff account and can\'t also be a parent.', 'talenttrack' ) );
        }

        // Already guarding this player?
        if ( in_array( $parent_user_id, $this->parents->parentsForPlayer( $player_id ), true ) ) {
            return [ 'ok' => true, 'code' => 'noop', 'message' => __( 'That parent is already linked to this player.', 'talenttrack' ) ];
        }

        $this->parents->link( $player_id, $parent_user_id );

        $user = get_userdata( $parent_user_id );
        if ( $user ) $user->add_role( self::PARENT_ROLE );

        return [ 'ok' => true, 'code' => 'linked', 'message' => __( 'Parent linked.', 'talenttrack' ) ];
    }

    /**
     * Unlink a parent from a player. Strips the tt_parent role only when the
     * account no longer guards any player in this club.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function unlinkFromPlayer( int $player_id, int $parent_user_id ): array {
        if ( $player_id <= 0 || $parent_user_id <= 0 ) {
            return $this->err( 'bad_request', __( 'A player and a parent are both required.', 'talenttrack' ) );
        }

        if ( ! in_array( $parent_user_id, $this->parents->parentsForPlayer( $player_id ), true ) ) {
            return [ 'ok' => true, 'code' => 'noop', 'message' => __( 'That parent was not linked to this player.', 'talenttrack' ) ];
        }

        $this->parents->unlink( $player_id, $parent_user_id );

        if ( empty( $this->parents->playersForParent( $parent_user_id ) ) ) {
            $user = get_userdata( $parent_user_id );
            if ( $user ) $user->remove_role( self::PARENT_ROLE );
        }

        return [ 'ok' => true, 'code' => 'unlinked', 'message' => __( 'Parent unlinked.', 'talenttrack' ) ];
    }

    /**
     * Player names for a set of ids, club-scoped, in the same order as the
     * ids where possible.
     *
     * @param int[] $player_ids
     * @return string[]
     */
    private function playerNames( array $player_ids ): array {
        $player_ids = array_values( array_filter( array_map( 'intval', $player_ids ), static fn ( int $i ): bool => $i > 0 ) );
        if ( empty( $player_ids ) ) return [];

        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        $args = array_merge( $player_ids, [ CurrentClub::id() ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$wpdb->prefix}tt_players
              WHERE id IN ({$ph}) AND club_id = %d",
            ...$args
        ) );

        $by_id = [];
        foreach ( (array) $rows as $r ) {
            $by_id[ (int) $r->id ] = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
        }

        $names = [];
        foreach ( $player_ids as $pid ) {
            if ( isset( $by_id[ $pid ] ) && $by_id[ $pid ] !== '' ) {
                $names[] = $by_id[ $pid ];
            }
        }
        return $names;
    }

    /**
     * @return array{ok:bool,code:string,message:string}
     */
    private function err( string $code, string $message ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message ];
    }
}
