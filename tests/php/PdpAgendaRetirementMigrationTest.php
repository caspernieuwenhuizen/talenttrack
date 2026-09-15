<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\PdpConversationTemplate;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Prep\PdpPrepAnswersRepository;
use TT\Modules\Pdp\Prep\PdpPrepQuestionDefaults;
use TT\Modules\Pdp\Prep\PdpPrepQuestionsRepository;

/**
 * The retirement of `tt_pdp_conversations.agenda`, end to end:
 * migration 0257 moves the text into the prep question set (#3306), and
 * migration 0263 drops the column once it has (#3381).
 *
 * Both halves live in one class because the second one is only safe if the
 * first one worked, and the assertion that matters is the join between
 * them: every non-empty value counted before the drop is still readable
 * after it.
 *
 * The suite bootstrap has already run both migrations, so the column is
 * gone before the first test starts. `set_up` puts it back — the only way
 * to exercise a drop is to have something to drop — and `tear_down` takes
 * it away again along with the fixtures. That cleanup is explicit rather
 * than left to the usual transaction rollback: an ALTER commits the
 * enclosing transaction, so nothing written in these tests would roll back
 * on its own.
 */
final class PdpAgendaRetirementMigrationTest extends WP_UnitTestCase {

    private const AGENDA = 'Ask about the move to a back three, and about school.';

    private string $p;
    private int $club;
    private int $file;
    private int $user = 0;

    /** @var int[] */
    private array $archived = [];

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        MigrationHelpers::addColumnIfMissing(
            "{$this->p}tt_pdp_conversations",
            'agenda',
            'LONGTEXT',
            'conducted_at'
        );

        ( new RolesService() )->installRoles();
        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        $this->seedFile();
    }

    public function tear_down(): void {
        global $wpdb;

        foreach ( $this->archived as $question_id ) {
            $wpdb->update( "{$this->p}tt_pdp_prep_questions", [ 'archived_at' => null ], [ 'id' => $question_id ] );
        }
        $this->archived = [];

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->p}tt_pdp_prep_answers
              WHERE conversation_id IN (
                  SELECT id FROM {$this->p}tt_pdp_conversations WHERE pdp_file_id = %d
              )",
            $this->file
        ) );
        $wpdb->delete( "{$this->p}tt_pdp_conversations", [ 'pdp_file_id' => $this->file ] );
        $wpdb->delete( "{$this->p}tt_pdp_files", [ 'id' => $this->file ] );
        $wpdb->delete( "{$this->p}tt_players", [ 'first_name' => 'Agenda', 'last_name' => 'Player' ] );

        if ( MigrationHelpers::columnExists( "{$this->p}tt_pdp_conversations", 'agenda' ) ) {
            $wpdb->query( "ALTER TABLE {$this->p}tt_pdp_conversations DROP COLUMN agenda" );
        }

        if ( $this->user > 0 ) {
            wp_delete_user( $this->user );
            $this->user = 0;
        }

        parent::tear_down();
    }

    /* ---- 0257: the move ------------------------------------------------- */

    public function test_agenda_text_arrives_verbatim_as_a_prep_answer(): void {
        $conversation = $this->insertConversation( PdpConversationTemplate::START, self::AGENDA );

        $this->runMove();

        $target  = $this->catchAll( PdpConversationTemplate::START );
        $answers = ( new PdpPrepAnswersRepository() )->forConversation( $conversation );

        $this->assertArrayHasKey( $target, $answers );
        $this->assertSame( self::AGENDA, $answers[ $target ]['answer_text'] );
    }

    public function test_a_re_run_does_not_overwrite_what_a_coach_has_since_written(): void {
        $conversation = $this->insertConversation( PdpConversationTemplate::MID, self::AGENDA );

        $this->runMove();

        $target = $this->catchAll( PdpConversationTemplate::MID );
        ( new PdpPrepAnswersRepository() )->saveMany( $conversation, [ $target => 'Rewritten since.' ] );

        $this->runMove();

        $answers = ( new PdpPrepAnswersRepository() )->forConversation( $conversation );
        $this->assertSame( 'Rewritten since.', $answers[ $target ]['answer_text'] );
        $this->assertCount( 1, $answers );
    }

    public function test_a_conversation_with_no_catch_all_keeps_its_column(): void {
        // An academy that archived the free-text question before upgrading.
        global $wpdb;

        $conversation = $this->insertConversation( PdpConversationTemplate::END, self::AGENDA );
        $this->archiveCatchAll( PdpConversationTemplate::END );

        $this->runMove();

        $this->assertSame( [], ( new PdpPrepAnswersRepository() )->forConversation( $conversation ) );

        $kept = $wpdb->get_var( $wpdb->prepare(
            "SELECT agenda FROM {$this->p}tt_pdp_conversations WHERE id = %d",
            $conversation
        ) );
        $this->assertSame( self::AGENDA, $kept );
    }

    public function test_a_conversation_with_no_agenda_gains_no_empty_answer(): void {
        $conversation = $this->insertConversation( PdpConversationTemplate::START, '' );

        $this->runMove();

        $this->assertSame( [], ( new PdpPrepAnswersRepository() )->forConversation( $conversation ) );
    }

    /* ---- 0263: the drop ------------------------------------------------- */

    /**
     * The count that licenses the drop. Three conversations carrying text,
     * three prep answers carrying the same text afterwards — asserted on
     * the far side of the ALTER, where the column can no longer answer for
     * itself.
     */
    public function test_every_agenda_value_is_still_readable_after_the_column_goes(): void {
        $texts = [
            PdpConversationTemplate::START => 'Ask about the back three.',
            PdpConversationTemplate::MID   => 'School reports are due.',
            PdpConversationTemplate::END   => 'Talk about next season.',
        ];
        foreach ( $texts as $template => $text ) {
            $this->insertConversation( $template, $text );
        }

        $this->assertSame( 3, $this->conversationsWithAgenda(), 'three values to account for' );

        $this->runMove();
        $this->runDrop();

        $this->assertFalse(
            MigrationHelpers::columnExists( "{$this->p}tt_pdp_conversations", 'agenda' ),
            'the column is gone'
        );

        foreach ( $texts as $text ) {
            $this->assertSame( 1, $this->answersCarrying( $text ), "\"{$text}\" survived the drop" );
        }
    }

    /**
     * The other direction, and the one that matters more: a value the move
     * never reached keeps its column. Losing the text is not recoverable;
     * an install carrying a column a fresh one does not have is a ticket.
     */
    public function test_the_drop_waits_for_text_the_move_never_reached(): void {
        global $wpdb;

        $conversation = $this->insertConversation( PdpConversationTemplate::START, self::AGENDA );

        // 0257 deliberately skipped this one — its catch-all was archived.
        $this->archiveCatchAll( PdpConversationTemplate::START );
        $this->runMove();

        $this->runDrop();

        $this->assertTrue(
            MigrationHelpers::columnExists( "{$this->p}tt_pdp_conversations", 'agenda' ),
            'the column is kept while a value lives only in it'
        );
        $kept = $wpdb->get_var( $wpdb->prepare(
            "SELECT agenda FROM {$this->p}tt_pdp_conversations WHERE id = %d",
            $conversation
        ) );
        $this->assertSame( self::AGENDA, $kept );
    }

    public function test_the_drop_is_idempotent(): void {
        $this->insertConversation( PdpConversationTemplate::START, self::AGENDA );

        $this->runMove();
        $this->runDrop();
        $this->runDrop();

        $this->assertFalse( MigrationHelpers::columnExists( "{$this->p}tt_pdp_conversations", 'agenda' ) );
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function runMove(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0257_pdp_agenda_to_prep.php';
        $migration->up();
    }

    private function runDrop(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0263_drop_pdp_conversation_agenda.php';
        $migration->up();
    }

    private function conversationsWithAgenda(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_pdp_conversations
              WHERE pdp_file_id = %d AND agenda IS NOT NULL AND agenda <> ''",
            $this->file
        ) );
    }

    private function answersCarrying( string $text ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_pdp_prep_answers WHERE answer_text = %s",
            $text
        ) );
    }

    private function catchAll( string $template_key ): int {
        $question = ( new PdpPrepQuestionsRepository() )
            ->findByLabelKey( $template_key, PdpPrepQuestionDefaults::ANYTHING_ELSE );
        $this->assertNotNull( $question, 'the shipped set ends with the free-text catch-all' );
        return (int) $question['id'];
    }

    private function archiveCatchAll( string $template_key ): void {
        $id = $this->catchAll( $template_key );
        ( new PdpPrepQuestionsRepository() )->archive( $id );
        $this->archived[] = $id;
    }

    private function seedFile(): void {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Agenda',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $player,
            'status'    => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;
    }

    private function insertConversation( string $template_key, string $agenda ): int {
        global $wpdb;

        static $sequence = 0;
        $sequence++;

        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id'      => $this->club,
            'pdp_file_id'  => $this->file,
            'sequence'     => $sequence,
            'template_key' => $template_key,
            'scheduled_at' => '2026-10-01 10:00:00',
            'agenda'       => $agenda !== '' ? $agenda : null,
        ] );
        return (int) $wpdb->insert_id;
    }
}
