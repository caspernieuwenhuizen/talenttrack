<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\DescribesOptions;
use TT\Modules\Analytics\Reports\EvaluationsBlockOptions;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\ReportStudio\Blocks\MonthlySectionBlock;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockOption;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\FamilySection;
use TT\Modules\ReportStudio\Domain\RendersDocument;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * #4961 — the second wave of Studio blocks: every other section of the team
 * monthly report and the player report, over the report's own composers.
 *
 * - every section of both reports has a block, apart from the note sections;
 * - each block's payload equals the report's payload for the same section,
 *   reader and options; a player section for every audience it may reach;
 * - a block prints with the report's section renderer and is measured with
 *   the report's heights;
 * - a section's options are generated from its option class, and the class
 *   accepts no option the block does not offer;
 * - injuries, behaviour and potential are staff only, refused for a scout or
 *   a family, and an injuries read writes what the report's read writes;
 * - a section a family may receive names it, and only that one.
 */
final class ReportStudioSectionBlocksTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    /** The sections the first wave already carries, and the blocks that carry them. */
    private const FIRST_WAVE_MONTHLY = [
        TeamMonthlyReportBlock::LETTERHEAD => 'letterhead',
        TeamMonthlyReportBlock::KPI        => 'key_figures',
        TeamMonthlyReportBlock::ATTENDANCE => 'attendance_per_player',
        TeamMonthlyReportBlock::MINUTES    => 'minutes_share',
        TeamMonthlyReportBlock::MATCHES    => 'match_results',
    ];
    private const FIRST_WAVE_PLAYER = [
        PlayerReportBlock::LETTERHEAD => 'letterhead',
        PlayerReportBlock::RATINGS    => 'category_averages',
        PlayerReportBlock::ATTENDANCE => 'attendance_per_week',
        PlayerReportBlock::MINUTES    => 'minutes_per_match',
        PlayerReportBlock::GOALS      => 'open_goals',
    ];

    private int $club = 1;
    private int $team = 0;
    private int $hod = 0;
    private int $test_def = 0;

    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team = $this->insertTeam( 'Sections U14' );
        $this->hod  = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team );
        $this->seed();
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- coverage --------------------------------------------------------------

    public function test_every_section_of_both_reports_has_a_block_apart_from_the_notes(): void {
        $monthly = array_diff( TeamMonthlyReportBlock::ALL, array_keys( self::FIRST_WAVE_MONTHLY ), [ TeamMonthlyReportBlock::NOTES ] );
        $this->assertEqualsCanonicalizing( array_values( $monthly ), array_keys( MonthlySectionBlock::KEYS ) );

        $player = array_diff( PlayerReportBlock::ALL, array_keys( self::FIRST_WAVE_PLAYER ), [ PlayerReportBlock::NOTES, PlayerReportBlock::THREAD_NOTES ] );
        $this->assertEqualsCanonicalizing( array_values( $player ), array_keys( PlayerSectionBlock::KEYS ) );

        foreach ( array_merge( self::FIRST_WAVE_MONTHLY, self::FIRST_WAVE_PLAYER, MonthlySectionBlock::KEYS, PlayerSectionBlock::KEYS ) as $key ) {
            $this->assertNotNull( BlockRegistry::find( $key ), "{$key} is registered" );
        }
    }

    // ---- the monthly sections ----------------------------------------------------

    /** @return array<string,array{string}> */
    public function monthlySections(): array {
        $out = [];
        foreach ( array_keys( MonthlySectionBlock::KEYS ) as $section ) $out[ $section ] = [ $section ];
        return $out;
    }

    /** @dataProvider monthlySections */
    public function test_a_monthly_section_block_composes_the_reports_payload( string $section ): void {
        $block  = $this->preview( MonthlySectionBlock::KEYS[ $section ], $this->teamContext() );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ $section ], $this->hod );

        $this->assertArrayHasKey( $section, $report['data'] );
        $this->assertNotSame( [], $block['data'], "the fixture gives the {$section} composer something" );
        $this->assertSame( $this->json( $report['data'][ $section ] ), $this->json( $block['data'] ) );
    }

    /** @dataProvider monthlySections */
    public function test_a_monthly_section_prints_and_measures_as_the_monthly_report( string $section ): void {
        $renderer = BlockRegistry::find( MonthlySectionBlock::KEYS[ $section ] );
        $this->assertInstanceOf( RendersDocument::class, $renderer );
        $data = $this->preview( MonthlySectionBlock::KEYS[ $section ], $this->teamContext() )['data'];

        $this->assertStringContainsString( TeamMonthlyReportDocument::sectionHtml( $section, $data ), $renderer->document( $data ) );
        $this->assertSame( TeamMonthlyReportLayout::sectionHeight( $section, $data ), $renderer->documentHeight( $data ) );
    }

    public function test_the_tests_and_evaluations_options_come_from_their_option_classes(): void {
        foreach ( [ TeamMonthlyReportBlock::TESTS => TestsBlockOptions::class, TeamMonthlyReportBlock::EVALUATIONS => EvaluationsBlockOptions::class ] as $section => $owner ) {
            $this->assertSame( $owner, TeamMonthlyReportBlockOptions::ownerOf( $section ) );
            $this->assertTrue( is_subclass_of( $owner, DescribesOptions::class ) );

            $block   = BlockRegistry::find( MonthlySectionBlock::KEYS[ $section ] );
            $this->assertNotNull( $block );
            $options = $block->options( \TT\Modules\ReportStudio\Domain\ReportContext::forListing( 'team', PlayerReportAudience::INTERNAL, $this->hod ) );
            $keys    = array_map( static fn( BlockOption $o ): string => $o->key, $options );
            $this->assertSame( array_column( $owner::fields(), 'key' ), $keys, "{$section}: one option per field of the class" );

            // The class accepts exactly the fields it describes, so an option
            // added to the class without a field is refused by the report too.
            foreach ( $keys as $key ) $this->assertSame( [], $owner::unknownKeys( [ $key => '1' ] ), "{$section}.{$key}" );
            $this->assertSame( [ 'not_a_field' ], $owner::unknownKeys( [ 'not_a_field' => '1' ] ) );
        }

        $this->assertSame( [], TestsBlockOptions::unknownKeys( [ 'show' => 'values' ] ), 'the deprecated spelling is still accepted' );
    }

    public function test_a_monthly_section_with_options_composes_what_the_report_composes_with_them(): void {
        $options = [ 'level' => 'details', 'change' => false, 'definitions' => [ $this->test_def ] ];
        $read    = BlockEngine::preview( MonthlySectionBlock::KEYS[ TeamMonthlyReportBlock::TESTS ], $this->teamContext(), $options, $this->hod );
        $this->assertIsArray( $read );
        $report  = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ TeamMonthlyReportBlock::TESTS ], $this->hod, [ TeamMonthlyReportBlock::TESTS => $options ] );
        $this->assertSame( $this->json( $report['data'][ TeamMonthlyReportBlock::TESTS ] ), $this->json( $read['data'] ) );

        $default = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ TeamMonthlyReportBlock::TESTS ], $this->hod );
        $this->assertNotSame( $this->json( $default['data'][ TeamMonthlyReportBlock::TESTS ] ), $this->json( $read['data'] ), 'the options reach the composer' );

        $refused = BlockEngine::preview( MonthlySectionBlock::KEYS[ TeamMonthlyReportBlock::TESTS ], $this->teamContext(), [ 'level' => 'everything' ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $refused );
        $this->assertSame( 'invalid_field', $refused->code );

        $unknown = BlockEngine::preview( MonthlySectionBlock::KEYS[ TeamMonthlyReportBlock::EVALUATIONS ], $this->teamContext(), [ 'detial' => 'x' ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $unknown );
        $this->assertSame( 'unknown_field', $unknown->code );
    }

    public function test_a_monthly_section_is_staff_only_and_team_only(): void {
        foreach ( MonthlySectionBlock::KEYS as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block );
            $this->assertSame( [ PlayerReportAudience::INTERNAL ], $block->audiences(), $key );
            $this->assertSame( [], $block->externalKeys(), $key );
            $this->assertNotInstanceOf( FamilySection::class, $block, $key );
        }
    }

    // ---- the player sections -----------------------------------------------------

    /** @return array<string,array{string}> */
    public function playerSections(): array {
        $out = [];
        foreach ( array_keys( PlayerSectionBlock::KEYS ) as $section ) $out[ $section ] = [ $section ];
        return $out;
    }

    /** @dataProvider playerSections */
    public function test_a_player_section_block_composes_the_reports_payload_for_every_audience( string $section ): void {
        $key   = PlayerSectionBlock::KEYS[ $section ];
        $block = BlockRegistry::find( $key );
        $this->assertNotNull( $block );
        $this->assertSame( PlayerReportAudience::audiencesFor( $section ), $block->audiences(), 'the report\'s allowlists, not a list of its own' );

        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
            SharedReads::flush();
            $read = BlockEngine::preview( $key, $this->playerContext( $audience ), [], $this->hod );
            if ( ! in_array( $audience, $block->audiences(), true ) ) {
                $this->assertInstanceOf( StudioError::class, $read, "{$section} for {$audience}" );
                $this->assertSame( 'invalid_field', $read->code );
                $this->assertSame( 400, $read->status );
                continue;
            }
            $this->assertIsArray( $read, "{$section} for {$audience}" );
            $report = ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, [ $section ], $this->hod, $audience );
            $this->assertIsArray( $report );
            $this->assertArrayHasKey( $section, $report['data'] );
            $this->assertSame( $this->json( $report['data'][ $section ] ), $this->json( $read['data'] ), "{$section} for {$audience}" );
        }
    }

    /** @dataProvider playerSections */
    public function test_a_player_section_prints_and_measures_as_the_player_report( string $section ): void {
        $renderer = BlockRegistry::find( PlayerSectionBlock::KEYS[ $section ] );
        $this->assertInstanceOf( RendersDocument::class, $renderer );
        $data = $this->preview( PlayerSectionBlock::KEYS[ $section ], $this->playerContext() )['data'];

        $html = $renderer->document( $data );
        $this->assertStringContainsString( PlayerReportPdfDocument::sectionHtml( $section, $data ), $html );
        $this->assertStringContainsString( esc_html( PlayerReportBlock::title( $section ) ), $html, 'the section prints its own heading' );
        $this->assertSame( PlayerReportLayout::printedHeight( $section, $data ), $renderer->documentHeight( $data ) );
    }

    public function test_the_player_sections_print_with_the_reports_rules_on_paper_and_screen(): void {
        $scoped = PlayerReportPdfDocument::scopedCss();
        $this->assertStringContainsString( '.tt-pr-doc .tt-d-tbl', $scoped );
        $this->assertStringNotContainsString( '@page', $scoped );
        $this->assertStringNotContainsString( 'flex', $scoped );
        $this->assertStringNotContainsString( 'grid', $scoped );
        $this->assertStringContainsString( $scoped, StudioDocument::screenCss() );

        $html = PlayerReportPdfDocument::sectionHtml( PlayerReportBlock::BEHAVIOUR, [ 'items' => [] ] );
        $this->assertDoesNotMatchRegularExpression( '/class="(?!tt-)/', $html, 'every class is prefixed' );
    }

    public function test_injuries_behaviour_and_potential_are_staff_only(): void {
        foreach ( [ PlayerReportBlock::INJURIES, PlayerReportBlock::BEHAVIOUR, PlayerReportBlock::POTENTIAL ] as $section ) {
            $key   = PlayerSectionBlock::KEYS[ $section ];
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block );
            $this->assertSame( [ PlayerReportAudience::INTERNAL ], $block->audiences(), $key );

            foreach ( [ PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
                $saved = ReportDefinition::normalise( [ 'blocks' => [ [ 'key' => $key ] ] ], 'rows', 'player', $audience );
                $this->assertInstanceOf( StudioError::class, $saved, "{$key} on a {$audience} report" );
                $this->assertSame( 'invalid_field', $saved->code );
                $this->assertSame( 400, $saved->status );
            }
            $this->assertIsArray( ReportDefinition::normalise( [ 'blocks' => [ [ 'key' => $key ] ] ], 'rows', 'player', PlayerReportAudience::INTERNAL ) );
        }
    }

    public function test_an_injuries_read_writes_what_the_player_reports_read_writes(): void {
        $before = $this->auditRows();
        ( new PlayerReport() )->forPlayer( $this->players[1], self::FROM, self::TO, [ PlayerReportBlock::INJURIES ], $this->hod, PlayerReportAudience::INTERNAL );
        $report = $this->auditRows() - $before;

        SharedReads::flush();
        $before = $this->auditRows();
        $this->preview( PlayerSectionBlock::KEYS[ PlayerReportBlock::INJURIES ], $this->playerContext( PlayerReportAudience::INTERNAL, $this->players[1] ) );
        $this->assertSame( $report, $this->auditRows() - $before );
    }

    public function test_only_a_section_a_family_may_receive_names_it(): void {
        foreach ( PlayerSectionBlock::KEYS as $section => $key ) {
            $block  = BlockRegistry::find( $key );
            $family = in_array( $section, PlayerReportAudience::FAMILY_BLOCKS, true );
            $this->assertSame( $family, $block instanceof FamilySection, $key );
            if ( $block instanceof FamilySection ) $this->assertSame( $section, $block->familySection() );
            $this->assertSame( $family || in_array( $section, PlayerReportAudience::SCOUT_BLOCKS, true ) ? [ 'items' ] : [], $block->externalKeys(), $key );
        }
    }

    // ---- helpers -------------------------------------------------------------------

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
    private function teamContext(): array {
        return [ 'scope' => 'team', 'team_id' => $this->team, 'from' => self::FROM, 'to' => self::TO ];
    }

    /** @return array<string,mixed> */
    private function playerContext( string $audience = PlayerReportAudience::INTERNAL, int $player = 0 ): array {
        return [ 'scope' => 'player', 'player_id' => $player ?: $this->players[0], 'from' => self::FROM, 'to' => self::TO, 'audience' => $audience ];
    }

    /** A payload as JSON, with the clock's values levelled. */
    private function json( mixed $payload ): string {
        return (string) wp_json_encode( $this->level( $payload ) );
    }

    private function level( mixed $payload ): mixed {
        if ( ! is_array( $payload ) ) return $payload;
        foreach ( $payload as $key => $value ) {
            $payload[ $key ] = $key === 'generated_at' || $key === 'as_of' ? '' : $this->level( $value );
        }
        return $payload;
    }

    private function auditRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log" );
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
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => 'U14' ] );
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
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Sections',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }

    /**
     * One squad over March 2020: trainings with registers, matches with
     * minutes, evaluations, a test taken twice, an injury, a journey entry,
     * behaviour and potential.
     */
    private function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ [ 'Anna', 7 ], [ 'Bram', 3 ], [ 'Cas', 10 ] ] as [ $first, $jersey ] ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => $first, 'last_name' => 'Sections',
                'jersey_number' => $jersey, 'status' => 'active', 'date_of_birth' => '2006-04-12', 'preferred_positions' => '["CB"]',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $statuses = [ [ 'Present', 'Absent', 'Late' ], [ 'Present', 'Present', 'Absent' ], [ 'Excused', 'Present', 'Absent' ] ];
        foreach ( [ '2020-02-11', '2020-03-03', '2020-03-10', '2020-03-17' ] as $i => $date ) {
            $id = $this->activity( $date, 'training' );
            foreach ( $this->players as $j => $pid ) $this->attend( $id, $pid, $statuses[ max( 0, $i - 1 ) ][ $j ] );
        }
        $this->activity( '2020-03-24', 'training' );
        foreach ( [ [ '2020-03-07', 3, 0 ], [ '2020-03-14', 0, 2 ] ] as [ $date, $ours, $theirs ] ) {
            $id = $this->activity( $date, 'game', [ 'opponent' => 'Rood', 'home_away' => 'home', 'home_score' => $ours, 'away_score' => $theirs ] );
            foreach ( $this->players as $j => $pid ) $this->attend( $id, $pid, 'Present', $j === 2 ? 0 : 60 );
        }

        $wpdb->insert( "{$p}tt_eval_categories", [
            'club_id' => $this->club, 'category_key' => 'zz_sections_' . wp_rand( 1000, 9999 ), 'label' => 'Zz sections',
            'parent_id' => null, 'display_order' => 990, 'is_active' => 1,
        ] );
        $category = (int) $wpdb->insert_id;
        foreach ( [ [ 0, '2020-02-20', 6.0 ], [ 0, '2020-03-12', 7.0 ], [ 1, '2020-03-19', 5.0 ], [ 2, '2020-03-20', 2.0 ] ] as [ $who, $date, $rating ] ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $this->club, 'player_id' => $this->players[ $who ], 'coach_id' => $this->hod,
                'eval_type_id' => 0, 'eval_date' => $date, 'notes' => 'Sections evaluation.',
            ] );
            $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $this->club, 'evaluation_id' => (int) $wpdb->insert_id, 'category_id' => $category, 'rating' => $rating ] );
        }

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => 'Sections jump', 'value_type' => 'numeric',
            'unit' => 'cm', 'direction' => 'higher', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $this->test_def = (int) $wpdb->insert_id;
        foreach ( [ '2020-02-12' => [ 30.0, 34.0, 28.0 ], '2020-03-11' => [ 33.0, 32.0, 31.0 ] ] as $date => $values ) {
            $wpdb->insert( "{$p}tt_measurement_sessions", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'definition_id' => $this->test_def,
                'team_id' => $this->team, 'planned_date' => $date, 'status' => 'completed',
            ] );
            foreach ( $this->players as $i => $pid ) {
                $wpdb->insert( "{$p}tt_measurement_results", [
                    'club_id' => $this->club, 'definition_id' => $this->test_def, 'player_id' => $pid,
                    'recorded_date' => $date, 'value_numeric' => $values[ $i ],
                ] );
            }
        }

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $this->players[1], 'started_on' => '2020-03-18', 'actual_return' => null, 'notes' => 'Ankle.',
        ] );
        $wpdb->insert( "{$p}tt_player_events", [
            'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $this->players[0], 'event_type' => 'position_changed',
            'event_date' => '2020-03-20 09:00:00', 'summary' => 'Moved to centre back.', 'visibility' => 'public',
            'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => 1,
        ] );

        ( new PlayerBehaviourRatingsRepository() )->create( [ 'player_id' => $this->players[0], 'rated_at' => '2020-03-10 10:00:00', 'rated_by' => $this->hod, 'rating' => 8.0, 'context' => 'training', 'notes' => 'Leads the warm-up.' ] );
        ( new PlayerPotentialRepository() )->create( [ 'player_id' => $this->players[0], 'set_at' => '2020-03-15 10:00:00', 'set_by' => $this->hod, 'potential_band' => 'ahead', 'notes' => 'Reads the game early.' ] );
    }

    /** @param array<string,mixed> $extra */
    private function activity( string $date, string $type, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", $extra + [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => ucfirst( $type ) . ' ' . $date,
            'session_date' => $date, 'activity_type_key' => $type, 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( int $activity_id, int $player_id, string $status, ?int $minutes = null ): void {
        global $wpdb;
        $row = [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'record_type' => 'actual', 'is_guest' => 0,
        ];
        if ( $minutes !== null ) $row['minutes_played'] = $minutes;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", $row );
    }
}
