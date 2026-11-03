<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use WPDieException;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Infrastructure\Tenancy\ClubMembership;
use TT\Modules\Mfa\Admin\MfaActionHandlers;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;

/**
 * #4798 — an enrolment is the account's, and the operator's reset and the
 * list it picks from follow the academy: an operator resets, and sees, only
 * the accounts of the academy they are working in. The reset is written to
 * the audit log of every academy the account belongs to.
 */
final class MfaOperatorResetAcademyTest extends WP_UnitTestCase {

    private MfaSecretsRepository $repo;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        $this->repo = new MfaSecretsRepository();
        $this->flush();
    }

    public function tear_down(): void {
        unset( $_POST['target_user_id'], $_POST['confirm'], $_REQUEST['tt_mfa_nonce'], $_POST['tt_mfa_nonce'] );
        remove_all_filters( 'wp_redirect' );
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    public function test_an_operator_of_another_academy_is_refused_and_the_enrolment_stays(): void {
        $target   = $this->enrolledMemberOf( [ 1 ] );
        $operator = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( user_can( $operator, 'tt_edit_settings' ), 'precondition: the operator may reset in their own academy' );

        $refused = ClubIteration::withClub( 2, function () use ( $operator, $target ): ?int {
            try {
                $this->submitReset( $operator, $target );
            } catch ( WPDieException $e ) {
                return 403;
            }
            return null;
        } );

        $this->assertSame( 403, $refused, 'refused in an academy the account does not belong to' );
        $this->assertTrue( $this->repo->isEnrolled( $target ), 'the enrolment survives' );
        $this->assertSame( 0, $this->auditRows( $target ), 'and nothing is logged as reset' );
    }

    public function test_an_operator_of_the_accounts_academy_resets_it_and_every_academy_logs_it(): void {
        $target   = $this->enrolledMemberOf( [ 1, 3 ] );
        $operator = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        add_filter( 'wp_redirect', static function (): string {
            throw new \RuntimeException( 'redirected' );
        } );

        $done = ClubIteration::withClub( 1, function () use ( $operator, $target ): string {
            try {
                $this->submitReset( $operator, $target );
            } catch ( \RuntimeException $e ) {
                return $e->getMessage();
            }
            return '';
        } );

        $this->assertSame( 'redirected', $done );
        $this->assertFalse( $this->repo->isEnrolled( $target ), 'the enrolment is reset' );
        global $wpdb;
        $clubs = $wpdb->get_col( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_id = %d ORDER BY club_id ASC",
            'mfa.disabled', $target
        ) );
        $this->assertSame( [ '1', '3' ], array_values( (array) $clubs ), 'one audit row in each academy of the account' );
    }

    public function test_the_list_holds_only_the_academys_enrolled_accounts(): void {
        $here      = $this->enrolledMemberOf( [ 1 ] );
        $elsewhere = $this->enrolledMemberOf( [ 2 ] );

        $listed = array_column( $this->repo->enrolledAccountsGovernedBy( 1 ), 'wp_user_id' );
        $this->assertContains( $here, $listed, 'precondition: the academy\'s own enrolled account is listed' );
        $this->assertNotContains( $elsewhere, $listed, 'another academy\'s enrolled account is not listed' );

        $this->assertContains( $elsewhere, array_column( $this->repo->enrolledAccountsGovernedBy( 2 ), 'wp_user_id' ), 'and it is listed in its own academy' );
    }

    /**
     * An enrolled account with a staff record in each of these academies.
     *
     * @param list<int> $clubs
     */
    private function enrolledMemberOf( array $clubs ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create();
        foreach ( $clubs as $club ) {
            $wpdb->insert( "{$wpdb->prefix}tt_people", [
                'club_id' => $club, 'first_name' => 'Reset', 'last_name' => 'Member' . $user, 'wp_user_id' => $user, 'status' => 'active',
            ] );
        }
        ClubIteration::withClub( $clubs[0], function () use ( $user ): void {
            $this->repo->upsertSecret( $user, TotpService::generateSecret(), [] );
            $this->repo->markEnrolled( $user );
        } );
        $this->flush();
        $this->assertTrue( $this->repo->isEnrolled( $user ), 'fixture: enrolled' );
        return $user;
    }

    private function submitReset( int $operator, int $target ): void {
        wp_set_current_user( $operator );
        $_POST['target_user_id']  = (string) $target;
        $_POST['confirm']         = 'yes';
        $_REQUEST['tt_mfa_nonce'] = wp_create_nonce( MfaActionHandlers::ACTION_OPERATOR_DISABLE );
        MfaActionHandlers::handleOperatorDisable();
    }

    private function auditRows( int $target ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_id = %d",
            'mfa.disabled', $target
        ) );
    }

    private function flush(): void {
        ClubMembership::flush();
        AuthorizationService::flushCache();
    }
}
