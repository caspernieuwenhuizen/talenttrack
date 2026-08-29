<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Rest\CommsRestController;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;

/**
 * #2605 Gate D — the REST surface Comms shipped without.
 *
 * The module has written `tt_comms_log` since v3.106.0 and `tt_comms_inbox`
 * since v3.110.0, and read neither, so "did the parents get the
 * cancellation message?" needed SQL and every in-app message was delivered
 * into a room with no door.
 *
 * Authorization gets most of the attention here, because the rows are
 * message metadata about minors. Two properties are worth proving rather
 * than reading off the route table: reading the log takes a capability,
 * and no route can reach another person's inbox.
 */
final class CommsRestTest extends WP_UnitTestCase {

    private string $log;
    private string $inbox;
    private int $admin;
    private int $parent_a;
    private int $parent_b;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->log   = $wpdb->prefix . 'tt_comms_log';
        $this->inbox = $wpdb->prefix . 'tt_comms_inbox';

        $wpdb->query( "DELETE FROM {$this->log}" );
        $wpdb->query( "DELETE FROM {$this->inbox}" );

        $this->admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->parent_a = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->parent_b = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        TemplateRegistry::clear();
        TemplateRegistry::register( new TrainingCancelledTemplate() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        wp_set_current_user( $this->admin );
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        parent::tear_down();
    }

    // ── the log ─────────────────────────────────────────────────────────

    public function test_reading_the_log_takes_a_capability(): void {
        wp_set_current_user( $this->parent_a );
        $this->assertSame( 403, $this->raw( 'GET', '/talenttrack/v1/comms/messages' )->get_status() );
        $this->assertSame( 403, $this->raw( 'GET', '/talenttrack/v1/players/7/messages' )->get_status() );
    }

    public function test_the_log_lists_what_was_sent_and_never_a_body(): void {
        $this->insertLogRow( [ 'recipient_player_id' => 7, 'status' => 'sent' ] );

        $data = $this->request( 'GET', '/talenttrack/v1/comms/messages' );

        $this->assertCount( 1, $data['messages'] );
        $row = $data['messages'][0];
        $this->assertSame( 'training_cancelled', $row['template_key'] );
        $this->assertSame( 'sent', $row['status'] );
        $this->assertSame( 7, $row['recipient']['player_id'] );

        // The audit row stores a SHA-256 of the body and nothing more; the
        // API must not hand a consumer either the body or the hash.
        $this->assertArrayNotHasKey( 'body', $row );
        $this->assertArrayNotHasKey( 'payload_hash', $row );
    }

    public function test_the_player_scoped_route_answers_for_one_player(): void {
        $this->insertLogRow( [ 'recipient_player_id' => 7 ] );
        $this->insertLogRow( [ 'recipient_player_id' => 8 ] );

        $data = $this->request( 'GET', '/talenttrack/v1/players/7/messages' );

        $this->assertCount( 1, $data['messages'] );
        $this->assertSame( 7, $data['messages'][0]['recipient']['player_id'] );
    }

    public function test_the_url_segment_beats_a_conflicting_query_parameter(): void {
        // Otherwise `/players/7/messages?player_id=8` would answer for the
        // wrong child, which on this data is the worst possible bug.
        $this->insertLogRow( [ 'recipient_player_id' => 7 ] );
        $this->insertLogRow( [ 'recipient_player_id' => 8 ] );

        $data = $this->request( 'GET', '/talenttrack/v1/players/7/messages', [ 'player_id' => 8 ] );

        $this->assertCount( 1, $data['messages'] );
        $this->assertSame( 7, $data['messages'][0]['recipient']['player_id'] );
    }

    public function test_the_log_filters_by_status_and_reports_what_it_holds(): void {
        $this->insertLogRow( [ 'status' => 'sent' ] );
        $this->insertLogRow( [ 'status' => 'opted_out' ] );

        $data = $this->request( 'GET', '/talenttrack/v1/comms/messages', [ 'status' => 'opted_out' ] );

        $this->assertCount( 1, $data['messages'] );
        $this->assertSame( 'opted_out', $data['messages'][0]['status'] );
        $this->assertSame( [ 'opted_out', 'sent' ], $data['statuses_in_use'] );
    }

    // ── the inbox ───────────────────────────────────────────────────────

    public function test_the_inbox_shows_only_your_own_messages(): void {
        $this->insertInboxRow( $this->parent_a, 'Yours' );
        $this->insertInboxRow( $this->parent_b, 'Not yours' );

        wp_set_current_user( $this->parent_a );
        $data = $this->request( 'GET', '/talenttrack/v1/comms/inbox' );

        $this->assertCount( 1, $data['messages'] );
        $this->assertSame( 'Yours', $data['messages'][0]['subject'] );
        $this->assertSame( 1, $data['unread_count'] );
    }

    public function test_marking_read_persists_and_moves_the_unread_count(): void {
        $id = $this->insertInboxRow( $this->parent_a, 'Training cancelled' );

        wp_set_current_user( $this->parent_a );
        $data = $this->request( 'PATCH', "/talenttrack/v1/comms/inbox/{$id}", [ 'read' => true ] );

        $this->assertTrue( $data['message']['is_read'] );
        $this->assertNotNull( $data['message']['read_at'] );
        $this->assertSame( 0, $data['unread_count'] );
    }

    public function test_someone_elses_message_does_not_exist(): void {
        $id = $this->insertInboxRow( $this->parent_b, 'Another family' );

        wp_set_current_user( $this->parent_a );
        $response = $this->raw( 'PATCH', "/talenttrack/v1/comms/inbox/{$id}", [ 'read' => true ] );

        // 404 rather than 403 on purpose: a 403 would confirm the message
        // exists, which is itself a fact about another family.
        $this->assertSame( 404, $response->get_status() );
    }

    public function test_reading_twice_does_not_move_the_first_read_stamp(): void {
        $id = $this->insertInboxRow( $this->parent_a, 'Training cancelled' );

        wp_set_current_user( $this->parent_a );
        $first = $this->request( 'PATCH', "/talenttrack/v1/comms/inbox/{$id}", [ 'read' => true ] );
        $again = $this->request( 'PATCH', "/talenttrack/v1/comms/inbox/{$id}", [ 'read' => true ] );

        $this->assertSame( $first['message']['read_at'], $again['message']['read_at'] );
    }

    // ── the template switch ─────────────────────────────────────────────

    public function test_the_switch_reads_and_writes_through_rest(): void {
        $data = $this->request( 'GET', '/talenttrack/v1/comms/templates' );
        $this->assertSame( 'training_cancelled', $data['templates'][0]['key'] );
        $this->assertTrue( $data['templates'][0]['enabled'] );

        $patched = $this->request( 'PATCH', '/talenttrack/v1/comms/templates/training_cancelled', [ 'enabled' => false ] );
        $this->assertFalse( $patched['enabled'] );
        $this->assertFalse( TemplateSwitch::isEnabled( 'training_cancelled' ) );
    }

    public function test_an_unknown_template_is_a_404_not_a_stored_key(): void {
        $response = $this->raw( 'PATCH', '/talenttrack/v1/comms/templates/no_such_template', [ 'enabled' => false ] );
        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( [], TemplateSwitch::disabledKeys() );
    }

    // ── opt-out preferences ─────────────────────────────────────────────

    public function test_preferences_round_trip_for_the_caller(): void {
        wp_set_current_user( $this->parent_a );

        $put = $this->request( 'PUT', '/talenttrack/v1/comms/preferences', [
            'opted_out' => [ MessageType::GOAL_NUDGE ],
        ] );
        $this->assertSame( [ MessageType::GOAL_NUDGE ], $put['opted_out'] );
        $this->assertTrue( ( new OptOutPolicy() )->isOptedOut( $this->parent_a, MessageType::GOAL_NUDGE ) );

        // A PUT states the complete list, so a type left out is a type the
        // user wants to hear about again.
        $cleared = $this->request( 'PUT', '/talenttrack/v1/comms/preferences', [ 'opted_out' => [] ] );
        $this->assertSame( [], $cleared['opted_out'] );
    }

    public function test_an_operational_type_is_never_offered_or_stored(): void {
        wp_set_current_user( $this->parent_a );

        $get = $this->request( 'GET', '/talenttrack/v1/comms/preferences' );
        $this->assertNotContains( MessageType::SAFEGUARDING_BROADCAST, $get['message_types'] );

        $put = $this->request( 'PUT', '/talenttrack/v1/comms/preferences', [
            'opted_out' => [ MessageType::SAFEGUARDING_BROADCAST ],
        ] );
        $this->assertSame( [], $put['opted_out'], 'safeguarding email is not something a recipient can mute' );
    }

    public function test_every_message_type_constant_is_listed(): void {
        // `all()` reads the class's own constants, so a type added without
        // a preference row is impossible by construction. Pin two of them
        // so a rename of the reader is caught.
        $this->assertContains( MessageType::TRAINING_CANCELLED, MessageType::all() );
        $this->assertContains( MessageType::SAFEGUARDING_BROADCAST, MessageType::all() );
        $this->assertNotContains( MessageType::SAFEGUARDING_BROADCAST, MessageType::optOutable() );
    }

    public function test_the_capability_the_log_uses_is_the_audit_reader(): void {
        // Deliberately not `tt_send_email`: being allowed to send is not
        // being allowed to read what everyone else sent.
        $this->assertSame( 'tt_view_audit_log', CommsRestController::CAP_READ_LOG );
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function insertLogRow( array $overrides = [] ): int {
        global $wpdb;
        $wpdb->insert( $this->log, array_merge( [
            'club_id'      => CurrentClub::id(),
            'uuid'         => wp_generate_uuid4(),
            'template_key' => 'training_cancelled',
            'message_type' => MessageType::TRAINING_CANCELLED,
            'channel'      => 'email',
            'sender_user_id' => 0,
            'recipient_user_id' => $this->parent_a,
            'recipient_kind' => 'parent',
            'address_blob' => 'parent@example.test',
            'subject'      => 'Training cancelled',
            'payload_hash' => str_repeat( 'a', 64 ),
            'status'       => 'sent',
        ], $overrides ) );
        return (int) $wpdb->insert_id;
    }

    private function insertInboxRow( int $user_id, string $subject ): int {
        global $wpdb;
        $wpdb->insert( $this->inbox, [
            'club_id'           => CurrentClub::id(),
            'uuid'              => wp_generate_uuid4(),
            'recipient_user_id' => $user_id,
            'template_key'      => 'training_cancelled',
            'message_type'      => MessageType::TRAINING_CANCELLED,
            'subject'           => $subject,
            'body'              => 'Tuesday training is cancelled.',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $body */
    private function raw( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $body as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<int|string,mixed>
     */
    private function request( string $method, string $route, array $body = [] ): array {
        $response = $this->raw( $method, $route, $body );
        $this->assertLessThan( 300, $response->get_status(), "unexpected status for {$method} {$route}" );
        $data = $response->get_data();
        return is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : (array) $data;
    }
}
