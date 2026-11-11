<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the playing-time blocks: each figure equals the report it comes
 * from (the monthly report's minutes section, the minutes report's per-match
 * read, the goal log's contributions, the minutes-share report), a family
 * never receives a staff-only block or another child's minutes, a coach is
 * refused another team's subject, and each block runs a bounded number of
 * statements.
 */
final class ReportStudioPlayingTimeBlocksTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pa3 = 0;
    private int $pb1 = 0;
    private int $m1 = 0;
    private int $m2 = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Minutes U12' );
        $this->team_b  = $this->insertTeam( 'Minutes U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna', 9 );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram', 4 );
        // In the squad, on the bench both matches.
        $this->pa3 = $this->insertPlayer( $this->team_a, 'Cor', 12 );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Dirk', 7 );

        $this->m1 = $this->insertMatch( $this->team_a, '2020-03-07', 'League' );
        $this->m2 = $this->insertMatch( $this->team_a, '2020-03-21', 'Cup' );
        $this->insertAttendance( $this->m1, $this->pa1, 70 );
        $this->insertAttendance( $this->m1, $this->pa2, 35 );
        $this->insertAttendance( $this->m1, $this->pa3, 0 );
        $this->insertAttendance( $this->m2, $this->pa1, 50 );
        $this->insertAttendance( $this->m2, $this->pa3, 0 );

        // Anna two goals, Bram one and an assist; an own goal counts for nobody.
        $this->insertGoal( $this->m1, $this->pa1, $this->pa2 );
        $this->insertGoal( $this->m1, $this->pa2, 0 );
        $this->insertGoal( $this->m2, $this->pa1, 0 );
        $this->insertGoal( $this->m2, $this->pa2, 0, true );

        $b = $this->insertMatch( $this->team_b, '2020-03-08', 'League' );
        $this->insertAttendance( $b, $this->pb1, 60 );
        $this->insertGoal( $b, $this->pb1, 0 );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_playing_time_block_is_registered(): void {
        foreach ( [ 'playing_time', 'minutes_per_match', 'goals_assists', 'minutes_share' ] as $key ) {
            $this->assertNotNull( BlockRegistry::find( $key ), $key );
        }
    }

    public function test_playing_time_lists_the_bench_player_against_the_resolved_minutes(): void {
        $block  = $this->preview( 'playing_time', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'minutes' ] );

        $this->assertSame( array_column( $report['data']['minutes']['rows'], 'player_id' ), array_column( $block['data']['rows'], 'player_id' ) );
        $this->assertContains( $this->pa3, array_column( $block['data']['rows'], 'player_id' ), 'a squad player who never got on is listed' );
        // The minutes available are the matches' resolved lengths, the
        // minutes-share report's denominator, not a fixed norm.
        $available = ( new MinutesShareQuery() )->availableForTeam( $this->team_a, self::FROM, self::TO )['minutes'];
        foreach ( $block['data']['rows'] as $row ) {
            $this->assertSame( $available, $row['available_minutes'] );
        }
        $this->assertSame( MinutesShareQuery::targetPct(), $block['data']['target_pct'] );
    }

    public function test_minutes_per_match_equals_the_minutes_reports_read_for_every_audience(): void {
        $theirs = ( new MinutesQuery() )->playingTimeForPlayer( $this->team_a, $this->pa1, self::FROM, self::TO );
        $this->assertSame( 120, $theirs['total_minutes'] );

        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $block = $this->preview( 'minutes_per_match', $this->player( $this->pa1, $audience ) );
            $this->assertSame( $theirs['total_minutes'], $block['data']['total_minutes'], $audience );
            $this->assertSame( array_column( $theirs['matches'], 'minutes' ), array_column( $block['data']['rows'], 'minutes' ), $audience );
            $this->assertSame( array_column( $theirs['matches'], 'session_date' ), array_column( $block['data']['rows'], 'session_date' ), $audience );
            foreach ( $block['data']['rows'] as $row ) {
                $this->assertArrayNotHasKey( 'player_id', $row );
                $this->assertNotSame( '', $row['type'], 'the match type is labelled' );
            }
        }
    }

    public function test_team_goals_and_assists_equal_the_goal_log_and_the_monthly_report(): void {
        $block  = $this->preview( 'goals_assists', $this->team( $this->team_a ) );
        $log    = ( new GoalContributionQuery() )->forTeam( $this->team_a, [ 'from' => self::FROM, 'to' => self::TO ] );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'matches' ] );

        // Row for row and in the report's order, rank included.
        $theirs = array_map(
            static fn( array $s ): array => [ (int) $s['player_id'], $s['rank'], (int) $s['goals'], (int) $s['assists'] ],
            $report['data']['matches']['scorers']
        );
        $mine = array_map(
            static fn( array $r ): array => [ $r['player_id'], $r['rank'], $r['goals'], $r['assists'] ],
            $block['data']['rows']
        );
        $this->assertSame( $theirs, $mine, 'the monthly report\'s scorers, in its order' );
        foreach ( $block['data']['rows'] as $row ) {
            $this->assertSame( (int) $log[ $row['player_id'] ]['goals'], $row['goals'] );
            $this->assertSame( (int) $log[ $row['player_id'] ]['assists'], $row['assists'] );
        }
        // The hand count: Anna 2 goals ranks first, Bram 1 goal and 1 assist second.
        $this->assertSame( [ [ $this->pa1, 1, 2, 0 ], [ $this->pa2, 2, 1, 1 ] ], $mine );
        $this->assertSame( $report['data']['matches']['scorer_totals']['goals'], $block['data']['goals'] );
        $this->assertSame( $report['data']['matches']['scorer_totals']['assists'], $block['data']['assists'] );
    }

    public function test_a_players_goals_and_assists_equal_their_goal_contributions(): void {
        $theirs = ( new GoalContributionQuery() )->forPlayer( $this->pa2, [ 'from' => self::FROM, 'to' => self::TO ] );
        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $block = $this->preview( 'goals_assists', $this->player( $this->pa2, $audience ) );
            $this->assertSame( [ [ 'goals' => $theirs['goals'], 'assists' => $theirs['assists'], 'contributions' => $theirs['contributions'] ] ], $block['data']['rows'], $audience );
        }
        $this->assertSame( 1, $theirs['goals'], 'an own goal is not a goal' );
    }

    /**
     * #4966 — the block's share is the monthly report's, per player and for
     * the median. A played match nobody recorded minutes for is the case the
     * two definitions part on: the monthly report leaves it out of the
     * minutes available (#2252), the minutes-share report counts it.
     */
    public function test_the_minutes_share_equals_the_monthly_reports_minutes_section(): void {
        $this->insertMatch( $this->team_a, '2020-03-28', 'League' ); // played, no minutes recorded
        $this->flush();

        $section = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'minutes' ] )['data']['minutes'];
        $other   = ( new MinutesShareQuery() )->forTeam( $this->team_a, self::FROM, self::TO );
        $this->assertSame( $other['available_minutes'], $section['rows'][0]['available_minutes'], 'the minutes-share report and the monthly section agree on the minutes available' );

        $block = $this->preview( 'minutes_share', $this->team( $this->team_a ) );
        $rows  = $block['data']['rows'];

        $by_player = array_column( $section['rows'], null, 'player_id' );
        $this->assertCount( count( $section['rows'] ), $rows, 'every squad player of the section' );
        foreach ( $rows as $row ) {
            $theirs = $by_player[ $row['player_id'] ] ?? null;
            $this->assertIsArray( $theirs, 'a player of the section' );
            $this->assertSame( $theirs['total_minutes'], $row['minutes'] );
            $this->assertSame( $theirs['available_minutes'], $row['available_minutes'] );
            $this->assertSame( $theirs['share_pct'], $row['share_pct'] );
        }
        $this->assertSame( $section['median_share_pct'], $block['data']['median_share_pct'] );
        $this->assertNotNull( $block['data']['median_share_pct'] );
        $this->assertSame( $section['target_pct'], $block['data']['target_pct'] );

        $shares = array_column( $rows, 'share_pct' );
        $sorted = $shares;
        sort( $sorted );
        $this->assertSame( $sorted, $shares, 'lowest share first' );

        // The playing-time block and the key-figures tile give the same median.
        $this->assertSame( $section['median_share_pct'], $this->preview( 'playing_time', $this->team( $this->team_a ) )['data']['median_share_pct'] );

        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $player = $this->preview( 'minutes_share', $this->player( $this->pa1, $audience ) )['data'];
            $this->assertSame( $by_player[ $this->pa1 ]['share_pct'], $player['value'], $audience );
            $this->assertSame( $by_player[ $this->pa1 ]['total_minutes'], $player['minutes'], $audience );
            $this->assertSame( $by_player[ $this->pa1 ]['available_minutes'], $player['available_minutes'], $audience );
            $this->assertArrayNotHasKey( 'median_share_pct', $player, 'no average for a single player' );
        }
    }

    /**
     * #4966 — the minutes-share report (and `GET /teams/{id}/minutes-share`)
     * counts only matches with minutes recorded, so it gives the monthly
     * report's share, for the team and for one player. The played match
     * without minutes is in `played_matches` and nowhere else.
     */
    public function test_the_minutes_share_report_equals_the_monthly_report(): void {
        $before = ( new MinutesShareQuery() )->forTeam( $this->team_a, self::FROM, self::TO );
        $this->insertMatch( $this->team_a, '2020-03-28', 'League' ); // played, no minutes recorded
        $this->flush();

        $report  = ( new MinutesShareQuery() )->forTeam( $this->team_a, self::FROM, self::TO );
        $section = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'minutes' ] )['data']['minutes'];
        $rows    = array_column( $section['rows'], null, 'player_id' );

        $this->assertSame( $before['available_minutes'], $report['available_minutes'], 'a match without minutes adds no minutes' );
        $this->assertSame( $before['matches'], $report['matches'] );
        $this->assertSame( $before['played_matches'] + 1, $report['played_matches'], 'and is a played match' );
        $this->assertGreaterThan( 0, $report['available_minutes'] );

        $this->assertNotEmpty( $report['players'] );
        foreach ( $report['players'] as $p ) {
            $mine = $rows[ $p['player_id'] ] ?? null;
            $this->assertIsArray( $mine, 'a player of the monthly report' );
            $this->assertSame( $mine['available_minutes'], $report['available_minutes'], "player {$p['player_id']}: available" );
            $this->assertSame( $mine['total_minutes'], $p['minutes'], "player {$p['player_id']}: minutes" );
            $this->assertSame( $mine['share_pct'], round( (float) $p['share_pct'], 1 ), "player {$p['player_id']}: share" );
        }
        $median = MinutesShareQuery::medianShare( $report['players'] );
        $this->assertNotNull( $median );
        $this->assertSame( $section['median_share_pct'], round( $median, 1 ), 'the median' );

        $one = ( new MinutesShareQuery() )->forPlayer( $this->team_a, $this->pa1, self::FROM, self::TO );
        $this->assertIsArray( $one );
        $this->assertSame( $rows[ $this->pa1 ]['share_pct'], round( (float) $one['share_pct'], 1 ), 'one player' );
        $this->assertSame( $rows[ $this->pa1 ]['available_minutes'], $one['available_minutes'] );
    }

    public function test_a_period_without_matches_reads_as_empty_not_as_zero(): void {
        $empty  = [ 'from' => '2020-05-01', 'to' => '2020-05-31' ];
        $team   = array_merge( $this->team( $this->team_a ), $empty );
        $player = array_merge( $this->player( $this->pa1 ), $empty );

        $this->assertSame( [], $this->preview( 'minutes_per_match', $player )['data']['rows'] );
        $this->assertSame( 0, $this->preview( 'minutes_per_match', $player )['data']['total_minutes'] );
        $this->assertSame( [], $this->preview( 'goals_assists', $team )['data']['rows'] );
        // The section still lists the squad with no minutes to share: each
        // share is no figure, never 0 %.
        foreach ( $this->preview( 'minutes_share', $team )['data']['rows'] as $row ) {
            $this->assertNull( $row['share_pct'] );
        }
        $this->assertNull( $this->preview( 'minutes_share', $team )['data']['median_share_pct'] );

        // No minutes to share: the player's share is no figure (a dash), not 0 %.
        $share = $this->preview( 'minutes_share', $player )['data'];
        $this->assertNull( $share['value'] );
        $this->assertSame( 0, $share['available_minutes'] );

        // The monthly report's minutes section still lists the squad, as it
        // does on paper, but with no share: nothing was available.
        $playing = $this->preview( 'playing_time', $team )['data'];
        $this->assertSame( 0, $playing['total_minutes'] );
        foreach ( $playing['rows'] as $row ) {
            $this->assertSame( 0, $row['total_minutes'] );
            $this->assertNull( $row['share_pct'], 'no share without minutes available' );
        }
        $this->assertNull( $playing['median_share_pct'] );
    }

    public function test_a_family_never_receives_a_staff_only_block_or_another_childs_minutes(): void {
        $playing = BlockRegistry::find( 'playing_time' );
        $this->assertNotNull( $playing );
        $this->assertSame( [ PlayerReportAudience::INTERNAL ], $playing->audiences() );

        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::FAMILY, $this->hod ) ) );
        $this->assertNotContains( 'playing_time', $listed );
        foreach ( [ 'minutes_per_match', 'minutes_share' ] as $key ) {
            $this->assertContains( $key, $listed, "{$key} is the player's own" );
        }
        // #5021 — the player report gives a family no goals and assists.
        $this->assertNotContains( 'goals_assists', $listed );

        // A team, and so every other child's figure, is never a family subject.
        foreach ( [ 'playing_time', 'goals_assists', 'minutes_share' ] as $key ) {
            $out = BlockEngine::preview( $key, [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
            $this->assertInstanceOf( StudioError::class, $out, $key );
        }
    }

    public function test_an_external_payload_has_no_breakdown_by_player_and_no_average(): void {
        foreach ( [ 'minutes_per_match', 'goals_assists', 'minutes_share' ] as $key ) {
            foreach ( [ PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
                // #5021 — goals and assists reach a scout, never a family.
                if ( $key === 'goals_assists' && $audience === PlayerReportAudience::FAMILY ) continue;
                $out = $this->preview( $key, $this->player( $this->pa1, $audience ) );
                $this->assertNotSame( 'player_id', $out['data']['breakdown'] ?? null, "{$key} for {$audience}" );
                foreach ( (array) ( $out['data']['rows'] ?? [] ) as $row ) {
                    $this->assertArrayNotHasKey( 'player_id', (array) $row, "{$key} for {$audience}" );
                }
                foreach ( [ 'group_average', 'median_share_pct', 'comparison', 'team' ] as $average ) {
                    $this->assertArrayNotHasKey( $average, $out['data'], "{$key} for {$audience}" );
                }
            }
        }
    }

    public function test_a_coach_out_of_scope_is_refused_each_blocks_subject(): void {
        $cases = [
            [ 'playing_time', $this->team( $this->team_b ) ],
            [ 'goals_assists', $this->team( $this->team_b ) ],
            [ 'minutes_share', $this->team( $this->team_b ) ],
            [ 'minutes_per_match', $this->player( $this->pb1 ) ],
            [ 'goals_assists', $this->player( $this->pb1 ) ],
            [ 'minutes_share', $this->player( $this->pb1 ) ],
        ];
        foreach ( $cases as [ $key, $context ] ) {
            $out = BlockEngine::preview( $key, $context, [], $this->manager );
            $this->assertInstanceOf( StudioError::class, $out, "{$key} on {$context['scope']}" );
            $this->assertSame( 403, $out->status, "{$key} on {$context['scope']}" );
        }
    }

    public function test_each_block_runs_a_bounded_number_of_statements(): void {
        $cases = [
            'playing_time'      => $this->team( $this->team_a ),
            'goals_assists'     => $this->team( $this->team_a ),
            'minutes_share'     => $this->team( $this->team_a ),
            'minutes_per_match' => $this->player( $this->pa1 ),
        ];
        $before = [];
        foreach ( $cases as $key => $context ) $before[ $key ] = $this->statementsFor( $key, $context );

        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'Extra' . $i, 20 + $i );
            $this->insertAttendance( $this->m1, $pid, 10 );
            $this->insertGoal( $this->m2, $pid, $this->pa1 );
        }

        foreach ( $cases as $key => $context ) {
            $this->assertSame( $before[ $key ], $this->statementsFor( $key, $context ), "{$key} ran a statement per player" );
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
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Minutes',
            'status' => 'active', 'jersey_number' => $jersey,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date, string $subtype ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Match ' . $date, 'session_date' => $date,
            'activity_type_key' => 'match', 'game_subtype_key' => $subtype,
            'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => 'Present', 'is_guest' => 0, 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
    }

    private function insertGoal( int $activity_id, int $scorer, int $assist, bool $own = false ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_match_execution_goal_events", [
            'event_uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'execution_id' => 0,
            'activity_id' => $activity_id, 'player_id' => $scorer, 'assist_player_id' => $assist,
            'half' => 1, 'minute_in_half' => 10, 'team' => 'home', 'is_own_goal' => $own ? 1 : 0,
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
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Minutes',
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
