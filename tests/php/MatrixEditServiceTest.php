<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixEditService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendMatrixView;

/**
 * #2654 — the escalation guardrail, and what a save writes.
 *
 * The guardrail is the reason this issue could be shipped at all: the
 * matrix decides who sees a minor's medical and safeguarding fields, and
 * delegating it to a non-administrator is only defensible if the parts
 * that decide *who may delegate* stay out of reach.
 *
 * So the tests that matter here are the rejections, and specifically that
 * they happen server-side. A disabled checkbox proves nothing — every
 * assertion below submits the protected cell as if the markup had been
 * edited, which is exactly what an attacker would do.
 */
final class MatrixEditServiceTest extends WP_UnitTestCase {

    /** A row nobody's real matrix depends on, so the writes are isolated. */
    private const ENTITY    = 'players';
    private const PROTECTED = 'authorization_matrix';
    private const PERSONA   = 'scout';

    /**
     * The pairs these tests write to. Cleared before each test and after,
     * because the matrix table is shared and an install may or may not have
     * been seeded — a test that only works against one of those two states
     * is a test that fails on somebody else's machine.
     *
     * @var list<array{0:string,1:string}>
     */
    private const WORKING_PAIRS = [
        [ self::PERSONA, self::ENTITY ],
        [ self::PERSONA, self::PROTECTED ],
        [ 'academy_admin', self::ENTITY ],
        [ 'parent', self::ENTITY ],
    ];

    /**
     * Enough rows that `personas()` and `entities()` know the tuples under
     * test exist. Deliberately on pairs no test writes to, so seeding does
     * not become a starting state a test has to reason about.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const SKELETON = [
        [ self::PERSONA,   'teams',        'TT\\Modules\\Teams\\TeamsModule' ],
        [ 'academy_admin', 'teams',        'TT\\Modules\\Teams\\TeamsModule' ],
        [ 'parent',        'teams',        'TT\\Modules\\Teams\\TeamsModule' ],
        [ 'head_coach',    self::ENTITY,   'TT\\Modules\\Players\\PlayersModule' ],
        [ 'head_coach',    self::PROTECTED,'TT\\Modules\\Authorization\\AuthorizationModule' ],
    ];

    /** The module that owns the entity governing the matrix itself. */
    private const AUTH_MODULE = 'TT\\Modules\\Authorization\\AuthorizationModule';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        FrontendMatrixView::ensureCapabilities();

        $repo = new MatrixRepository();
        foreach ( self::SKELETON as [ $persona, $entity, $module ] ) {
            $repo->setRow( $persona, $entity, 'read', 'global', $module );
        }
        // With the bridge active, `tt_manage_authorization` resolves through
        // `authorization_matrix:change` rather than through the role — which
        // is the point of the mapping. The shipped seed grants academy_admin
        // exactly this; a test database may not have run it, and without the
        // row a club admin is refused by the bridge before any of these
        // assertions get a chance to mean anything.
        $repo->setRow( 'academy_admin', 'authorization_matrix', 'read', 'global', self::AUTH_MODULE );
        $repo->setRow( 'academy_admin', 'authorization_matrix', 'change', 'global', self::AUTH_MODULE );

        $this->clearWorkingPairs();
    }

    public function tear_down(): void {
        $this->clearWorkingPairs();

        $repo = new MatrixRepository();
        foreach ( self::SKELETON as [ $persona, $entity ] ) {
            $repo->removeRow( $persona, $entity, 'read', 'global' );
        }
        $repo->removeRow( 'academy_admin', 'authorization_matrix', 'read', 'global' );
        $repo->removeRow( 'academy_admin', 'authorization_matrix', 'change', 'global' );

        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function clearWorkingPairs(): void {
        $repo = new MatrixRepository();
        foreach ( self::WORKING_PAIRS as [ $persona, $entity ] ) {
            foreach ( MatrixEditService::ACTIVITIES as $activity ) {
                foreach ( MatrixEditService::SCOPE_KINDS as $scope ) {
                    $repo->removeRow( $persona, $entity, $activity, $scope );
                }
            }
        }
        MatrixRepository::clearCache();
    }

    private function administrator(): int {
        return self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    /**
     * A club admin: holds the capability, does NOT hold `manage_options`.
     * That second half is what makes them restricted, so it is asserted
     * here rather than assumed.
     *
     * The capability is granted on the user rather than left to the role,
     * because how a role acquires it is production wiring with its own test
     * below — and a behavioural test that fails because a role definition
     * did not survive a test-database rollback tells you nothing about the
     * guardrail it was written to check.
     */
    private function clubAdmin(): int {
        $uid  = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $user = new \WP_User( $uid );
        $user->add_cap( FrontendMatrixView::CAP );

        $this->assertFalse( user_can( $uid, 'manage_options' ), 'precondition: a club admin is not an administrator' );

        return $uid;
    }

    private function changelogCount(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_changelog" );
    }

    // ---- the guardrail ----------------------------------------------------

    /**
     * A protected entity is protected on the way in, not merely on screen.
     * This submits the cell exactly as a hand-crafted POST would.
     */
    public function test_a_club_admin_cannot_grant_on_a_protected_entity(): void {
        $uid    = $this->clubAdmin();
        $before = $this->changelogCount();

        $summary = ( new MatrixEditService() )->applyGrid(
            [ self::PERSONA . '|' . self::PROTECTED . '|change' => '1' ],
            [ self::PERSONA . '|' . self::PROTECTED             => 'global' ],
            $uid
        );

        $this->assertSame( 1, $summary['rejected'] );
        $this->assertSame( 0, $summary['grants'] );
        $this->assertFalse(
            ( new MatrixRepository() )->lookup( self::PERSONA, self::PROTECTED, 'change', 'global' ),
            'a protected cell was written despite being rejected'
        );
        $this->assertSame( $before, $this->changelogCount(), 'a rejected cell must not leave an audit row' );
    }

    /** No self-escalation: the club admin's own persona row is off limits. */
    public function test_a_club_admin_cannot_edit_their_own_persona(): void {
        $uid = $this->clubAdmin();

        $summary = ( new MatrixEditService() )->applyGrid(
            [ 'academy_admin|' . self::ENTITY . '|create_delete' => '1' ],
            [ 'academy_admin|' . self::ENTITY                    => 'global' ],
            $uid
        );

        $this->assertSame( 1, $summary['rejected'] );
        $this->assertFalse(
            ( new MatrixRepository() )->lookup( 'academy_admin', self::ENTITY, 'create_delete', 'global' )
        );
    }

    /** Everything else is theirs to fix — that is the point of the issue. */
    public function test_a_club_admin_can_edit_an_ordinary_cell(): void {
        $uid = $this->clubAdmin();

        $summary = ( new MatrixEditService() )->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|change' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY             => 'global' ],
            $uid
        );

        $this->assertSame( 0, $summary['rejected'] );
        $this->assertSame( 1, $summary['grants'] );
        $this->assertTrue(
            ( new MatrixRepository() )->lookup( self::PERSONA, self::ENTITY, 'change', 'global' )
        );
    }

    /** An administrator is the recovery path, so nothing is locked for them. */
    public function test_an_administrator_may_edit_a_protected_cell(): void {
        $uid = $this->administrator();

        $editable = MatrixEditService::editableFor( $uid );
        $this->assertTrue( $editable['unrestricted'] );
        $this->assertSame( [], $editable['protected_entities'] );

        $summary = ( new MatrixEditService() )->applyGrid(
            [ self::PERSONA . '|' . self::PROTECTED . '|read' => '1' ],
            [ self::PERSONA . '|' . self::PROTECTED           => 'global' ],
            $uid
        );

        $this->assertSame( 0, $summary['rejected'] );
        $this->assertSame( 1, $summary['grants'] );
    }

    public function test_the_editable_mask_names_what_a_club_admin_cannot_touch(): void {
        $editable = MatrixEditService::editableFor( $this->clubAdmin() );

        $this->assertFalse( $editable['unrestricted'] );
        $this->assertContains( 'authorization_matrix', $editable['protected_entities'] );
        $this->assertContains( 'academy_admin', $editable['protected_personas'] );
    }

    // ---- what a save writes ------------------------------------------------

    /** A revoke is an audit row too, or the log only records generosity. */
    public function test_a_revoke_is_written_and_audited(): void {
        $uid  = $this->administrator();
        $svc  = new MatrixEditService();
        $repo = new MatrixRepository();

        $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'global' ],
            $uid
        );
        $this->assertTrue( $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'global' ) );

        $summary = $svc->applyGrid(
            [],
            [ self::PERSONA . '|' . self::ENTITY => 'global' ],
            $uid
        );

        $this->assertSame( 1, $summary['revokes'] );
        $this->assertFalse( $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'global' ) );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT change_type, actor_user_id FROM {$wpdb->prefix}tt_authorization_changelog
             WHERE persona = %s AND entity = %s AND activity = %s ORDER BY id DESC LIMIT 1",
            self::PERSONA, self::ENTITY, 'read'
        ) );
        $this->assertSame( 'revoke', (string) $row->change_type );
        $this->assertSame( $uid, (int) $row->actor_user_id );
    }

    /** Moving a grant between scopes is a move, not a second grant. */
    public function test_a_scope_change_is_recorded_as_one(): void {
        $uid = $this->administrator();
        $svc = new MatrixEditService();

        $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'global' ],
            $uid
        );

        $summary = $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'team' ],
            $uid
        );

        $this->assertSame( 1, $summary['scope_changes'] );
        $this->assertSame( 0, $summary['grants'] );

        $repo = new MatrixRepository();
        $this->assertTrue( $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'team' ) );
        $this->assertFalse(
            $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'global' ),
            'the old scope row survived the move, so the grant now exists twice'
        );
    }

    /**
     * The keys of `scopes` declare coverage. Without that, a REST client
     * sending one cell would revoke every cell it did not mention — which
     * is how a partial payload becomes a lock-out.
     */
    public function test_a_pair_absent_from_scopes_is_left_alone(): void {
        $uid  = $this->administrator();
        $svc  = new MatrixEditService();
        $repo = new MatrixRepository();

        $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'global' ],
            $uid
        );

        // A payload about a completely different pair.
        $svc->applyGrid(
            [ 'parent|' . self::ENTITY . '|read' => '1' ],
            [ 'parent|' . self::ENTITY           => 'global' ],
            $uid
        );

        $this->assertTrue(
            $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'global' ),
            'an untouched pair was revoked by a payload that never mentioned it'
        );
    }

    /**
     * A JSON client revokes by sending `false`, not by omitting the key.
     * Reading a present-but-false cell as granted would turn every revoke
     * a REST consumer sends into a grant.
     */
    public function test_a_falsy_cell_value_means_revoked(): void {
        $uid  = $this->administrator();
        $svc  = new MatrixEditService();
        $repo = new MatrixRepository();

        $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '1' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'global' ],
            $uid
        );

        $summary = $svc->applyGrid(
            [ self::PERSONA . '|' . self::ENTITY . '|read' => '' ],
            [ self::PERSONA . '|' . self::ENTITY           => 'global' ],
            $uid
        );

        $this->assertSame( 1, $summary['revokes'] );
        $this->assertFalse( $repo->lookup( self::PERSONA, self::ENTITY, 'read', 'global' ) );
    }

    // ---- the capability ----------------------------------------------------

    /**
     * The production wiring: the capability is granted to the two roles that
     * should hold it, and to nothing else. Asserted on the role objects
     * rather than through a user, so it tests the grant rather than a
     * particular user's cap resolution.
     */
    public function test_the_capability_is_granted_to_the_right_roles(): void {
        FrontendMatrixView::ensureCapabilities();

        $administrator = get_role( 'administrator' );
        $this->assertNotNull( $administrator );
        $this->assertTrue( $administrator->has_cap( FrontendMatrixView::CAP ) );

        $club_admin = get_role( 'tt_club_admin' );
        $this->assertNotNull( $club_admin, 'the club-admin role is what this issue delegates to' );
        $this->assertTrue( $club_admin->has_cap( FrontendMatrixView::CAP ) );

        $coach = get_role( 'tt_coach' );
        $this->assertNotNull( $coach );
        $this->assertFalse(
            $coach->has_cap( FrontendMatrixView::CAP ),
            'a coach must not be able to redefine what every persona can do'
        );
    }

    public function test_the_view_refuses_without_the_capability(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        wp_set_current_user( $uid );

        ob_start();
        FrontendMatrixView::render( $uid, false );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( 'tt-matrix-table', $html );
        $this->assertStringContainsString( 'tt-notice', $html, 'the refusal must say something' );
    }

    /**
     * The locked cells are disabled in the markup as well. Not the control —
     * the service is — but a screen that lets a club admin tick a box only
     * to silently drop it on save is its own kind of broken.
     */
    public function test_the_grid_disables_the_cells_it_will_reject(): void {
        $uid = $this->clubAdmin();
        wp_set_current_user( $uid );

        ob_start();
        FrontendMatrixView::render( $uid, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-matrix-table', $html, 'precondition: the grid rendered' );
        $this->assertStringContainsString( 'is-locked', $html );
        $this->assertMatchesRegularExpression(
            '/name="cell\[academy_admin\|[a-z_]+\|read\]"[^>]*disabled/',
            $html,
            'the club admin\'s own persona column is editable in the markup'
        );
    }
}
