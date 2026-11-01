<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Blocks\OwnQuestionBlock;
use TT\Modules\ReportStudio\Blocks\PlayingTimeBlock;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4568 — the Report Studio engine: row scope applied in SQL, a breakdown
 * that matches a hand count, the subject checked on every call, an external
 * audience that never receives a player breakdown, the truncation flag, a
 * bounded number of statements per block, and the playing-time block agreeing
 * with the team monthly report.
 */
final class ReportStudioEngineTest extends WP_UnitTestCase {

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pb1 = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a = $this->insertTeam( 'Studio U12' );
        $this->team_b = $this->insertTeam( 'Studio U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna' );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram' );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Cas' );

        $a1 = $this->insertActivity( $this->team_a, '2020-03-02' );
        $a2 = $this->insertActivity( $this->team_a, '2020-03-10' );
        $b1 = $this->insertActivity( $this->team_b, '2020-03-03' );
        $this->insertAttendance( $a1, $this->pa1, 'Present' );
        $this->insertAttendance( $a1, $this->pa2, 'Absent' );
        $this->insertAttendance( $a2, $this->pa1, 'Present' );
        $this->insertAttendance( $a2, $this->pa2, 'Present' );
        $this->insertAttendance( $b1, $this->pb1, 'Present' );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_query_never_fetches_another_teams_row(): void {
        $window = [ 'date_after' => '2020-03-01', 'date_before' => '2020-03-31' ];

        $rows = FactQuery::rows( 'attendance', $window, FactScope::forUser( $this->manager ), 100 );
        $this->assertCount( 4, $rows );
        foreach ( $rows as $row ) {
            $this->assertSame( $this->team_a, (int) $row->team_id, 'a row of another team reached a team-scoped reader' );
        }

        $grouped = FactQuery::run( 'attendance', [ 'team_id' ], [ 'count_present' ], $window, FactScope::forUser( $this->manager ) );
        $this->assertSame( [ $this->team_a ], array_map( static fn( $r ) => (int) $r->team_id, $grouped ) );

        // A filter naming the other team narrows inside the scope; it never widens it.
        $narrowed = FactQuery::run( 'attendance', [], [ 'count_present' ], $window + [ 'team_id_eq' => $this->team_b ], FactScope::forUser( $this->manager ) );
        $this->assertSame( 0, (int) ( $narrowed[0]->count_present ?? 0 ) );

        $this->assertCount( 5, FactQuery::rows( 'attendance', $window, FactScope::forUser( $this->hod ), 100 ), 'club-wide reader sees every team' );
    }

    public function test_a_fact_that_cannot_be_narrowed_is_for_club_wide_readers_only(): void {
        $this->assertFalse( FactQuery::canRead( 'prospects', FactScope::forUser( $this->manager ) ) );
        $this->assertFalse( FactQuery::canRead( 'trial_decisions', FactScope::forUser( $this->manager ) ) );
        $this->assertSame( [], FactQuery::run( 'prospects', [], [ 'count' ], [], FactScope::forUser( $this->manager ) ) );
        $this->assertTrue( FactQuery::canRead( 'attendance', FactScope::forUser( $this->manager ) ) );
        $this->assertTrue( FactQuery::canRead( 'prospects', FactScope::unrestricted() ) );
    }

    public function test_two_readers_never_share_a_cached_answer(): void {
        $window = [ 'date_after' => '2020-03-01', 'date_before' => '2020-03-31' ];
        $hod     = FactQuery::run( 'attendance', [], [ 'count_present' ], $window, FactScope::forUser( $this->hod ) );
        $manager = FactQuery::run( 'attendance', [], [ 'count_present' ], $window, FactScope::forUser( $this->manager ) );
        $this->assertSame( 4, (int) $hod[0]->count_present );
        $this->assertSame( 3, (int) $manager[0]->count_present );
    }

    public function test_a_breakdown_matches_a_hand_count(): void {
        $out = BlockEngine::preview(
            'own_question',
            [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            [ 'fact' => 'attendance', 'measure' => 'count_present', 'breakdown' => 'player_id' ],
            $this->hod
        );
        $this->assertIsArray( $out );
        $counts = [];
        foreach ( $out['data']['rows'] as $row ) $counts[ (int) $row['key'] ] = $row['value'];
        ksort( $counts );
        $this->assertSame( [ $this->pa1 => 2.0, $this->pa2 => 1.0 ], $counts );
        $this->assertFalse( $out['data']['truncated'] );
        $labels = array_column( $out['data']['rows'], 'label' );
        $this->assertContains( 'Anna Studio', $labels, 'the breakdown labels come from the server' );
    }

    public function test_attendance_per_week_reads_the_attendance_fact(): void {
        $out = BlockEngine::preview(
            'attendance_per_week',
            [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            [],
            $this->hod
        );
        $this->assertIsArray( $out );
        $this->assertSame( 'week', $out['data']['breakdown'] );
        $this->assertSame(
            [ [ 'week' => '2020-W10', 'attendance_pct' => 50.0, 'present' => 1, 'absent' => 1 ], [ 'week' => '2020-W11', 'attendance_pct' => 100.0, 'present' => 2, 'absent' => 0 ] ],
            $out['data']['rows']
        );
    }

    public function test_a_coach_gets_nothing_about_another_team(): void {
        $out = BlockEngine::preview(
            'own_question',
            [ 'scope' => 'team', 'team_id' => $this->team_b, 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            [ 'fact' => 'attendance', 'measure' => 'count_present' ],
            $this->manager
        );
        $this->assertInstanceOf( StudioError::class, $out );
        $this->assertSame( 403, $out->status );
        $this->assertSame( 'forbidden_team', $out->code );

        // Their own scope, broken down by team, holds only their team.
        $me = BlockEngine::preview(
            'own_question',
            [ 'scope' => 'me', 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            [ 'fact' => 'attendance', 'measure' => 'count_present', 'breakdown' => 'team_id' ],
            $this->manager
        );
        $this->assertIsArray( $me );
        $this->assertSame( [ (string) $this->team_a ], array_column( $me['data']['rows'], 'key' ) );
    }

    public function test_a_group_drops_the_players_the_reader_cannot_see(): void {
        $out = BlockEngine::preview(
            'attendance_per_week',
            [ 'scope' => 'group', 'player_ids' => [ $this->pa1, $this->pb1 ], 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            [],
            $this->manager
        );
        $this->assertIsArray( $out );
        $this->assertSame( [ $this->pa1 ], $out['context']['player_ids'] );
    }

    public function test_an_external_audience_never_receives_a_player_breakdown(): void {
        $context = [ 'scope' => 'player', 'player_id' => $this->pa1, 'from' => '2020-03-01', 'to' => '2020-03-31', 'audience' => PlayerReportAudience::FAMILY ];

        $refused = BlockEngine::preview( 'own_question', $context, [ 'fact' => 'attendance', 'measure' => 'count_present', 'breakdown' => 'player_id' ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $refused, 'a breakdown by player is not offered to the family' );
        $this->assertSame( 'breakdown', $refused->details['field'] ?? null );

        $built = ReportContext::fromInput( $context, $this->hod );
        $this->assertInstanceOf( ReportContext::class, $built );
        $cut = BlockEngine::forAudience( new OwnQuestionBlock(), $built, [
            'fact' => 'attendance', 'breakdown' => 'player_id', 'group_average' => 71.5,
            'rows' => [ [ 'key' => (string) $this->pa2, 'value' => 1.0 ] ], 'secret' => 'x',
        ] );
        $this->assertArrayNotHasKey( 'rows', $cut );
        $this->assertArrayNotHasKey( 'group_average', $cut );
        $this->assertArrayNotHasKey( 'secret', $cut );
        $this->assertNull( $cut['breakdown'] );

        $team = ReportContext::fromInput( [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => PlayerReportAudience::SCOUT ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $team, 'an external audience reads about one player, never a squad' );

        $weeks = BlockEngine::preview( 'attendance_per_week', $context, [], $this->hod );
        $this->assertIsArray( $weeks, 'the family still gets the player\'s own weeks' );
        $this->assertSame( [ 'breakdown', 'rows', 'truncated' ], array_keys( $weeks['data'] ) );
    }

    public function test_the_limits_are_enforced(): void {
        $ids = range( 1, ReportContext::MAX_GROUP + 1 );
        $this->assertInstanceOf( StudioError::class, ReportContext::fromInput( [ 'scope' => 'group', 'player_ids' => $ids ], $this->hod ) );
        $long = ReportContext::fromInput( [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2019-01-01', 'to' => '2020-03-31' ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $long );
        $this->assertSame( 'bad_date', $long->code );
    }

    public function test_a_result_at_the_row_cap_says_so(): void {
        global $wpdb;
        $values = [];
        for ( $i = 0; $i <= FactQuery::ROW_LIMIT; $i++ ) {
            $values[] = $wpdb->prepare( '(%d, %d, %s, %s, %s, %s)', $this->club, $this->team_a, 'Cap', '2019-06-01', 'training', 'completed' );
        }
        $wpdb->query( "INSERT INTO {$wpdb->prefix}tt_activities (club_id, team_id, title, session_date, activity_type_key, activity_status_key) VALUES " . implode( ',', $values ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}tt_evaluations (club_id, activity_id, player_id, coach_id, eval_date)
             SELECT club_id, id, %d, 1, '2019-06-01' FROM {$wpdb->prefix}tt_activities WHERE title = 'Cap'",
            $this->pa1
        ) );

        $capped = FactQuery::query( 'evaluations_per_session', [ 'activity_id' ], [ 'count_evaluations' ], [], FactScope::unrestricted() );
        $this->assertTrue( $capped->truncated );
        $this->assertCount( FactQuery::ROW_LIMIT, $capped->rows );

        $small = FactQuery::query( 'evaluations_per_session', [ 'month' ], [ 'count_evaluations' ], [], FactScope::unrestricted() );
        $this->assertFalse( $small->truncated );
    }

    public function test_a_block_runs_the_same_number_of_statements_whatever_the_squad_size(): void {
        $match = $this->insertActivity( $this->team_a, '2020-03-14', 'match' );
        $this->insertAttendance( $match, $this->pa1, 'Present', 60 );
        $this->insertAttendance( $match, $this->pa2, 'Present', 30 );

        $cases = [
            'playing_time'        => [],
            'attendance_per_week' => [],
            'own_question'        => [ 'fact' => 'attendance', 'measure' => 'attendance_pct', 'breakdown' => 'player_id' ],
        ];
        $context = [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ];

        $before = [];
        foreach ( $cases as $key => $options ) $before[ $key ] = $this->statementsFor( $key, $context, $options );

        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'Extra' . $i );
            $this->insertAttendance( $match, $pid, 'Present', 10 );
        }

        foreach ( $cases as $key => $options ) {
            $this->assertSame( $before[ $key ], $this->statementsFor( $key, $context, $options ), "{$key} ran a statement per player" );
        }
    }

    public function test_playing_time_totals_the_monthly_report_minutes(): void {
        $match = $this->insertActivity( $this->team_a, '2020-03-14', 'match' );
        $this->insertAttendance( $match, $this->pa1, 'Present', 60 );
        $this->insertAttendance( $match, $this->pa2, 'Present', 25 );

        $block = BlockEngine::preview( 'playing_time', [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ], [], $this->hod );
        $this->assertIsArray( $block );

        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, '2020-03-01', '2020-03-31', [ 'minutes' ] );
        $report_total = array_sum( array_column( $report['data']['minutes']['rows'], 'total_minutes' ) );

        $this->assertSame( 85, $block['data']['total_minutes'] );
        $this->assertSame( $report_total, $block['data']['total_minutes'] );
    }

    public function test_a_block_without_data_is_not_listed(): void {
        $real = BlockRegistry::all();
        BlockRegistry::clear();
        BlockRegistry::register( new PlayingTimeBlock() );
        BlockRegistry::register( new class implements ReportBlock {
            public function key(): string { return 'never_available'; }
            public function label(): string { return 'Never'; }
            public function scopes(): array { return ReportContext::SCOPES; }
            public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
            public function capability(): string { return 'read'; }
            public function options( ReportContext $context ): array { return []; }
            public function available( ReportContext $context ): bool { return false; }
            public function externalKeys(): array { return []; }
            public function render( ReportContext $context, array $options ): array { return []; }
        } );

        try {
            $keys = array_map( static fn( ReportBlock $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'team', 'internal', $this->hod ) ) );
            $this->assertContains( 'playing_time', $keys );
            $this->assertNotContains( 'never_available', $keys );
        } finally {
            BlockRegistry::clear();
            foreach ( $real as $block ) BlockRegistry::register( $block );
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     */
    private function statementsFor( string $key, array $context, array $options ): int {
        global $wpdb;
        // Warm the reader's capability and scope caches, then count one
        // cold render of the block's own reads. `num_queries` counts every
        // statement whether or not SAVEQUERIES keeps their text.
        BlockEngine::preview( $key, $context, $options, $this->hod );
        SharedReads::flush();
        DimensionValueResolver::flush();
        wp_cache_flush();
        $start = (int) $wpdb->num_queries;
        $out   = BlockEngine::preview( $key, $context, $options, $this->hod );
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

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Studio', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date, string $type = 'training' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => ucfirst( $type ) . ' ' . $date, 'session_date' => $date,
            'activity_type_key' => $type, 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status, ?int $minutes = null ): void {
        global $wpdb;
        $row = [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'is_guest' => 0, 'record_type' => 'actual',
        ];
        if ( $minutes !== null ) $row['minutes_played'] = $minutes;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", $row );
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Studio',
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
