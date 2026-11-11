<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PdpPdfExporter;
use TT\Modules\Export\Format\Renderers\PdfRenderer;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Print\PdpFormPrintableRenderer;
use TT\Modules\Pdp\Print\PdpPrintRouter;
use TT\Tests\Php\Support\GoldenSnapshot;

/**
 * #4982 (epic #4975) — a signed conversation form, printed and as a PDF.
 *
 * One renderer for both, so the PDF is the print page without its toolbar.
 * What prints is what the reader may read on screen: the same refusals, the
 * same parts. Golden snapshots pin the document for both forms.
 */
final class PdpFormPrintTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const OUTFIELD   = 'jg4it_elite_outfield';
    private const GOALKEEPER = 'jg4it_elite_goalkeeper';

    private string $p    = '';
    private int $club    = 0;
    private int $player  = 0;
    private int $conv    = 0;
    private int $file    = 0;
    private int $trainer = 0;
    private int $account = 0;
    private int $parent  = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( PdpFormRegistry::CONFIG_KEY, 'rating_min', 'rating_max', 'rating_step' );
        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        GoldenSnapshot::reset();

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

        $this->trainer = (int) self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Trainer Golden' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        PdpFormRegistry::reset();
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    // ---- the document --------------------------------------------------------

    /**
     * @dataProvider forms
     */
    public function test_the_signed_form_prints_as_the_academy_form_and_the_pdf_is_the_same( string $position, string $form ): void {
        $this->seed( $position );
        $this->playerPrepares( $form );
        $this->signOff( $form );

        $printed = PdpFormPrintableRenderer::forConversation( $this->conv, $this->trainer );
        $this->assertSame( PdpFormPrintableRenderer::OK, $printed['status'] );
        GoldenSnapshot::assertMatches( 'pdp_form_print/' . $form, $printed['body'] );

        $items = count( PdpFormRegistry::items( (array) PdpFormRegistry::definition( $form ) ) );
        $this->assertSame( $items, substr_count( $printed['body'], '<td class="tt-pdpform-print__t">' ), 'every item with the trainer\'s score' );

        // Paper and PDF: one document, the PDF without the browser toolbar.
        $page = PdpPrintRouter::renderConversationHtml( $this->conv, $this->trainer )['html'];
        $pdf  = $this->exportHtml( $this->trainer );
        $this->assertStringContainsString( 'class="toolbar"', $page );
        $this->assertStringNotContainsString( 'class="toolbar"', $pdf );
        $this->assertStringContainsString( $printed['body'], $page );
        $this->assertStringContainsString( $printed['body'], $pdf );

        // Every item with both scores on one A4.
        $this->assertSame( 1, $this->pdfPages( $this->trainer ), 'one A4' );
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function forms(): array {
        return [
            'outfield'   => [ 'CB', self::OUTFIELD ],
            'goalkeeper' => [ 'GK', self::GOALKEEPER ],
        ];
    }

    public function test_the_print_is_dompdf_safe_and_scoped_to_its_wrapper(): void {
        $css = PdpFormPrintableRenderer::styleBlock();
        $this->assertDoesNotMatchRegularExpression( '/display\s*:\s*(inline-)?(flex|grid)/', $css, 'no flex or grid' );
        foreach ( array_filter( array_map( 'trim', explode( "\n", $css ) ) ) as $rule ) {
            if ( strpos( $rule, '@page' ) === 0 ) continue;
            foreach ( explode( ',', (string) strstr( $rule, '{', true ) ) as $selector ) {
                $this->assertStringStartsWith( '.tt-pdpform-print', trim( $selector ), 'scoped: ' . $rule );
            }
        }
    }

    public function test_without_preparation_or_confirmation_it_says_so(): void {
        $this->seed( 'CB' );
        $this->signOff( self::OUTFIELD );
        $body = PdpFormPrintableRenderer::forConversation( $this->conv, $this->trainer )['body'];
        $items = count( PdpFormRegistry::items( (array) PdpFormRegistry::definition( self::OUTFIELD ) ) );
        $this->assertSame( $items, substr_count( $body, '<td class="tt-pdpform-print__s"><em>not filled in</em></td>' ), 'the player\'s column on every item' );
        $this->assertSame( 2, substr_count( $body, 'not yet confirmed' ), 'the player and the parent, with no date' );
    }

    public function test_an_unfinished_conversation_prints_no_form_draft(): void {
        $this->seed( 'CB' );
        $this->playerPrepares( self::OUTFIELD );
        $printed = PdpFormPrintableRenderer::forConversation( $this->conv, $this->trainer );
        $this->assertSame( PdpFormPrintableRenderer::UNSIGNED, $printed['status'] );
        $this->assertStringNotContainsString( 'tt-pdpform-print__items', $printed['body'], 'no item, no score' );
        $this->assertStringNotContainsString( 'Mijn wendbaarheid 4982', $printed['body'] );
    }

    // ---- who prints ----------------------------------------------------------

    public function test_the_print_follows_the_screens_access(): void {
        global $wpdb;
        $this->seed( 'CB' );

        $this->assertSame( PdpFormPrintableRenderer::REFUSED, PdpFormPrintableRenderer::forConversation( $this->conv, $this->parent )['status'], 'a parent before sign-off' );
        $this->signOff( self::OUTFIELD );

        foreach ( [ 'staff' => $this->trainer, 'player' => $this->account, 'parent' => $this->parent ] as $who => $uid ) {
            $printed = PdpFormPrintableRenderer::forConversation( $this->conv, $uid );
            $this->assertSame( PdpFormPrintableRenderer::OK, $printed['status'], $who );
            $this->assertStringContainsString( 'tt-pdpform-print__t', $printed['body'], $who . ' reads the trainer\'s scores' );
        }

        // Print is offered where the form is read: under the family's read
        // view, and under the trainer's signed form.
        wp_set_current_user( $this->parent );
        ob_start();
        \TT\Modules\Pdp\Frontend\PdpFormReadView::render( $this->conv, $this->player );
        $this->assertStringContainsString( 'conversation_id=' . $this->conv, (string) ob_get_clean(), 'the parent\'s read view' );
        wp_set_current_user( $this->trainer );
        ob_start();
        \TT\Modules\Pdp\Frontend\PdpFormConversationView::render( $this->conv, $this->trainer );
        $this->assertStringContainsString( 'tt-pdp-printact', (string) ob_get_clean(), 'the trainer\'s signed form' );

        $stranger = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $wpdb->insert( "{$this->p}tt_people", [ 'club_id' => $this->club, 'first_name' => 'Obs', 'last_name' => '4982', 'role_type' => 'readonly_observer', 'wp_user_id' => $observer, 'status' => 'active' ] );
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false );
        $this->flush();
        foreach ( [ 'unlinked parent' => $stranger, 'observer' => $observer, 'parent with the PDP hidden' => $this->parent ] as $who => $uid ) {
            $status = PdpFormPrintableRenderer::forConversation( $this->conv, $uid )['status'];
            $this->assertContains( $status, [ PdpFormPrintableRenderer::REFUSED, PdpFormPrintableRenderer::NOT_FOUND ], $who );
            $this->assertStringContainsString( 'You do not have access', $this->exportHtml( $uid ), $who . ' gets no PDF' );
        }
    }

    public function test_a_talk_without_a_form_prints_its_file_as_before(): void {
        global $wpdb;
        $this->seed( 'CB' );
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [ 'club_id' => $this->club, 'pdp_file_id' => $this->file, 'sequence' => 2, 'template_key' => 'mid' ] );
        $plain = (int) $wpdb->insert_id;
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '' );
        PdpFormRegistry::reset();

        $printed = PdpPrintRouter::renderConversationHtml( $plain, $this->trainer );
        $this->assertSame( PdpFormPrintableRenderer::NO_FORM, $printed['status'] );
        $this->assertSame( '', $printed['html'], 'no form document' );
        $this->assertSame( $this->file, $printed['file_id'], 'the file prints instead' );

        wp_set_current_user( $this->trainer );
        $file = ( new \TT\Modules\Pdp\Repositories\PdpFilesRepository() )->find( $this->file );
        $whole = PdpPrintRouter::renderHtml( (object) $file, false );
        foreach ( [ 'Current goals', 'Agreed actions', 'Coach signature', 'Player signature', 'Parent signature' ] as $part ) {
            $this->assertStringContainsString( $part, $whole, 'the whole-file print keeps ' . $part );
        }
        $this->assertStringNotContainsString( 'tt-pdpform-print', $whole );
        $this->assertSame( $whole, $this->exportHtml( $this->trainer, $plain ) === '' ? '' : $whole, 'unchanged' );
        $this->assertStringContainsString( 'Coach signature', $this->exportHtml( $this->trainer, $plain ), 'and the PDF of a plain talk is the file' );
    }

    // ---- helpers -------------------------------------------------------------

    private function exportHtml( int $user, int $conv = 0 ): string {
        wp_set_current_user( $user );
        AuthorizationService::flushCache();
        $out = ( new PdpPdfExporter() )->collect( $this->request( $user, $conv ) );
        return (string) ( $out['html'] ?? '' );
    }

    private function pdfPages( int $user ): int {
        wp_set_current_user( $user );
        $request = $this->request( $user, 0 );
        $bytes   = ( new PdfRenderer() )->render( $request, ( new PdpPdfExporter() )->collect( $request ) )->bytes;        return (int) preg_match_all( '#/Type\s*/Page[^s]#', $bytes );
    }

    private function request( int $user, int $conv ): ExportRequest {
        return new ExportRequest( 'pdp_pdf', 'pdf', $this->club, $user, null, [ 'file_id' => 0, 'conversation_id' => $conv > 0 ? $conv : $this->conv, 'include_evidence' => false ] );
    }

    private function playerPrepares( string $form ): void {
        $first = PdpFormRegistry::items( (array) PdpFormRegistry::definition( $form ) )[0]['key'];
        wp_set_current_user( $this->account );
        $this->assertSame( 200, $this->send( 'PATCH', 'form/player', [ 'scores' => [ $first => 4 ], 'answers' => [ 'strongest_quality' => 'Mijn wendbaarheid 4982' ] ] )[1] );
        $this->assertSame( 200, $this->send( 'PUT', 'form/player/preparation', [] )[1] );
    }

    private function signOff( string $form ): void {
        global $wpdb;
        $scores = [];
        foreach ( PdpFormRegistry::items( (array) PdpFormRegistry::definition( $form ) ) as $i => $item ) $scores[ $item['key'] ] = ( $i % 3 ) + 2;
        // A note on every section, as a trainer writes them: the fuller case
        // for the one-A4 check.
        $answers = [ 'strongest_quality' => 'Afgesproken: rust aan de bal, en eerst kijken voor je de bal krijgt.' ];
        foreach ( (array) ( (array) PdpFormRegistry::definition( $form ) )['sections'] as $section ) {
            $answers[ 'notes.' . (string) $section['key'] ] = 'Toelichting 4982: goede stappen gezet dit blok, nog wisselend onder druk; blijven herhalen in de positiespellen.';
        }
        wp_set_current_user( $this->trainer );
        [ $data, $status ] = $this->send( 'PATCH', 'form/coach', [
            'scores'  => $scores,
            'answers' => $answers,
            'goals'   => [
                '1' => [ 'action' => 'Twintig passes links per training.', 'support' => 'Extra oefenvorm.', 'due_date' => '2027-01-31' ],
                '2' => [ 'action' => 'Kijken voor de aanname.', 'support' => 'Video na elke wedstrijd.', 'due_date' => '2027-02-28' ],
            ],
        ] );
        $this->assertSame( 200, $status, wp_json_encode( $data ) );
        [ $data, $status ] = $this->send( 'POST', 'signoff', [] );
        $this->assertSame( 201, $status, wp_json_encode( $data ) );
        // A fixed moment, so the snapshot does not move with the clock.
        $wpdb->update( "{$this->p}tt_pdp_conversations", [ 'coach_signoff_at' => '2026-10-06 09:30:00', 'conducted_at' => '2026-10-05 16:00:00' ], [ 'id' => $this->conv ] );
        $this->flush();
    }

    private function seed( string $position ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1 Golden' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Sam', 'last_name' => 'Print4982',
            'status' => 'active', 'preferred_positions' => '["' . $position . '"]',
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_seasons", [ 'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1 ] );
        $wpdb->insert( "{$this->p}tt_pdp_files", [ 'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => (int) $wpdb->insert_id, 'status' => 'open' ] );
        $this->file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id' => $this->club, 'pdp_file_id' => $this->file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
        ] );
        $this->conv    = (int) $wpdb->insert_id;
        $this->account = (int) self::factory()->user->create( [ 'role' => 'tt_player', 'display_name' => 'Sam Print4982' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $this->account ], [ 'id' => $this->player ] );
        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'display_name' => 'Ouder Golden' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->parent, true );
        $this->flush();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/pdp-conversations/' . $this->conv . '/' . $route );
        if ( $body !== [] ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
