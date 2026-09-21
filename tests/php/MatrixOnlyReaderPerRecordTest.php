<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #3644 — two authorities, one question, two answers.
 *
 * The read-only observer could pull every team's evaluations into a
 * spreadsheet and was refused the same data one player at a time. The
 * export gates on the raw view capability, which the matrix bridge
 * answers; the per-player route goes through
 * `AuthorizationService::canViewPlayer()` → `userHasPermission()`, which
 * read `tt_user_role_scopes`, the functional-role mapping and the derived
 * player/parent links — and never the matrix.
 *
 * A persona granted purely through `config/authorization_seed.php` has
 * none of those sources, so it resolved to nothing. That is the class of
 * defect, not one seat, which is why the scout below is asserted
 * alongside the observer: neither has a role-scope row, both hold a
 * global matrix read, and before this fix both were refused.
 */
final class MatrixOnlyReaderPerRecordTest extends WP_UnitTestCase {

    private int $playerId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Observer O14-1', 'age_group' => 'U14' ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Milan',
            'last_name'  => 'De Groot',
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        $this->playerId = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * A WordPress user holding only the role — no `tt_people` row, no
     * `tt_user_role_scopes` row, no functional role. Their entire grant
     * is the persona's matrix rows, which is the shape this fix is about.
     */
    private function makeMatrixOnlyUser( string $wp_role, string $expected_persona ): int {
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );

        $this->assertContains(
            $expected_persona,
            PersonaResolver::personasFor( $uid ),
            "{$wp_role} must resolve to {$expected_persona} or nothing below means anything"
        );
        $this->assertSame(
            [],
            AuthorizationService::getResolvedScopesForUser( $uid ),
            'the fixture must have no legacy scopes — that is the condition under test'
        );

        AuthorizationService::flushCache();
        return $uid;
    }

    // ── the reported seat ──────────────────────────────────────────────

    public function test_the_read_only_observer_can_view_any_player(): void {
        $observer = $this->makeMatrixOnlyUser( 'tt_readonly_observer', 'readonly_observer' );

        $this->assertTrue( AuthorizationService::canViewPlayer( $observer, $this->playerId ) );
    }

    public function test_the_observer_reaches_a_single_players_evaluations_over_rest(): void {
        $observer = $this->makeMatrixOnlyUser( 'tt_readonly_observer', 'readonly_observer' );
        wp_set_current_user( $observer );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->playerId . '/evaluations' );
        $request->set_param( 'id', $this->playerId );

        $this->assertTrue(
            \TT\Infrastructure\REST\PlayerEvaluationsRestController::can_read( $request ),
            'the route that answered 403 while the bulk export of the same data answered 200'
        );
    }

    // ── the class, not the seat ────────────────────────────────────────

    public function test_a_non_observer_matrix_only_reader_resolves_too(): void {
        // The scout, like the observer, typically has no role-scope row and
        // is granted only through the seed — which is the property this
        // asserts. Since #3807 that seeded grant is `players [r, player]`
        // rather than `[r, global]`, so the scope has to be held for the
        // resolution to reach anything: the link is the scope.
        $scout = $this->makeMatrixOnlyUser( 'tt_scout', 'scout' );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->playerId ] ) );
        AuthorizationService::flushCache();

        $this->assertTrue(
            AuthorizationService::canViewPlayer( $scout, $this->playerId ),
            'the fix has to reach every persona granted only through the seed'
        );
    }

    /**
     * #3807 — and the same reader, without the link, is refused. Before the
     * scope narrowing this could not be asserted: the scout held the player
     * entity globally, so there was no player they could not read.
     */
    public function test_a_matrix_only_reader_without_the_scope_is_refused(): void {
        $scout = $this->makeMatrixOnlyUser( 'tt_scout', 'scout' );
        AuthorizationService::flushCache();

        $this->assertFalse(
            AuthorizationService::canViewPlayer( $scout, $this->playerId ),
            'a seeded grant at player scope is not a global one'
        );
    }

    // ── and no blanket widening ────────────────────────────────────────

    public function test_a_user_with_neither_a_matrix_row_nor_a_scope_row_is_still_refused(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        AuthorizationService::flushCache();

        $this->assertFalse( AuthorizationService::canViewPlayer( $nobody, $this->playerId ) );
    }

    public function test_the_observer_gains_no_write_from_this(): void {
        $observer = $this->makeMatrixOnlyUser( 'tt_readonly_observer', 'readonly_observer' );

        $this->assertFalse(
            AuthorizationService::canEditPlayer( $observer, $this->playerId ),
            'the map is read-only on purpose; a write bridged here would write to a child\'s record'
        );
        $this->assertFalse( AuthorizationService::canEvaluatePlayer( $observer, $this->playerId ) );
        $this->assertFalse( AuthorizationService::canManageTeam( $observer, 1 ) );
    }

    public function test_an_unmapped_permission_is_unchanged(): void {
        $observer = $this->makeMatrixOnlyUser( 'tt_readonly_observer', 'readonly_observer' );

        $this->assertFalse(
            AuthorizationService::userHasPermission( $observer, 'people.manage' ),
            'a permission absent from the map is answered by the scope sources alone'
        );
    }
}
