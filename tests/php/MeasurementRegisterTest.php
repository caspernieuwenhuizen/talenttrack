<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Frontend\FrontendMeasurementsView;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * #3526 — the Metingen tab as one register per category.
 *
 * The complaint this rebuild answers was that the surface is least readable
 * exactly when a player is new, so most of what is worth pinning is the sparse
 * state: a never-measured test that used to render a bare dash, an apology
 * printed once per row, and a colour carrying a verdict with nothing on screen
 * to check it against.
 */
final class MeasurementRegisterTest extends WP_UnitTestCase {

    private int $player_id = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'first_name' => 'Tess', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;
    }

    private function define( string $name, string $direction = 'higher', string $frequency = 'annual', string $unit = 'm' ): int {
        return ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => 1,
            'name'        => $name,
            'value_type'  => 'numeric',
            'unit'        => $unit,
            'frequency'   => $frequency,
            'direction'   => $direction,
        ] );
    }

    private function record( int $definition_id, string $date, float $value ): void {
        ( new MeasurementResultsRepository() )->create( [
            'player_id'     => $this->player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $value,
        ] );
    }

    private function render(): string {
        ob_start();
        FrontendMeasurementsView::renderBody( $this->player_id );
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // One row shape
    // ---------------------------------------------------------------

    public function test_a_category_renders_one_table_not_two_idioms(): void {
        $this->record( $this->define( 'Sprint 30m', 'lower', 'quarterly', 's' ), '2026-06-01', 4.5 );
        $this->record( $this->define( 'Height', 'neutral', 'annual', 'cm' ), '2026-06-01', 168.0 );

        $html = $this->render();

        $this->assertStringContainsString( 'tt-meas-reg', $html );
        $this->assertStringNotContainsString( 'tt-meas-list', $html, 'the row list is gone' );
        $this->assertStringNotContainsString( 'tt-meas-cols__caption', $html, 'so is the second table and its caption' );
        $this->assertSame( 1, substr_count( $html, '<table class="tt-meas-reg"' ), 'one table per category' );
    }

    public function test_a_direction_less_test_is_an_ordinary_row_with_no_target(): void {
        $this->record( $this->define( 'Height', 'neutral', 'annual', 'cm' ), '2026-06-01', 168.0 );

        $html = $this->render();

        $this->assertStringContainsString( 'no target', $html );
        $this->assertStringNotContainsString( 'tt-meas-chip--ok', $html, 'no verdict where there is no better or worse' );
    }

    // ---------------------------------------------------------------
    // The sparse state — where the surface used to be worst
    // ---------------------------------------------------------------

    public function test_a_never_measured_test_says_so_instead_of_printing_a_bare_dash(): void {
        $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );

        $html = $this->render();

        $this->assertStringContainsString( 'not measured yet', $html );
        $this->assertStringContainsString( 'Never measured:', $html, 'and it is named in the footer' );
        $this->assertStringContainsString( 'Yo-Yo', $html, 'rather than dropped out of the table' );
    }

    public function test_the_single_reading_line_appears_once_per_category_not_once_per_row(): void {
        $this->record( $this->define( 'Sprint 30m', 'lower', 'quarterly', 's' ), '2026-06-01', 4.5 );
        $this->record( $this->define( 'Jump', 'higher', 'annual', 'cm' ), '2026-06-01', 41.0 );
        $this->record( $this->define( 'Yo-Yo', 'higher', 'annual', 'm' ), '2026-06-01', 1200.0 );

        $html = $this->render();

        $this->assertSame(
            1,
            substr_count( $html, 'a trend needs at least two' ),
            'three single-reading tests used to print the same apology three times'
        );
    }

    public function test_the_no_target_explanation_renders_once_per_surface(): void {
        // Two direction-less tests, so a per-category note would show twice.
        $this->record( $this->define( 'Height', 'neutral', 'annual', 'cm' ), '2026-06-01', 168.0 );
        $this->record( $this->define( 'Weight', 'neutral', 'annual', 'kg' ), '2026-06-01', 58.0 );

        $html = $this->render();

        $this->assertSame( 1, substr_count( $html, 'the reading is recorded, not judged' ) );
    }

    // ---------------------------------------------------------------
    // Colour never alone
    // ---------------------------------------------------------------

    public function test_a_verdict_is_stated_in_words_beside_the_colour(): void {
        // A never-measured test always produces a chip, so this asserts
        // something whatever the target rows on the install look like.
        $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $this->define( 'Sprint 30m', 'lower', 'quarterly', 's' ), '2026-06-01', 4.5 );

        $html = $this->render();

        preg_match_all( '/class="tt-meas-chip[^"]*">([^<]*)</', $html, $matches );

        $this->assertNotEmpty( $matches[1], 'the register renders at least one verdict chip' );
        foreach ( $matches[1] as $label ) {
            $this->assertNotSame( '', trim( $label ), 'a chip never carries colour without words' );
        }
    }

    public function test_the_old_colour_only_value_pill_is_gone(): void {
        $this->record( $this->define( 'Yo-Yo', 'higher', 'annual', 'm' ), '2026-06-01', 1200.0 );

        $html = $this->render();

        $this->assertStringNotContainsString( 'tt-meas-flag-ok', $html );
        $this->assertStringNotContainsString( 'tt-meas-flag-warn', $html );
        $this->assertStringNotContainsString( 'tt-meas-flag-bad', $html );
    }

    // ---------------------------------------------------------------
    // The trend column
    // ---------------------------------------------------------------

    public function test_a_two_reading_test_shows_a_signed_change_with_its_sense_named(): void {
        $id = $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $id, '2025-06-01', 1200.0 );
        $this->record( $id, '2026-06-01', 1620.0 );

        $html = $this->render();

        $this->assertStringContainsString( 'tt-meas-reg__delta', $html );
        $this->assertStringContainsString( 'forward', $html, 'the sense is named, never left to the slope' );
    }

    public function test_the_history_opens_across_the_register_rather_than_inside_a_column(): void {
        $id = $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $id, '2025-06-01', 1200.0 );
        $this->record( $id, '2026-06-01', 1620.0 );

        $html = $this->render();

        $this->assertStringContainsString( 'tt-meas-reg__historyrow', $html );
        $this->assertStringContainsString( 'colspan="5"', $html );
        $this->assertStringContainsString( '<details class="tt-meas-trend">', $html );
    }

    // ---------------------------------------------------------------
    // Overdue, which is the one new derivation
    // ---------------------------------------------------------------

    public function test_a_stale_annual_reading_is_overdue(): void {
        $id = $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $id, gmdate( 'Y-m-d', strtotime( '-20 months' ) ?: time() ), 1200.0 );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $this->player_id );
        $test    = $profile[0]['tests'][0];

        $this->assertTrue( (bool) $test['overdue'] );
        $this->assertStringContainsString( 'Overdue:', $this->render() );
    }

    public function test_a_recent_annual_reading_is_not_overdue(): void {
        $id = $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $id, gmdate( 'Y-m-d', strtotime( '-2 months' ) ?: time() ), 1200.0 );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $this->player_id );

        $this->assertFalse( (bool) $profile[0]['tests'][0]['overdue'] );
    }

    public function test_an_annual_reading_just_past_the_year_is_still_inside_its_grace(): void {
        // A measuring round that slips a fortnight must not flag the squad.
        $id = $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );
        $this->record( $id, gmdate( 'Y-m-d', strtotime( '-12 months -14 days' ) ?: time() ), 1200.0 );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $this->player_id );

        $this->assertFalse( (bool) $profile[0]['tests'][0]['overdue'] );
    }

    public function test_a_never_measured_test_is_missing_rather_than_overdue(): void {
        $this->define( 'Yo-Yo', 'higher', 'annual', 'm' );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $this->player_id );

        $this->assertFalse(
            (bool) $profile[0]['tests'][0]['overdue'],
            'a blank profile is a different problem from a stale reading'
        );
    }

    public function test_a_test_with_no_frequency_is_never_overdue(): void {
        $id = $this->define( 'Yo-Yo', 'higher', '', 'm' );
        $this->record( $id, '2020-01-01', 1200.0 );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $this->player_id );

        $this->assertFalse(
            (bool) $profile[0]['tests'][0]['overdue'],
            'nothing was said about how often to measure, so there is nothing to be late for'
        );
    }
}
