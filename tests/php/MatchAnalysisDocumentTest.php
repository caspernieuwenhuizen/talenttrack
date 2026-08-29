<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\Frontend\MatchAnalysisDocument;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #2748 / #2749 — the finished document, and sharing it.
 *
 * The document is what the read-only view, the share page and the print
 * sheet all render, so what is frozen here is the shape of the thing people
 * forward to each other: both chains present, the phases in the order a
 * coach reads them, unmentioned players left off.
 *
 * The share tests exist for a bug worth never repeating: rendering the
 * surface used to mint a share link as a side effect, so every analysis
 * anyone merely looked at ended up with a live URL nobody asked for.
 */
final class MatchAnalysisDocumentTest extends WP_UnitTestCase {

    /** @var int */
    private $activity_id;

    /** @var array<int,int> */
    private $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 4,
            'title'             => 'Wedstrijd 6.2',
            'session_date'      => '2026-08-15',
            'activity_type_key' => 'game',
            'opponent'          => 'Ajax U17',
            'home_away'         => 'away',
        ] );
        $this->activity_id = (int) $wpdb->insert_id;

        foreach ( [ 'Thijs', 'Justin' ] as $name ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id'    => CurrentClub::id(),
                'team_id'    => 4,
                'first_name' => $name,
                'last_name'  => 'Speler',
            ] );
            $player_id = (int) $wpdb->insert_id;
            $this->players[ $name ] = $player_id;

            $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
                'club_id'        => CurrentClub::id(),
                'activity_id'    => $this->activity_id,
                'player_id'      => $player_id,
                'status'         => 'present',
                'record_type'    => 'actual',
                'minutes_played' => 70,
                'is_guest'       => 0,
            ] );
        }

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function writeAnalysis(): int {
        $composer    = new MatchAnalysisComposer();
        $analysis_id = ( new MatchAnalysisRepository() )->ensureForActivity( $this->activity_id );

        ( new MatchAnalysisWriter() )->apply( $analysis_id, [
            'summary'  => 'Traag begonnen en er ingegroeid.',
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ 'Rustig opgebouwd' ],
                ],
                MatchAnalysisEnums::SECTION_SET_PIECES_DEFEND => [
                    'rating' => MatchAnalysisEnums::RATING_NEEDS_WORK,
                    'notes'  => [ 'Tweede bal na hun corners' ],
                ],
            ],
            'players'  => [
                $this->players['Thijs'] => [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT, 'note' => 'Verdedigend sterk.' ],
            ],
        ], [] );

        return $analysis_id;
    }

    private function document(): string {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $this->activity_id, false );

        ob_start();
        MatchAnalysisDocument::render( $payload );
        return (string) ob_get_clean();
    }

    // ---- the document -----------------------------------------------------

    public function test_it_renders_both_chains_with_every_phase(): void {
        $this->writeAnalysis();
        $html = $this->document();

        foreach ( MatchAnalysisEnums::chains() as $keys ) {
            foreach ( $keys as $key ) {
                $this->assertStringContainsString(
                    esc_html( MatchAnalysisEnums::sectionLabel( $key ) ),
                    $html,
                    "phase '$key' is missing from the document"
                );
            }
        }
    }

    /**
     * The chain order is the point of the two columns: a transition only
     * means something read next to the phase it comes out of.
     */
    public function test_each_chain_reads_in_order(): void {
        $this->writeAnalysis();
        $html = $this->document();

        foreach ( MatchAnalysisEnums::chains() as $keys ) {
            $previous = -1;
            foreach ( $keys as $key ) {
                $at = strpos( $html, esc_html( MatchAnalysisEnums::sectionLabel( $key ) ) );
                $this->assertIsInt( $at );
                $this->assertGreaterThan( $previous, $at, "phase '$key' is out of order" );
                $previous = $at;
            }
        }
    }

    public function test_an_unrated_phase_is_marked_as_such_rather_than_hidden(): void {
        $this->writeAnalysis();
        $html = $this->document();

        // Verdedigen was never rated in the fixture: it still appears, so
        // the reader can see what was not covered.
        $this->assertStringContainsString( 'data-rating=""', $html );
        $this->assertStringContainsString( esc_html__( 'Not rated', 'talenttrack' ), $html );
    }

    public function test_only_mentioned_players_appear(): void {
        $this->writeAnalysis();
        $html = $this->document();

        $this->assertStringContainsString( 'Thijs', $html );
        $this->assertStringNotContainsString( 'Justin', $html, 'an unmentioned player must not be listed' );
    }

    public function test_the_summary_is_rendered_as_its_own_block(): void {
        $this->writeAnalysis();
        $html = $this->document();

        $this->assertStringContainsString( 'tt-mad__summary', $html );
        $this->assertStringContainsString( 'Traag begonnen', $html );
    }

    /**
     * An analysis written before the set-piece split keeps its words even
     * though the key is no longer offered for writing.
     */
    public function test_a_legacy_set_piece_section_still_renders(): void {
        global $wpdb;

        $analysis_id = $this->writeAnalysis();

        $wpdb->insert( $wpdb->prefix . 'tt_match_analysis_sections', [
            'club_id'     => CurrentClub::id(),
            'analysis_id' => $analysis_id,
            'section_key' => MatchAnalysisEnums::SECTION_SET_PIECES_LEGACY,
            'rating'      => MatchAnalysisEnums::RATING_MIXED,
            'updated_at'  => current_time( 'mysql' ),
        ] );

        // The note lives in its own table since #3091, which is where
        // migration 0245 puts the text a pre-split row was carrying. Written
        // the same way here so the fixture matches what a real install has
        // after upgrading.
        $wpdb->insert( $wpdb->prefix . 'tt_match_analysis_notes', [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => CurrentClub::id(),
            'analysis_id' => $analysis_id,
            'scope'       => 'section',
            'section_key' => MatchAnalysisEnums::SECTION_SET_PIECES_LEGACY,
            'valence'     => '',
            'body'        => 'Van voor de splitsing',
            'position'    => 0,
            'updated_at'  => current_time( 'mysql' ),
        ] );

        $this->assertStringContainsString( 'Van voor de splitsing', $this->document() );
    }

    // ---- sharing ----------------------------------------------------------

    /**
     * The bug this file exists for: opening an analysis must not create a
     * share link. A seed written by a render is a URL nobody chose to hand
     * out, on a document that names children.
     */
    public function test_rendering_the_document_does_not_mint_a_share_link(): void {
        $analysis_id = $this->writeAnalysis();

        $this->document();

        $this->assertSame(
            '',
            ( new MatchAnalysisRepository() )->shareTokenSeed( $analysis_id ),
            'rendering wrote a share-token seed'
        );
    }

    public function test_creating_a_link_is_explicit_and_idempotent(): void {
        $analysis_id = $this->writeAnalysis();
        $repo        = new MatchAnalysisRepository();

        $first = $this->post( '/talenttrack/v1/activities/' . $this->activity_id . '/analysis/share' );

        $this->assertNotSame( '', $repo->shareTokenSeed( $analysis_id ) );
        $this->assertStringContainsString( 'match-analysis-share', (string) $first['share_url'] );

        // Asking twice must not quietly invalidate the link already sent
        // out — replacing one is what rotate is for, and it says so.
        $second = $this->post( '/talenttrack/v1/activities/' . $this->activity_id . '/analysis/share' );
        $this->assertSame( $first['share_url'], $second['share_url'] );
    }

    public function test_rotating_returns_a_different_link(): void {
        $this->writeAnalysis();

        $first  = $this->post( '/talenttrack/v1/activities/' . $this->activity_id . '/analysis/share' );
        $second = $this->post( '/talenttrack/v1/activities/' . $this->activity_id . '/analysis/share/rotate' );

        $this->assertNotSame( $first['share_url'], $second['share_url'] );
    }

    /**
     * @return array<string,mixed>
     */
    private function post( string $route ): array {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', $route ) );
        $data     = (array) $response->get_data();

        $this->assertTrue( (bool) $data['success'], "POST {$route} failed" );

        return (array) $data['data'];
    }
}
