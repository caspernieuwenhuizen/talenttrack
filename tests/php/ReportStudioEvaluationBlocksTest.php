<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerRadarQuery;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Blocks\ProgressRadarBlock;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the evaluation blocks: the average per main category equals the
 * team monthly report's evaluations section for a team and the player
 * report's ratings for a player, the progress block equals the progress and
 * radar report's read with an unrated category as no figure, the radar is
 * staff only, no external payload carries a player breakdown or a coach's
 * notes, a coach is refused another team, and each block runs a bounded
 * number of statements.
 */
final class ReportStudioEvaluationBlocksTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pb1 = 0;
    private int $cat_a = 0;
    private int $cat_b = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Ratings U12' );
        $this->team_b  = $this->insertTeam( 'Ratings U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna', 9 );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram', 4 );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Cor', 2 );

        $this->cat_a = $this->insertCategory( 'Zz passing', 980 );
        $this->cat_b = $this->insertCategory( 'Zz shooting', 981 );

        // February, the previous window; then two March evaluations each.
        $this->evaluate( $this->pa1, '2020-02-10', [ $this->cat_a => 6.0, $this->cat_b => 6.0 ] );
        $this->evaluate( $this->pa1, '2020-03-05', [ $this->cat_a => 7.0, $this->cat_b => 8.0 ], 'Talked about his first touch.' );
        $this->evaluate( $this->pa1, '2020-03-20', [ $this->cat_a => 8.0 ] );
        $this->evaluate( $this->pa2, '2020-03-06', [ $this->cat_a => 6.0, $this->cat_b => 5.0 ] );
        $this->evaluate( $this->pb1, '2020-03-07', [ $this->cat_a => 9.0 ] );
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_team_averages_equal_the_monthly_reports_evaluations_section(): void {
        $block  = $this->preview( 'category_averages', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'evaluations', 'kpi' ] );
        $theirs = $report['data']['evaluations']['categories'];

        $this->assertSame( array_column( $theirs, 'label' ), array_column( $block['data']['rows'], 'category' ), 'the methodology\'s order' );
        foreach ( $block['data']['rows'] as $i => $row ) {
            $this->assertSame( $theirs[ $i ]['avg'], $row['average'] );
            $this->assertSame( $theirs[ $i ]['delta'], $row['change'] );
            $this->assertSame( $theirs[ $i ]['min'], $row['lowest'] );
            $this->assertSame( $theirs[ $i ]['max'], $row['highest'] );
        }
        $this->assertSame( $report['data']['evaluations']['squad_avg'], $block['data']['squad_avg'] );
        $this->assertSame( $report['data']['kpi']['squad_rating']['value'], $block['data']['squad_avg'], 'the "Squad rating" tile\'s figure' );

        // The hand count on passing: March 7, 8 and 6 average 7.0; February 6 → +1.0.
        $passing = array_column( $block['data']['rows'], null, 'category' )['Zz passing'];
        $this->assertEquals( 7.0, $passing['average'] );
        $this->assertEquals( 1.0, $passing['change'] );
    }

    public function test_a_players_averages_equal_the_player_reports_ratings_for_every_audience(): void {
        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $block  = $this->preview( 'category_averages', $this->player( $this->pa1, $audience ) );
            $report = ( new PlayerReport() )->forPlayer( $this->pa1, self::FROM, self::TO, [ 'ratings' ], $this->hod, $audience );
            $this->assertIsArray( $report );
            $theirs = $report['data']['ratings'];

            $this->assertSame( array_column( $theirs['categories'], 'label' ), array_column( $block['data']['rows'], 'category' ), $audience );
            foreach ( $block['data']['rows'] as $i => $row ) {
                $this->assertSame( $theirs['categories'][ $i ]['average'], $row['average'], $audience );
                $this->assertSame( $theirs['categories'][ $i ]['latest'], $row['latest'], $audience );
                $this->assertSame( $theirs['categories'][ $i ]['count'], $row['count'], $audience );
            }
            $this->assertSame( $theirs['evaluation_count'], $block['data']['evaluation_count'], $audience );
            $this->assertSame( $theirs['average'], $block['data']['average'], $audience );

            // Scores only: no evaluation list, so never a coach's written note.
            $this->assertArrayNotHasKey( 'evaluations', $block['data'] );
            $this->assertStringNotContainsString( 'first touch', (string) wp_json_encode( $block['data'] ) );
        }
    }

    public function test_the_progress_block_equals_the_radar_report_with_an_unrated_category_as_no_figure(): void {
        $block  = $this->preview( 'progress_radar', $this->player( $this->pa1 ) );
        $theirs = ( new PlayerRadarQuery() )->progressForPlayer( $this->pa1, ProgressRadarBlock::LIMIT );

        $this->assertSame( array_values( array_map( 'strval', $theirs['labels'] ) ), $block['data']['categories'] );
        $this->assertSame( array_column( $theirs['datasets'], 'label' ), array_column( $block['data']['rows'], 'eval_date' ) );
        foreach ( $block['data']['rows'] as $i => $row ) {
            foreach ( $row['values'] as $j => $value ) {
                $raw = (float) $theirs['datasets'][ $i ]['values'][ $j ];
                $this->assertSame( $raw > 0 ? $raw : null, $value );
            }
        }

        // The 20 March evaluation rated no shooting: that is a dash, not 0.
        $col  = array_search( 'Zz shooting', $block['data']['categories'], true );
        $last = end( $block['data']['rows'] );
        $this->assertNotFalse( $col );
        $this->assertIsArray( $last );
        $this->assertSame( '2020-03-20', $last['eval_date'] );
        $this->assertNull( $last['values'][ $col ] );

        // "Now", not the period: the last five, whenever they were.
        for ( $d = 1; $d <= 4; $d++ ) $this->evaluate( $this->pa1, '2020-06-0' . $d, [ $this->cat_a => 7.0 ] );
        SharedReads::flush();
        $now = $this->preview( 'progress_radar', $this->player( $this->pa1 ) );
        $this->assertCount( ProgressRadarBlock::LIMIT, $now['data']['rows'] );
        $this->assertSame( '2020-06-04', end( $now['data']['rows'] )['eval_date'] );
    }

    public function test_the_radar_is_staff_only_and_no_external_payload_breaks_down_by_player(): void {
        $radar = BlockRegistry::find( 'progress_radar' );
        $this->assertNotNull( $radar );
        $this->assertSame( [ PlayerReportAudience::INTERNAL ], $radar->audiences() );
        $family = BlockEngine::preview( 'progress_radar', $this->player( $this->pa1, PlayerReportAudience::FAMILY ), [], $this->hod );
        $this->assertInstanceOf( StudioError::class, $family );

        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::FAMILY, $this->hod ) ) );
        $this->assertNotContains( 'progress_radar', $listed );
        $this->assertContains( 'category_averages', $listed );

        foreach ( [ PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $out = $this->preview( 'category_averages', $this->player( $this->pa1, $audience ) );
            $this->assertSame( 'category', $out['data']['breakdown'] );
            foreach ( $out['data']['rows'] as $row ) $this->assertArrayNotHasKey( 'player_id', $row );
            $this->assertArrayNotHasKey( 'squad_avg', $out['data'] );
        }
        // The team averages are never a family subject.
        $team = BlockEngine::preview( 'category_averages', [ 'scope' => 'team', 'team_id' => $this->team_a, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
        $this->assertInstanceOf( StudioError::class, $team );
    }

    public function test_each_block_answers_to_the_read_of_the_route_that_owns_its_figure(): void {
        // The assistant coach reads the team's reports and holds no
        // evaluations read (#1060).
        $assistant = $this->assistantCoachOf( $this->team_a );
        $this->assertFalse( AuthorizationService::mayReadPlayerSection( $assistant, $this->pa1, 'evaluations' ), 'Precondition: no evaluations read on the player.' );
        $subject = \TT\Modules\ReportStudio\Domain\ReportSubjectAccess::check( ReportContext::fromInput( $this->team( $this->team_a ), $assistant ) );
        $this->assertInstanceOf( ReportContext::class, $subject, 'Precondition: the reports read opens the team.' );

        // The squad's averages are the monthly report's evaluations section:
        // the team's reports read opens them, as it opens that section.
        $team   = BlockEngine::preview( 'category_averages', $this->team( $this->team_a ), [], $assistant );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'evaluations' ], $assistant );
        $this->assertIsArray( $team, 'the team averages for a reader of the team\'s reports' );
        $this->assertSame( array_column( $report['data']['evaluations']['categories'], 'avg' ), array_column( $team['data']['rows'], 'average' ) );
        $listed_team = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'team', PlayerReportAudience::INTERNAL, $assistant ) ) );
        $this->assertContains( 'category_averages', $listed_team );

        // A player's ratings ask the evaluations read on the player, as the
        // player report does: refused, and not listed.
        foreach ( [ 'category_averages', 'progress_radar' ] as $key ) {
            $out = BlockEngine::preview( $key, $this->player( $this->pa1 ), [], $assistant );
            $this->assertInstanceOf( StudioError::class, $out, "{$key} on a player" );
            $this->assertSame( 404, $out->status, "{$key} on a player" );
        }
        $listed_player = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::INTERNAL, $assistant ) ) );
        $this->assertNotContains( 'category_averages', $listed_player );
        $this->assertNotContains( 'progress_radar', $listed_player );

        // The edge: the evaluations read on the player is what opens them.
        $this->assertTrue( AuthorizationService::mayReadPlayerSection( $this->hod, $this->pa1, 'evaluations' ) );
        $this->assertIsArray( BlockEngine::preview( 'category_averages', $this->player( $this->pa1 ), [], $this->hod ) );
        $this->assertIsArray( BlockEngine::preview( 'progress_radar', $this->player( $this->pa1 ), [], $this->hod ) );
    }

    public function test_a_coach_out_of_scope_is_refused_each_blocks_subject(): void {
        $cases = [
            [ 'category_averages', $this->team( $this->team_b ) ],
            [ 'category_averages', $this->player( $this->pb1 ) ],
            [ 'progress_radar', $this->player( $this->pb1 ) ],
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
        // The radar reads up to five evaluations: start from more than five.
        for ( $d = 1; $d <= 3; $d++ ) $this->evaluate( $this->pa1, '2019-12-0' . $d, [ $this->cat_a => 6.0 ] );
        $before = [
            'team'   => $this->statementsFor( 'category_averages', $team ),
            'player' => $this->statementsFor( 'category_averages', $player ),
            'radar'  => $this->statementsFor( 'progress_radar', $player ),
        ];

        // Six more evaluated squad players, and six more evaluations of the player.
        for ( $i = 0; $i < 6; $i++ ) {
            $pid = $this->insertPlayer( $this->team_a, 'Extra' . $i, 20 + $i );
            $this->evaluate( $pid, '2020-03-1' . $i, [ $this->cat_a => 6.5 ] );
            $this->evaluate( $this->pa1, '2020-01-1' . $i, [ $this->cat_a => 6.5 ] );
        }

        $this->assertSame( $before['team'], $this->statementsFor( 'category_averages', $team ), 'the team averages ran a statement per player' );
        $this->assertSame( $before['radar'], $this->statementsFor( 'progress_radar', $player ), 'the radar reads five evaluations, however many there are' );
        // The player's averages read the evidence packet over the period; the
        // extra evaluations are before it.
        $this->assertSame( $before['player'], $this->statementsFor( 'category_averages', $player ), 'the player averages ran a statement per evaluation' );
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
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Ratings',
            'status' => 'active', 'jersey_number' => $jersey,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertCategory( string $label, int $order ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id' => $this->club, 'category_key' => sanitize_key( $label ) . '_' . wp_rand( 1000, 9999 ),
            'label' => $label, 'parent_id' => null, 'display_order' => $order, 'is_active' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<int,float> $ratings category id => rating */
    private function evaluate( int $player_id, string $date, array $ratings, string $notes = '' ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $player_id, 'coach_id' => 1, 'eval_type_id' => 0,
            'eval_date' => $date, 'notes' => $notes,
        ] );
        $eval_id = (int) $wpdb->insert_id;
        foreach ( $ratings as $category => $rating ) {
            $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
                'club_id' => $this->club, 'evaluation_id' => $eval_id, 'category_id' => $category, 'rating' => $rating,
            ] );
        }
    }

    /** An assistant coach of one team, through the auth bridge: the person, the team seat and the scope. */
    private function assistantCoachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Assistant', 'last_name' => 'Ratings',
            'role_type' => 'assistant_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        $this->flush();
        return $user;
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Ratings',
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
