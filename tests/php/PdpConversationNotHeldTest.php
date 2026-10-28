<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Modules\Pdp\Services\PdpFamilyReader;

/**
 * #4298 — a PDP talk that never took place no longer blocks the cycle.
 *
 * The active talk can be marked not held, with a reason, by the people who
 * sign a talk off. The next talk then becomes the active one, so the rest of
 * the cycle, the end-of-season talk included, can be recorded. The not-held
 * talk stays on the file with its date and reason, is never overdue, takes
 * no reflection, and counts as not held rather than conducted.
 */
final class PdpConversationNotHeldTest extends WP_UnitTestCase {

    private int $coach = 0;
    private int $season = 0;
    private int $team = 0;
    private int $file = 0;

    /** @var array<int,int> sequence => conversation id */
    private array $talks = [];

    /** @var list<int> users granted tt_view_pdp and nothing else */
    private array $viewers = [];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->cap_filter = function ( $allcaps, $caps, $args, $user ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( in_array( $uid, $this->viewers, true ) ) {
                $allcaps['tt_view_pdp']      = true;
                $allcaps['tt_edit_pdp']      = false;
                $allcaps['tt_edit_settings'] = false;
                $allcaps['manage_options']   = false;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Not held O14-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Sven', 'last_name' => 'Overslag', 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id' => $club, 'player_id' => $player, 'season_id' => $this->season,
            'owner_coach_id' => $this->coach, 'status' => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;

        foreach ( [ 1 => 'start', 2 => 'mid', 3 => 'end' ] as $seq => $key ) {
            $wpdb->insert( "{$p}tt_pdp_conversations", [
                'club_id'      => $club,
                'pdp_file_id'  => $this->file,
                'sequence'     => $seq,
                'template_key' => $key,
                'scheduled_at' => sprintf( '2026-%02d-15 10:00:00', 8 + $seq * 2 ),
                'planning_window_start' => '2026-08-01',
                'planning_window_end'   => '2027-06-30',
            ] );
            $this->talks[ $seq ] = (int) $wpdb->insert_id;
        }

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_marking_the_active_talk_moves_the_cycle_on_to_the_next(): void {
        [ , $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill that week.' ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 1 );
        $this->assertNotEmpty( $row['not_held_at'] );
        $this->assertSame( 'Ill that week.', $row['not_held_reason'] );
        $this->assertSame( $this->talks[2], ( new PdpConversationsRepository() )->activeConversationId( $this->file ) );

        // The next talk is now recorded and signed as usual.
        [ , $held ] = $this->patch( 2, [ 'conducted_at' => '2026-12-15 10:00:00', 'notes' => 'Went well.' ] );
        $this->assertSame( 200, $held );
        [ , $signed ] = $this->patch( 2, [ 'coach_signoff_at' => '2026-12-15 11:00:00' ] );
        $this->assertSame( 200, $signed );

        // And so is the end-of-season talk after it.
        $this->assertSame( $this->talks[3], ( new PdpConversationsRepository() )->activeConversationId( $this->file ) );
        [ , $end ] = $this->patch( 3, [ 'conducted_at' => '2027-02-15 10:00:00' ] );
        $this->assertSame( 200, $end );
    }

    public function test_a_reason_is_required_and_bounded(): void {
        [ $data, $status ] = $this->patch( 1, [ 'not_held' => true ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );

        [ $data, $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => '   ' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );

        [ $data, $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => str_repeat( 'x', 256 ) ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['errors'][0]['code'] ?? null );

        $this->assertEmpty( $this->row( 1 )['not_held_at'], 'a refused mark writes nothing' );
    }

    public function test_only_the_active_talk_can_be_marked(): void {
        [ $data, $status ] = $this->patch( 2, [ 'not_held' => true, 'not_held_reason' => 'Out of order.' ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_not_active', $data['errors'][0]['code'] ?? null );
    }

    public function test_a_held_or_signed_talk_cannot_be_marked(): void {
        $this->patch( 1, [ 'conducted_at' => '2026-10-15 10:00:00' ] );

        [ $data, $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Too late.' ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_already_held', $data['errors'][0]['code'] ?? null );
    }

    public function test_the_mark_travels_alone(): void {
        [ $data, $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill.', 'notes' => 'Something.' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'not_held_alone', $data['errors'][0]['code'] ?? null );
    }

    public function test_a_reader_without_change_rights_is_refused(): void {
        $viewer = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->viewers[] = $viewer;
        wp_set_current_user( $viewer );

        [ , $status ] = $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Not mine.' ] );
        $this->assertSame( 403, $status );
        $this->assertEmpty( $this->row( 1 )['not_held_at'] );
    }

    public function test_the_mark_comes_off_until_a_later_talk_moves_on(): void {
        $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill.' ] );

        [ , $status ] = $this->patch( 1, [ 'not_held' => false ] );
        $this->assertSame( 200, $status );
        $row = $this->row( 1 );
        $this->assertEmpty( $row['not_held_at'] );
        $this->assertEmpty( $row['not_held_reason'] );
        $this->assertSame( $this->talks[1], ( new PdpConversationsRepository() )->activeConversationId( $this->file ) );

        $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill after all.' ] );
        $this->patch( 2, [ 'conducted_at' => '2026-12-15 10:00:00' ] );

        [ $data, $status ] = $this->patch( 1, [ 'not_held' => false ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_not_active', $data['errors'][0]['code'] ?? null );
        $this->assertNotEmpty( $this->row( 1 )['not_held_at'], 'the mark stays once the cycle moved on' );
    }

    public function test_a_not_held_talk_takes_no_reflection_and_is_never_overdue(): void {
        $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill.' ] );

        [ $data, $status ] = $this->patch( 1, [ 'player_reflection' => 'My thoughts.' ] );
        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_not_held', $data['errors'][0]['code'] ?? null );

        $talk = ( new PdpConversationsRepository() )->find( $this->talks[1] );
        $this->assertNotNull( $talk );
        $this->assertTrue( PdpCycleState::isNotHeld( $talk ) );
        $this->assertFalse( PdpCycleState::isOverdue( $talk, (int) strtotime( '2027-05-01 UTC' ) ) );

        $next = PdpCycleState::nextPlanned( ( new PdpConversationsRepository() )->listForFile( $this->file ) );
        $this->assertNotNull( $next );
        $this->assertSame( $this->talks[2], (int) ( ( (array) $next )['id'] ?? 0 ) );
    }

    public function test_the_talk_stays_on_the_record_with_its_reason(): void {
        $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Coach change.' ] );

        $this->assertSame( 'not_held', PdpFamilyReader::stateOf( $this->row( 1 ), $this->talks[2], (int) strtotime( '2027-05-01 UTC' ) ) );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-files/' . $this->file ) );
        $this->assertSame( 200, $response->get_status() );
        $data  = (array) $response->get_data();
        $convs = (array) ( ( (array) ( $data['data'] ?? [] ) )['conversations'] ?? [] );
        $first = null;
        foreach ( $convs as $c ) {
            if ( (int) ( ( (array) $c )['id'] ?? 0 ) === $this->talks[1] ) $first = (array) $c;
        }
        $this->assertNotNull( $first, 'the not-held talk is still listed on the file' );
        $this->assertSame( 'Coach change.', $first['not_held_reason'] ?? null );
        $this->assertNotEmpty( $first['not_held_at'] ?? null );
    }

    public function test_planning_counts_it_as_not_held_not_conducted(): void {
        $this->patch( 1, [ 'not_held' => true, 'not_held_reason' => 'Ill.' ] );

        $matrix = FrontendPdpPlanningView::buildMatrix( $this->season, [ $this->team ] );
        $cell   = $matrix['teams'][ $this->team ]['blocks'][1] ?? null;
        $this->assertIsArray( $cell );
        $this->assertSame( 1, $cell['not_held'] );
        $this->assertSame( 0, $cell['conducted'] );
        $this->assertSame( 0, $cell['planned'] );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function patch( int $sequence, array $body ): array {
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/pdp-conversations/' . $this->talks[ $sequence ] );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /** @return array<string,mixed> */
    private function row( int $sequence ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_pdp_conversations WHERE id = %d",
            $this->talks[ $sequence ]
        ), ARRAY_A );
    }
}
