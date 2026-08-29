<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\Frontend\PlayerTallyRoster;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Wizards\PlayersStep;

/**
 * #2726 — the roster is a tally grid, and the markup behind it still posts.
 *
 * Two failure modes are worth freezing here, because both are invisible
 * until a coach loses work:
 *
 *   1. The script builds the grid from server-rendered markup. If that
 *      markup ever stops carrying a real radio per marker, the grid becomes
 *      the only way to set a value and a browser without JS silently posts
 *      nothing.
 *   2. Every wizard step is its own request. The stylesheet being enqueued
 *      on one step only is what #2726 was — and the failure looked like a
 *      broken screen, not a missing style, because the marker chips are a
 *      hidden input plus a styled label.
 */
final class MatchAnalysisRosterTest extends WP_UnitTestCase {

    /** @var int */
    private $activity_id;

    /** @var int */
    private $player_id;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 9,
            'title'             => 'PSV U16 — home',
            'session_date'      => '2026-08-15',
            'activity_type_key' => 'game',
        ] );
        $this->activity_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 9,
            'first_name' => 'Joris',
            'last_name'  => 'Veen',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'        => CurrentClub::id(),
            'activity_id'    => $this->activity_id,
            'player_id'      => $this->player_id,
            'status'         => 'present',
            'record_type'    => 'actual',
            'minutes_played' => 65,
            'is_guest'       => 0,
        ] );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function roster(): array {
        return [
            [
                'player_id'     => $this->player_id,
                'name'          => 'Joris',
                'full_name'     => 'Joris Veen',
                'minutes'       => 65,
                'marker'        => '',
                'note'          => '',
                'note_items'    => [],
                'team_function' => null,
                'prep_focus'    => '',
                'prep_specific' => false,
                'prep_analyst'  => false,
            ],
        ];
    }

    private function render(): string {
        ob_start();
        PlayerTallyRoster::render( $this->roster(), 'test' );
        return (string) ob_get_clean();
    }

    // ---- the markup the script enhances -----------------------------------

    public function test_the_container_is_marked_for_the_script(): void {
        $this->assertStringContainsString( 'data-tt-tally', $this->render() );
    }

    /**
     * The grid is presentation. Every marker still has a real radio behind
     * it, or a browser that never runs the script posts nothing at all.
     */
    public function test_every_marker_has_a_real_radio_behind_it(): void {
        $html = $this->render();

        foreach ( array_keys( MatchAnalysisEnums::markers() ) as $marker ) {
            $this->assertStringContainsString(
                'value="' . $marker . '"',
                $html,
                "marker '$marker' has no radio in the fallback markup"
            );
        }

        $this->assertStringContainsString(
            'name="players[' . $this->player_id . '][marker]"',
            $html,
            'the radio must post under the name the writer reads'
        );
        $this->assertStringContainsString( 'value=""', $html, 'clearing a marker needs its own option' );
    }

    /**
     * The note and phase inputs exist for every player, marked or not — the
     * script only hides them. Rendering them lazily would mean a player
     * marked client-side had nowhere to type.
     */
    public function test_note_and_phase_inputs_render_for_an_unmarked_player(): void {
        $html = $this->render();

        // Two note rows since #3091, each with its own valence control, so
        // a player can hold a plus and a minus in the same match.
        $this->assertStringContainsString( 'name="players[' . $this->player_id . '][notes][0][body]"', $html );
        $this->assertStringContainsString( 'name="players[' . $this->player_id . '][notes][1][body]"', $html );
        $this->assertStringContainsString( 'name="players[' . $this->player_id . '][notes][0][valence]"', $html );
        $this->assertStringContainsString( 'name="players[' . $this->player_id . '][team_function]"', $html );
    }

    public function test_the_row_carries_what_the_script_needs_to_label_it(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'data-player-id="' . $this->player_id . '"', $html );
        $this->assertStringContainsString( 'data-name="Joris"', $html );
        $this->assertStringContainsString( 'data-marker=""', $html );
    }

    /**
     * Colour is not the only signal: each marker carries a glyph, so the
     * grid still reads for anyone who cannot separate the green fill from
     * the amber one.
     */
    public function test_each_marker_carries_a_glyph(): void {
        $glyphs = PlayerTallyRoster::glyphs();

        $this->assertSame( '▲', $glyphs[ MatchAnalysisEnums::MARKER_STOOD_OUT ] );
        $this->assertSame( '▼', $glyphs[ MatchAnalysisEnums::MARKER_BELOW_PAR ] );
        $this->assertStringContainsString( '▲', $this->render() );
    }

    public function test_an_empty_roster_says_so_instead_of_rendering_an_empty_grid(): void {
        ob_start();
        PlayerTallyRoster::render( [], 'test' );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( 'data-tt-tally', $html );
        $this->assertStringContainsString( 'tt-ma__hint', $html );
    }

    // ---- #2726: the assets, on every step ---------------------------------

    public function test_the_players_step_enqueues_the_stylesheet_and_the_script(): void {
        // A wizard step is its own request; enqueueing on step 1 only is
        // exactly the bug this test exists to keep fixed.
        ob_start();
        ( new PlayersStep() )->render( [ 'activity_id' => $this->activity_id ] );
        ob_end_clean();

        $this->assertTrue( wp_style_is( 'tt-frontend-match-analysis', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'tt-match-analysis-tally', 'enqueued' ) );
    }

    public function test_the_players_step_renders_the_shared_roster(): void {
        ob_start();
        ( new PlayersStep() )->render( [ 'activity_id' => $this->activity_id ] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-tt-tally', $html );
        $this->assertStringContainsString( 'name="players[' . $this->player_id . '][marker]"', $html );
    }

    /**
     * A coach who went Back and changed their mind must see their own
     * in-flight answer, not what the database still holds.
     */
    public function test_wizard_state_wins_over_the_stored_value(): void {
        ob_start();
        ( new PlayersStep() )->render( [
            'activity_id' => $this->activity_id,
            'players'     => [
                $this->player_id => [
                    'marker'        => MatchAnalysisEnums::MARKER_BELOW_PAR,
                    'notes'         => [ [ 'valence' => 'minus', 'body' => 'Zakte te ver in.' ] ],
                    'team_function' => null,
                ],
            ],
        ] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-marker="below_par"', $html );
        $this->assertStringContainsString( 'Zakte te ver in.', $html );
    }
}
