<?php
namespace TT\Infrastructure\People;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * StaffPersonProvisioner — every staff login has a People record (#4091).
 *
 * The person record is the portable identity for staff (CLAUDE.md §4): the
 * trial panel is assigned by `person_id`, and `StaffDirectory` answers from
 * `tt_people`. An account that held a staff role but was never entered in
 * People could only be named by its WordPress user id, which is what kept
 * `user_id` alive on `POST trial-cases/{id}/staff`.
 *
 * Two entry points keep the gap closed:
 *
 *   - `backfill()`, run once by migration 0296, for the accounts that
 *     already exist;
 *   - the `set_user_role` / `add_user_role` hooks, for every account that
 *     gains a staff role from now on, whichever screen gave it: a new user in
 *     wp-admin, a role change on a profile, onboarding, a staff invitation.
 *     A role is always set through one of the two, including when
 *     `wp_insert_user()` creates the account.
 *
 * What it never does:
 *
 *   - touch an account bound to a player (`tt_players.wp_user_id`) or to a
 *     child as a parent (`tt_player_parents.parent_user_id`), or one holding
 *     the player or parent role. Those are not staff, whatever else they
 *     hold, and a person record would put them in the staff pickers;
 *   - create a second record for an account any person row of this club
 *     already holds, active or not. An inactive or archived record is an
 *     administrator's decision about that account, not a gap to fill.
 *
 * Idempotent: a second run creates nothing.
 */
final class StaffPersonProvisioner {

    /**
     * Account role => the People `role_type` it reads as. A role missing
     * here, or an account holding none of these, gets `staff`.
     *
     * @var array<string,string>
     */
    private const ROLE_TYPE_BY_ROLE = [
        'tt_head_dev'     => 'head_of_development',
        'tt_coach'        => 'coach',
        'tt_team_manager' => 'manager',
        'tt_scout'        => 'scout',
        'tt_staff'        => 'staff',
        'tt_club_admin'   => 'staff',
        'administrator'   => 'staff',
    ];

    /** Roles that make an account a player's or a parent's, never staff. */
    private const BOUND_ROLES = [ 'tt_player', 'tt_parent' ];

    /**
     * Nesting depth of `hold()`. While above zero the role hooks do
     * nothing, so a caller that links the account to an existing person
     * straight after creating it (a staff invitation) is not raced by a
     * second record.
     */
    private static int $held = 0;

    public static function init(): void {
        add_action( 'set_user_role', [ self::class, 'onRoleChanged' ], 20, 1 );
        add_action( 'add_user_role', [ self::class, 'onRoleChanged' ], 20, 1 );
    }

    /** Pause the role hooks. Pair every call with `release()`. */
    public static function hold(): void {
        self::$held++;
    }

    public static function release(): void {
        self::$held = max( 0, self::$held - 1 );
    }

    /** @param int|string $user_id */
    public static function onRoleChanged( $user_id ): void {
        if ( self::$held > 0 ) return;
        ( new self() )->ensureForUser( (int) $user_id );
    }

    /**
     * Create the People record for one staff account when it has none.
     *
     * @return int The person id created, or 0 when nothing was created
     *             (not staff, bound to a player or parent, or already held).
     */
    public function ensureForUser( int $user_id ): int {
        if ( $user_id <= 0 ) return 0;
        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) return 0;

        $roles = array_values( array_map( 'strval', (array) $user->roles ) );
        if ( array_intersect( $roles, StaffDirectory::STAFF_ROLES ) === [] ) return 0;
        if ( array_intersect( $roles, self::BOUND_ROLES ) !== [] ) return 0;
        if ( $this->isBound( $user_id ) ) return 0;
        if ( $this->isHeld( $user_id ) ) return 0;

        [ $first, $last ] = self::namesFor( $user );

        $repo = new PeopleRepository();
        $id   = $repo->create( [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) $user->user_email,
            'role_type'  => self::roleTypeFor( $roles ),
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );

        return is_int( $id ) && $id > 0 ? $id : 0;
    }

    /**
     * Every staff account of this club without a People record gets one.
     *
     * @return int How many records were created.
     */
    public function backfill(): int {
        $created = 0;
        $ids     = get_users( [
            'role__in' => StaffDirectory::STAFF_ROLES,
            'fields'   => 'ID',
            'number'   => -1,
        ] );
        foreach ( (array) $ids as $id ) {
            if ( $this->ensureForUser( (int) $id ) > 0 ) $created++;
        }
        return $created;
    }

    /** A login bound to a player or, as a parent, to a child of this club. */
    private function isBound( int $user_id ): bool {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();

        $player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE club_id = %d AND wp_user_id = %d",
            $club, $user_id
        ) );
        if ( $player > 0 ) return true;

        $table = "{$p}tt_player_parents";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return false;
        $parent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE club_id = %d AND parent_user_id = %d",
            $club, $user_id
        ) );
        return $parent > 0;
    }

    /** Any person row of this club already holds the account, in any state. */
    private function isHeld( int $user_id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_people WHERE club_id = %d AND wp_user_id = %d",
            CurrentClub::id(), $user_id
        ) ) > 0;
    }

    /**
     * First and last name: the account's own name fields, else the display
     * name split at its first space, else the login.
     *
     * @return array{0:string,1:string}
     */
    private static function namesFor( \WP_User $user ): array {
        $first = trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) );
        $last  = trim( (string) get_user_meta( (int) $user->ID, 'last_name', true ) );
        if ( $first !== '' || $last !== '' ) return [ $first, $last ];

        $display = trim( (string) $user->display_name );
        if ( $display === '' ) $display = trim( (string) $user->user_login );
        $parts = preg_split( '/\s+/', $display, 2 );
        $parts = is_array( $parts ) ? $parts : [ $display ];
        return [ (string) ( $parts[0] ?? '' ), (string) ( $parts[1] ?? '' ) ];
    }

    /** @param list<string> $roles */
    private static function roleTypeFor( array $roles ): string {
        foreach ( self::ROLE_TYPE_BY_ROLE as $role => $type ) {
            if ( in_array( $role, $roles, true ) ) return $type;
        }
        return 'staff';
    }
}
