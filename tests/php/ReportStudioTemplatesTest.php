<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportTemplateRegistry;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Templates\PlayerReportTemplate;

/**
 * #5022 — the player report as Studio templates, one per audience.
 *
 * - each template opens on the sections today's report opens on for that
 *   audience, in its order;
 * - each block's payload equals the player report's payload for the same
 *   player, period and audience, and prints the same section;
 * - a scout or family template holds only blocks on that audience's
 *   allowlist, and a definition that adds one off it is refused;
 * - a note on the notes block is stored on the snapshot, never in the
 *   report's definition;
 * - `GET /report-templates` lists only the templates the caller may start.
 */
final class ReportStudioTemplatesTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private const FROM   = '2020-03-01';
    private const TO     = '2020-03-31';
    private const PERIOD = '2020-03-01/2020-03-31';
    private const NOTE   = 'Agreed to work on the weaker foot before the summer.';

    private int $club = 1;
    private int $team = 0;
    private int $other_team = 0;
    private int $player = 0;
    private int $hod = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Template U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Template U16', 'age_group' => 'U16' ] );
        $this->other_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Tess', 'last_name' => 'Template',
            'jersey_number' => 8, 'status' => 'active', 'date_of_birth' => '2006-04-12', 'preferred_positions' => '["CM"]',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->coach = $this->coachOf( $this->team );
        $this->seed();
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    /** @return array<string,array{string}> */
    public function audiences(): array {
        return [
            'internal' => [ PlayerReportAudience::INTERNAL ],
            'scout'    => [ PlayerReportAudience::SCOUT ],
            'family'   => [ PlayerReportAudience::FAMILY ],
        ];
    }

    /** @dataProvider audiences */
    public function test_a_template_opens_on_the_reports_sections_for_its_audience( string $audience ): void {
        $template = ReportTemplateRegistry::find( PlayerReportTemplate::KEYS[ $audience ] );
        $this->assertNotNull( $template );
        $this->assertSame( $audience, $template->choices['audience'] );

        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [], $this->hod, $audience );
        $this->assertIsArray( $report );
        $this->assertSame( $report['blocks'], $this->sectionsOf( $template->definition['blocks'] ), 'the report\'s default composition, in its order' );

        foreach ( $template->definition['blocks'] as $block ) {
            $registered = BlockRegistry::find( $block['key'] );
            $this->assertNotNull( $registered, $block['key'] );
            $this->assertContains( $audience, $registered->audiences(), "{$block['key']} may reach the {$audience} audience" );
        }
    }

    /** @dataProvider audiences */
    public function test_each_template_block_composes_and_prints_the_reports_section( string $audience ): void {
        $template = ReportTemplateRegistry::find( PlayerReportTemplate::KEYS[ $audience ] );
        $this->assertNotNull( $template );
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [], $this->hod, $audience );
        $this->assertIsArray( $report );

        $context = [ 'scope' => 'player', 'player_id' => $this->player, 'from' => self::FROM, 'to' => self::TO, 'audience' => $audience ];
        $doc     = [ 'context' => $context, 'blocks' => [], 'page_fit' => [] ];
        foreach ( $template->definition['blocks'] as $block ) {
            $read = BlockEngine::preview( $block['key'], $context, [], $this->hod );
            $this->assertIsArray( $read, $block['key'] . ( $read instanceof StudioError ? ': ' . $read->message : '' ) );
            $doc['blocks'][] = $block + [ 'data' => $read['data'] ];

            $section = $this->sectionOf( $block['key'] );
            if ( $section === PlayerReportBlock::LETTERHEAD ) {
                foreach ( $report['data'][ $section ] as $k => $v ) {
                    if ( $k === 'generated_at' ) continue;
                    $this->assertSame( $v, $read['data'][ $k ] ?? null, "letterhead.{$k}" );
                }
                continue;
            }
            $this->assertSame( $this->json( $report['data'][ $section ] ), $this->json( $read['data'] ), "{$section} for {$audience}" );
        }

        // The rendered section headers and figures: each section prints with
        // the report's own renderer, from the report's own payload.
        $body = StudioDocument::body( $doc, $this->hod );
        foreach ( $report['blocks'] as $section ) {
            if ( $section === PlayerReportBlock::LETTERHEAD ) continue;
            $this->assertStringContainsString( PlayerReportPdfDocument::sectionHtml( $section, $report['data'][ $section ] ), $body, "{$section} prints as the report prints it" );
            $this->assertStringContainsString( esc_html( PlayerReportBlock::title( $section ) ), $body );
        }
    }

    public function test_a_scout_or_family_report_refuses_a_block_off_its_list(): void {
        foreach ( [ PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
            $id     = $this->report( $audience );
            $blocks = PlayerReportTemplate::blocks( $audience );
            $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] )->get_status(), "the {$audience} template saves" );

            foreach ( [ PlayerReportBlock::STATUS, PlayerReportBlock::INJURIES, PlayerReportBlock::BEHAVIOUR, PlayerReportBlock::POTENTIAL, PlayerReportBlock::THREAD_NOTES, PlayerReportBlock::NOTES ] as $section ) {
                $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => array_merge( $blocks, [ [ 'id' => 'b99', 'key' => PlayerSectionBlock::KEYS[ $section ] ] ] ) ] ] );
                $this->assertSame( 400, $res->get_status(), "{$section} on a {$audience} report" );
                $this->assertSame( 'invalid_field', $this->code( $res ) );
            }
        }
        $this->assertNotContains( PlayerReportAudience::SCOUT, BlockRegistry::find( PlayerSectionBlock::KEYS[ PlayerReportBlock::GOALS ] )->audiences(), 'a scout never receives goals' );
    }

    public function test_a_note_on_the_notes_block_is_stored_on_the_snapshot_only(): void {
        $id     = $this->report( PlayerReportAudience::INTERNAL );
        $blocks = PlayerReportTemplate::blocks( PlayerReportAudience::INTERNAL );
        $notes  = array_values( array_filter( $blocks, static fn( array $b ): bool => $b['key'] === PlayerSectionBlock::KEYS[ PlayerReportBlock::NOTES ] ) );
        $this->assertCount( 1, $notes, 'the staff template has the notes area' );
        $note_block = $notes[0]['id'];

        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] )->get_status() );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First' ] )->get_status() );
        $snap = $this->send( 'POST', "reports/{$id}/snapshots" );
        $this->assertSame( 201, $snap->get_status(), (string) wp_json_encode( $snap->get_data() ) );
        $uuid = (string) $snap->get_data()['data']['uuid'];

        $res = $this->send( 'PUT', "report-snapshots/{$uuid}/notes/{$note_block}", [ 'body' => self::NOTE ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $this->assertStringContainsString( self::NOTE, (string) $res->get_data()['data']['html'], 'the note prints under the notes area' );

        global $wpdb;
        $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes_json FROM {$wpdb->prefix}tt_player_report_snapshots WHERE uuid = %s", $uuid ) );
        $this->assertStringContainsString( self::NOTE, $stored, 'on the snapshot' );
        foreach ( [ 'tt_report_drafts', 'tt_report_versions' ] as $table ) {
            $definitions = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(definition_json) FROM {$wpdb->prefix}{$table} WHERE report_id = %d", $id ) );
            $this->assertNotSame( '', $definitions, "fixture: {$table} holds the definition" );
            $this->assertStringNotContainsString( self::NOTE, $definitions, "never in {$table}" );
        }
    }

    public function test_the_templates_route_lists_what_the_caller_may_start(): void {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'GET', 'report-templates' );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $hod = array_column( (array) $res->get_data()['data']['templates'], 'key' );
        foreach ( PlayerReportTemplate::KEYS as $key ) $this->assertContains( $key, $hod, "the head of development may start {$key}" );

        wp_set_current_user( $this->coach );
        $res = $this->send( 'GET', 'report-templates' );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $coach = array_column( (array) $res->get_data()['data']['templates'], 'key' );
        $this->assertContains( PlayerReportTemplate::KEYS[ PlayerReportAudience::INTERNAL ], $coach );
        $this->assertContains( PlayerReportTemplate::KEYS[ PlayerReportAudience::FAMILY ], $coach );
        $this->assertNotContains( PlayerReportTemplate::KEYS[ PlayerReportAudience::SCOUT ], $coach, 'only someone who publishes writes for a scout' );

        $first = (array) $res->get_data()['data']['templates'][0];
        foreach ( [ 'key', 'label', 'description', 'choices', 'definition' ] as $field ) $this->assertArrayHasKey( $field, $first );

        $player = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        wp_set_current_user( $player );
        $this->assertSame( 403, $this->send( 'GET', 'report-templates' )->get_status(), 'a reader who builds nothing' );
    }

    // ---- helpers ---------------------------------------------------------------------

    /** A report about the player by the head of development, with the current user set to them. */
    private function report( string $audience ): int {
        wp_set_current_user( $this->hod );
        $choices = [
            'kind' => 'report', 'scope' => 'player', 'audience' => $audience, 'binding' => 'fixed',
            'player_id' => $this->player, 'period_mode' => 'fixed', 'period_value' => self::PERIOD, 'layout' => 'rows',
        ] + ( $audience === PlayerReportAudience::FAMILY ? [ 'sharing' => 'family' ] : [] );
        $res = $this->send( 'POST', 'reports', $choices );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
    }

    /**
     * @param list<array{key:string}> $blocks
     * @return list<string>
     */
    private function sectionsOf( array $blocks ): array {
        return array_map( fn( array $b ): string => $this->sectionOf( $b['key'] ), $blocks );
    }

    private function sectionOf( string $key ): string {
        if ( $key === LetterheadBlock::KEY ) return PlayerReportBlock::LETTERHEAD;
        $section = array_search( $key, PlayerSectionBlock::KEYS, true );
        $this->assertIsString( $section, $key );
        return $section;
    }

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

    /** @param array<string,mixed> $params */
    private function send( string $method, string $route, array $params = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            if ( $params !== [] ) $req->set_query_params( $params );
        } else {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $params ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Head', 'last_name' => 'Template',
            'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    /** March 2020 for one player: trainings, a match with minutes, an evaluation, a test and a goal. */
    private function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ [ '2020-03-03', 'training', 'Present', null ], [ '2020-03-10', 'training', 'Absent', null ], [ '2020-03-07', 'game', 'Present', 70 ] ] as [ $date, $type, $status, $minutes ] ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => ucfirst( $type ) . ' ' . $date, 'session_date' => $date,
                'activity_type_key' => $type, 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] + ( $type === 'game' ? [ 'opponent' => 'Rood', 'home_away' => 'home', 'home_score' => 2, 'away_score' => 1 ] : [] ) );
            $row = [ 'club_id' => $this->club, 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $this->player, 'status' => $status, 'record_type' => 'actual', 'is_guest' => 0 ];
            if ( $minutes !== null ) $row['minutes_played'] = $minutes;
            $wpdb->insert( "{$p}tt_attendance", $row );
        }

        $wpdb->insert( "{$p}tt_eval_categories", [
            'club_id' => $this->club, 'category_key' => 'zz_template_' . wp_rand( 1000, 9999 ), 'label' => 'Zz template',
            'parent_id' => null, 'display_order' => 991, 'is_active' => 1,
        ] );
        $category = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $this->player, 'coach_id' => $this->hod, 'eval_type_id' => 0,
            'eval_date' => '2020-03-12', 'rating' => 7.0, 'notes' => 'A coach\'s written note.',
        ] );
        $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $this->club, 'evaluation_id' => (int) $wpdb->insert_id, 'category_id' => $category, 'rating' => 7.0 ] );

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => 'Template sprint', 'value_type' => 'numeric',
            'unit' => 's', 'direction' => 'lower', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $definition = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_measurement_results", [
            'club_id' => $this->club, 'definition_id' => $definition, 'player_id' => $this->player, 'recorded_date' => '2020-03-11', 'value_numeric' => 4.2,
        ] );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => $this->club, 'player_id' => $this->player, 'title' => 'Win more headers', 'status' => 'in_progress',
            'created_by' => $this->hod, 'created_at' => '2020-03-05 09:00:00', 'updated_at' => '2020-03-05 09:00:00',
        ] );
    }
}
