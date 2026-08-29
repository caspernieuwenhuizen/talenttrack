<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3008 (epic #2881) — the one contract every autosaving surface depends on:
 * **a write that does not mention a field must not change it.**
 *
 * With an explicit Save, a form posts itself whole, once, and a handler that
 * rebuilds the entire row from `$_POST` is merely wasteful. With autosave the
 * same handler is a data-loss bug: any client that sends a slice — a later
 * per-panel save, an integration, a mobile app — silently blanks everything it
 * did not know to resend, and it does not look like a bug. It looks like a
 * coach's write-up of a child disappearing when they edited a different field.
 *
 * `EvaluationsRestController::update_eval` was exactly that handler and is
 * fixed in this change; goals and PDP conversations were already correct and
 * are pinned here so they stay that way.
 *
 * The PDP cases also cover the second promise this slice makes: autosave stops
 * at sign-off. The endpoint refuses a locked conversation, so the guarantee
 * does not rest on the view remembering to hide a control.
 */
final class AutosaveWriteContractTest extends WP_UnitTestCase {

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function makePlayer(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => 'Daan',
            'last_name'  => 'Peters',
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . ltrim( $route, '/' ) );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /** @return array<string,mixed>|null */
    private function row( string $table, int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // ---- evaluations ------------------------------------------------------

    /**
     * The fix. Before #3008 this body would have zeroed `player_id` and
     * `eval_type_id`, reset `eval_date` to today, and blanked
     * `player_feedback`, `opponent`, `competition`, `game_result` and
     * `home_away` — every one of them from a `?? ''` default meant for a
     * create.
     */
    public function test_an_evaluation_write_leaves_the_fields_it_omits_alone(): void {
        global $wpdb;
        $player_id = $this->makePlayer();

        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'         => CurrentClub::id(),
            'player_id'       => $player_id,
            'coach_id'        => $this->coach,
            'eval_type_id'    => 4,
            'eval_date'       => '2026-03-01',
            'notes'           => 'Staff note as first written.',
            'player_feedback' => 'Keep working on your first touch.',
            'opponent'        => 'Ajax U17',
            'competition'     => 'League',
            'game_result'     => '2-1',
            'home_away'       => 'away',
        ] );
        $eval_id = (int) $wpdb->insert_id;

        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'notes' => 'Staff note, rewritten.',
        ] );

        $this->assertSame( 200, $status );
        $this->assertTrue( $data['success'] );

        $row = $this->row( 'tt_evaluations', $eval_id );
        $this->assertNotNull( $row );

        $this->assertSame( 'Staff note, rewritten.', $row['notes'] );
        $this->assertSame( $player_id, (int) $row['player_id'], 'a partial write must not orphan the evaluation' );
        $this->assertSame( 4, (int) $row['eval_type_id'] );
        $this->assertSame( '2026-03-01', substr( (string) $row['eval_date'], 0, 10 ) );
        $this->assertSame( 'Keep working on your first touch.', $row['player_feedback'] );
        $this->assertSame( 'Ajax U17', $row['opponent'] );
        $this->assertSame( 'League', $row['competition'] );
        $this->assertSame( '2-1', $row['game_result'] );
        $this->assertSame( 'away', $row['home_away'] );
    }

    /**
     * An empty string is a value, not an omission: clearing the player-facing
     * feedback has to keep working, or the fix above would have traded one
     * data-loss bug for a field a coach cannot empty.
     */
    public function test_an_evaluation_field_sent_empty_is_cleared(): void {
        global $wpdb;
        $player_id = $this->makePlayer();

        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'         => CurrentClub::id(),
            'player_id'       => $player_id,
            'coach_id'        => $this->coach,
            'eval_type_id'    => 4,
            'eval_date'       => '2026-03-01',
            'notes'           => 'Staff note.',
            'player_feedback' => 'Said too soon.',
        ] );
        $eval_id = (int) $wpdb->insert_id;

        $this->send( 'PUT', 'evaluations/' . $eval_id, [ 'player_feedback' => '' ] );

        $row = $this->row( 'tt_evaluations', $eval_id );
        $this->assertNotNull( $row );
        $this->assertSame( '', (string) $row['player_feedback'] );
        $this->assertSame( 'Staff note.', $row['notes'] );
    }

    /**
     * The coach column is never in the patch. An edit must not re-point an
     * evaluation at whoever happened to open it — the record says who formed
     * the judgement, and that is not the person fixing a typo in it.
     */
    public function test_an_evaluation_write_does_not_reassign_the_coach(): void {
        global $wpdb;
        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'      => CurrentClub::id(),
            'player_id'    => $this->makePlayer(),
            'coach_id'     => $author,
            'eval_type_id' => 4,
            'eval_date'    => '2026-03-01',
            'notes'        => 'By the original coach.',
        ] );
        $eval_id = (int) $wpdb->insert_id;

        $this->send( 'PUT', 'evaluations/' . $eval_id, [ 'notes' => 'Edited by someone else.' ] );

        $row = $this->row( 'tt_evaluations', $eval_id );
        $this->assertNotNull( $row );
        $this->assertSame( $author, (int) $row['coach_id'] );
    }

    // ---- goals ------------------------------------------------------------

    public function test_a_goal_write_leaves_the_fields_it_omits_alone(): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'     => CurrentClub::id(),
            'player_id'   => $this->makePlayer(),
            'title'       => 'Win more first duels',
            'description' => 'Body position before the ball arrives.',
            'status'      => 'active',
            'priority'    => 'high',
            'due_date'    => '2026-06-01',
            'created_by'  => $this->coach,
        ] );
        $goal_id = (int) $wpdb->insert_id;

        [ , $status ] = $this->send( 'PUT', 'goals/' . $goal_id, [
            'description' => 'Body position, and scanning before the pass.',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_goals', $goal_id );
        $this->assertNotNull( $row );
        $this->assertSame( 'Body position, and scanning before the pass.', $row['description'] );
        $this->assertSame( 'Win more first duels', $row['title'] );
        $this->assertSame( 'active', $row['status'] );
        $this->assertSame( 'high', $row['priority'] );
        $this->assertSame( '2026-06-01', substr( (string) $row['due_date'], 0, 10 ) );
    }

    // ---- PDP conversations ------------------------------------------------

    /** @return array{0:int,1:int} file id, conversation id */
    private function makeConversation( array $conv = [] ): array {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_seasons', [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $season_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_pdp_files', [
            'club_id'        => CurrentClub::id(),
            'player_id'      => $this->makePlayer(),
            'season_id'      => $season_id,
            'owner_coach_id' => $this->coach,
            'status'         => 'open',
        ] );
        $file_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_pdp_conversations', array_merge( [
            'club_id'        => CurrentClub::id(),
            'pdp_file_id'    => $file_id,
            'sequence'       => 1,
            'template_key'   => 'start',
            'scheduled_at'   => '2026-09-01 10:00:00',
            'agenda'         => 'Agreed agenda.',
            'notes'          => 'What was said.',
            'agreed_actions' => 'What happens next.',
        ], $conv ) );

        return [ $file_id, (int) $wpdb->insert_id ];
    }

    public function test_a_conversation_write_leaves_the_fields_it_omits_alone(): void {
        [ , $conv_id ] = $this->makeConversation();

        [ , $status ] = $this->send( 'PATCH', 'pdp-conversations/' . $conv_id, [
            'notes' => 'What was said, in more detail.',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_pdp_conversations', $conv_id );
        $this->assertNotNull( $row );
        $this->assertSame( 'What was said, in more detail.', $row['notes'] );
        $this->assertSame( 'Agreed agenda.', $row['agenda'] );
        $this->assertSame( 'What happens next.', $row['agreed_actions'] );
        $this->assertSame( '2026-09-01 10:00:00', (string) $row['scheduled_at'] );
    }

    /**
     * Autosave stops at sign-off — and it stops in the endpoint, not only in
     * the view. A signed conversation is a signed document; a debounce that
     * arrived a second late must not be able to edit it.
     */
    public function test_a_signed_off_conversation_refuses_further_writes(): void {
        [ , $conv_id ] = $this->makeConversation( [ 'coach_signoff_at' => '2026-09-02 09:00:00' ] );

        [ $data, $status ] = $this->send( 'PATCH', 'pdp-conversations/' . $conv_id, [
            'notes' => 'A late edit.',
        ] );

        $this->assertSame( 409, $status );
        $this->assertFalse( $data['success'] );
        $this->assertSame( 'conversation_locked', $data['errors'][0]['code'] );

        $row = $this->row( 'tt_pdp_conversations', $conv_id );
        $this->assertNotNull( $row );
        $this->assertSame( 'What was said.', $row['notes'], 'a refused write must not have written' );
    }
}
