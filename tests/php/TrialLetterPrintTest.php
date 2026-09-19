<?php
namespace TT\Tests\Php;

use WPDieException;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Print\TrialLetterPrintRouter;
use TT\Modules\Trials\TrialsModule;

/**
 * #3661 — the trial letter on screen and on paper.
 *
 * Two failures, one cause. The letter carried its own stylesheet inside
 * the HTML that gets stored for it; `wp_kses_post()` drops `<style>` tags
 * and keeps their text, so the Letter tab showed a block of CSS above an
 * unstyled letter. And the "Print view" link pointed at a `print=1` query
 * arg nothing read, so it reopened the case page — WP toolbar, tabs,
 * letter history and all — instead of printing a letter for a family.
 */
final class TrialLetterPrintTest extends WP_UnitTestCase {

    private int $case_id = 0;
    private int $manager = 0;
    private int $coach   = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Letter',
            'last_name'  => 'Subject',
            'status'     => 'trial',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => $club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'decided',
            'decision'   => 'admit',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach   = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        unset( $_GET[ TrialLetterPrintRouter::QUERY_ARG ], $_GET['case_id'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function case(): object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d",
            $this->case_id
        ) );
        $this->assertNotNull( $row );
        return $row;
    }

    private function generateLetter(): void {
        $id = ( new TrialLetterService() )->generate(
            $this->case(),
            AudienceType::TRIAL_ADMITTANCE,
            $this->manager
        );
        $this->assertGreaterThan( 0, $id, 'the letter was not generated' );
    }

    /* ---- The stylesheet is no longer content ------------------------- */

    public function test_a_generated_letter_carries_no_style_element(): void {
        $html = ( new LetterTemplateEngine() )->render( AudienceType::TRIAL_ADMITTANCE, $this->case() );

        $this->assertStringNotContainsString( '<style', $html );
        $this->assertStringContainsString( 'class="tt-letter"', $html );
    }

    public function test_a_letter_stored_before_the_fix_displays_without_its_stylesheet(): void {
        $stored = '<style>.tt-letter { max-width: 720px; }</style><article class="tt-letter"><p>Beste ouders</p></article>';

        $shown = LetterTemplateEngine::displayHtml( $stored );

        $this->assertStringNotContainsString( 'max-width: 720px', $shown );
        $this->assertStringNotContainsString( '<style', $shown );
        $this->assertStringContainsString( 'Beste ouders', $shown );
    }

    /* ---- The print document is only the letter ----------------------- */

    public function test_the_print_document_is_the_letter_and_nothing_else(): void {
        $this->generateLetter();

        $html = TrialLetterPrintRouter::renderHtml( $this->case_id );

        $this->assertStringContainsString( 'class="tt-letter"', $html );
        $this->assertStringNotContainsString( 'tt-trial-letter-preview', $html );
        $this->assertStringNotContainsString( 'wpadminbar', $html );
        $this->assertStringNotContainsString( 'tt-spine', $html );
        $this->assertStringNotContainsString( 'tt-player-tab-panel', $html );
        $this->assertStringNotContainsString( 'Letter history', $html );
    }

    public function test_a_legacy_letter_prints_without_its_stylesheet_text(): void {
        $this->generateLetter();

        global $wpdb;
        $letter = ( new TrialLetterService() )->findActiveForCase( $this->case_id );
        $this->assertNotNull( $letter );
        $row = (array) $letter;
        $wpdb->update(
            "{$wpdb->prefix}tt_player_reports",
            [ 'rendered_html' => '<style>.tt-legacy-marker { color: red; }</style>' . (string) $row['rendered_html'] ],
            [ 'id' => (int) $row['id'] ]
        );

        $html = TrialLetterPrintRouter::renderHtml( $this->case_id );

        $this->assertStringNotContainsString( 'tt-legacy-marker', $html );
        $this->assertStringContainsString( 'class="tt-letter"', $html );
    }

    public function test_a_case_without_a_letter_renders_nothing(): void {
        $this->assertSame( '', TrialLetterPrintRouter::renderHtml( $this->case_id ) );
    }

    /* ---- Access ------------------------------------------------------ */

    public function test_a_manager_may_print_and_an_unassigned_coach_may_not(): void {
        $this->assertTrue( TrialLetterPrintRouter::canPrint( $this->manager, $this->case_id ) );
        $this->assertFalse( TrialLetterPrintRouter::canPrint( $this->coach, $this->case_id ) );
    }

    public function test_a_logged_out_visitor_gets_401(): void {
        $this->generateLetter();
        wp_set_current_user( 0 );
        $_GET[ TrialLetterPrintRouter::QUERY_ARG ] = '1';
        $_GET['case_id']                           = (string) $this->case_id;

        try {
            TrialLetterPrintRouter::maybeRender();
            $this->fail( 'the letter was printed for a logged-out visitor' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 401, $e->getCode() );
        }
    }

    public function test_a_user_without_access_to_the_case_gets_403(): void {
        $this->generateLetter();
        wp_set_current_user( $this->coach );
        $_GET[ TrialLetterPrintRouter::QUERY_ARG ] = '1';
        $_GET['case_id']                           = (string) $this->case_id;

        try {
            TrialLetterPrintRouter::maybeRender();
            $this->fail( 'the letter was printed for a coach who is not on the case' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 403, $e->getCode() );
        }
    }
}
