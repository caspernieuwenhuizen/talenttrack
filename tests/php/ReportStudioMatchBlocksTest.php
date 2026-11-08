<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the match blocks and the one "Played" rule: the monthly report's
 * record and the statistics tab's record agree on every figure, "Played" on
 * both is the reports' played-match rule (a past match without a score is
 * played, a match still to come is not), the results equal the monthly
 * report's fixtures, the statistics equal the tab's, each block answers to
 * the read of the route that owns it, and statements are bounded.
 */
final class ReportStudioMatchBlocksTest extends WP_UnitTestCase {

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private string $from = '';
    private string $to = '';
    private int $future = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Matches U12' );
        $this->team_b  = $this->insertTeam( 'Matches U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        // A window around today, so a match still to come falls inside it.
        $this->from = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
        $this->to   = gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS );

        $this->match( $this->team_a, -30, 'Rood', 'home', 3, 1 );          // won
        $this->match( $this->team_a, -20, 'Blauw', 'away', 0, 2 );         // lost
        $this->match( $this->team_a, -10, 'Groen', 'home', 1, 1 );         // drawn
        $this->match( $this->team_a, -5, '', 'away', null, null );          // played, no score, no opponent
        $this->future = $this->match( $this->team_a, 10, 'Paars', 'home', null, null, 'planned' );
        $this->match( $this->team_b, -15, 'Wit', 'home', 5, 0 );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_played_is_one_rule_on_the_monthly_report_and_the_statistics_tab(): void {
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team_a, $this->from, $this->to, [ 'matches' ] )['data']['matches']['record'];
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team_a, [ 'from' => $this->from, 'to' => $this->to ] )['record'];

        foreach ( [ 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference' ] as $key ) {
            $this->assertSame( $monthly[ $key ], $tab[ $key ], $key );
        }
        // "Without a score" is not part of the Played rule: the monthly report
        // still lists the match still to come among the fixtures without one,
        // the tab, which reads past fixtures only, does not.
        $this->assertSame( $tab['without_a_score'] + 1, $monthly['without_score'] );

        // The hand count: four played (the one without a score included), the
        // match in ten days not; one won, one drawn, one lost; 4–4.
        $this->assertSame( 4, $tab['played'], 'a past match without a score is played; one still to come is not' );
        $this->assertSame( [ 1, 1, 1 ], [ $tab['won'], $tab['drawn'], $tab['lost'] ] );
        $this->assertSame( [ 4, 4, 0 ], [ $tab['goals_for'], $tab['goals_against'], $tab['goal_difference'] ] );
        $this->assertSame( 1, $tab['without_a_score'] );
    }

    /**
     * The boundary between the two reads: the tab reads fixtures up to and
     * including today, the rule calls a match played when it is completed,
     * before today, or has minutes. A match today, still planned, with no
     * minutes, is therefore a fixture without a score and not played.
     */
    public function test_a_planned_match_today_without_minutes_is_not_played(): void {
        global $wpdb;
        $tab_before = ( new TeamMatchStatsQuery() )->forTeam( $this->team_a, [ 'from' => $this->from, 'to' => $this->to ] )['record'];

        // Today as the database reads it, the date `CURDATE()` compares with.
        $today = (string) $wpdb->get_var( 'SELECT CURDATE()' );
        $this->matchOn( $this->team_a, $today, 'Oranje', 'home', null, null, 'planned' );
        $this->flush();

        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team_a, $this->from, $this->to, [ 'matches' ] )['data']['matches']['record'];
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team_a, [ 'from' => $this->from, 'to' => $this->to ] )['record'];

        $this->assertSame( $tab_before['played'], $tab['played'], 'a planned match today without minutes is not played' );
        $this->assertSame( 4, $tab['played'] );
        $this->assertSame( $tab_before['without_a_score'] + 1, $tab['without_a_score'], 'the tab lists it among the fixtures without a score' );
        foreach ( [ 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference' ] as $key ) {
            $this->assertSame( $monthly[ $key ], $tab[ $key ], $key );
        }
        $this->assertSame( $tab['without_a_score'] + 1, $monthly['without_score'] );
    }

    public function test_the_results_equal_the_monthly_reports_match_section(): void {
        $block  = $this->preview( 'match_results', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, $this->from, $this->to, [ 'matches' ] )['data']['matches'];

        $this->assertSame( $report['record'], $block['data']['record'] );
        $this->assertSame( array_column( $report['fixtures'], 'date' ), array_column( $block['data']['rows'], 'match_date' ) );
        $this->assertCount( 5, $block['data']['rows'], 'every fixture in the window, the one still to come included' );

        $by_date = array_column( $block['data']['rows'], null, 'match_date' );
        $first   = $by_date[ gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ];
        $this->assertSame( '3–1', $first['score'] );
        $this->assertSame( 'H', $first['venue'] );
        $gap = $by_date[ gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ) ];
        $this->assertSame( 'no score recorded', $gap['score'] );
        $this->assertSame( 'Unknown opponent', $gap['opponent'] );
    }

    public function test_the_team_statistics_equal_the_statistics_tab(): void {
        $block = $this->preview( 'team_statistics', $this->team( $this->team_a ) );
        $tab   = ( new TeamMatchStatsQuery() )->forTeam( $this->team_a, [ 'from' => $this->from, 'to' => $this->to ] )['record'];

        $row = $block['data']['rows'][0];
        foreach ( [ 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference', 'clean_sheets', 'without_a_score' ] as $key ) {
            $this->assertSame( $tab[ $key ], $row[ $key ], $key );
        }
        $this->assertSame( $tab['tournaments_excluded'], $block['data']['tournaments_excluded'] );
    }

    public function test_each_block_answers_to_the_read_of_the_route_that_owns_it(): void {
        foreach ( [ $this->hod, $this->manager ] as $user ) {
            $tab_gate = ( AuthorizationService::userCanOrMatrix( $user, 'tt_view_teams' ) || AuthorizationService::userCanOrMatrix( $user, 'tt_edit_teams' ) )
                && AllTeamsScope::canReadTeam( $user, $this->team_a );
            $out = BlockEngine::preview( 'team_statistics', $this->team( $this->team_a ), [], $user );
            $this->assertSame( $tab_gate, is_array( $out ), "the statistics follow GET /teams/{id}/stats for user {$user}" );
        }
        // The results are the monthly report's section: the reports read is theirs.
        $this->assertIsArray( BlockEngine::preview( 'match_results', $this->team( $this->team_a ), [], $this->manager ) );
    }

    public function test_the_match_blocks_are_staff_only_and_refuse_another_team(): void {
        foreach ( [ 'match_results', 'team_statistics' ] as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block );
            $this->assertSame( [ PlayerReportAudience::INTERNAL ], $block->audiences(), $key );
            $this->assertSame( [], $block->externalKeys(), $key );

            $family = BlockEngine::preview( $key, [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
            $this->assertInstanceOf( StudioError::class, $family, $key );

            $other = BlockEngine::preview( $key, $this->team( $this->team_b ), [], $this->manager );
            $this->assertInstanceOf( StudioError::class, $other, $key );
            $this->assertSame( 403, $other->status, $key );
        }
        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::FAMILY, $this->hod ) ) );
        $this->assertNotContains( 'match_results', $listed );
        $this->assertNotContains( 'team_statistics', $listed );
    }

    public function test_each_block_runs_a_bounded_number_of_statements(): void {
        $context = $this->team( $this->team_a );
        $before  = [];
        foreach ( [ 'match_results', 'team_statistics' ] as $key ) $before[ $key ] = $this->statementsFor( $key, $context );
        for ( $i = 1; $i <= 6; $i++ ) $this->match( $this->team_a, -30 - $i, 'Extra ' . $i, 'home', 2, 0 );
        foreach ( [ 'match_results', 'team_statistics' ] as $key ) {
            $this->assertSame( $before[ $key ], $this->statementsFor( $key, $context ), "{$key} ran a statement per match" );
        }
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
        return [ 'scope' => 'team', 'team_id' => $team_id, 'from' => $this->from, 'to' => $this->to ];
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

    /** A game `$days` from today, scored or not. */
    private function match( int $team_id, int $days, string $opponent, string $home_away, ?int $ours, ?int $theirs, string $status = 'completed' ): int {
        return $this->matchOn( $team_id, gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ), $opponent, $home_away, $ours, $theirs, $status );
    }

    /** A game on this date, scored or not. */
    private function matchOn( int $team_id, string $date, string $opponent, string $home_away, ?int $ours, ?int $theirs, string $status = 'completed' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Match ' . $date, 'session_date' => $date,
            'activity_type_key' => 'game', 'activity_status_key' => $status, 'plan_state' => $status === 'completed' ? 'completed' : 'planned',
            'opponent' => $opponent, 'home_away' => $home_away, 'home_score' => $ours, 'away_score' => $theirs,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Matches',
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
