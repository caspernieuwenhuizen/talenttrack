<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PotentialOverviewQuery;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\Players\PotentialAccess;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;
use TT\Modules\Players\Services\BehaviourPotentialSummary;
use TT\Modules\Players\Services\PotentialTrajectory;
use TT\Modules\TeamDevelopment\Services\PlayerAttributeAudience;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4694 (c) — the read-only observer reads a player's status and potential,
 * with the band masked: it sees that a potential was set, when and by whom,
 * and never the band, its movement or its notes. A scout keeps the band of
 * a player it is linked to and reaches no other; the head of development
 * and a head coach on their own team read what they read before.
 *
 * One rule (`potential_judgement`, `PotentialAccess`), asked at the data
 * sources, and one assertion per path that hands out a band.
 */
final class ObserverReadsPotentialTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0335_authorization_observer_reads_potential.php';

    private const NOTES = 'Reads the game two passes ahead of his age group.';
    private const LABEL = 'Ahead of age group';
    private const SET_AT = '2026-09-15 10:00:00';

    private string $p;
    private string $matrix_was = '';
    private int $club;
    private int $observer = 0;
    private int $scout    = 0;
    private int $hod      = 0;
    private int $coach    = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $elsewhere = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();
        $this->migration()->up();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Obspot U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->player    = $this->player( 'Banda', $this->team );
        $this->elsewhere = $this->player( 'Verra', 0 );

        $this->hod      = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->scout    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->player ] ) );
        $this->coach = $this->headCoach( $this->team );

        $repo = new PlayerPotentialRepository();
        $repo->create( [ 'player_id' => $this->player, 'set_at' => '2026-03-01 10:00:00', 'set_by' => $this->hod, 'potential_band' => 'on_track' ] );
        $repo->create( [ 'player_id' => $this->player, 'set_at' => self::SET_AT, 'set_by' => $this->hod, 'potential_band' => 'ahead', 'notes' => self::NOTES ] );
        $repo->create( [ 'player_id' => $this->elsewhere, 'set_at' => self::SET_AT, 'set_by' => $this->hod, 'potential_band' => 'ahead' ] );
        $this->flush();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_rule_masks_the_observer_alone(): void {
        $this->assertTrue( PotentialAccess::readsWithoutJudgement( $this->observer ), 'the observer is the read-only shape' );
        $this->assertFalse( PotentialAccess::seesJudgement( $this->observer, $this->player ) );
        $this->assertFalse( PotentialAccess::scoutReads( $this->observer, $this->player ), 'reading prospects is not working the funnel' );

        foreach ( [ 'head of development' => $this->hod, 'head coach' => $this->coach, 'scout' => $this->scout ] as $who => $uid ) {
            $this->assertFalse( PotentialAccess::readsWithoutJudgement( $uid ), "{$who}: not the masked shape" );
            $this->assertTrue( PotentialAccess::seesJudgement( $uid, $this->player ), "{$who} reads the band as before" );
        }

        // The scout's own rule: the prospects right and a player they may view.
        $this->assertTrue( PotentialAccess::scoutReads( $this->scout, $this->player ), 'a linked player' );
        $this->assertFalse( PotentialAccess::scoutReads( $this->scout, $this->elsewhere ), 'not a player the scout is not linked to' );
    }

    public function test_the_trajectory_route_keeps_the_dates_and_leaves_out_the_band(): void {
        $response = $this->get( $this->observer, '/talenttrack/v1/players/' . $this->player . '/potential' );
        $this->assertSame( 200, $response->get_status() );
        $data = $this->data( $response );

        $this->assertCount( 2, $data['entries'], 'both entries are there' );
        foreach ( $data['entries'] as $entry ) {
            $this->assertArrayHasKey( 'set_at', $entry );
            $this->assertArrayHasKey( 'set_by_name', $entry );
            foreach ( [ 'band', 'label', 'notes', 'direction', 'steps' ] as $key ) {
                $this->assertArrayNotHasKey( $key, $entry, "the observer gets no {$key}" );
            }
        }
        $this->assertSame( self::SET_AT, $data['current']['set_at'] );
        $this->assertArrayNotHasKey( 'band', $data['current'] );

        $body = (string) wp_json_encode( $data );
        $this->assertStringNotContainsString( self::NOTES, $body );
        $this->assertStringNotContainsString( self::LABEL, $body );
        $this->assertStringNotContainsString( '"ahead"', $body );
    }

    public function test_the_head_of_development_a_head_coach_and_a_linked_scout_read_the_band(): void {
        foreach ( [ 'head of development' => $this->hod, 'head coach' => $this->coach, 'linked scout' => $this->scout ] as $who => $uid ) {
            $response = $this->get( $uid, '/talenttrack/v1/players/' . $this->player . '/potential' );
            $this->assertSame( 200, $response->get_status(), $who );
            $data = $this->data( $response );
            $this->assertSame( 'ahead', $data['current']['band'] ?? null, "{$who} reads the band" );
            $this->assertSame( self::NOTES, $data['current']['notes'] ?? null, "{$who} reads the notes" );
            $this->assertSame( PotentialTrajectory::UP, $data['current']['direction'] ?? null );
        }

        $this->assertSame(
            403,
            $this->get( $this->scout, '/talenttrack/v1/players/' . $this->elsewhere . '/potential' )->get_status(),
            'a scout reaches no player it is not linked to'
        );
    }

    public function test_the_status_verdict_loses_the_potential_input_for_the_observer(): void {
        $observer = $this->data( $this->get( $this->observer, '/talenttrack/v1/players/' . $this->player . '/status' ) );
        $this->assertArrayHasKey( 'color', $observer, 'the colour stays' );
        $this->assertArrayHasKey( 'reasons', $observer, 'the reasons stay' );
        $this->assertArrayHasKey( 'potential', $observer['inputs'] );
        $this->assertArrayNotHasKey( 'value', $observer['inputs']['potential'] );
        $this->assertArrayNotHasKey( 'score', $observer['inputs']['potential'] );
        $this->assertArrayHasKey( 'weight', $observer['inputs']['potential'] );
        $this->assertNull( $observer['score'], 'the composite would give the band back' );
        $this->assertNotContains( 'potential', $observer['missing_inputs'], 'a hidden band is not a missing one' );
        foreach ( $observer['reasons'] as $reason ) {
            $this->assertDoesNotMatchRegularExpression( '/Composite \d/', (string) $reason, 'no reason carries the composite' );
        }

        $hod = $this->data( $this->get( $this->hod, '/talenttrack/v1/players/' . $this->player . '/status' ) );
        $this->assertEqualsWithDelta( 80.0, (float) $hod['inputs']['potential']['value'], 0.01, 'the head of development reads the input' );

        $board = $this->data( $this->get( $this->observer, '/talenttrack/v1/teams/' . $this->team . '/player-statuses' ) );
        $rows  = array_values( array_filter( $board['rows'], fn( $r ) => (int) $r['player_id'] === $this->player ) );
        $this->assertCount( 1, $rows, 'the squad board reaches the observer' );
        $this->assertArrayNotHasKey( 'value', $rows[0]['inputs']['potential'] );

        $coach = $this->data( $this->get( $this->coach, '/talenttrack/v1/teams/' . $this->team . '/player-statuses' ) );
        $rows  = array_values( array_filter( $coach['rows'], fn( $r ) => (int) $r['player_id'] === $this->player ) );
        $this->assertArrayHasKey( 'value', $rows[0]['inputs']['potential'], 'the head coach reads the input' );
    }

    /**
     * A threshold reason names the composite, which gives the band back by
     * the same arithmetic as the score: it keeps the threshold and loses the
     * number, in the object and in its array form alike (one rule).
     */
    public function test_a_threshold_reason_keeps_the_threshold_and_loses_the_composite(): void {
        foreach ( [ [ StatusVerdict::COLOR_AMBER, 'amber', '60' ], [ StatusVerdict::COLOR_RED, 'red', '40' ] ] as [ $color, $word, $threshold ] ) {
            $reason  = sprintf( 'Composite %s below %s threshold %s.', '35.5', $word, $threshold );
            $verdict = new StatusVerdict(
                $color,
                35.5,
                [
                    'ratings'   => [ 'value' => 5.0, 'weight' => 40, 'score' => 30.0 ],
                    'potential' => [ 'value' => 60.0, 'weight' => 15, 'score' => 60.0 ],
                ],
                [ 'No evaluations in the last 90 days.', $reason ],
                '2026-09-30 00:00:00',
                'shipped'
            );

            $masked = $verdict->withoutPotential();
            $this->assertNull( $masked->score );
            $this->assertSame( [ 'weight' => 15 ], $masked->inputs['potential'] );
            $this->assertSame(
                [ 'No evaluations in the last 90 days.', sprintf( 'Composite below the %s threshold (%s).', $word, $threshold ) ],
                $masked->reasons,
                "the {$word} reason keeps its threshold"
            );
            $this->assertSame( $reason, $verdict->reasons[1], 'the computed verdict is left as it was' );

            $this->assertSame(
                $masked->toArray(),
                StatusVerdict::payloadWithoutPotential( $verdict->toArray() ),
                'the array form follows the same rule'
            );
            $this->assertSame( $masked->toArray(), \TT\Modules\Analytics\Reports\PlayerSectionRights::statusWithoutPotential( $verdict->toArray() ) );
        }
    }

    /**
     * A scheduled report goes out as one PDF; a recipient whose rights
     * cannot be checked (0) counts as one with none, so the band is hidden
     * from every recipient, as the evaluation notes are.
     */
    public function test_a_report_with_an_unverifiable_recipient_hides_the_band(): void {
        $this->assertTrue( PotentialTrajectory::bandHiddenFrom( 0, $this->player ) );
        $this->assertFalse( PotentialAccess::seesJudgement( 0, $this->player ) );

        $packet = EvidencePacket::forReaders( $this->player, '2026-09-01', '2026-09-30', ReaderSet::of( [ $this->hod, 0 ] ) );
        $this->assertIsArray( $packet );
        $item = $packet['potential'][0];
        $this->assertIsArray( $item );
        $this->assertTrue( $item['band_hidden'] ?? false );
        $this->assertArrayNotHasKey( 'potential_band', $item );
        $this->assertArrayNotHasKey( 'value', $packet['status']['inputs']['potential'] );
        $this->assertArrayNotHasKey( 'score', $packet['status']['inputs']['potential'] );
        $this->assertNull( $packet['status']['score'] );

        $pdf = $this->callPrivate( \TT\Modules\Export\Exporters\PlayerReportPdfDocument::class, 'potential', [ [ 'items' => [ $item ] ] ] );
        $this->assertStringContainsString( 'Hidden', $pdf );
        $this->assertStringNotContainsString( self::LABEL, $pdf );

        $alone = EvidencePacket::forReaders( $this->player, '2026-09-01', '2026-09-30', ReaderSet::of( [ $this->hod ] ) );
        $this->assertIsArray( $alone );
        $this->assertArrayHasKey( 'value', $alone['status']['inputs']['potential'], 'the head of development alone reads it' );
    }

    public function test_the_squad_overview_shows_no_band_and_filters_none_back(): void {
        $query = new PotentialOverviewQuery();

        $rows = $this->rowFor( $query->rows( $this->observer, PotentialOverviewQuery::SCOPE_TEAM, $this->team, '' ) );
        $this->assertTrue( $rows['recorded'], 'that a band is recorded stays' );
        $this->assertSame( self::SET_AT, $rows['set_at'] );
        foreach ( [ 'band', 'band_label', 'notes', 'direction', 'previous_band', 'previous_band_label' ] as $key ) {
            $this->assertSame( '', $rows[ $key ], "the observer gets no {$key}" );
        }
        $this->assertSame(
            [],
            $query->rows( $this->observer, PotentialOverviewQuery::SCOPE_TEAM, $this->team, '', [ 'ahead' ] ),
            'filtering on the true band gives nothing back'
        );

        // A hidden band carries no rank, so a reversed band sort keeps it with
        // the unrecorded instead of floating it to the top.
        $unrecorded = $this->player( 'Aazon', $this->team );
        $ids = static fn( array $list ): array => array_map( static fn( array $r ): int => (int) $r['player_id'], $list );
        $asc  = $ids( $query->rows( $this->observer, PotentialOverviewQuery::SCOPE_TEAM, $this->team, '', [], 'band', 'asc' ) );
        $desc = $ids( $query->rows( $this->observer, PotentialOverviewQuery::SCOPE_TEAM, $this->team, '', [], 'band', 'desc' ) );
        $this->assertSame( [ $unrecorded, $this->player ], $asc, 'unranked rows by name' );
        $this->assertSame( $asc, $desc, 'the direction gives no band back' );

        $api = RecordRedactor::redactRows( 'player_potential', [ $rows ], $this->observer, 'player_id' );
        $this->assertArrayNotHasKey( 'band', $api[0], 'the REST row leaves the band out' );
        $this->assertArrayNotHasKey( 'band_rank', $api[0] );

        $hod = $this->rowFor( $query->rows( $this->hod, PotentialOverviewQuery::SCOPE_TEAM, $this->team, '' ) );
        $this->assertSame( 'ahead', $hod['band'], 'the head of development reads the band' );
        $this->assertSame( self::NOTES, $hod['notes'] );
    }

    public function test_the_player_report_and_its_pdf_say_hidden(): void {
        $packet = EvidencePacket::forPlayer( $this->player, '2026-09-01', '2026-09-30', $this->observer );
        $this->assertIsArray( $packet );
        $this->assertCount( 1, $packet['potential'] );
        $item = $packet['potential'][0];
        $this->assertIsArray( $item );
        $this->assertTrue( $item['band_hidden'] ?? false );
        $this->assertArrayNotHasKey( 'potential_band', $item );
        $this->assertArrayNotHasKey( 'notes', $item );
        $this->assertSame( self::SET_AT, $item['set_at'] );
        $this->assertArrayNotHasKey( 'value', $packet['status']['inputs']['potential'] );

        $pdf = $this->callPrivate( \TT\Modules\Export\Exporters\PlayerReportPdfDocument::class, 'potential', [ [ 'items' => [ $item ] ] ] );
        $this->assertStringContainsString( 'Hidden', $pdf );
        $this->assertStringNotContainsString( self::LABEL, $pdf );

        ob_start();
        $this->callPrivate( \TT\Modules\Analytics\Frontend\PlayerReportPage::class, 'renderPotential', [ [ 'items' => [ $item ] ] ] );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'tt-masked-value', $html );
        $this->assertStringNotContainsString( self::LABEL, $html );

        $hod = EvidencePacket::forPlayer( $this->player, '2026-09-01', '2026-09-30', $this->hod );
        $this->assertIsArray( $hod );
        $row = $hod['potential'][0];
        $this->assertSame( 'ahead', is_object( $row ) ? $row->potential_band : $row['potential_band'], 'the head of development reads the band' );
        $this->assertArrayHasKey( 'value', $hod['status']['inputs']['potential'] );
    }

    public function test_the_profile_card_the_attributes_and_the_export_follow_the_rule(): void {
        $summary = ( new BehaviourPotentialSummary() )->forPlayer( $this->player, null, $this->observer );
        $current = $summary['potential']['current'] ?? null;
        $this->assertIsArray( $current );
        $this->assertTrue( $current['band_hidden'] ?? false );
        $this->assertArrayNotHasKey( 'band', $current );
        $this->assertArrayNotHasKey( 'label', $current );
        $this->assertSame( self::SET_AT, $current['set_at'] );

        $hod = ( new BehaviourPotentialSummary() )->forPlayer( $this->player, null, $this->hod );
        $this->assertSame( 'ahead', $hod['potential']['current']['band'] ?? null );

        $this->assertFalse( PlayerAttributeAudience::canReadDevelopment( $this->observer, $this->player ), 'no forecast group for the observer' );
        $this->assertTrue( PlayerAttributeAudience::canReadDevelopment( $this->hod, $this->player ) );
        $this->assertTrue( PlayerAttributeAudience::canReadDevelopment( $this->coach, $this->player ) );

        $this->assertFalse( \TT\Modules\Export\Exporters\KpiSnapshotXlsxExporter::mayReadPotential( $this->observer ), 'a global read alone is not the band' );
        $this->assertTrue( \TT\Modules\Export\Exporters\KpiSnapshotXlsxExporter::mayReadPotential( $this->hod ) );
    }

    public function test_the_observer_sets_no_potential(): void {
        wp_set_current_user( $this->observer );
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player . '/potential' );
        $request->set_param( 'potential_band', 'exceptional' );
        $this->assertSame( 403, rest_do_request( $request )->get_status() );
        $this->assertFalse( MatrixGate::can( $this->observer, 'player_potential', MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL ) );
    }

    public function test_the_migration_adds_the_rows_once(): void {
        global $wpdb;
        $table = "{$this->p}tt_authorization_matrix";
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('player_status','player_status_breakdown','player_potential')" );
        $this->flush();

        $this->migration()->up();
        $this->migration()->up();

        $rows = $wpdb->get_col( "SELECT entity FROM {$table} WHERE persona = 'readonly_observer' AND entity IN ('player_status','player_status_breakdown','player_potential') AND activity = 'read' AND scope_kind = 'global' ORDER BY entity" );
        $this->assertSame( [ 'player_potential', 'player_status', 'player_status_breakdown' ], array_values( $rows ) );
        $this->flush();
        $this->assertTrue( PotentialAccess::readsWithoutJudgement( $this->observer ) );
    }

    private function get( int $user_id, string $path ): \WP_REST_Response {
        wp_set_current_user( $user_id );
        $this->flush();
        return rest_do_request( new WP_REST_Request( 'GET', $path ) );
    }

    /** @return array<string,mixed> */
    private function data( \WP_REST_Response $response ): array {
        $body = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $this->assertIsArray( $body );
        return is_array( $body['data'] ?? null ) ? $body['data'] : $body;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function rowFor( array $rows ): array {
        $found = [];
        foreach ( $rows as $row ) {
            if ( (int) $row['player_id'] === $this->player ) $found = $row;
        }
        $this->assertNotSame( [], $found, 'the player is on the overview' );
        return $found;
    }

    /**
     * @param list<mixed> $args
     * @return mixed
     */
    private function callPrivate( string $class, string $method, array $args ) {
        $ref = new \ReflectionMethod( $class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    private function player( string $last, int $team ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'first_name' => 'Pot', 'last_name' => $last,
            'team_id' => $team, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function headCoach( int $team ): int {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Obspot', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $coach;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
