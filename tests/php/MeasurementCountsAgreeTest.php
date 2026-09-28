<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Modules\Measurements\Frontend\FrontendMeasurementsView;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * #4166 — one player profile printed three measurement counts: the tab
 * badge, the category header and the at-a-glance tile. The badge counted
 * result rows without looking at the definition, so a test hidden from the
 * profile still counted towards it.
 */
final class MeasurementCountsAgreeTest extends WP_UnitTestCase {

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

    private function define( string $name, int $show_on_profile = 1 ): int {
        return ( new MeasurementDefinitionsRepository() )->create( [
            'category_id'     => 1,
            'name'            => $name,
            'value_type'      => 'numeric',
            'unit'            => 's',
            'frequency'       => 'quarterly',
            'direction'       => 'lower',
            'show_on_profile' => $show_on_profile,
        ] );
    }

    private function record( int $definition_id ): void {
        ( new MeasurementResultsRepository() )->create( [
            'player_id'     => $this->player_id,
            'definition_id' => $definition_id,
            'recorded_date' => '2026-06-20',
            'value_numeric' => 4.5,
        ] );
    }

    public function test_the_badge_skips_a_test_hidden_from_the_profile(): void {
        $this->record( $this->define( 'Sprint 30m' ) );
        $this->record( $this->define( 'Secret metric', 0 ) );
        $this->define( 'Yo-Yo' );

        $badge   = PlayerFileCounts::for( $this->player_id )['measurements'];
        $tracked = ( new PlayerMeasurementProfile() )->summaryForPlayer( $this->player_id )['tracked'];

        $this->assertSame( 1, $badge, 'only the visible, measured test counts' );
        $this->assertSame( $tracked, $badge, 'the badge and the at-a-glance tile agree' );
    }

    public function test_the_category_header_says_how_many_tests_carry_a_reading(): void {
        $this->record( $this->define( 'Sprint 30m' ) );
        $this->define( 'Yo-Yo' );

        ob_start();
        FrontendMeasurementsView::renderBody( $this->player_id );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( '1 of 2 measured', $html );
    }
}
