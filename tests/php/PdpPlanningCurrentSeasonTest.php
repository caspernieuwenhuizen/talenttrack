<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;

/**
 * #4258 — PDP planning opens on the season the admin flagged current, not
 * on whichever season today's date falls in. The date lookups remain the
 * fallback when no season is flagged.
 */
final class PdpPlanningCurrentSeasonTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tt_seasons WHERE club_id = %d",
            CurrentClub::id()
        ) );
    }

    public function test_flagged_season_wins_over_the_one_covering_today(): void {
        $flagged = $this->season( 'Flagged', '-400 days', '-40 days', true );
        $this->season( 'Covers today', '-30 days', '+300 days', false );

        $this->assertSame( $flagged, $this->resolve() );
    }

    public function test_without_a_flag_the_season_covering_today_is_used(): void {
        $this->season( 'Older', '-400 days', '-40 days', false );
        $today = $this->season( 'Covers today', '-30 days', '+300 days', false );
        $this->season( 'Later', '+310 days', '+600 days', false );

        $this->assertSame( $today, $this->resolve() );
    }

    public function test_without_a_flag_or_a_covering_season_the_latest_is_used(): void {
        $this->season( 'Older', '-800 days', '-440 days', false );
        $latest = $this->season( 'Latest', '-400 days', '-40 days', false );

        $this->assertSame( $latest, $this->resolve() );
    }

    private function season( string $name, string $start, string $end, bool $current ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [
            'club_id'    => (int) CurrentClub::id(),
            'name'       => $name,
            'start_date' => gmdate( 'Y-m-d', (int) strtotime( $start ) ),
            'end_date'   => gmdate( 'Y-m-d', (int) strtotime( $end ) ),
            'is_current' => $current ? 1 : 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function resolve(): int {
        $method = new ReflectionMethod( FrontendPdpPlanningView::class, 'resolveCurrentSeason' );
        $method->setAccessible( true );
        return (int) $method->invoke( null );
    }
}
