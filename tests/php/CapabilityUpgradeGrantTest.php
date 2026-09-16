<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Comms\Send\SafeguardingBroadcastSender;

/**
 * #3432 — a capability declared in a release must reach the roles that hold
 * it without anybody opening wp-admin.
 *
 * The re-assert used to hang off `admin_init`. That was invisible to this
 * suite for the most literal reason available: the wp-env bootstrap never
 * fires `admin_init`, and five existing test classes work around the
 * consequence by calling `installRoles()` in their own `set_up()` — see the
 * comments in RestSmokeTest, RecycleBinPreviewPermissionTest and the three
 * measurement/methodology REST tests, each of which says so out loud. Every
 * one of them was describing this bug and none of them failed on it.
 *
 * So these tests never call `installRoles()` to arrange, and assert that
 * `admin_init` did not fire while they ran. The arrange step is a *removal*:
 * put an install back into the state an upgrade leaves it in — the version
 * stamp behind, the capability absent — and assert the boot-path sync closes
 * it on its own.
 *
 * Both directions are asserted, because a re-grant that overreached would be
 * the worse bug: a grant an operator withdrew through the authorization
 * matrix must stay withdrawn.
 */
final class CapabilityUpgradeGrantTest extends WP_UnitTestCase {

    /** The capability that exposed the bug on a live install (#3423). */
    private const CAP = SafeguardingBroadcastSender::CAP;

    /** Roles the #3423 design says hold it. */
    private const HOLDERS = [ 'administrator', 'tt_club_admin' ];

    /* ---- the regression -------------------------------------------------- */

    /**
     * The state the bug actually produces on a frontend-only install: the
     * plugin version moved, nothing reasserted the shape, and the role the
     * product names as a safeguarding-broadcast sender does not hold the
     * capability that permits one.
     */
    public function test_an_upgrade_grants_a_new_capability_without_admin_init(): void {
        $this->simulateUpgradeFrom( '4.120.0' );

        foreach ( self::HOLDERS as $slug ) {
            $this->assertNotContains(
                self::CAP,
                $this->storedCapsFor( $slug ),
                "arrange: {$slug} must start without the capability"
            );
        }

        $admin_init_before = did_action( 'admin_init' );

        $this->assertTrue(
            ( new RolesService() )->syncForVersion( TT_VERSION ),
            'a version change must assert the role + capability shape'
        );

        foreach ( self::HOLDERS as $slug ) {
            $this->assertContains(
                self::CAP,
                $this->storedCapsFor( $slug ),
                "{$slug} must hold the capability after the upgrade, with no wp-admin visit"
            );
        }

        $this->assertSame(
            $admin_init_before,
            did_action( 'admin_init' ),
            'the grant must not depend on admin_init — that dependency is the bug'
        );
    }

    /**
     * The user-level consequence, which is what the operator reported:
     * `user_can()` returned false for the capability. Asserted on an Academy
     * Admin rather than a WordPress administrator, because an administrator
     * bypasses every `tt_*` check in LegacyCapMapper and would pass whether
     * the grant landed or not.
     */
    public function test_an_academy_admin_can_send_after_the_upgrade(): void {
        $this->simulateUpgradeFrom( '4.120.0' );
        ( new RolesService() )->syncForVersion( TT_VERSION );

        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );

        $this->assertTrue(
            user_can( $uid, self::CAP ),
            'an academy admin must be able to send a safeguarding broadcast after the upgrade'
        );
    }

    /**
     * The Kernel is wired to the version change, not to `admin_init`. The
     * bootstrap boots the kernel and nothing else; if the stamp is present
     * here, the boot path did the work on a request that was never an admin
     * request.
     */
    public function test_the_boot_path_stamped_the_capability_version(): void {
        $this->assertSame(
            0,
            did_action( 'admin_init' ),
            'the suite must not fire admin_init — that is what makes this test mean anything'
        );
        $this->assertSame(
            TT_VERSION,
            (string) get_option( RolesService::CAPABILITY_VERSION_OPTION, '' ),
            'Kernel::boot() must assert the capability shape on the version change'
        );
        foreach ( self::HOLDERS as $slug ) {
            $this->assertContains( self::CAP, $this->storedCapsFor( $slug ) );
        }
    }

    /** Idempotent: a stamped version does no work and reports so. */
    public function test_it_does_nothing_once_the_version_is_stamped(): void {
        update_option( RolesService::CAPABILITY_VERSION_OPTION, TT_VERSION );

        $this->assertFalse(
            ( new RolesService() )->syncForVersion( TT_VERSION ),
            'a stamped version must not repeat install-time work on every request'
        );
        $this->assertTrue(
            ( new RolesService() )->syncForVersion( TT_VERSION, true ),
            'activation and the operator-triggered re-run force the work regardless'
        );
    }

    /* ---- the other direction --------------------------------------------- */

    /**
     * A grant the operator withdrew through the authorization matrix stays
     * withdrawn across an upgrade. The matrix is a separate store and the
     * sync never writes it; this holds that separation to account, because a
     * "catch-up" that quietly became a reset would hand back access somebody
     * deliberately took away.
     */
    public function test_a_grant_withdrawn_through_the_matrix_is_not_restored(): void {
        $repo    = new MatrixRepository();
        $persona = 'tt_test_persona_3432';

        $repo->setRow( $persona, 'players', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL, '' );
        $this->assertTrue(
            $repo->lookup( $persona, 'players', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
            'arrange: the grant exists before the operator withdraws it'
        );

        $repo->removeRow( $persona, 'players', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );

        $this->simulateUpgradeFrom( '4.120.0' );
        ( new RolesService() )->syncForVersion( TT_VERSION );

        $this->assertFalse(
            $repo->lookup( $persona, 'players', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
            'an upgrade must not restore a matrix grant the operator removed'
        );
    }

    /**
     * The same property one layer down: the sync only ever adds. A capability
     * an academy granted a role itself — one no role definition mentions — is
     * still there afterwards.
     */
    public function test_it_adds_and_never_strips(): void {
        $role = get_role( 'tt_club_admin' );
        $this->assertNotNull( $role, 'arrange: the academy admin role exists' );
        $role->add_cap( 'tt_test_locally_granted_cap_3432' );

        $this->simulateUpgradeFrom( '4.120.0' );
        ( new RolesService() )->syncForVersion( TT_VERSION );

        $this->assertContains(
            'tt_test_locally_granted_cap_3432',
            $this->storedCapsFor( 'tt_club_admin' ),
            'the re-grant is additive — it must not reset a role to its definition'
        );
    }

    /* ---- helpers ---------------------------------------------------------- */

    /**
     * Put the install back into the state an upgrade leaves behind: an older
     * version stamped, and the capability the new version declares absent
     * from every role that should hold it.
     */
    private function simulateUpgradeFrom( string $previous ): void {
        update_option( RolesService::CAPABILITY_VERSION_OPTION, $previous );
        foreach ( self::HOLDERS as $slug ) {
            $role = get_role( $slug );
            if ( $role ) {
                $role->remove_cap( self::CAP );
            }
        }
    }

    /**
     * Capabilities as persisted for a role, read from the stored option
     * rather than the `WP_Roles` singleton so the assertion is about what an
     * install actually holds.
     *
     * @return list<string>
     */
    private function storedCapsFor( string $slug ): array {
        global $wpdb;
        $stored = get_option( $wpdb->get_blog_prefix() . 'user_roles', [] );
        if ( ! is_array( $stored ) || ! isset( $stored[ $slug ]['capabilities'] ) ) {
            return [];
        }
        $caps = (array) $stored[ $slug ]['capabilities'];
        $held = [];
        foreach ( $caps as $cap => $granted ) {
            if ( $granted ) {
                $held[] = (string) $cap;
            }
        }
        return $held;
    }
}
