<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\FormationLayoutResolver;
use TT\Modules\MatchPrep\Services\MatchPrepState;

/**
 * #4353 — players left on positions the current formation does not draw
 * are not starters.
 *
 * A 4-3-3 switched to 3-2-1 keeps the rows of slots 7-11 so switching back
 * restores them. `listLineup()` now returns only the drawn positions, which
 * every reader of the saved line-up uses (minutes, the live match, the
 * attendance projection, the team sheet, the printout, the evaluation
 * wizard); `listLineupAll()` keeps every row for the editor.
 */
final class MatchPrepLineupVisibleSlotsTest extends WP_UnitTestCase {

    private int $activity = 0;
    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'O15-1', 'football_form' => '11v11' ] );
        $team = (int) $wpdb->insert_id;
        for ( $i = 1; $i <= 11; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => 1, 'team_id' => $team, 'first_name' => 'Speler', 'last_name' => (string) $i, 'status' => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$p}tt_activities", [
            'club_id'           => 1,
            'team_id'           => $team,
            'title'             => 'Hedel - Den Bosch',
            'session_date'      => '2026-09-26',
            'activity_type_key' => 'match',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_switch_to_three_two_one_leaves_six_starters_and_a_switch_back_eleven(): void {
        $four_three_three = $this->seededTemplate( '4-3-3' );
        $three_two_one    = $this->seededTemplate( '3-2-1' );
        $six              = count( FormationLayoutResolver::layoutFor( $three_two_one ) );
        $this->assertSame( 6, $six );

        $this->fillElevenIn( $four_three_three );
        $prep_id = $this->prepId();
        $repo    = new MatchPrepRepository();
        $this->assertCount( 11, $this->halfOne( $repo->listLineup( $prep_id ) ) );

        $this->assertSame( 200, $this->send( 'PUT', [ 'formation_template_id' => $three_two_one ] )[1] );

        $this->assertCount( 6, $this->halfOne( $repo->listLineup( $prep_id ) ), 'readers see the six drawn positions' );
        $this->assertCount( 11, $this->halfOne( $repo->listLineupAll( $prep_id ) ), 'the stored rows stay' );
        $this->assertSame( [ 'bench' => 5, 'start' => 6 ], $this->attendanceRoles(), 'the projection starts six, benches the Present others' );

        // The editor still gets every slot, so a switch back restores them.
        $state = MatchPrepState::forActivity( $this->activity );
        $this->assertIsArray( $state );
        $this->assertCount( 11, (array) $state['lineup']['1'] );
        [ $read ] = $this->send( 'GET' );
        $this->assertCount( 11, (array) $read['data']['lineup']['1'], 'GET match-prep/{id} keeps the hidden slots' );

        $this->assertSame( 200, $this->send( 'PUT', [ 'formation_template_id' => $four_three_three ] )[1] );

        $this->assertCount( 11, $this->halfOne( $repo->listLineup( $prep_id ) ) );
        $this->assertSame( [ 'start' => 11 ], $this->attendanceRoles() );
    }

    /**
     * A player on a hidden slot who is not Present is projected as not
     * starting, whether the availability marks them Absent or carries no
     * row for them at all; neither keeps the `start` of the 4-3-3 save.
     */
    public function test_a_hidden_slot_player_who_is_not_present_is_not_projected_as_starting(): void {
        $this->fillElevenIn( $this->seededTemplate( '4-3-3' ) );
        $this->assertSame( [ 'start' => 11 ], $this->attendanceRoles() );

        // Slots 9 and 10 are not on a 3-2-1. One is marked Absent, the other
        // has no availability row any more.
        $absent  = $this->players[8];
        $no_row  = $this->players[9];
        $availability = [];
        foreach ( $this->players as $pid ) {
            if ( $pid === $no_row ) continue;
            $availability[ $pid ] = [ 'status' => $pid === $absent ? 'Absent' : 'Present', 'reason' => '' ];
        }
        [ , $status ] = $this->send( 'PUT', [
            'formation_template_id' => $this->seededTemplate( '3-2-1' ),
            'availability'          => $availability,
        ] );
        $this->assertSame( 200, $status );

        $this->assertNotSame( 'start', $this->attendanceRoleOf( $absent ), 'the Absent slot-9 player is not starting' );
        $this->assertNotSame( 'start', $this->attendanceRoleOf( $no_row ), 'nor the slot-10 player without an availability row' );
        $this->assertSame( 6, $this->attendanceRoles()['start'] ?? 0 );
    }

    public function test_a_template_with_its_own_slots_is_filtered_by_its_own_slot_numbers(): void {
        global $wpdb;
        $slots = [];
        foreach ( [ 1, 2, 3, 4, 5, 10 ] as $i => $num ) {
            $slots[] = [ 'num' => $num, 'label' => 'P' . $num, 'pos' => [ 'x' => 0.1 * ( $i + 1 ), 'y' => 0.5 ] ];
        }
        // A 3-2-1 shape whose own geometry numbers its striker 10, not 6.
        $wpdb->insert( "{$wpdb->prefix}tt_formation_templates", [
            'name'            => 'Own 3-2-1',
            'formation_shape' => '3-2-1',
            'slots_json'      => (string) wp_json_encode( $slots ),
            'is_seeded'       => 0,
        ] );
        $own = (int) $wpdb->insert_id;

        $this->fillElevenIn( $this->seededTemplate( '4-3-3' ) );
        $this->send( 'PUT', [ 'formation_template_id' => $own ] );

        $kept = [];
        foreach ( $this->halfOne( ( new MatchPrepRepository() )->listLineup( $this->prepId() ) ) as $row ) {
            $kept[] = (int) $row->slot_number;
        }
        sort( $kept );
        $this->assertSame( [ 1, 2, 3, 4, 5, 10 ], $kept );
    }

    /**
     * The readers of the saved line-up go through `listLineup()`; only the
     * two editor paths ask for every row.
     */
    public function test_every_reader_takes_the_drawn_positions_and_only_the_editor_takes_all(): void {
        $readers = [
            'src/Modules/Analytics/Reports/MinutesQuery.php'                         => '->listLineupForPrep(',
            'src/Modules/MatchExecution/Repositories/MatchExecutionRepository.php'   => '->listLineup(',
            'src/Modules/MatchExecution/Rest/MatchExecutionRestController.php'       => '->listLineup(',
            'src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php'     => '->listLineup(',
            'src/Modules/Export/Exporters/MatchDayTeamSheetPdfExporter.php'          => '->listLineup(',
            'src/Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php'             => '->listLineup(',
            'src/Modules/Wizards/Evaluation/RateActorsStep.php'                      => '->listLineup(',
        ];
        foreach ( $readers as $file => $call ) {
            $src = (string) file_get_contents( TT_PLUGIN_DIR . $file );
            $this->assertStringContainsString( $call, $src, $file );
            $this->assertStringNotContainsString( 'listLineupAll(', $src, $file );
        }

        // The attendance projection reads every row once and filters it
        // against the same layout its position labels come from; the hidden
        // rows tell it whom to project as not starting (tested above).
        $projection = (string) file_get_contents( TT_PLUGIN_DIR . 'src/Modules/MatchPrep/Rest/MatchPrepRestController.php' );
        $this->assertStringContainsString( 'isset( $slot_position[ $slot ] )', $projection );

        $rate = (string) file_get_contents( TT_PLUGIN_DIR . 'src/Modules/Wizards/Evaluation/RateActorsStep.php' );
        $this->assertStringNotContainsString( 'tt_match_prep_lineup', preg_replace( '#/\*.*?\*/#s', '', $rate ) ?? '', 'no raw query around the filter' );

        foreach ( [ 'src/Modules/MatchPrep/Services/MatchPrepState.php', 'src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php' ] as $file ) {
            $src = (string) file_get_contents( TT_PLUGIN_DIR . $file );
            $this->assertStringContainsString( '->listLineupAll(', $src, $file );
            $this->assertStringNotContainsString( '->listLineup(', $src, $file );
        }
    }

    private function fillElevenIn( int $template_id ): void {
        $availability = [];
        $lineup       = [];
        foreach ( $this->players as $i => $pid ) {
            $availability[ $pid ]          = [ 'status' => 'Present', 'reason' => '' ];
            $lineup[ (string) ( $i + 1 ) ] = $pid;
        }
        [ , $status ] = $this->send( 'PUT', [
            'formation_template_id' => $template_id,
            'availability'          => $availability,
            'lineup'                => [ '1' => $lineup ],
        ] );
        $this->assertSame( 200, $status );
    }

    private function prepId(): int {
        $prep = ( new MatchPrepRepository() )->findByActivity( $this->activity );
        $this->assertNotNull( $prep );
        return (int) $prep->id;
    }

    /**
     * @param object[] $rows
     * @return object[]
     */
    private function halfOne( array $rows ): array {
        return array_values( array_filter( $rows, static fn( $r ) => (int) $r->half === 1 ) );
    }

    /** @return array<string,int> lineup_role => players */
    private function attendanceRoles(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT lineup_role, COUNT( DISTINCT player_id ) AS n FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND lineup_role IS NOT NULL
              GROUP BY lineup_role ORDER BY lineup_role",
            $this->activity
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row->lineup_role ] = (int) $row->n;
        }
        return $out;
    }

    private function attendanceRoleOf( int $player_id ): ?string {
        global $wpdb;
        $role = $wpdb->get_var( $wpdb->prepare(
            "SELECT lineup_role FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND lineup_role IS NOT NULL
              LIMIT 1",
            $this->activity, $player_id
        ) );
        return $role === null ? null : (string) $role;
    }

    private function seededTemplate( string $shape ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_formation_templates WHERE formation_shape = %s AND is_seeded = 1 ORDER BY id LIMIT 1",
            $shape
        ) );
        $this->assertGreaterThan( 0, $id, "a seeded {$shape} template" );
        return $id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function send( string $method, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/match-prep/' . $this->activity );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
