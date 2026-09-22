<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Frontend\FrontendEvaluationsView;

/**
 * #3987 — the archived branch of the evaluation detail follows the same
 * per-player check as the live branch. An archived evaluation of a player
 * the viewer may not read is answered as not found; one the viewer may read
 * renders the archived record.
 */
final class ArchivedEvaluationDetailCheckTest extends WP_UnitTestCase {

    private int $admin   = 0;
    private int $teamA   = 0;
    private int $teamB   = 0;
    private int $playerA = 0;
    private int $playerB = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Archived A' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Archived B' ] );
        $this->teamB = (int) $wpdb->insert_id;
        $this->playerA = $this->makePlayer( 'Archalpha', $this->teamA );
        $this->playerB = $this->makePlayer( 'Archbravo', $this->teamB );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    public function test_the_archived_detail_follows_the_same_per_player_check_as_the_live_one(): void {
        $evalA = $this->seedArchivedEvaluation( $this->playerA );
        $evalB = $this->seedArchivedEvaluation( $this->playerB );

        $coach = $this->makeHeadCoach( $this->teamA );

        $html = $this->renderDetail( $coach, $evalA );
        $this->assertStringContainsString( 'This record is archived.', $html, 'the grant: the squad coach sees the archived evaluation' );
        $this->assertStringContainsString( 'Archalpha', $html );

        $html = $this->renderDetail( $coach, $evalB );
        $this->assertStringNotContainsString( 'This record is archived.', $html );
        $this->assertStringNotContainsString( 'Archbravo', $html );
        $this->assertStringContainsString( 'That evaluation no longer exists, or you do not have access.', $html, 'answered as not found' );
    }

    // Fixtures

    private function seedArchivedEvaluation( int $player_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $player_id,
            'coach_id'  => $this->admin,
            'eval_date' => '2024-09-01',
        ] );
        $this->assertNotFalse( $ok, 'evaluation insert must succeed' );
        $id = (int) $wpdb->insert_id;

        $archived = ( new ArchiveRepository() )->archive( 'evaluation', [ $id ], $this->admin );
        $this->assertSame( 1, $archived, 'the fixture must archive the evaluation' );
        $resolved = ( new ArchiveRepository() )->findIncludingArchived( 'evaluation', $id );
        $this->assertSame( 'archived', $resolved['state'] ?? null );
        return $id;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Eval',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'evaluations' ), 'the head coach must read their squad, or the refusal is vacuous' );
        return $uid;
    }

    private function renderDetail( int $user_id, int $eval_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET = [ 'tt_view' => 'evaluations', 'id' => (string) $eval_id ];
        ob_start();
        FrontendEvaluationsView::render( $user_id, false );
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }
}
