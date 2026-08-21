<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * #2591 (epic #2589) — who may see a photograph of a child.
 *
 * These assertions are the feature's privacy contract, so they are written
 * against the real decision chain — WP user → persona → matrix grant →
 * runtime scope — rather than against a stubbed gate. A mocked
 * `MatrixGate` would pass while the actual seeded grants leaked.
 *
 * The co-depiction case (D5) is pinned deliberately: it is a decision, not
 * an accident, and a future reader who assumes it is a bug should find a
 * test telling them otherwise before they "fix" it.
 */
final class MediaVisibilityTest extends WP_UnitTestCase {

    private const ENTITY = 'media';

    /** @var list<array{0:string,1:string,2:string}> persona/activity/scope rows written here */
    private $granted = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        foreach ( $this->granted as [ $persona, $activity, $scope ] ) {
            $repo->removeRow( $persona, self::ENTITY, $activity, $scope );
        }
        $this->granted = [];
        MatrixRepository::clearCache();
        MediaVisibilityService::flush();
        parent::tear_down();
    }

    // ── staff, team scope ──────────────────────────────────────────────

    public function test_coach_sees_media_of_a_player_on_their_own_team(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team   = 5101;
        $coach  = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', $team );
        $player = $this->makePlayer( $team );

        $media = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::PLAYER, $player );

        $this->assertTrue(
            ( new MediaVisibilityService() )->canView( $coach, $media ),
            'a coach is neither the player nor its parent, so the player link must also resolve through the team'
        );
    }

    public function test_coach_cannot_see_media_of_a_player_on_another_team(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $coach  = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', 5201 );
        $player = $this->makePlayer( 5202 );

        $media = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::PLAYER, $player );

        $this->assertFalse( ( new MediaVisibilityService() )->canView( $coach, $media ) );
    }

    public function test_media_on_an_activity_resolves_through_that_activity_team(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team     = 5301;
        $coach    = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', $team );
        $activity = $this->makeActivity( $team );
        $other    = $this->makeActivity( 5302 );

        $mine = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $mine->id, MediaEntityType::ACTIVITY, $activity );

        $theirs = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $theirs->id, MediaEntityType::ACTIVITY, $other );

        $svc = new MediaVisibilityService();
        $this->assertTrue( $svc->canView( $coach, $mine ) );
        $this->assertFalse( $svc->canView( $coach, $theirs ) );
    }

    // ── family, player scope ───────────────────────────────────────────

    public function test_parent_sees_their_own_child_and_not_another(): void {
        $this->grant( 'parent', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );

        $mine     = $this->makePlayer( 5401 );
        $stranger = $this->makePlayer( 5401 );
        $parent   = $this->makeParentOf( $mine );

        $ours = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $ours->id, MediaEntityType::PLAYER, $mine );

        $theirs = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $theirs->id, MediaEntityType::PLAYER, $stranger );

        $svc = new MediaVisibilityService();
        $this->assertTrue( $svc->canView( $parent, $ours ) );
        $this->assertFalse(
            $svc->canView( $parent, $theirs ),
            'a parent on the same team must still not reach another family\'s photographs'
        );
    }

    /**
     * Epic #2589 decision D5. A clip showing three children is visible to
     * all three families — team sport is photographed in groups, and the
     * alternative would hide nearly every training photo from everyone.
     * The docs state this so consent wording can match it.
     *
     * If this test ever fails, the fix is a product decision, not a code
     * change.
     */
    public function test_co_depiction_is_visible_to_every_linked_family(): void {
        $this->grant( 'parent', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );

        $team = 5501;
        $a    = $this->makePlayer( $team );
        $b    = $this->makePlayer( $team );
        $c    = $this->makePlayer( $team );

        $parent_a = $this->makeParentOf( $a );
        $parent_b = $this->makeParentOf( $b );

        $clip  = $this->makeMedia();
        $links = new MediaLinksRepository();
        foreach ( [ $a, $b, $c ] as $player ) {
            $links->link( $clip->id, MediaEntityType::PLAYER, $player );
        }

        $svc = new MediaVisibilityService();
        $this->assertTrue( $svc->canView( $parent_a, $clip ) );
        $this->assertTrue( $svc->canView( $parent_b, $clip ) );
    }

    public function test_player_sees_their_own_media(): void {
        $this->grant( 'player', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );

        $uid    = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player = $this->makePlayer( 5601, $uid );

        $media = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::PLAYER, $player );

        $this->assertTrue( ( new MediaVisibilityService() )->canView( $uid, $media ) );
    }

    // ── activity verbs ─────────────────────────────────────────────────

    /**
     * A read-only persona — the shape `team_manager` and `scout` hold —
     * must not gain upload or delete from its read grant.
     *
     * Exercised through `head_coach` rather than `team_manager` because
     * the `tt_team_manager` WP role does not exist yet (PersonaResolver
     * maps it, but RolesService does not install it until its own sprint),
     * so a team-manager user would resolve to no persona and this would
     * pass for the wrong reason. The grant under test is written by the
     * test itself; the real seeded scopes are covered by
     * `test_seeded_grants_match_the_intended_matrix`.
     */
    public function test_read_grant_alone_does_not_permit_upload_or_delete(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team   = 5701;
        $viewer = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', $team );

        $media = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::TEAM, $team );

        $svc = new MediaVisibilityService();
        $this->assertTrue( $svc->canView( $viewer, $media ) );
        $this->assertFalse( $svc->canEdit( $viewer, $media ) );
        $this->assertFalse( $svc->canDelete( $viewer, $media ) );
        $this->assertFalse( $svc->canAttachTo( $viewer, MediaEntityType::TEAM, $team ) );
    }

    public function test_global_grant_short_circuits_without_links(): void {
        $this->grant( 'academy_admin', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );

        $uid = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $this->assertContains( 'academy_admin', PersonaResolver::effectivePersonas( $uid ) );

        $media = $this->makeMedia();

        $this->assertTrue( ( new MediaVisibilityService() )->canView( $uid, $media ) );
    }

    public function test_media_with_no_links_is_refused_below_global_scope(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $coach = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', 5801 );
        $media = $this->makeMedia();

        $this->assertFalse(
            ( new MediaVisibilityService() )->canView( $coach, $media ),
            'nothing owns a link-less item, so nothing below global scope has a route to it'
        );
    }

    public function test_a_user_with_no_grant_at_all_sees_nothing(): void {
        $uid    = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $media  = $this->makeMedia();
        ( new MediaLinksRepository() )->link( $media->id, MediaEntityType::TEAM, 5901 );

        $this->assertFalse( ( new MediaVisibilityService() )->canView( $uid, $media ) );
    }

    // ── list filtering ─────────────────────────────────────────────────

    public function test_filter_visible_keeps_only_reachable_items(): void {
        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 6001;
        $coach = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', $team );
        $links = new MediaLinksRepository();

        $ours = $this->makeMedia();
        $links->link( $ours->id, MediaEntityType::TEAM, $team );

        $theirs = $this->makeMedia();
        $links->link( $theirs->id, MediaEntityType::TEAM, 6002 );

        $orphan = $this->makeMedia();

        $kept = ( new MediaVisibilityService() )->filterVisible( $coach, [ $ours, $theirs, $orphan ] );

        $this->assertSame( [ (int) $ours->id ], array_map( static function ( $m ) { return (int) $m->id; }, $kept ) );
    }

    /**
     * The reason `filterVisible()` exists at all. A gallery of 60 items
     * must not issue 60 permission checks that each re-resolve the same
     * team assignments.
     */
    public function test_filter_visible_does_not_scale_queries_with_list_length(): void {
        global $wpdb;

        $this->grant( 'head_coach', MatrixGate::READ, MatrixGate::SCOPE_TEAM );

        $team  = 6101;
        $coach = $this->makeStaffScopedToTeam( 'tt_coach', 'head_coach', $team );
        $links = new MediaLinksRepository();

        $small = [];
        for ( $i = 0; $i < 3; $i++ ) {
            $m = $this->makeMedia();
            $links->link( $m->id, MediaEntityType::TEAM, $team );
            $small[] = $m;
        }
        $large = $small;
        for ( $i = 0; $i < 27; $i++ ) {
            $m = $this->makeMedia();
            $links->link( $m->id, MediaEntityType::TEAM, $team );
            $large[] = $m;
        }

        $svc = new MediaVisibilityService();

        MediaVisibilityService::flush();
        $before = $wpdb->num_queries;
        $svc->filterVisible( $coach, $small );
        $cost_small = $wpdb->num_queries - $before;

        MediaVisibilityService::flush();
        $before = $wpdb->num_queries;
        $svc->filterVisible( $coach, $large );
        $cost_large = $wpdb->num_queries - $before;

        $this->assertSame( 3, count( $small ) );
        $this->assertSame( 30, count( $large ) );
        $this->assertSame(
            $cost_small,
            $cost_large,
            "filtering 30 items cost {$cost_large} queries vs {$cost_small} for 3 — the batch lookup has regressed to per-item"
        );
    }

    // ── the seed itself ────────────────────────────────────────────────

    /**
     * Guards the grants rather than the gate. A well-meaning edit that
     * widens the scout to global read, or hands a team manager delete,
     * would otherwise pass every behavioural test above.
     */
    public function test_seeded_grants_match_the_intended_matrix(): void {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';

        $media = [];
        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== self::ENTITY ) continue;
            $media[ $row['persona'] ][ $row['scope_kind'] ][] = $row['activity'];
        }

        $expected = [
            'player'              => [ 'self'   => [ 'read' ] ],
            'parent'              => [ 'player' => [ 'read' ] ],
            'scout'               => [ 'player' => [ 'read' ] ],
            'team_manager'        => [ 'team'   => [ 'read' ] ],
            'assistant_coach'     => [ 'team'   => [ 'change', 'create_delete', 'read' ] ],
            'head_coach'          => [ 'team'   => [ 'change', 'create_delete', 'read' ] ],
            'head_of_development' => [ 'global' => [ 'change', 'create_delete', 'read' ] ],
            'academy_admin'       => [ 'global' => [ 'change', 'create_delete', 'read' ] ],
        ];

        $this->assertSame(
            array_keys( $expected ),
            array_keys( $media ),
            'exactly these eight personas hold a media grant, in this order'
        );

        foreach ( $expected as $persona => $scopes ) {
            $this->assertSame(
                array_keys( $scopes ),
                array_keys( $media[ $persona ] ),
                "{$persona} must hold media at exactly one scope"
            );
            foreach ( $scopes as $scope => $activities ) {
                $have = $media[ $persona ][ $scope ];
                sort( $have );
                $this->assertSame( $activities, $have, "{$persona} at {$scope} scope" );
            }
        }
    }

    public function test_caps_bridge_to_the_media_entity(): void {
        $mapper = new \ReflectionClass( \TT\Modules\Authorization\LegacyCapMapper::class );
        $map    = $mapper->getConstant( 'MAPPING' );

        $this->assertSame( [ 'media', 'read' ], $map['tt_view_media'] ?? null );
        $this->assertSame( [ 'media', 'change' ], $map['tt_edit_media'] ?? null );
        $this->assertSame(
            [ 'media', 'create_delete' ],
            $map['tt_manage_media'] ?? null,
            'upload is a create, and the matrix carries create under create_delete'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function grant( string $persona, string $activity, string $scope ): void {
        ( new MatrixRepository() )->setRow( $persona, self::ENTITY, $activity, $scope, '' );
        $this->granted[] = [ $persona, $activity, $scope ];
        MatrixRepository::clearCache();
    }

    private function makeMedia(): object {
        $id = ( new MediaRepository() )->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => 'Clip',
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
        ] );
        $this->assertGreaterThan( 0, $id );
        return ( new MediaRepository() )->find( $id );
    }

    private function makePlayer( int $team_id, int $wp_user_id = 0 ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
            'wp_user_id' => $wp_user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'      => 1,
            'title'        => 'Training',
            'team_id'      => $team_id,
            // NOT NULL with no default — omitting it fails under strict mode.
            'session_date' => '2026-08-14',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function makeParentOf( int $player_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'player_id'      => $player_id,
            'parent_user_id' => $uid,
        ] );
        return $uid;
    }

    /** A staff WP user with a tt_people row and a team scope assignment. */
    private function makeStaffScopedToTeam( string $wp_role, string $persona, int $team_id ): int {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        $this->assertContains(
            $persona,
            PersonaResolver::effectivePersonas( $uid ),
            "a {$wp_role} user must resolve to the {$persona} persona for this test to mean anything"
        );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Staff',
            'role_type'  => $persona,
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        return $uid;
    }
}
