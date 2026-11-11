<?php
namespace TT\Tests\Php;

use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;

/**
 * #5080 (epic #5078) — a PDP talk's page is two parts, each with its own save
 * state: 1 the talk's details, 2 the form. A form talk asks no notes and no
 * agreed actions in part 1; an older one shows what they hold, read only.
 * Sign-off sits outside both, under "Finish".
 */
final class PdpTalkPartsTest extends WP_UnitTestCase {

    // Form writes elsewhere in the suite commit their own transaction.
    use CommitsAfterCascade;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';

    private string $p    = '';
    private int $club    = 0;
    private int $file    = 0;
    private int $conv    = 0;
    private int $trainer = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step' );
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();

        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        $forms = [ 'outfield' => self::OUTFIELD, 'goalkeeper' => self::GOALKEEPER ];
        $this->assertIsArray( PdpFormCategorySeeder::ensure( $forms ) );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->seed();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        unset( $_GET['id'], $_GET['conv'] );
        PdpFormRegistry::reset();
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_each_part_carries_its_own_save_state_over_its_own_fields(): void {
        $html    = $this->page();
        $details = $this->part( $html, 'tt-pdp-part tt-pdp-part--details' );
        $form    = $this->part( $html, 'tt-pdp-part tt-pdp-coachform' );

        $this->assertSame( 2, substr_count( $html, 'data-tt-save-state' ), 'one save state per part' );
        $this->assertSame( 1, substr_count( $details, 'data-tt-save-state' ) );
        $this->assertSame( 1, substr_count( $form, 'data-tt-save-state' ) );
        $this->assertSame( 1, substr_count( $details, 'data-tt-save-undo' ), 'part 1 has its own undo' );
        $this->assertSame( 1, substr_count( $form, 'data-tt-save-undo' ), 'part 2 has its own undo' );

        // An undo takes back what its own form committed, and the forms do
        // not share a field: part 1 holds no score, part 2 no date.
        $this->assertStringContainsString( 'name="scheduled_at"', $details );
        $this->assertStringNotContainsString( 'name="scores[', $details );
        $this->assertStringContainsString( 'name="scores[', $form );
        $this->assertStringNotContainsString( 'name="scheduled_at"', $form );
        $this->assertStringNotContainsString( 'name="conducted_at"', $form );

        $this->assertStringContainsString( 'tt-pdp-part__num" aria-hidden="true">1</span>', $details );
        $this->assertStringContainsString( 'Conversation details', $details );
        $this->assertStringContainsString( 'tt-pdp-part__head tt-pdp-part__head--sticky', $form, 'part 2\'s header stays in view' );
        $this->assertStringContainsString( 'Conversation form · Outfield player', $form );
    }

    public function test_a_form_talk_asks_no_notes_and_no_agreed_actions(): void {
        $html = $this->page();

        $this->assertStringNotContainsString( 'name="notes"', $html );
        $this->assertStringNotContainsString( 'name="agreed_actions"', $html );
        $this->assertStringNotContainsString( 'tt-pdp-part__earlier', $html, 'nothing earlier to show' );
        $this->assertStringContainsString( 'name="conducted_at"', $html, 'the dates stay' );
    }

    public function test_an_older_form_talk_shows_its_notes_read_only(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_pdp_conversations", [
            'notes' => 'Besproken: de passing 5080.', 'agreed_actions' => 'Extra training links 5080.',
        ], [ 'id' => $this->conv ] );
        $html = $this->page();

        $this->assertStringNotContainsString( 'name="notes"', $html, 'no input' );
        $this->assertStringNotContainsString( 'name="agreed_actions"', $html );
        $this->assertStringContainsString( 'tt-pdp-part__earlier', $html );
        $this->assertStringContainsString( 'Besproken: de passing 5080.', $html, 'nothing written is hidden' );
        $this->assertStringContainsString( 'Extra training links 5080.', $html );
    }

    public function test_a_talk_without_a_form_keeps_both_fields_in_one_part(): void {
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '' );
        PdpFormRegistry::reset();
        $html = $this->page();

        $this->assertStringContainsString( 'name="notes"', $html );
        $this->assertStringContainsString( 'name="agreed_actions"', $html );
        $this->assertStringNotContainsString( 'tt-pdp-coachform', $html );
        $this->assertSame( 1, substr_count( $html, 'data-tt-save-state' ) );
        $this->assertStringNotContainsString( 'tt-pdp-part__num', $html, 'one part needs no number' );
        $this->assertStringContainsString( '<h2 class="tt-pdp-finish-h">Finish</h2>', $html, 'sign-off under its own heading' );
    }

    public function test_sign_off_sits_outside_both_parts_under_finish(): void {
        $html     = $this->page();
        $finish   = strpos( $html, '<h2 class="tt-pdp-finish-h">Finish</h2>' );
        $box      = strpos( $html, 'data-tt-coach-signoff-box' );
        $form_end = strrpos( substr( $html, 0, (int) $box ), '</section>' );

        $this->assertNotFalse( $finish );
        $this->assertNotFalse( $box );
        $this->assertGreaterThan( (int) $form_end, $finish, 'the heading follows the last part' );
        $this->assertGreaterThan( $finish, $box, 'and the sign-off sits under it' );
        $this->assertStringNotContainsString( 'data-tt-coach-signoff-box', $this->part( $html, 'tt-pdp-part tt-pdp-coachform' ) );
    }

    // ---- helpers -------------------------------------------------------------

    private function page(): string {
        wp_set_current_user( $this->trainer );
        $_GET['id']   = (string) $this->file;
        $_GET['conv'] = (string) $this->conv;
        ob_start();
        FrontendPdpManageView::render( $this->trainer, true );
        return (string) ob_get_clean();
    }

    /** One part's markup: from its opening tag to the first `</section>` after it. */
    private function part( string $html, string $class ): string {
        $at = strpos( $html, '<section class="' . $class . '"' );
        if ( $at === false ) return '';
        $end = strpos( $html, '</section>', $at );
        return substr( $html, $at, $end === false ? null : $end - $at );
    }

    private function seed(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Parts O15-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sam', 'last_name' => 'Tweedelen',
            'status' => 'active', 'preferred_positions' => '["CB"]',
        ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id' => $this->club, 'player_id' => $player, 'season_id' => $season,
            'owner_coach_id' => $this->trainer, 'status' => 'open', 'cycle_size' => 2,
        ] );
        $this->file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $this->file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv = (int) $wpdb->insert_id;
    }
}
