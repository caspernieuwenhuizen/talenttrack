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
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Exporters\ReportStudioPdfExporter;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\CutsForReader;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4961 — snapshots show each reader only the player sections they may read
 * now. A player section's records are gated inside its payload (injuries on
 * the medical rung, journey entries and tests on the reader's levels), so a
 * frozen copy is cut by the same rights the live section is composed with.
 *
 * - an assistant coach without the medical rung gets no injury and no
 *   medical journey entry from a snapshot the head of development took, on
 *   both snapshot routes and in the PDF;
 * - the head of development still gets them;
 * - for every player section block, the frozen copy cut for a reader equals
 *   what the live block answers that reader.
 */
final class ReportStudioSnapshotSectionsTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private const FROM   = '2020-03-01';
    private const TO     = '2020-03-31';
    private const PERIOD = '2020-03-01/2020-03-31';

    private const INJURY  = 'Hamstring strain, left leg.';
    private const MEDICAL = 'Medical: scan booked.';
    private const PUBLIC  = 'Captain for the cup match.';

    private int $club = 1;
    private int $team = 0;
    private int $player = 0;
    private int $hod = 0;
    private int $assistant = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Sections U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Sven', 'last_name' => 'Sections', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->assistant = $this->assistantOf( $this->team );
        $this->seed();

        FeatureRegistry::setEnabled( 'report_studio', true );
        FeatureRegistry::setEnabled( 'journey_medical_visibility', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
        $this->flush();

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

    public function test_a_reader_without_the_medical_rung_gets_no_injury_from_a_snapshot(): void {
        $this->assertTrue( ReaderSet::of( [ $this->hod ] )->seesMedical(), 'Precondition: the head of development reads injuries.' );
        $this->assertFalse( ReaderSet::of( [ $this->assistant ] )->seesMedical(), 'Precondition: the assistant coach does not.' );

        $uuid = $this->snapshot( $this->published() );

        foreach ( [ [ $this->assistant, false ], [ $this->hod, true ] ] as [ $reader, $sees ] ) {
            wp_set_current_user( $reader );
            foreach ( [ "report-snapshots/{$uuid}", "player-report-snapshots/{$uuid}" ] as $route ) {
                $res = $this->send( 'GET', $route );
                $this->assertSame( 200, $res->get_status(), $route . ' ' . wp_json_encode( $res->get_data() ) );
                $body = (string) wp_json_encode( $res->get_data() );
                $this->assertSame( $sees, str_contains( $body, self::INJURY ), "{$route}: the injury" );
                $this->assertSame( $sees, str_contains( $body, self::MEDICAL ), "{$route}: the medical journey entry" );
                $this->assertStringContainsString( self::PUBLIC, $body, "{$route}: the public journey entry stays" );
            }

            $read = ReportSnapshots::read( $uuid, $reader );
            $this->assertIsArray( $read );
            $pdf = ReportStudioPdfExporter::documentPayload( $read['document'], $reader, 'Snapshot', $read['notes'] )['html'];
            $this->assertSame( $sees, str_contains( (string) $pdf, self::INJURY ), 'the PDF: the injury' );
            $this->assertSame( $sees, str_contains( (string) $pdf, self::MEDICAL ), 'the PDF: the medical journey entry' );
        }
    }

    public function test_the_frozen_copy_cut_for_a_reader_equals_the_live_block(): void {
        $context = [ 'scope' => 'player', 'player_id' => $this->player, 'from' => self::FROM, 'to' => self::TO, 'audience' => 'internal' ];
        foreach ( PlayerSectionBlock::KEYS as $section => $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertInstanceOf( CutsForReader::class, $block, $key );

            SharedReads::flush();
            $frozen = BlockEngine::preview( $key, $context, [], $this->hod );
            $this->assertIsArray( $frozen, $key . ( $frozen instanceof StudioError ? ': ' . $frozen->message : '' ) );

            foreach ( [ $this->hod, $this->assistant ] as $reader ) {
                SharedReads::flush();
                $live = BlockEngine::preview( $key, $context, [], $reader );
                $this->assertIsArray( $live, "{$key} for {$reader}" . ( $live instanceof StudioError ? ': ' . $live->message : '' ) );
                $this->assertSame(
                    $this->json( $live['data'] ),
                    $this->json( $block->forReader( $frozen['data'], $reader, $context ) ),
                    "{$section}: the frozen copy reads for this reader as the live block does"
                );
            }
        }
    }

    // ---- helpers ---------------------------------------------------------------------

    /** A staff report on the player with every player section, published by the head of development. */
    private function published(): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', [
            'kind' => 'report', 'scope' => 'player', 'audience' => 'internal', 'binding' => 'fixed',
            'player_id' => $this->player, 'period_mode' => 'fixed', 'period_value' => self::PERIOD,
        ] );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id = (int) $res->get_data()['data']['id'];

        $blocks = [ [ 'id' => 'b1', 'key' => 'letterhead' ] ];
        foreach ( array_values( PlayerSectionBlock::KEYS ) as $i => $key ) $blocks[] = [ 'id' => 'b' . ( $i + 2 ), 'key' => $key ];
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First' ] )->get_status() );
        return $id;
    }

    private function snapshot( int $id ): string {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', "reports/{$id}/snapshots" );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $body = (string) wp_json_encode( $res->get_data() );
        $this->assertStringContainsString( self::INJURY, $body, 'Precondition: the taker froze the injury.' );
        return (string) $res->get_data()['data']['uuid'];
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

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    /** An assistant coach of one team, through the auth bridge: the person, the team seat and the scope. */
    private function assistantOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Assistant', 'last_name' => 'Sections',
            'role_type' => 'assistant_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    /** March 2020 for the player: an injury, a public and a medical journey entry, attendance and one test reading. */
    private function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $this->player, 'started_on' => '2020-03-02', 'actual_return' => '2020-03-09', 'notes' => self::INJURY,
        ] );
        foreach ( [ [ 'milestone', '2020-03-20 09:00:00', self::PUBLIC, 'public' ], [ 'injury_started', '2020-03-02 09:00:00', self::MEDICAL, 'medical' ] ] as $i => [ $type, $when, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $this->player, 'event_type' => $type,
                'event_date' => $when, 'summary' => $summary, 'visibility' => $visibility,
                'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => $i + 1,
            ] );
        }

        foreach ( [ '2020-03-03', '2020-03-10' ] as $date ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Training ' . $date, 'session_date' => $date,
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $this->club, 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
                'status' => 'Present', 'record_type' => 'actual', 'is_guest' => 0,
            ] );
        }

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $this->club, 'category_id' => 0, 'name' => 'Sections sprint', 'value_type' => 'numeric',
            'unit' => 's', 'direction' => 'lower', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $wpdb->insert( "{$p}tt_measurement_results", [
            'club_id' => $this->club, 'definition_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
            'recorded_date' => '2020-03-11', 'value_numeric' => 4.2,
        ] );
    }
}
