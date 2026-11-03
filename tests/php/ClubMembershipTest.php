<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Infrastructure\Tenancy\ClubMembership;
use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Settings\MfaPolicyService;
use TT\Modules\Mfa\Settings\MfaSettings;

/**
 * #4787 — one account-to-club lookup, for the two-step login gate and its
 * policy counts.
 *
 * Membership comes from the club-scoped staff, player and guardian rows.
 * With two clubs on one install, each club's policy screen counts only its
 * own accounts, an account in one club follows that club's policy, and an
 * account in both is required as soon as either club requires it.
 */
final class ClubMembershipTest extends WP_UnitTestCase {

    private const CLUB_A = 1;
    private const CLUB_B = 2;

    private int $scout_a    = 0;
    private int $scout_b    = 0;
    private int $scout_both = 0;
    private int $player_b   = 0;
    private int $parent_a   = 0;

    /** @var array<int,string> club => stored policy before the test */
    private array $policy_was = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ClubMembership::flush();

        foreach ( [ self::CLUB_A, self::CLUB_B ] as $club ) {
            $this->policy_was[ $club ] = ClubIteration::withClub( $club, static function (): string {
                return (string) ( new \TT\Infrastructure\Config\ConfigService() )->get( MfaSettings::KEY_REQUIRED_PERSONAS, '' );
            } );
        }

        $this->scout_a    = $this->staff( 'tt_scout', [ self::CLUB_A ] );
        $this->scout_b    = $this->staff( 'tt_scout', [ self::CLUB_B ] );
        $this->scout_both = $this->staff( 'tt_scout', [ self::CLUB_A, self::CLUB_B ] );

        global $wpdb;
        $this->player_b = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => self::CLUB_B, 'first_name' => 'Bo', 'last_name' => 'Tweede', 'status' => 'active', 'wp_user_id' => $this->player_b,
        ] );

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => self::CLUB_A, 'first_name' => 'Kim', 'last_name' => 'Eerste', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $child          = (int) $wpdb->insert_id;
        $this->parent_a = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => self::CLUB_A, 'player_id' => $child, 'parent_user_id' => $this->parent_a,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        foreach ( $this->policy_was as $club => $value ) {
            ClubIteration::withClub( (int) $club, static function () use ( $value ): void {
                ( new \TT\Infrastructure\Config\ConfigService() )->set( MfaSettings::KEY_REQUIRED_PERSONAS, $value );
            } );
        }
        ClubMembership::flush();
        parent::tear_down();
    }

    /** @param list<int> $clubs */
    private function staff( string $role, array $clubs ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => $role ] );
        foreach ( $clubs as $club ) {
            $wpdb->insert( "{$wpdb->prefix}tt_people", [
                'club_id' => $club, 'first_name' => 'Staf', 'last_name' => 'Lid' . $club, 'role_type' => 'scout', 'wp_user_id' => $uid, 'status' => 'active',
            ] );
        }
        return $uid;
    }

    /** @param list<string> $personas */
    private function policy( int $club, array $personas ): void {
        ClubIteration::withClub( $club, static function () use ( $personas ): void {
            ( new MfaSettings() )->setRequiredPersonas( $personas );
        } );
    }

    // ---- the lookup ----

    public function test_membership_comes_from_staff_player_and_guardian_rows(): void {
        $this->assertSame( [ self::CLUB_A ], ClubMembership::clubIdsForUser( $this->scout_a ) );
        $this->assertSame( [ self::CLUB_B ], ClubMembership::clubIdsForUser( $this->scout_b ) );
        $this->assertSame( [ self::CLUB_A, self::CLUB_B ], ClubMembership::clubIdsForUser( $this->scout_both ) );
        $this->assertSame( [ self::CLUB_B ], ClubMembership::clubIdsForUser( $this->player_b ), 'a player record' );
        $this->assertSame( [ self::CLUB_A ], ClubMembership::clubIdsForUser( $this->parent_a ), 'a guardian link' );

        $loner = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->assertSame( [], ClubMembership::clubIdsForUser( $loner ) );
    }

    public function test_a_club_lists_only_its_own_accounts(): void {
        $a = ClubMembership::userIdsForClub( self::CLUB_A );
        $b = ClubMembership::userIdsForClub( self::CLUB_B );

        foreach ( [ $this->scout_a, $this->scout_both, $this->parent_a ] as $uid ) $this->assertContains( $uid, $a );
        foreach ( [ $this->scout_b, $this->player_b ] as $uid ) $this->assertNotContains( $uid, $a );
        foreach ( [ $this->scout_b, $this->scout_both, $this->player_b ] as $uid ) $this->assertContains( $uid, $b );
        foreach ( [ $this->scout_a, $this->parent_a ] as $uid ) $this->assertNotContains( $uid, $b );
    }

    public function test_membership_never_comes_from_user_meta(): void {
        $loner = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $loner, 'tt_club_id', self::CLUB_B );
        update_user_meta( $loner, 'club_id', self::CLUB_B );

        $this->assertSame( [], ClubMembership::clubIdsForUser( $loner ) );
        $this->assertNotContains( $loner, ClubMembership::userIdsForClub( self::CLUB_B ) );
    }

    public function test_answers_are_memoised_until_flushed(): void {
        global $wpdb;
        $this->assertSame( [ self::CLUB_A ], ClubMembership::clubIdsForUser( $this->scout_a ) );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => self::CLUB_B, 'first_name' => 'Staf', 'last_name' => 'Later', 'role_type' => 'scout', 'wp_user_id' => $this->scout_a, 'status' => 'active',
        ] );
        $queries = $wpdb->num_queries;
        $this->assertSame( [ self::CLUB_A ], ClubMembership::clubIdsForUser( $this->scout_a ), 'the request keeps its first answer' );
        $this->assertSame( $queries, $wpdb->num_queries, 'and asks the database once' );

        ClubMembership::flush();
        $this->assertSame( [ self::CLUB_A, self::CLUB_B ], ClubMembership::clubIdsForUser( $this->scout_a ) );
    }

    // ---- the policy screen counts ----

    public function test_each_club_counts_only_its_own_accounts(): void {
        $count = static function ( int $club ): array {
            return ClubIteration::withClub( $club, static function (): array {
                foreach ( ( new MfaPolicyService() )->overview() as $row ) {
                    if ( $row['key'] === 'scout' ) return [ $row['total'], $row['unenrolled'] ];
                }
                return [ -1, -1 ];
            } );
        };

        $this->assertSame( [ 2, 2 ], $count( self::CLUB_A ), 'scout A and the scout in both clubs' );
        $this->assertSame( [ 2, 2 ], $count( self::CLUB_B ), 'scout B and the scout in both clubs' );
    }

    // ---- the login gate ----

    public function test_an_account_in_one_club_follows_that_clubs_policy(): void {
        $this->policy( self::CLUB_A, [ 'scout' ] );
        $this->policy( self::CLUB_B, [] );

        $this->assertTrue( MfaLoginGuard::personaIsGated( $this->scout_a ) );
        $this->assertFalse( MfaLoginGuard::personaIsGated( $this->scout_b ), 'club B does not require it' );

        $this->policy( self::CLUB_A, [] );
        $this->policy( self::CLUB_B, [ 'scout' ] );

        $this->assertFalse( MfaLoginGuard::personaIsGated( $this->scout_a ), 'club A no longer requires it' );
        $this->assertTrue( MfaLoginGuard::personaIsGated( $this->scout_b ) );
    }

    public function test_an_account_in_two_clubs_follows_the_strictest(): void {
        $this->policy( self::CLUB_A, [ 'scout' ] );
        $this->policy( self::CLUB_B, [] );
        $this->assertTrue( MfaLoginGuard::personaIsGated( $this->scout_both ), 'club A requires it' );

        $this->policy( self::CLUB_A, [] );
        $this->policy( self::CLUB_B, [ 'scout' ] );
        $this->assertTrue( MfaLoginGuard::personaIsGated( $this->scout_both ), 'club B requires it' );

        $this->policy( self::CLUB_B, [ 'head_of_development' ] );
        $this->assertFalse( MfaLoginGuard::personaIsGated( $this->scout_both ), 'neither requires it for a scout' );
    }

    public function test_an_account_with_no_club_row_follows_the_current_club(): void {
        $loner = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->policy( self::CLUB_A, [ 'scout' ] );
        $this->policy( self::CLUB_B, [] );

        $this->assertTrue( MfaLoginGuard::personaIsGated( $loner ), 'club 1 is current, as on every single-club install' );
        $this->assertFalse( ClubIteration::withClub( self::CLUB_B, static fn (): bool => MfaLoginGuard::personaIsGated( $loner ) ) );
    }
}
