<?php
namespace TT\Infrastructure\People;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * StaffDirectory — who counts as staff, and finding one by name (#4043).
 *
 * The trial panel and the parent link both need an account id, and
 * nothing a caller could reach turned a name into one. This is the one
 * answer to "which staff member do you mean": the REST lookup
 * (`GET /staff`) and the trial staff pickers read it, so both find the
 * same people.
 *
 * Staff is two populations, merged:
 *
 *   - a person record in this club that is not a parent, not archived and
 *     not inactive — with or without a login;
 *   - an account holding a staff role that has no person record here, so
 *     an administrator or coach who was never entered in People is still
 *     findable. It comes back with `person_id` null.
 *
 * A login bound to a player of this club is never staff, whatever role it
 * holds. The parent link's own candidate rules stay in
 * `ParentAccountService::eligibleUsers()`; they are a different question.
 */
final class StaffDirectory {

    /** Shortest search term the lookup accepts. */
    public const MIN_SEARCH = 2;

    /** Most rows one search hands back. */
    public const MAX_RESULTS = 20;

    /**
     * Account roles that make a login staff when there is no person record.
     *
     * @var list<string>
     */
    public const STAFF_ROLES = [
        'administrator',
        'tt_head_dev',
        'tt_club_admin',
        'tt_coach',
        'tt_team_manager',
        'tt_scout',
        'tt_staff',
    ];

    /**
     * Staff whose name contains `$term`, at most `$limit` of them.
     *
     * @return list<array{person_id:?int,user_id:?int,display_name:string}>
     */
    public function search( string $term, int $limit = self::MAX_RESULTS ): array {
        $term = trim( $term );
        if ( $term === '' ) return [];
        return $this->collect( $term, max( 1, $limit ) );
    }

    /**
     * Every staff member, for a picker that filters on the client.
     *
     * @return list<array{person_id:?int,user_id:?int,display_name:string}>
     */
    public function all(): array {
        return $this->collect( '', 0 );
    }

    /**
     * The account behind a staff person record.
     *
     * `status` is `ok` with the user id, `not_staff` when the id is not a
     * staff person of this club, or `no_account` when the person has no
     * login — someone who could never give an input.
     *
     * @return array{status:string,user_id:int}
     */
    public function accountForPerson( int $person_id ): array {
        if ( $person_id <= 0 ) return [ 'status' => 'not_staff', 'user_id' => 0 ];

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, wp_user_id FROM {$p}tt_people
              WHERE id = %d AND club_id = %d AND archived_at IS NULL
                AND ( status IS NULL OR status = 'active' )
                AND ( role_type IS NULL OR role_type <> 'parent' )",
            $person_id, CurrentClub::id()
        ) );
        if ( ! $row ) return [ 'status' => 'not_staff', 'user_id' => 0 ];

        $user_id = (int) ( ( (array) $row )['wp_user_id'] ?? 0 );
        if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
            return [ 'status' => 'no_account', 'user_id' => 0 ];
        }
        if ( in_array( $user_id, $this->playerAccounts(), true ) ) {
            return [ 'status' => 'not_staff', 'user_id' => 0 ];
        }
        return [ 'status' => 'ok', 'user_id' => $user_id ];
    }

    /**
     * @return list<array{person_id:?int,user_id:?int,display_name:string}>
     */
    private function collect( string $term, int $limit ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();

        $players = $this->playerAccounts();

        $where  = [
            'p.club_id = %d',
            'p.archived_at IS NULL',
            "( p.status IS NULL OR p.status = 'active' )",
            "( p.role_type IS NULL OR p.role_type <> 'parent' )",
        ];
        $params = [ $club ];
        if ( $term !== '' ) {
            $where[]  = "CONCAT_WS(' ', p.first_name, p.last_name) LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $term ) . '%';
        }
        $scope = QueryHelpers::apply_demo_scope( 'p', 'person' );
        $sql   = "SELECT p.id, p.first_name, p.last_name, p.wp_user_id
                    FROM {$p}tt_people p
                   WHERE " . implode( ' AND ', $where ) . " {$scope}
                   ORDER BY p.last_name ASC, p.first_name ASC";
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $data = (array) $row;
            $uid  = (int) ( $data['wp_user_id'] ?? 0 );
            if ( $uid > 0 && in_array( $uid, $players, true ) ) continue;
            $name = trim( (string) ( $data['first_name'] ?? '' ) . ' ' . (string) ( $data['last_name'] ?? '' ) );
            $out[] = [
                'person_id'    => (int) ( $data['id'] ?? 0 ),
                'user_id'      => $uid > 0 ? $uid : null,
                'display_name' => $name,
            ];
        }

        // Every account a person row of this club holds is already answered
        // above, or deliberately left out (a parent, an archived record); it
        // must not come back in through its role.
        $claimed = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$p}tt_people
              WHERE club_id = %d AND wp_user_id IS NOT NULL AND wp_user_id > 0",
            $club
        ) ) );

        $args = [
            'role__in' => self::STAFF_ROLES,
            'fields'   => [ 'ID', 'display_name' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'exclude'  => array_values( array_unique( array_merge( $claimed, $players ) ) ),
        ];
        if ( $term !== '' ) {
            $args['search']         = '*' . $term . '*';
            $args['search_columns'] = [ 'display_name' ];
        }
        foreach ( get_users( $args ) as $user ) {
            $u     = (array) $user;
            $out[] = [
                'person_id'    => null,
                'user_id'      => (int) ( $u['ID'] ?? 0 ),
                'display_name' => (string) ( $u['display_name'] ?? '' ),
            ];
        }

        usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['display_name'], $b['display_name'] ) );

        return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
    }

    /**
     * Logins bound to a player of this club.
     *
     * @return list<int>
     */
    private function playerAccounts(): array {
        global $wpdb;
        return array_values( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players
              WHERE club_id = %d AND wp_user_id IS NOT NULL AND wp_user_id > 0",
            CurrentClub::id()
        ) ) ) );
    }
}
