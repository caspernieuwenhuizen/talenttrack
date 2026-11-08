<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Services\PdpPlanningService;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the goals and PDP blocks: open and completed goals equal the
 * goal reads the player profile and the team monthly report use, and show
 * "now"; PDP conversations equal the planning matrix's row for the team in
 * the current season; each block answers to the read of the route that owns
 * its figure; a scout never gets goals and a family never gets the planning
 * matrix; a coach is refused another team; statements are bounded.
 */
final class ReportStudioGoalsPdpBlocksTest extends WP_UnitTestCase {

    private const FROM = '2026-09-01';
    private const TO   = '2026-09-30';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pb1 = 0;
    private int $season = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Goals U12' );
        $this->team_b  = $this->insertTeam( 'Goals U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna', 9 );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram', 4 );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Cor', 2 );

        // Anna: two open, one completed, one cancelled. Bram: one open.
        $this->goal( $this->pa1, 'Weak foot', 'in_progress' );
        $this->goal( $this->pa1, 'Scanning', 'pending' );
        $this->goal( $this->pa1, 'Heading', 'completed' );
        $this->goal( $this->pa1, 'Long throws', 'cancelled' );
        $this->goal( $this->pa2, 'First touch', 'in_progress' );
        $this->goal( $this->pb1, 'Passing', 'in_progress' );

        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [ 'name' => '2026/27 studio', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $this->season = PdpPlanningService::currentSeasonId();
        // Block 1: Anna held, Bram planned. Block 2: Anna not held.
        $fa = $this->pdpFile( $this->pa1 );
        $fb = $this->pdpFile( $this->pa2 );
        $this->talk( $fa, 1, '2026-09-10 10:00:00', '2026-09-10 10:30:00', null );
        $this->talk( $fb, 1, '2026-09-12 10:00:00', null, null );
        $this->talk( $fa, 2, '2027-01-10 10:00:00', null, '2027-01-11 09:00:00' );
        $this->talk( $this->pdpFile( $this->pb1 ), 1, '2026-09-11 10:00:00', '2026-09-11 10:30:00', null );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_players_goals_equal_the_goal_reads_and_show_now(): void {
        $repo = new GoalsRepository();
        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY ] as $audience ) {
            $open = $this->preview( 'open_goals', $this->player( $this->pa1, $audience ) );
            $done = $this->preview( 'goals_completed', $this->player( $this->pa1, $audience ) );
            $this->assertSame( $repo->countActiveForPlayer( $this->pa1 ), $open['data']['value'], $audience );
            $this->assertSame( $repo->countCompletedForPlayer( $this->pa1 ), $done['data']['value'], $audience );
        }
        // The hand count: two open (a cancelled goal is not open), one completed.
        $this->assertSame( 2, $repo->countActiveForPlayer( $this->pa1 ) );
        $this->assertSame( 1, $repo->countCompletedForPlayer( $this->pa1 ) );

        // "Now": another period gives the same figure.
        $other = $this->preview( 'open_goals', array_merge( $this->player( $this->pa1 ), [ 'from' => '2025-01-01', 'to' => '2025-01-31' ] ) );
        $this->assertSame( 2, $other['data']['value'] );
    }

    public function test_the_teams_open_goals_equal_the_monthly_reports_squad_list(): void {
        $block  = $this->preview( 'open_goals', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'roster' ] );
        $theirs = [];
        foreach ( $report['data']['roster']['rows'] as $r ) $theirs[ (int) $r['player_id'] ] = (int) $r['open_goals'];

        $this->assertSame( [ $this->pa2, $this->pa1 ], array_column( $block['data']['rows'], 'player_id' ), 'shirt order' );
        foreach ( $block['data']['rows'] as $row ) {
            $this->assertSame( $theirs[ $row['player_id'] ], $row['open_goals'] );
        }
        $this->assertSame( 3, $block['data']['total'] );
    }

    public function test_pdp_conversations_equal_the_planning_matrix_row(): void {
        $block  = $this->preview( 'pdp_conversations', $this->team( $this->team_a ) );
        $matrix = PdpPlanningService::buildMatrix( $this->season, [ $this->team_a ] );
        $cells  = $matrix['teams'][ $this->team_a ]['blocks'];

        $this->assertSame( array_keys( $cells ), array_column( $block['data']['rows'], 'block' ) );
        foreach ( $block['data']['rows'] as $row ) {
            $cell = $cells[ $row['block'] ];
            foreach ( [ 'expected', 'planned', 'conducted', 'not_held', 'window_start', 'window_end' ] as $field ) {
                $this->assertSame( $cell[ $field ], $row[ $field ], "{$field} of block {$row['block']}" );
            }
        }
        $this->assertSame( $this->season, $block['data']['season_id'] );

        // The hand count: block 1 one held, one planned and not yet held; block 2 one not held.
        $by_block = array_column( $block['data']['rows'], null, 'block' );
        $this->assertSame( 2, $by_block[1]['expected'] );
        $this->assertSame( 1, $by_block[1]['conducted'] );
        $this->assertSame( 2, $by_block[1]['planned'] );
        $this->assertSame( 1, $by_block[2]['not_held'] );
        $this->assertSame( 0, $by_block[2]['planned'], 'a talk marked not held is not planned' );
    }

    public function test_each_block_answers_to_the_read_of_the_route_that_owns_its_figure(): void {
        // The read-only observer reads reports and goals but not PDP planning.
        $observer = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team_a );

        foreach ( [ $this->hod, $this->manager, $observer ] as $user ) {
            $goals = AuthorizationService::canReadPlayerSection( $user, $this->pa1, 'goals' );
            $out   = BlockEngine::preview( 'open_goals', $this->player( $this->pa1 ), [], $user );
            $this->assertSame( $goals, is_array( $out ), "open goals follow the goals read for user {$user}" );
        }

        $teams = PdpPlanningService::accessibleTeamIds( $observer );
        $this->assertTrue( $teams !== null && ! in_array( $this->team_a, $teams, true ), 'Precondition: no planning read on the team.' );
        $pdp = BlockEngine::preview( 'pdp_conversations', $this->team( $this->team_a ), [], $observer );
        $this->assertInstanceOf( StudioError::class, $pdp, 'the planning matrix for a reader who may not open it' );
        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'team', PlayerReportAudience::INTERNAL, $observer ) ) );
        $this->assertNotContains( 'pdp_conversations', $listed );

        // The edge: the team manager plans PDP talks for their team.
        $this->assertIsArray( BlockEngine::preview( 'pdp_conversations', $this->team( $this->team_a ), [], $this->manager ) );
    }

    public function test_a_scout_never_gets_goals_and_a_family_never_gets_the_planning_matrix(): void {
        foreach ( [ 'open_goals', 'goals_completed' ] as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block );
            $this->assertNotContains( PlayerReportAudience::SCOUT, $block->audiences(), "{$key}: goals are off the scout list" );
            $scout = BlockEngine::preview( $key, $this->player( $this->pa1, PlayerReportAudience::SCOUT ), [], $this->hod );
            $this->assertInstanceOf( StudioError::class, $scout, $key );
            // The family gets the player's own count, nothing by player.
            $family = $this->preview( $key, $this->player( $this->pa1, PlayerReportAudience::FAMILY ) );
            $this->assertArrayNotHasKey( 'rows', $family['data'] );
        }
        $pdp = BlockRegistry::find( 'pdp_conversations' );
        $this->assertNotNull( $pdp );
        $this->assertSame( [ PlayerReportAudience::INTERNAL ], $pdp->audiences() );
        $this->assertSame( [], $pdp->externalKeys() );
        $team_family = BlockEngine::preview( 'open_goals', [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
        $this->assertInstanceOf( StudioError::class, $team_family );
    }

    public function test_a_coach_out_of_scope_is_refused_each_blocks_subject(): void {
        $cases = [
            [ 'open_goals', $this->team( $this->team_b ) ],
            [ 'open_goals', $this->player( $this->pb1 ) ],
            [ 'goals_completed', $this->player( $this->pb1 ) ],
            [ 'pdp_conversations', $this->team( $this->team_b ) ],
        ];
        foreach ( $cases as [ $key, $context ] ) {
            $out = BlockEngine::preview( $key, $context, [], $this->manager );
            $this->assertInstanceOf( StudioError::class, $out, "{$key} on {$context['scope']}" );
            $this->assertSame( 403, $out->status, "{$key} on {$context['scope']}" );
        }
    }

    public function test_each_block_runs_a_bounded_number_of_statements(): void {
        $team   = $this->team( $this->team_a );
        $player = $this->player( $this->pa1 );
        $before = [
            'team_goals' => $this->statementsFor( 'open_goals', $team ),
            'pdp'        => $this->statementsFor( 'pdp_conversations', $team ),
            'open'       => $this->statementsFor( 'open_goals', $player ),
            'done'       => $this->statementsFor( 'goals_completed', $player ),
        ];
        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'Extra' . $i, 20 + $i );
            $this->goal( $pid, 'Goal ' . $i, 'in_progress' );
            $this->talk( $this->pdpFile( $pid ), 1, '2026-09-15 10:00:00', null, null );
            $this->goal( $this->pa1, 'More ' . $i, 'in_progress' );
        }
        $this->assertSame( $before['team_goals'], $this->statementsFor( 'open_goals', $team ) );
        $this->assertSame( $before['pdp'], $this->statementsFor( 'pdp_conversations', $team ) );
        $this->assertSame( $before['open'], $this->statementsFor( 'open_goals', $player ) );
        $this->assertSame( $before['done'], $this->statementsFor( 'goals_completed', $player ) );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function preview( string $key, array $context ): array {
        $out = BlockEngine::preview( $key, $context, [], $this->hod );
        $this->assertIsArray( $out, $key . ( $out instanceof StudioError ? ': ' . $out->message : '' ) );
        return $out;
    }

    /** @return array<string,mixed> */
    private function team( int $team_id ): array {
        return [ 'scope' => 'team', 'team_id' => $team_id, 'from' => self::FROM, 'to' => self::TO ];
    }

    /** @return array<string,mixed> */
    private function player( int $player_id, string $audience = PlayerReportAudience::INTERNAL ): array {
        return [ 'scope' => 'player', 'player_id' => $player_id, 'from' => self::FROM, 'to' => self::TO, 'audience' => $audience ];
    }

    /** @param array<string,mixed> $context */
    private function statementsFor( string $key, array $context ): int {
        global $wpdb;
        BlockEngine::preview( $key, $context, [], $this->hod );
        SharedReads::flush();
        DimensionValueResolver::flush();
        wp_cache_flush();
        $start = (int) $wpdb->num_queries;
        $out   = BlockEngine::preview( $key, $context, [], $this->hod );
        $this->assertIsArray( $out, $key );
        return (int) $wpdb->num_queries - $start;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, int $jersey ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Goals',
            'status' => 'active', 'jersey_number' => $jersey,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function goal( int $player_id, string $title, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id' => $this->club, 'player_id' => $player_id, 'title' => $title, 'status' => $status, 'created_by' => 1,
        ] );
    }

    private function pdpFile( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_pdp_files", [
            'club_id' => $this->club, 'player_id' => $player_id, 'season_id' => $this->season, 'status' => 'open',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function talk( int $file_id, int $sequence, ?string $scheduled, ?string $conducted, ?string $not_held ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $file_id, 'sequence' => $sequence, 'template_key' => 'start',
            'scheduled_at' => $scheduled, 'conducted_at' => $conducted, 'not_held_at' => $not_held,
        ] );
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Goals',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }
}
