<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Print\PdpPrintRouter;

/**
 * #3663 — who may update a PDP conversation.
 *
 * `PATCH pdp-conversations/{id}` decided the coach whitelist with its own
 * "admin, or a PDP editor who coaches the player" check. The PDP file next
 * to it goes through `PdpAccess::canEditFile()`, which also lets in a global
 * PDP editor, so the head of development could open and edit the file and
 * its prep but got a 403 when moving the planned date of a talk for a player
 * outside their own teams. The conversation now asks the same question the
 * file does.
 *
 * The coach, player and parent rules stay as they were, and so does the
 * signature lock.
 */
final class PdpConversationEditAccessTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $file   = 0;

    /** @var list<int> users that may hold the PDP caps in this test */
    private array $pdp_editors = [];

    /** @var list<int> users that may read PDP files in this test */
    private array $pdp_viewers = [];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // The PDP caps are handed out explicitly here, so the test does not
        // depend on whether the matrix bridge is active in the test install.
        // Nobody in this test holds tt_edit_settings or manage_options.
        $this->cap_filter = function ( $allcaps, $caps, $args, $user ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( in_array( $uid, $this->pdp_viewers, true ) ) {
                $allcaps['tt_view_pdp'] = true;
            }
            if ( in_array( $uid, $this->pdp_editors, true ) ) {
                $allcaps['tt_view_pdp'] = true;
                $allcaps['tt_edit_pdp'] = true;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        $this->seedFile();
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_head_of_development_can_move_the_planned_date_of_a_talk_they_do_not_coach(): void {
        $conv = $this->conversation();
        wp_set_current_user( $this->headOfDevelopment() );

        [ , $status ] = $this->patch( $conv, [ 'scheduled_at' => '2026-11-19 18:00:00' ] );

        $this->assertSame( 200, $status );
        $this->assertSame( '2026-11-19 18:00:00', $this->column( $conv, 'scheduled_at' ) );
    }

    public function test_head_of_development_can_write_the_notes_of_the_active_talk(): void {
        $conv = $this->conversation();
        wp_set_current_user( $this->headOfDevelopment() );

        [ , $status ] = $this->patch( $conv, [ 'notes' => 'Written up by the head of development.' ] );

        $this->assertSame( 200, $status );
        $this->assertSame( 'Written up by the head of development.', $this->column( $conv, 'notes' ) );
    }

    public function test_a_pdp_editor_who_does_not_coach_the_player_is_still_refused(): void {
        $conv  = $this->conversation();
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_editors[] = $coach;
        wp_set_current_user( $coach );

        [ , $status ] = $this->patch( $conv, [ 'scheduled_at' => '2026-11-19 18:00:00' ] );

        $this->assertSame( 403, $status );
        $this->assertSame( '2026-10-01 10:00:00', $this->column( $conv, 'scheduled_at' ) );
    }

    public function test_the_coach_of_the_players_team_can_still_update_it(): void {
        $conv  = $this->conversation();
        $coach = $this->coachOfTheTeam();
        wp_set_current_user( $coach );

        [ , $status ] = $this->patch( $conv, [ 'scheduled_at' => '2026-11-20 17:30:00' ] );

        $this->assertSame( 200, $status );
        $this->assertSame( '2026-11-20 17:30:00', $this->column( $conv, 'scheduled_at' ) );
    }

    public function test_a_linked_parent_can_only_acknowledge(): void {
        $conv   = $this->conversation();
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->pdp_viewers[] = $parent;
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $this->player,
            'parent_user_id' => $parent,
        ] );
        wp_set_current_user( $parent );

        // A field outside the parent's whitelist is dropped, not written.
        $this->patch( $conv, [ 'scheduled_at' => '2026-11-19 18:00:00' ] );
        $this->assertSame( '2026-10-01 10:00:00', $this->column( $conv, 'scheduled_at' ) );

        [ , $status ] = $this->patch( $conv, [ 'parent_ack_at' => '2026-10-02 09:00:00' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( '2026-10-02 09:00:00', $this->column( $conv, 'parent_ack_at' ) );
    }

    public function test_a_signed_conversation_stays_locked_for_the_head_of_development(): void {
        $conv = $this->conversation( [ 'coach_signoff_at' => '2026-10-01 11:00:00' ] );
        wp_set_current_user( $this->headOfDevelopment() );

        [ $data, $status ] = $this->patch( $conv, [ 'scheduled_at' => '2026-11-19 18:00:00' ] );

        $this->assertSame( 409, $status );
        $this->assertSame( 'conversation_locked', $data['errors'][0]['code'] );
        $this->assertSame( '2026-10-01 10:00:00', $this->column( $conv, 'scheduled_at' ) );
    }

    public function test_head_of_development_can_print_a_file_they_can_open(): void {
        global $wpdb;
        $file = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_pdp_files WHERE id = %d", $this->file
        ) );
        $this->assertIsObject( $file );

        wp_set_current_user( $this->headOfDevelopment() );
        $this->assertTrue( PdpPrintRouter::canAccess( $file ) );

        $stranger = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_viewers[] = $stranger;
        wp_set_current_user( $stranger );
        $this->assertFalse( PdpPrintRouter::canAccess( $file ), 'a coach of another team cannot print it' );
    }

    // ---- fixtures ---------------------------------------------------------

    private function seedFile(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'O15-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'de Vries',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'        => $club,
            'player_id'      => $this->player,
            'season_id'      => $season,
            'owner_coach_id' => (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] ),
            'status'         => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $overrides */
    private function conversation( array $overrides = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_pdp_conversations", array_merge( [
            'club_id'      => (int) CurrentClub::id(),
            'pdp_file_id'  => $this->file,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2026-10-01 10:00:00',
            'notes'        => 'What was said.',
        ], $overrides ) );
        return (int) $wpdb->insert_id;
    }

    private function headOfDevelopment(): int {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->pdp_viewers[] = $user;
        return $user;
    }

    private function coachOfTheTeam(): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_editors[] = $coach;

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );
        return $coach;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function patch( int $conversation_id, array $body ): array {
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/pdp-conversations/' . $conversation_id );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_do_request( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    private function column( int $conversation_id, string $column ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_pdp_conversations WHERE id = %d", $conversation_id
        ), ARRAY_A );
        return is_array( $row ) ? (string) ( $row[ $column ] ?? '' ) : '';
    }
}
