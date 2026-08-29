<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Cron\CommsScheduledCron;
use TT\Modules\Comms\Domain\CommsStatusLabels;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Frontend\FrontendMessageLogView;
use TT\Modules\Comms\Frontend\FrontendMyMessagesView;
use TT\Modules\Comms\Repositories\CommsInboxRepository;
use TT\Modules\Comms\Repositories\CommsLogRepository;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;

/**
 * #2606 Gate C — the two surfaces that give the Comms tables a reader.
 *
 * `tt_comms_log` and `tt_comms_inbox` have both been written since the
 * module shipped and read by nothing, so the properties worth pinning are
 * about what each surface is allowed to show:
 *
 *  1. **The log takes a capability**, and the same one the REST routes
 *     take — a screen and an API that refuse different people is a hole.
 *  2. **The log never renders a body**, because none is stored. If a
 *     column for one ever appears, the privacy limit the table was
 *     designed around has been quietly reversed.
 *  3. **The inbox is scoped to the reader in SQL**, not by a check — a
 *     parent must not be able to reach another family's messages by any
 *     route, including a mistake.
 *  4. **The status vocabulary reads as English.** `quiet_hours` on screen
 *     is a log nobody can use without it being explained to them first.
 */
final class CommsSurfacesTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $admin;
    private int $parent_a;
    private int $parent_b;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        // DELETE rather than TRUNCATE: TRUNCATE forces an implicit commit
        // and breaks the transaction WP_UnitTestCase rolls back.
        $wpdb->query( "DELETE FROM {$this->p}tt_comms_log" );
        $wpdb->query( "DELETE FROM {$this->p}tt_comms_inbox" );
        delete_option( CommsScheduledCron::HEALTH_OPTION );

        $this->admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->parent_a = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Ann Parent' ] );
        $this->parent_b = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        TemplateRegistry::clear();
        TemplateRegistry::register( new TrainingCancelledTemplate() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        unset( $_GET['player_id'], $_GET['f_status'], $_GET['tt_view'] );
        parent::tear_down();
    }

    // ── the staff log ───────────────────────────────────────────────────

    public function test_the_log_refuses_a_reader_without_the_capability(): void {
        wp_set_current_user( $this->parent_a );
        $html = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), false ) );

        $this->assertStringContainsString( 'do not have permission', $html );
        // §5 — the breadcrumb chain is emitted on every path, refusals
        // included. A dead end with no way out is the failure the rule
        // exists to prevent.
        $this->assertStringContainsString( 'tt-breadcrumb', $html );
    }

    public function test_the_log_shows_the_message_and_the_outcome_in_words(): void {
        $player = $this->insertPlayer( 'Lucas', 'Berg' );
        $this->insertLogRow( [ 'recipient_player_id' => $player, 'status' => 'quiet_hours' ] );

        $html = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );

        $this->assertStringContainsString( 'Training cancelled', $html, 'the template label, not its key' );
        $this->assertStringContainsString( 'Held until morning', $html );
        $this->assertStringNotContainsString( 'quiet_hours', $html, 'a raw status key is not an outcome a reader can act on' );
        $this->assertStringContainsString( 'Ann Parent', $html, 'the recipient by name where there is one' );
    }

    public function test_the_log_never_renders_a_message_body(): void {
        // The audit row stores a SHA-256 and no body, deliberately. The
        // surface must not leak the hash either — it is an integrity value,
        // and putting it on screen only invites someone to try it.
        $this->insertLogRow( [ 'payload_hash' => str_repeat( 'b', 64 ) ] );

        $html = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );

        $this->assertStringNotContainsString( str_repeat( 'b', 64 ), $html );
    }

    public function test_the_log_answers_for_one_player(): void {
        $lucas = $this->insertPlayer( 'Lucas', 'Berg' );
        $sara  = $this->insertPlayer( 'Sara', 'Vos' );
        $this->insertLogRow( [ 'recipient_player_id' => $lucas, 'subject' => 'About Lucas' ] );
        $this->insertLogRow( [ 'recipient_player_id' => $sara,  'subject' => 'About Sara' ] );

        $_GET['player_id'] = (string) $lucas;
        $html = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );

        $this->assertStringContainsString( 'About Lucas', $html );
        $this->assertStringNotContainsString( 'About Sara', $html );
        // The heading names the player, so the reader can see whose
        // record they are looking at without reading the URL.
        $this->assertStringContainsString( 'Lucas Berg', $html );
    }

    public function test_a_healthy_cron_shows_no_panel_and_a_broken_one_does(): void {
        // A panel that is always green is a panel nobody reads, so it only
        // appears when a detector has actually been failing.
        update_option( CommsScheduledCron::HEALTH_OPTION, [
            'goal_nudge' => [ 'ran_at' => '2026-08-29 03:00:00', 'ok' => true, 'error' => '' ],
        ] );
        $healthy = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );
        $this->assertStringNotContainsString( 'tt-msglog-health', $healthy );

        update_option( CommsScheduledCron::HEALTH_OPTION, [
            'goal_nudge' => [ 'ran_at' => '2026-08-29 03:00:00', 'ok' => false, 'error' => 'Table missing' ],
        ] );
        $broken = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );
        $this->assertStringContainsString( 'tt-msglog-health', $broken );
        $this->assertStringContainsString( 'Table missing', $broken );
    }

    public function test_an_empty_log_says_nothing_was_attempted(): void {
        // "No rows" and "it failed to send" are different problems with
        // different fixes, and the empty state is where that gets confused.
        $html = self::capture( static fn () => FrontendMessageLogView::render( get_current_user_id(), true ) );
        $this->assertStringContainsString( 'nothing was attempted', $html );
    }

    // ── the inbox ───────────────────────────────────────────────────────

    public function test_the_inbox_shows_only_the_readers_own_messages(): void {
        $this->insertInboxRow( $this->parent_a, 'Your training is off' );
        $this->insertInboxRow( $this->parent_b, 'Another family entirely' );

        wp_set_current_user( $this->parent_a );
        $html = self::capture( static fn () => FrontendMyMessagesView::render( get_current_user_id() ) );

        $this->assertStringContainsString( 'Your training is off', $html );
        $this->assertStringNotContainsString( 'Another family entirely', $html );
        $this->assertStringContainsString( '1 unread message', $html );
    }

    public function test_the_inbox_asks_a_signed_out_reader_to_sign_in_without_a_dead_end(): void {
        $html = self::capture( static fn () => FrontendMyMessagesView::render( 0 ) );
        $this->assertStringContainsString( 'signed in', $html );
        $this->assertStringContainsString( 'tt-breadcrumb', $html );
    }

    public function test_the_repository_cannot_reach_another_users_message(): void {
        // The guarantee is structural: the user id is in every WHERE
        // clause, so there is no "read any message" call to get wrong.
        $theirs = $this->insertInboxRow( $this->parent_b, 'Not yours' );
        $repo   = new CommsInboxRepository();

        $this->assertNull( $repo->findForUser( $theirs, $this->parent_a ) );
        $this->assertNull( $repo->setRead( $theirs, $this->parent_a, true ) );
        $this->assertSame( 1, $repo->unreadCount( $this->parent_b ), 'and the real owner is untouched' );
    }

    // ── the vocabulary ──────────────────────────────────────────────────

    public function test_every_status_reads_as_english(): void {
        foreach ( [ 'sent', 'opted_out', 'quiet_hours', 'rate_limited', 'no_recipients', 'template_disabled', 'exception' ] as $status ) {
            $label = CommsStatusLabels::label( $status );
            $this->assertNotSame( $status, $label, "status '{$status}' still renders as its key" );
            $this->assertStringNotContainsString( '_', $label );
        }
    }

    public function test_the_error_code_wins_where_it_says_more(): void {
        // "Failed" tells nobody anything; "No email address on file" tells
        // them what to fix.
        $this->assertSame( 'No email address on file', CommsStatusLabels::label( 'failed', 'no_address' ) );
    }

    public function test_an_honoured_opt_out_is_not_painted_as_a_failure(): void {
        // Three tones, not two: one red for both an opt-out and a bounce
        // teaches operators to ignore red.
        $this->assertSame( 'ok', CommsStatusLabels::tone( 'sent' ) );
        $this->assertSame( 'withheld', CommsStatusLabels::tone( 'opted_out' ) );
        $this->assertSame( 'problem', CommsStatusLabels::tone( 'bounced' ) );
    }

    public function test_the_player_filter_offers_only_players_the_log_mentions(): void {
        $mentioned = $this->insertPlayer( 'Lucas', 'Berg' );
        $this->insertPlayer( 'Never', 'Messaged' );
        $this->insertLogRow( [ 'recipient_player_id' => $mentioned ] );

        $players = ( new CommsLogRepository() )->playersInLog();

        $this->assertSame( [ $mentioned => 'Lucas Berg' ], $players );
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function insertPlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $overrides */
    private function insertLogRow( array $overrides = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_comms_log", array_merge( [
            'club_id'           => $this->club,
            'uuid'              => wp_generate_uuid4(),
            'template_key'      => 'training_cancelled',
            'message_type'      => MessageType::TRAINING_CANCELLED,
            'channel'           => 'email',
            'sender_user_id'    => 0,
            'recipient_user_id' => $this->parent_a,
            'recipient_kind'    => 'parent',
            'address_blob'      => 'ann@example.test',
            'subject'           => 'Training cancelled',
            'payload_hash'      => str_repeat( 'a', 64 ),
            'status'            => 'sent',
        ], $overrides ) );
        return (int) $wpdb->insert_id;
    }

    private function insertInboxRow( int $user_id, string $subject ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_comms_inbox", [
            'club_id'           => $this->club,
            'uuid'              => wp_generate_uuid4(),
            'recipient_user_id' => $user_id,
            'template_key'      => 'training_cancelled',
            'message_type'      => MessageType::TRAINING_CANCELLED,
            'subject'           => $subject,
            'body'              => 'Tuesday training is cancelled.',
        ] );
        return (int) $wpdb->insert_id;
    }

    private static function capture( callable $render ): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }
}
